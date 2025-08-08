<?php
// M-Pesa Daraja API Configuration for SmartMeterApp

define('DARAJA_CONSUMER_KEY', 'AUnPbJM5vzwbkQZvTqArQKGVwMtyUUfwpTjomaaTJIoUyUL9');
define('DARAJA_CONSUMER_SECRET', 'QdQJJ3A6veAaBMiAmNwQl05xilkOopiM24zkLcCFXAps3gSbYrtLskYa8ImAshQN');

define('MPESA_SHORTCODE', '174379'); // BusinessShortCode for STK
define('MPESA_PASSKEY', 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919');

// Replace with your Ngrok URL, webhook.site, or live domain
define('CALLBACK_URL', 'https://h0bmlhcl-8000.uks1.devtunnels.ms/stk_callback.php');


 // <- update this!

// Access token helper
function get_access_token() {
    $credentials = base64_encode(DARAJA_CONSUMER_KEY . ':' . DARAJA_CONSUMER_SECRET);
    $ch = curl_init('https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Basic $credentials"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    return $data['access_token'] ?? null;
}
?>
