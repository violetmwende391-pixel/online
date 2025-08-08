<?php
// ============================
// PesaPal Sandbox API Endpoint
// ============================
$auth_url = "https://cybqa.pesapal.com/pesapalv3/api/Auth/RequestToken";
$order_url = "https://cybqa.pesapal.com/pesapalv3/api/Transactions/SubmitOrderRequest";

// ============================
// Your sandbox API keys
// ============================
$consumer_key = "U9fFRI2LaLAMUrNE0wCfk2GEIiJDOGmN";
$consumer_secret = "IzKkoLzhnQKNUYsiYbmUmgq4+l8=";

// ============================
// Customer info from form
// ============================
$name = $_POST['name'];
$email = $_POST['email'];
$amount = $_POST['amount'];
$account_number = $_POST['account_number'];

// Callback URL (local testing)
$callback_url = "http://localhost/pesapal_test/pesapal_callback.php";

// ============================
// STEP 1: Get Access Token
// ============================
$token_payload = json_encode([
    "consumer_key" => $consumer_key,
    "consumer_secret" => $consumer_secret
]);

$ch = curl_init($auth_url);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Accept: application/json"
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $token_payload);
$token_response = curl_exec($ch);
curl_close($ch);

$token_data = json_decode($token_response, true);
if (!isset($token_data['token'])) {
    die("Error getting token: " . $token_response);
}
$token = $token_data['token'];

// ============================
// STEP 2: Create Payment Request
// ============================
$order_payload = json_encode([
    "id" => uniqid(),
    "currency" => "KES",
    "amount" => $amount,
    "description" => "Payment for Account: $account_number",
    "callback_url" => $callback_url,
    "notification_id" => "",
    "billing_address" => [
        "email_address" => $email,
        "phone_number" => "",
        "country_code" => "KE",
        "first_name" => $name,
        "middle_name" => "",
        "last_name" => ""
    ]
]);

$ch = curl_init($order_url);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Authorization: Bearer $token"
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $order_payload);
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);

// ============================
// STEP 3: Redirect to PesaPal Payment Page
// ============================
if (isset($data['redirect_url'])) {
    header("Location: " . $data['redirect_url']);
    exit;
} else {
    echo "Error creating payment request.<br>";
    echo "<pre>";
    print_r($data);
    echo "</pre>";
}
?>
