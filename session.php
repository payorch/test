<?php
// Replace with your actual merchant public key and API password
$username = "6620c3e2-5088-41a8-8be6-98c003153932";
$password = "f6c874bd-7ca0-4da6-82ed-403934eb488c";
error_reporting(E_ERROR | E_PARSE);

// Set the API endpoint URL
$url = "https://api.merchant.geidea.net/payment-intent/api/v1/direct/session";

//header("Access-Control-Allow-Origin: http://localhost:63342/");
//header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
//header("Access-Control-Allow-Headers: Content-Type, Authorization");


header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Origin, Content-Type, X-Auth-Token');

// header('Access-Control-Allow-Origin: *');
// header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
// header('Access-Control-Allow-Headers: Origin, Content-Type, X-Auth-Token');

// Retrieve the JSON string from the request body
$data = file_get_contents("php://input");

// Decode the JSON string into a PHP associative array
$data = json_decode($data, true);


// echo "<script>console.log('{$output}' );</script>";


// Access the values of the array
// Access the values of the array
$amount = $data["amount"];
$currency = $data["currencyId"];
$callbackUrl = $data["callbackUrl"];
$merchantReferenceId = $data["merchantReferenceId"];
$language = $data["language"];
$email = $data["email"];
$phone = $data["phone"];

// Set the request data
$data = array(
    'amount' => $amount,
    'currency' => $currency,
    'callbackUrl' => $callbackUrl,
    'merchantReferenceId' => $merchantReferenceId,
    'language' => $language,
    'customer' => array(
        'email' => $email,
        'phone' => $phone
    )
);

add_filter( 'woocommerce_credit_card_form_fields', 'my_custom_credit_card_field' );
function my_custom_credit_card_field( $fields ) {
   $fields['card-name-field'] = array(
      'label' => __( 'Cardholder Name', 'my-text-domain' ),
      'required' => true,
      'class' => array( 'form-row-wide' ),
      'clear' => true,
      'autocomplete' => 'cc-name',
      'type' => 'text',
      'placeholder' => __( 'Enter Cardholder Name', 'my-text-domain' ),
   );
   return $fields;
}
// Set the cURL options
$options = array(
    CURLOPT_URL => $url,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($data),
    CURLOPT_HTTPHEADER => array(
        "Content-Type: application/json",
        "Authorization: Basic " . base64_encode("$username:$password")
    ),
    CURLOPT_RETURNTRANSFER => true
);

// Initialize cURL
$ch = curl_init();

// Set cURL options
curl_setopt_array($ch, $options);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);


// Execute the cURL request
$response = curl_exec($ch);

// Check for errors
if (curl_errno($ch)) {
    $error_msg = curl_error($ch);
    curl_close($ch);
    die("cURL Error: $error_msg");
}

// Close the cURL session
curl_close($ch);

// Decode the JSON response
$response_data = json_decode($response, true);

// Check for errors in the response
if ($response_data["responseCode"] !== "000" || $response_data["detailedResponseCode"] !== "000") {
    die("Error: " . $response_data["detailedResponseMessage"]);
}

// Get the session ID from the response
$session_id = $response_data["session"]["id"];

 // Output the session ID
echo $session_id;
?>
