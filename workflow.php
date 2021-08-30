
<?php
#
# Uses this PHP Rest Client: https://github.com/tcdent/php-restclient
#
$openurlParams = array(
'title',
'author',
'publisher',
'location',
'year',
'oclc',
'issn',
'requesttype',
'volume',
'issue',
'month',
'atitle',
'pages',
'pickup',
'notes',
);

//url encode user-submitted input from the query string
$userSubmittedParams = array();
foreach ($openurlParams as $p) {
	if (isset($_GET[$p])){
		$userSubmittedParams[$p] = urlencode($_GET[$p]);
	}
}

Class Alma {

	private $api;
	private $userCache = array();
	/*
	*returns ExLibris API function
	*/
	public function __construct(){
		include_once 'vendor/tcdent/php-restclient/restclient.php';
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
		include_once 'vendor/tcdent/php-restclient/restclient.php';
		include_once '../../configs/config.php';
		$this->api = new RestClient([
			'base_url' => $this->apiConfig($campus)['base_url'].'/illiadwebplatform/',
			'headers' => ['Apikey'=> $this->apiConfig($campus)['api_key'],
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
	* ILLiad api base url and key for user's library system
	* @param string $campus the user's Alma "campus" code
	* @return array The ILLiad base url and api key for the corresponding library system
	*/
	private function apiConfig($campus) {
		if ($this->librarySystem($campus) == "ULS"){
			return array('base_url'=>'https://pitt.illiad.oclc.org/','api_key'=>ILLIAD_API_KEY_ULS);
		}
		elseif ($this->librarySystem($campus) == "HSLS") {
			return array('base_url'=>'https://illiad.hsls.pitt.edu/','api_key'=>ILLIAD_API_KEY_HSLS);
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
	* @param array $params Contains url-encoded entries from the query string to determine request type and prepopulate ILLiad fields
	* @return string The complete ILLiad request form URL
	*/
	public function buildUrl($campus, $params) {
		// Service address
		if ($this->librarySystem($campus)=="ULS") {
			$url = 'https://pitt-illiad-oclc-org.pitt.idm.oclc.org/illiad/illiad.dll';
		}
		elseif ($this->librarySystem($campus)=="HSLS") {
			$url = 'https://illiad.hsls.pitt.edu/illiad/illiad.dll';
		}
		// Action and Form
		switch ($params['requesttype']) {
			case 'book':
				$url .= '?Action=10&Form='.($campus === 'HSLS' ? '30' : '21');
				break;
			case 'chapter':
				$url .= '?Action=10&Form=23';
				break;
			case 'article':
				$url .= '?Action=10&Form=22';
				break;
		}

		// Parameters
		$map = array();
		switch ($params['requesttype']) {
			case 'book':
				$map = array('LoanTitle' => 'title', 'LoanAuthor' => 'author', 'LoanPublisher' => 'publisher', 'LoanPlace' => 'location', 'LoanDate' => 'year', 'ESPNumber' => 'oclc');
				break;
			case 'article':
				// article specific
				$map = array('PhotoJournalVolume' => 'volume', 'PhotoJournalIssue' => 'issue', 'PhotoJournalMonth' => 'month', 'PhotoArticleTitle' => 'atitle', 'PhotoJournalInclusivePages' => 'pages');
				break;
			case 'chapter':
				// both articles and chapters
				$map = array_merge($map, array('PhotoJournalTitle' => 'title', 'PhotoItemAuthor' => 'author', 'PhotoItemPublisher' => 'publisher', 'PhotoItemPlace' => 'location', 'PhotoJournalYear' => 'year', 'ESPNumber' => 'oclc'));
				break;
		}
		foreach ($map as $k => $v) {
			$url .= '&'.$k.'='.$params[$v];
		}
		return $url;
	}
}

//Get the patron's Pitt username
$user = new Alma();
$userId = $user->getUserId();

//if their account has all the info we need
if ($user->getUserRecord($userId) && $user->getUserRecord($userId)->campus_code) {

	//Determine which Alma campus the user belongs to
	$campus = $user->getUserRecord($userId)->campus_code->value;
	
	//Does user have an ILLiad account? We'll check based on their campus and corresponding library system
	$illiad = new Illiad($campus);
	$illiadUserExists = $illiad->userExists($userId);

	//construct a link to the appropriate ILLiad form
	$illiadUrl = $illiad->buildUrl($campus, $userSubmittedParams);
	
	//if they already have an ILLiad account
	if ($illiadUserExists) {
		//send them immediately to the ILLiad request form
		//if not, the html instructions below will display by default
		$requestStatus = "Success";
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
				if ($requestStatus == 'almaError') {
					echo <<<ALMA_API_ERROR
					<p>Error: Failed to connect to your library account. Please <a href="https://www.library.pitt.edu/ask-us">Ask Us</a> for assistance.</p>
ALMA_API_ERROR;
				}
				else{
			echo <<<NOILLIADUSER
			<p>Hi, there!  Prior to completing your request, and due to changes with our materials sharing provider, we ask that you <a href="$illiadUrl">complete a one-time registration with our interlibrary-loan service</a> if you have not already done so.</p>
			<p>After registration, just complete the request in the form provided and we will have your materials on their way to you as quickly as possible!</p>
			<p>If you need any additional assistance, please <a href="https://www.library.pitt.edu/ask-us">Ask Us</a></p>
NOILLIADUSER;
			}
			?>
     	</div>
	 </body>
</html>


