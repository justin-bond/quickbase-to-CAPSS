<?php 

date_default_timezone_set('UTC');
$postData = json_decode(file_get_contents('php://input'));
$location = $postData->location;
$timestamp = (strtotime('yesterday midnight')*1000);

include_once('settings.php');// include the account info variables
include_once('quickbase.php');//include the api file

if ( !isset($location) ) {
    $return_array = array(
    	'status' => 0,
    	'error' => 'No location set'
    );
} else {
	$return_array = array(
		'status' => 1,
		'location' => $location,
		'timestamp' => array(
			'milliseconds' => $timestamp,
			'date' => gmdate("m-d-Y", $timestamp / 1000)
		),
		'transactionCount' => '',
		'transactions' => array()
	);

	// create the object for the TRANSACTIONS table
	$qbTransactions = new QuickBase($qbUser, $qbPassword, $qbUserToken, true, $qbDbTables['transactions'], $qbAppToken, $qbRealm, '');

	// set the queries for the TRANSACTIONS table
	$transactionQueries = array(
		array(
			'fid'  => '46', // Feild ID
			'ev'   => 'EX', // Exact
			'cri'  => (strtotime('yesterday midnight')*1000) // criteria
		),
		array(
			'ao'  => 'AND',//OR is also acceptable
			'fid' => '57',
			'ev'  => 'EX',
			'cri' => 'customer'
		),
		array(
			'ao'  => 'AND',
			'fid' => '20',
			'ev'  => 'EX',
			'cri' => $location
		)
	);

	// do the query in the TRANSATIONS table
	// 3 - Record ID
	// 26 - Full Name
	// 46 - Date (in milliseconds)
	// 42 - Time of Day (in milliseconds)
	// 33 - Employee
	$transactionResults = $qbTransactions->do_query($transactionQueries, '', '', '3.26.46.42.33', '3', 'structured', 'sortorder-A');
	$transactionResults = $transactionResults->table->records->record;

	// return the count of how many transactions
	$return_array['transactionCount'] = count($transactionResults);

	//check if there was 0 transactions and do not continue
	if ($return_array['transactionCount'] === 0) {
		$return_array['status'] = 0;
		$return_array['error'] = 'No transactions to be proccessed';
		echo json_encode($return_array);
		return;
	}

	// loop through every transaction that was placed for the day
	foreach($transactionResults as $record) {
		$record = json_decode(json_encode($record->f), true);

		// set the variables for the results
		$recordId = $record[0];
		$customerFullName = $record[1];
		$transactionDateSeconds = $record[2] / 1000;
		$transactionDate = gmdate("m-d-Y", $transactionDateSeconds);
		$employeeFullName = $record[4];

		// convert time of day from seconds to hours:mins:seconds
		$record[3] = $record[3]/1000;
		$hours = floor($record[3] / 3600);
		$mins = floor($record[3] / 60 % 60);
		$transactionTimeOfDay = sprintf('%02d:%02d', $hours, $mins);
		$transactionTimeOfDay = date('h:i A', strtotime($transactionTimeOfDay));

		// set the transaction info array
		$transactionInfo = array(
			'recordId' => $recordId,
			'customerFullName' => $customerFullName,
			'transactionDate' => $transactionDate,
			'transactionTime' => $transactionTimeOfDay,
		);

		// create the object for the CUSTOMERS table
		$qbCustomers = new QuickBase($qbUser, $qbPassword, $qbUserToken, true, $qbDbTables['customers'], $qbAppToken, $qbRealm, '');
		
		// set the queries for the CUSTOMERS table
		$customersQueries = array(
			array(
				'fid'  => '31',
				'ev'   => 'EX',
				'cri'  => $transactionInfo['customerFullName']
			)
		);

		// do the query in the CUSTOMERS table
		$customerResults = $qbCustomers->do_query($customersQueries, '', '', '6.7.35.8.9.10.11.14.70.71.72.73.74.75.62', '', 'structured', 'sortorder-A');
		$customerResults = $customerResults->table->records->record->f;
		$customerResults = json_decode(json_encode($customerResults), true);

		// set a default hair color if there is not one
		$hairColorOptions = array ('Bald', 'Black', 'Blond', 'Brown', 'Gray', 'Red', 'Sandy', 'White');
		$hairColor = in_array($customerResults[8],$hairColorOptions) ? $customerResults[8] : 'Brown';

		// set a default eye color if there is not one
		$eyeColorOptions = array ('Black', 'Blue', 'Brown', 'Gray', 'Hazel', 'Pink', 'Green', 'Multi Color', 'Unknown');
		$eyeColor = in_array($customerResults[9],$eyeColorOptions) ? $customerResults[9] : 'Brown';

		// set a default height if there is not one
		$heightFeet = is_array($customerResults[10]) ? '5' : $customerResults[10];
		$heightInches = is_array($customerResults[11]) ? '7' : $customerResults[11];
		$weight = is_array($customerResults[12]) ? '175' : $customerResults[12];

		// set the customer information for the transaction
		$transactionInfo['customerInfo'] = array(
			'firstName' => $customerResults[0],
			'lastName' => $customerResults[1],
			'dob' => gmdate("m-d-Y", $customerResults[2] / 1000),
			'address' => $customerResults[3],
			'city' => $customerResults[4],
			'state' => $customerResults[5],
			'postalCode' => $customerResults[6],
			'gender' => $customerResults[7],
			'hairColor' => $hairColor,
			'eyeColor' => $eyeColor,
			'heightFeet' => $heightFeet,
			'heightInches' => $heightInches,
			'weight' => $weight,
			'identificationType' => $customerResults[13],
			'idNumber' => $customerResults[14],
		);

		//set the store info for the transaction

		$transactionInfo['storeInfo'] = array(
			'employeeName' => $employeeFullName
		);









		$return_array['transactions'][$transactionInfo['recordId']] = $transactionInfo;
		$xmlDoc = create_xml($transactionInfo);
		//make the output pretty
		$xmlDoc->formatOutput = true;
		// print_r($xmlDoc->saveXML());die();

		$filePath = 'xml/'.gmdate("Y", $transactionDateSeconds).'/'.gmdate("m", $transactionDateSeconds).'/'.gmdate("d", $transactionDateSeconds);

		if (!file_exists($filePath)) {
	    	mkdir($filePath, 0777, true);
		}

		$xmlDoc->save($filePath."/".$transactionInfo['recordId'].".xml");
		// if ($xmlDoc->save($filePath."/".$recordInfo['recordId'].".xml")) {
		// 	echo 'Saved Record ID '.$recordInfo['recordId'].' to '.$filePath.'<br/><br/>';
		// } else {
		// 	echo 'Did not save Record ID '.$recordInfo['recordId'].'<br/><br/>';
		// }

	}
}


