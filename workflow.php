<?php

#
# Uses this PHP Rest Client: https://github.com/tcdent/php-restclient
#

//Get params from url for use in constructing illiad url
$title = urlencode($_GET['title']);
$author = urlencode($_GET['author']);
$publisher = urlencode($_GET['publisher']);
$location = urlencode($_GET['location']);
$year = $_GET['year'];
$oclc = $_GET['oclc'];
$issn = $_GET['issn'];
$type = $_GET['requesttype'];
$volume = $_GET['volume'];
$issue = $_GET['issue'];
$month = $_GET['month'];
$atitle = urlencode($_GET['atitle']);
$pages = $_GET['pages'];
$pickup = $_GET['pickup'];

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
			print 'no id';
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
	public function ezAuth(){
		$auth = array('ApiKey' => RELAIS_API_KEY, 'UserGroup' => "patron", 'PartnershipId' => "EZB", 'LibrarySymbol' => "PITT", 'PatronId' => RELAIS_API_PATRON);
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
	public function ezSearch($oclc){
		if ($aid = $this->ezAuth()){
			$sampleresponse = '{"Available":true,"RequestLink":{"RequestMessage":"At this time, all EZBorrow services are suspended at your institution. Please contact staff at your institution\'s library with any questions."},"OrigNumberOfRecords":1,"PickupLocation":[{"PickupLocationCode":"HILL","PickupLocationDescription":"Hillman"},{"PickupLocationCode":"LCSU","PickupLocationDescription":"LCSU"}]}';
			return $sampleresponse;
			
			//for real you'll have to actually make and handle the search request
			$data = json_encode(array('PartnershipId'=>'EZB','ExactSearch'=>array(['Type'=>'OCLC','Value'=>$oclc])));
			$response = $this->api->post("dws/item/available?aid=$aid", utf8_encode($data));
			return $response->response;
		}
	}
	
	/* ======== EZ REQUEST ======== */
	public function ezRequest($pickup,$oclc,$notes){		
		if ($aid = $this->ezAuth()){
			return '{"Problem":{"ErrorMessage":"Youre blocked!"}}';
			//for real you will use code below
			$data = array('PartnershipId'=>'EZB','PickupLocation'=>$pickup,'ExactSearch'=>array(['Type'=>'OCLC','Value'=>$oclc]));
			if ($notes){
				$data['Notes']=$notes;
			}
			$data = json_encode($data);
			$response = $this->api->post("dws/item/add?aid=$aid", utf8_encode($data));
			return $response->response;
		}
	}

}

class Illiad {

	/* ======== ILLIAD ======== */
	public function illiad($type,$campus){
	    global $title,$author,$publisher,$location,$year,$oclc;
		switch($campus){
			case "UPG":
			case "UPB":
			case "UPT":
			case "UPJ":
			case "PIT":
			$illiadLink = "https://pitt-illiad-oclc-org.pitt.idm.oclc.org/illiad/illiad.dll?Action=10&Form=21&LoanTitle=".$title."&LoanAuthor=".$author."&LoanPublisher=".$publisher."&LoanPlace=".$location."&LoanDate=".$year."&ESPNumber=".$oclc;
			$decoded->{'illiadLink'}=$illiadLink;
			echo json_encode($decoded);
			break;
	        case "HSLS":
	        header("Location: https://illiad.hsls.pitt.edu/illiad/illiad.dll?Action=10&Form=30&LoanTitle=".$title."&LoanAuthor=".$author."&LoanPublisher=".$publisher."&LoanPlace=".$location."&LoanDate=".$year."&ESPNumber=".$oclc);
	        break;
		} 
	}
}


//Get the logged-in user's campus code to see which library system serves them
$user = new Alma();
$userId = $user->getUserId();
if ($user->getUserRecord($userId) && $user->getUserRecord($userId)->campus_code && $user->getUserRecord($userId)->user_group){
	$campus = $user->getUserRecord($userId)->campus_code->value;
	$user_group = $user->getUserRecord($userId)->user_group->value;
}
else{
	echo "Error finding patron record";
}

if($type=='book'){
	//this one special group isn't eligible to use EZBorrow
	//ILLIAD
	if ($user_group=='UPPROGRAM'){
		$ill = new Illiad();
		$ill->illiad($type,$campus);
	}
	else{
		//EZ Borrow?
		$ezb = new EZBorrow();
		$result = $ezb->ezSearch($oclc);
		$decoded = json_decode($result);
		//Yes
		if ($decoded->{'Available'}){
			header("HTTP/1.1 200 OK");
			echo $result;
		}
		//No
		else{
			//ILLIAD
			echo $result;
			/*
			$ill = new Flow();
			$ill->illiad($type,$campus);
			*/
		}
	}
}
if($pickup && $pickup!==''){
	$ezb = new EZBorrow();
	$result = $ezb->ezRequest($pickup,$oclc,$notes);
	header("HTTP/1.1 200 OK");
	echo $result;
}
// Chapter and Article requests go straight to ILLIAD
if ($type=='chapter'){
	switch($campus){
		case "UPG":
		case "UPB":
		case "UPT":
		case "UPJ":
		case "PIT":
			header("Location: https://pitt-illiad-oclc-org.pitt.idm.oclc.org/illiad/illiad.dll?Action=10&Form=23&PhotoJournalTitle=".$title."&PhotoItemAuthor=".$author."&PhotoItemPublisher=".$publisher."&PhotoItemPlace=".$location."&PhotoJournalYear=".$year."&ESPNumber=".$oclc);
		break;

		case "HSLS":
			header("Location: https://illiad.hsls.pitt.edu/illiad/illiad.dll?Action=10&Form=23&PhotoJournalTitle=".$title."&PhotoItemAuthor=".$author."&PhotoItemPublisher=".$publisher."&PhotoItemPlace=".$location."&PhotoJournalYear=".$year."&ESPNumber=".$oclc);
		break;
	}
}

if ($type=='article'){
	switch($campus){
		case "UPG":
                case "UPB":
                case "UPT":
                case "UPJ":
		case "PIT":
			header("Location: https://pitt-illiad-oclc-org.pitt.idm.oclc.org/illiad/illiad.dll?Action=10&Form=22&PhotoJournalTitle=".$title."&ISSN=".$issn."&PhotoArticleAuthor=".$author."&PhotoItemPublisher=".$publisher."&PhotoItemPlace=".$location."&PhotoJournalYear=".$year."&PhotoJournalVolume=".$volume."&PhotoJournalIssue=".$issue."&PhotoJournalMonth=".$month."&PhotoArticleTitle=".$atitle."&PhotoJournalInclusivePages=".$pages."&ESPNumber=".$oclc);
                break;

                case "HSLS":
                        header("Location: https://illiad.hsls.pitt.edu/illiad/illiad.dll?Action=10&Form=22&PhotoJournalTitle=".$title."&ISSN=".$issn."&PhotoArticleAuthor=".$author."&PhotoItemPublisher=".$publisher."&PhotoItemPlace=".$location."&PhotoJournalYear=".$year."&PhotoJournalVolume=".$volume."&PhotoJournalIssue=".$issue."&PhotoJournalMonth=".$month."&PhotoArticleTitle=".$atitle."&PhotoJournalInclusivePages=".$pages."&ESPNumber=".$oclc);
                break;
        }
}


?>

