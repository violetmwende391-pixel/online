<?php
require_once 'daraja_config.php';

// Sanitize input
$phone = preg_replace('/\D/', '', $_POST['phone'] ?? '');
$meter_serial = trim($_POST['meter_serial'] ?? '');
$amount = intval($_POST['amount'] ?? 0);

// Basic validation
if (!$phone || !$meter_serial || $amount <= 0) {
    http_response_code(400);
    exit("❌ Missing or invalid parameters.");
}

// Format phone to E.164 (e.g., 2547xxxxxxxx)
if (strlen($phone) == 10 && substr($phone, 0, 1) == '0') {
    $phone = '254' . substr($phone, 1); // 07... -> 2547...
} elseif (strlen($phone) != 12 || substr($phone, 0, 3) != '254') {
    http_response_code(400);
    exit("❌ Invalid phone number format. Use 2547XXXXXXXX.");
}

// Get access token
$access_token = get_access_token();
if (!$access_token) {
    http_response_code(500);
    exit("❌ Failed to get access token. Check credentials.");
}

// Build password and timestamp
$timestamp = date('YmdHis');
$password = base64_encode(MPESA_SHORTCODE . MPESA_PASSKEY . $timestamp);

// STK Push payload
$payload = [
    'BusinessShortCode' => MPESA_SHORTCODE,
    'Password' => $password,
    'Timestamp' => $timestamp,
    'TransactionType' => 'CustomerPayBillOnline',
    'Amount' => $amount,
    'PartyA' => $phone,
    'PartyB' => MPESA_SHORTCODE,
    'PhoneNumber' => $phone,
    'CallBackURL' => CALLBACK_URL,
    'AccountReference' => $meter_serial,
    'TransactionDesc' => 'SmartMeter Payment'
];

// Send STK Push
$ch = curl_init('https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $access_token
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);

// Handle response
$data = json_decode($response, true);
if (isset($data['ResponseCode']) && $data['ResponseCode'] == '0') {
    echo "✅ STK Push sent successfully!<br>";
    echo "✔️ Check M-Pesa on your test phone.<br>";
    echo "<pre>" . print_r($data, true) . "</pre>";
} else {
    echo "❌ STK Push failed.<br>";
    echo "📋 Reason: " . ($data['errorMessage'] ?? 'Unknown error') . "<br>";
    echo "<pre>" . print_r($data, true) . "</pre>";
}