echo json_encode($return_array);
return;


function create_xml($transactionInfo) {
	// header("Content-Type: text/plain");

	/*
		REQUIRED FIELDS

		CUSTOMER INFO
			Last Name
			First Name
			DOB mm/dd/yyyy
			Address
			City
			State "California"
			Postal Code
			Gender (Male, Female)
			Hair Color (Bald, Black, Blond, Brown, Gray, Red, Sandy, White)
			Eye Color (Black, Blue, Browm, Gray, Hazel, Pink, Green, Multi Color)
			Height (ft.)
			Height (in.)
			Weight
			Indentification Type (Drivers License, Passport, State Id, Military Id, Matricula Consular, United States Id)
			ID Number
		
		STORE INFO
			Store Name
			License Number
			Law Enforcement Agency
			Address
			City
			State
			Postal Code
			Store Phone Number
			Employee Name
			Employee Signiture (image)

		TRANSACTION ITEMS
			Transaction Date (mm/dd/yyy)
			Transaction Time (hh:mm AM/PM)

		ITEM(S)
			Type (Buy, Consign, Trade, Auction)
			Article
			Brand Name
			Loan/Buy Number
			$ Amount
			Property Description (One Item Only, Size, Color, Material, etc...)

		SIGNITURE
			Customer Signiture (image)
			Customer Thumbprint (image)
	*/

	//create the xml document
	$xmlDoc = new DOMDocument('1.0', 'UTF-8');

	$capssUpload = $xmlDoc->appendChild(
		$xmlDoc->createElement('capssUpload'));

	$capssUpload->appendChild(
    	$xmlDoc->createAttribute("xmlns:xsd"))->appendChild(
      		$xmlDoc->createTextNode("http://www.w3.org/2001/XMLSchema"));

    $capssUpload->appendChild(
    	$xmlDoc->createAttribute("xmlns:xsi"))->appendChild(
      		$xmlDoc->createTextNode("http://www.w3.org/2001/XMLSchema-instance"));

    $bulkUploadData = $capssUpload->appendChild(
    	$xmlDoc->createElement("bulkUploadData"));
    $bulkUploadData->appendChild(
    	$xmlDoc->createAttribute("licenseNumber"))->appendChild(
      		$xmlDoc->createTextNode("01081001"));

	$propertyTransaction = $bulkUploadData->appendChild(
    	$xmlDoc->createElement("propertyTransaction"));

	$transactionTime = $propertyTransaction->appendChild(
    	$xmlDoc->createElement("transactionTime", gmdate('Y-m-d', $transactionInfo['transactionDate']).'T'.$transactionInfo['transactionTime']));

	$customer = $propertyTransaction->appendChild(
    	$xmlDoc->createElement("customer"));
	$custLastName = $customer->appendChild(
    	$xmlDoc->createElement("custLastName", $transactionInfo['customerInfo']['lastName']));
	$custFirstName = $customer->appendChild(
    	$xmlDoc->createElement("custFirstName", $transactionInfo['customerInfo']['firstName']));
	$custMiddleName = $customer->appendChild(
    	$xmlDoc->createElement("custMiddleName", $transactionInfo['customerInfo']['middleName']));
	$gender = $customer->appendChild(
    	$xmlDoc->createElement("gender", $transactionInfo['customerInfo']['gender']));
	$race = $customer->appendChild(
    	$xmlDoc->createElement("race", $transactionInfo['customerInfo']['race']));
	$hairColor = $customer->appendChild(
    	$xmlDoc->createElement("hairColor", $transactionInfo['customerInfo']['hairColor']));
	$eyeColor = $customer->appendChild(
    	$xmlDoc->createElement("eyeColor", $transactionInfo['customerInfo']['eyeColor']));
	$height = $customer->appendChild(
    	$xmlDoc->createElement("height", $transactionInfo['customerInfo']['heightFeet']."' ".$transactionInfo['customerInfo']['heightInches'].'"'));
	$weight = $customer->appendChild(
    	$xmlDoc->createElement("weight", $transactionInfo['customerInfo']['weight']));
		$weight->appendChild(
	    	$xmlDoc->createAttribute("unit"))->appendChild(
	      		$xmlDoc->createTextNode("pounds"));
	$dateOfBirth = $customer->appendChild(
    	$xmlDoc->createElement("dateOfBirth", $transactionInfo['customerInfo']['dob']));
	$streetAddress = $customer->appendChild(
    	$xmlDoc->createElement("streetAddress", $transactionInfo['customerInfo']['address']));
	$city = $customer->appendChild(
    	$xmlDoc->createElement("city", $transactionInfo['customerInfo']['city']));
	$state = $customer->appendChild(
    	$xmlDoc->createElement("state", $transactionInfo['customerInfo']['state']));
	$postalCode = $customer->appendChild(
    	$xmlDoc->createElement("postalCode", $transactionInfo['customerInfo']['postalCode']));
	$phoneNumber = $customer->appendChild(
    	$xmlDoc->createElement("phoneNumber", $transactionInfo['customerInfo']['phoneNumber']));



    return $xmlDoc;
}