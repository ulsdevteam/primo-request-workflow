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

$userParams = array();
foreach ($openurlParams as $p) {
	if (isset($_GET[$p])){
	// Legacy: set the variable by name
	if ($p === 'requesttype') {
		$type = urlencode($_GET[$p]);
	} else {
		//$$p sets a variable named with the value of $p
		//we also give it the value of $p here
		//eg. $pages = urlencode(_GET['pages']);
		$$p = urlencode($_GET[$p]);
	}
	// Preferred: create an array for passing around
	// Instead of calling for $pages, we say $userParams['pages'];
	$userParams[$p] = urlencode($_GET[$p]);
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
	* @param $userId is a unique id that references a user in our Alma database
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
			print 'Error: Failed to connect to your library account.  Please ask us for help with this at https://library.pitt.edu/askus';
			return false;
		}
	}
}

class EZBorrow {

	private $api;
	
	/*
	*returns Relais EZ Borrow API function
	*/
	public function __construct(){
		include_once 'vendor/tcdent/php-restclient/restclient.php';
		include_once '../../configs/config.php';

		$this->api = new RestClient([
			'base_url' => 'https://e-zborrow.relais-host.com/',
			'headers'  => ['Accept' => 'application/json',
						   'Content-Type'=>' application/json',
						  ],
		]);
	}

	/* ======= EZ AUTH ======== */
	public function ezAuth($userBarcode){
		$auth = array('ApiKey' => RELAIS_API_KEY, 'UserGroup' => "patron", 'PartnershipId' => "EZB", 'LibrarySymbol' => "PITT", 'PatronId' => $userBarcode);
		$send_data = json_encode($auth);
		$response = $this->api->post("portal-service/user/authentication", utf8_encode($send_data));
		$content = json_decode($response->response);
		// print_r($response);
		if ($response->info->http_code == 200 || $response->info->http_code == 201) {
			$aid=$content->AuthorizationId;
			return $aid; 
		}
		else{
			echo $response->response;
			return false;
		}
	}

	/* ======== EZ SEARCH ======== */
	public function ezSearch($userBarcode,$oclc){
		if ($aid = $this->ezAuth($userBarcode)){
			//$sampleresponse = '{"Available":true,"RequestLink":{"RequestMessage":"At this time, all EZBorrow services are suspended at your institution. Please contact staff at your institution\'s library with any questions."},"OrigNumberOfRecords":1,"PickupLocation":[{"PickupLocationCode":"HILL","PickupLocationDescription":"Hillman"},{"PickupLocationCode":"LCSU","PickupLocationDescription":"LCSU"}]}';
			//return $sampleresponse;
			
			//for real you'll have to actually make and handle the search request
			$data = json_encode(array('PartnershipId'=>'EZB','ExactSearch'=>array(['Type'=>'OCLC','Value'=>$oclc])));
			$response = $this->api->post("dws/item/available?aid=$aid", utf8_encode($data));
			return $response->response;
		}
	}
	
	/* ======== EZ REQUEST ======== */
	public function ezRequest($pickup,$oclc,$notes){		
		if ($aid = $this->ezAuth()){
			//return '{"Problem":{"Message":"You are blocked!"}}';
			//for real you will use code below
			$data = array('PartnershipId'=>'EZB','PickupLocation'=>$pickup,'ExactSearch'=>array(['Type'=>'OCLC','Value'=>$oclc]));
			if ($notes){
				$data['Notes']=$notes;
			}
			$data = json_encode($data);
			//return early for testing:
	 		//return $data;
			$response = $this->api->post("dws/item/add?aid=$aid", utf8_encode($data));
			return $response->response;
		}
	}

}

class Illiad {

	/* ======== ILLIAD ======== */
	public static function bookRequest($type,$campus,$oUrl){
		switch($campus){
			case "UPG":
			case "UPB":
			case "UPT":
			case "UPJ":
			case "PIT":
				$illiadLink = self::buildUrl($type, $campus, $oUrl);
				return $illiadLink;
				break;
			case "HSLS":
				header("Location: ".self::buildUrl($type, $campus, $oUrl));
				break;
		} 
	}

	public static function buildUrl($type, $campus, $params) {
		$url = '';
		// Service address
		switch ($campus) {
			case "UPG":
			case "UPB":
			case "UPT":
			case "UPJ":
			case "PIT":
				$url = 'https://pitt-illiad-oclc-org.pitt.idm.oclc.org/illiad/illiad.dll';
				break;
			case "HSLS":
				$url = 'https://illiad.hsls.pitt.edu/illiad/illiad.dll';
				break;
		}
		// Action and Form
		switch ($type) {
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
		switch ($type) {
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


//Get the logged-in user's campus code to see which library system serves them
$user = new Alma();
$userId = $user->getUserId();
if ($user->getUserRecord($userId) && $user->getUserRecord($userId)->campus_code && $user->getUserRecord($userId)->user_group && $user->getUserRecord($userId)->user_identifier){
	$campus = $user->getUserRecord($userId)->campus_code->value;
	$user_group = $user->getUserRecord($userId)->user_group->value;
	//a user record can have more than one type of identifier
	foreach($user->getUserRecord($userId)->user_identifier as $identifier){
		if ($identifier->id_type->value=='BARCODE'){
			$userBarcode = $identifier->value;
			break;
		}
	}
if($type=='book'){
	//this one special group isn't eligible to use EZBorrow
	//ILLIAD
	if ($user_group=='UPPROGRAM'){
		Illiad::bookRequest($type,$campus,$userParams);
	}
	else{
		//EZ Borrow?
		$ezb = new EZBorrow();
		$result = $ezb->ezSearch($userBarcode,$oclc);
		$decoded = json_decode($result);
		//Yes
		if ($decoded->{'Available'}){
			header("HTTP/1.1 200 OK");
			echo $result;
		}
		//No
		else{
			//ILLIAD
			$illiadLink = Illiad::bookRequest($type,$campus,$userParams);
			$decoded->{'illiadLink'}=$illiadLink;
			echo json_encode($decoded);
		}
	}
}
//REQUEST IT
if (isset($pickup) && $pickup!==''){
	$pickup = urldecode($pickup);
	if (isset($notes)){
		$notes=urldecode($notes);
	}
	$ezb = new EZBorrow();
	$result = $ezb->ezRequest($pickup,$oclc,$notes);
	header("HTTP/1.1 200 OK");
	echo $result;
}
// Chapter and Article requests go straight to ILLIAD
if ($type=='chapter'||$type=='article'){
header('Location: '.Illiad::buildUrl($type, $campus, $userParams));
}
}
else{
	echo "Error finding patron record";
}
?>

