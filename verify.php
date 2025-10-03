<?php
$secretKey = "sk_test_9e4c975feaf44a47972a17c0e41dcf76e8f259d9"; // Your Paystack Test Secret Key

if (!isset($_GET['reference'])) {
    die("No reference supplied");
}

$reference = $_GET['reference'];

// Verify transaction
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.paystack.co/transaction/verify/" . urlencode($reference));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $secretKey"
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
curl_close($ch);

$result = json_decode($response, true);

if ($result['status'] && $result['data']['status'] === "success") {
    echo "<h2>Payment Successful</h2>";
    echo "Account Number: " . $result['data']['metadata']['custom_fields'][0]['value'] . "<br>";
    echo "Amount Paid: " . ($result['data']['amount'] / 100) . " KES<br>";
    echo "Reference: " . $result['data']['reference'];
} else {
    echo "<h2>Payment Failed or Pending</h2>";
}
?>
