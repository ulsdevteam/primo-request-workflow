
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

	/*
	* Construct link to appropriate ILLiad request form
	* @param string $campus Which Alma "campus" does the user belong to? 
	* @return string The complete ILLiad request form URL
	*/
	public function buildUrl($campus) {
		// Service address
		if ($campus==="HSLS") {
			$url = 'https://illiad.hsls.pitt.edu/illiad/illiad.dll/AtlasAuthPortal/?Action=10&Form=30&';
		}
		else {
			$url = 'https://pitt-illiad-oclc-org.pitt.idm.oclc.org/illiad/illiad.dll/OpenURL?';
		}
		$userQueryString = '';
		//sanitize user-generated query string parameters
		foreach($_GET as $k=>$v) {
			//php replaces query string parameter keys containing dots as underscores, so rft.title becomes rft_title. Change that back with str_replace, but only for rft keys. rfr, for example, uses an underscore natively, like rfr_id
			if (!empty($v)){
				$userQueryString .= str_replace("rft_", "rft.", urlencode($k)).'='.urlencode($v).'&';
			}
		}
		//the last param should not be followed by &
		$url .= rtrim($userQueryString,'&');
		return $url;
	}
}

//These Alma user groups aren't permitted to place external requests
$noExternalBorrowing = array('LAWSPBORROWER','PATPURGE','PITTLIBASSIGNMENT','PROBLEM','ULSSPBORROWER','ULSSPRECIPROCAL','UPPROGRAM');

//Get the patron's Pitt username
$user = new Alma();
$userId = $user->getUserId();
$requestStatus = '';

//if their account has all the info we need
if (($userRecord = $user->getUserRecord($userId)) && $userRecord->campus_code && $userRecord->user_group->value) {
	//Find user's Alma usergroup and campus
	//If the blank Campus dropdown menu option in Alma is saved for a user, their user campus_code object doesn't get a value, just a blank description property
	if (!property_exists($userRecord->campus_code,'value')) {
		$campus = 'BLANK';
	}
	//Otherwise, just take the campus value straight from the user record 
	else{
		$campus = $userRecord->campus_code->value;
		//Barco Law Library users do not participate in Illiad, so we'll handle them separately later 
		if ($userRecord->campus_code->value==='LAW' && $requestStatus !== 'specialBorrower') {
			$requestStatus='lawPatron';
		}
	}

	//Determine Alma usergroup
	$userGroup = $userRecord->user_group->value;
	//Check that against the blocklist for special borrowers
	if (in_array($userGroup,$noExternalBorrowing)) {
		$requestStatus='specialBorrower';
	}
	if (!$requestStatus) {
	$illiad = new Illiad();
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
					<p>Resource Sharing services from other institutions are not available for your user group. Please <a href="https://www.library.pitt.edu/ask-us">Ask Us</a> for assistance.</p>
SPECIAL_BORROWER;
						break;
					case "lawPatron":
						echo <<<LAW_PATRON
						<p>Law School users, please see <a href="https://www.library.law.pitt.edu/research/interlibrary-loan-delivery">https://www.library.law.pitt.edu/research/interlibrary-loan-delivery</a> for interlibrary loan inforrmation.</p>
LAW_PATRON;
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


