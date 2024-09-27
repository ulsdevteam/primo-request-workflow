
<?php
#
# Uses this PHP Rest Client: https://github.com/tcdent/php-restclient
#

Class Alma {

	private $api;
	private $userCache = array();
	/*
	*returns ExLibris API function
	*/
	public function __construct(){
		include_once 'php-restclient/restclient.php';
		include_once '../../configs/config.php';
		
		$this->api = new RestClient([
			'base_url' => 'https://api-na.hosted.exlibrisgroup.com/',
			'headers' => ['Authorization' => 'apikey '. EXL_API_KEY,
						  'Accept' => 'application/json',
						  'Content-Type'=>' application/json',
						 ],
		]);
	}

	/*
	*returns unique id for user that will work in ExLibris Alma Users API
	*/
	public function getUserId(){
		if (getenv('HTTP_CN')) {
			return getenv('HTTP_CN');
		}
		else {
			return false;
		}
	}

	/*
	* @param $userId A unique id that references a user in our Alma database
	* returns php object describing the requested user's info in Alma
	*/
	public function getUserRecord($userId){
		if (isset($this->userCache[$userId])) {
			return $this->userCache[$userId];
		}
		$user = $this->api->get("almaws/v1/users/$userId");
		//SUCCESS: GOT INDIVIDUAL USER OBJECT
		if($user->info->http_code == 200) {
			$output = $user->response;
			$output = json_decode($output);
			$this->userCache[$userId] = $output;
			return $output;
		}
		//COULDN'T GET INDIVIDUAL USER OBJECT
		else {
			return false;
		}
	}
}

class Illiad {

	private $api;

	/*
	*returns ILLiad API function
	*/
	public function __construct($campus){
		include_once 'php-restclient/restclient.php';
		include_once '../../configs/config.php';
		$system=$this->librarySystem($campus);
		$this->api = new RestClient([
			'base_url' => ILLIAD_API_CONFIG[$system]['BASE_URL'] . 'illiadwebplatform/',
			'headers' => ['Apikey'=>ILLIAD_API_CONFIG[$system]['KEY'],
					'Accept' => 'application/json; version=1',
					'Content-Type' => 'application/json',
			],
		]);
	}

	/*
	* Pitt has two library systems that serve users from different Alma "campuses"
	* @param string $campus the user's Alma campus
	* $return string The user's library system for ILLiad purposes
	*/
	private function librarySystem($campus) {
		switch ($campus) {
			case "UPG":
			case "UPB":
			case "UPT":
			case "UPJ":
			case "PIT":
				return 'ULS';
				break;
			case "HSLS":
				return 'HSLS';
				break;
		}
	}	

	/*
	* Does Pitt user also have an existing ILLiad account?
	* @param string $user A Pitt username
	* @return bool
	*/
	public function userExists($user){
		$illiadUserRequest = $this->api->get("Users/$user@pitt.edu");
		
		//ILLiad user exists
		if($illiadUserRequest->info->http_code == 200) {
			return true;
		}
		//Couldn't find ILLiad user
		else {
			return false;
		}
	}	

	/*
	* Construct link to appropriate ILLiad request form
	* @param string $campus Which Alma "campus" does the user belong to? 
	* @return string The complete ILLiad request form URL
	*/
	public function buildUrl($campus) {
		// Service address
		if ($this->librarySystem($campus)=="ULS") {
			$url = 'https://pitt-illiad-oclc-org.pitt.idm.oclc.org/illiad/illiad.dll/OpenURL?';
		}
		elseif ($this->librarySystem($campus)=="HSLS") {
			$url = 'https://illiad.hsls.pitt.edu/illiad/illiad.dll/OpenURL?';
		}
		$userQueryString = '';
		//sanitize user-generated query string parameters
		foreach($_GET as $k=>$v) {
			//php replaces query string parameter keys containing dots as underscores, so rft.title becomes rft_title. Change that back with str_replace, but only for rft keys. rfr, for example, uses an underscore natively, like rfr_id
			$userQueryString .= str_replace("rft_", "rft.", urlencode($k)).'='.urlencode($v).'&';
		}
		//the last param should not be followed by &
		$url .= rtrim($userQueryString,'&');
		return $url;
	}
}

$noExternalBorrowing = array('HSLSSPBORROWER','LAWSPBORROWER','PATPURGE','PITTLIBASSIGNMENT','PROBLEM','ULSSPBORROWER','ULSSPRECIPROCAL','UPPROGRAM');
//Get the patron's Pitt username
$user = new Alma();
$userId = $user->getUserId();
$requestStatus = '';

//if their account has all the info we need
if ($user->getUserRecord($userId) && $user->getUserRecord($userId)->campus_code && $user->getUserRecord($userId)->user_group->value) {
	$userGroup = $user->getUserRecord($userId)->user_group->value;
	//this user group isn't permitted to place external requests
	if (in_array($userGroup,$noExternalBorrowing)) {
		$requestStatus='specialBorrower';
	}
	//Barco Law Library users do not participate in Illiad. 
	elseif ($user->getUserRecord($userId)->campus_code->value==='LAW') {
		$requestStatus='lawPatron';
	}
	//handle alternate Alma user 'Campus' options
	elseif ($user->getUserRecord($userId)->campus_code->value==='NA') {
		$requestStatus='unknownCampus';
	}
	//if the blank Campus option in Alma is saved for a user, their User object doesn't get a value, just a blank description property 
	elseif ($user->getUserRecord($userId)->campus_code->desc==='') {
		$requestStatus='blankCampus';
	}
	else {
		//continue on with the existing workflow
		//Determine which Alma campus the user belongs to
		$campus = $user->getUserRecord($userId)->campus_code->value;
		//Does user have an ILLiad account? We'll check based on their campus and corresponding library system
		$illiad = new Illiad($campus);
		$illiadUserExists = $illiad->userExists($userId);
		//construct a link to the appropriate ILLiad form
		$illiadUrl = $illiad->buildUrl($campus);
		//send them to ther campus's ILLiad request form
		header("Location: $illiadUrl");
	}
}
//Couldn't get Alma user record.
else {
		$requestStatus = 'almaError';
}
?>
<html>
	<head>
		<meta charset="utf-8">
		<title>Request this item</title>
		<link href="request.css" rel="stylesheet" type="text/css">
		<base href="/">
	</head>
    <body ng-app="ezBorrowAPI" ng-controller="ezBorrowAPIController">
        <div id="pitt-header">
        	<a href="https://pittcat.pitt.edu">
        		<img src="img/logos.png" alt="University of Pittsburgh Pittcat">
        	</a>
      	</div>
     	<div id="main-content">
	 		<h1>Request This Item</h1>
	 		<?php 
				switch ($requestStatus) {
					case "almaError":
						echo <<<ALMA_API_ERROR
						 <p>Error: Failed to connect to your library account. Please <a href="https://www.library.pitt.edu/ask-us">Ask Us</a> for assistance.</p>
ALMA_API_ERROR;
						break;
					case "specialBorrower":
					echo <<<SPECIAL_BORROWER
					<p>Special borrowers cannot order books from other libraries. Please <a href="https://www.library.pitt.edu/ask-us">Ask Us</a> for assistance.</p>
SPECIAL_BORROWER;
						break;
					case "lawPatron":
						echo <<<LAW_PATRON
						<p>Law School users, please see <a href="https://www.library.law.pitt.edu/research/interlibrary-loan-delivery">https://www.library.law.pitt.edu/research/interlibrary-loan-delivery</a> for interlibrary loan inforrmation.</p>
LAW_PATRON;
						break;
					case "unknownCampus":
						echo <<<UNKNOWN_CAMPUS
						<p>Your request cannot be submitted because the campus field in your library account is listed as 'Unknown.' Please <a href="https://www.library.pitt.edu/ask-us">Ask Us</a> for assistance and mention this error message.</p>
UNKNOWN_CAMPUS;
						break;
					case "blankCampus":
						echo <<<BLANK_CAMPUS
						<p>Your request cannot be submitted because the campus field in your library account is blank.  Please <a href="https://www.library.pitt.edu/ask-us">Ask Us</a> for assistance and mention this error message.</p>
BLANK_CAMPUS;
						break;
					default:
					echo <<<OTHER_ERROR
					<p>There is a problem with your request. Please <a href="https://www.library.pitt.edu/ask-us">Ask Us</a> for assistance.</p>
OTHER_ERROR;
				}
			?>
     	</div>
	 </body>
</html>


