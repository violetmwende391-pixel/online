<?php
$secretKey = "sk_test_9e4c975feaf44a47972a17c0e41dcf76e8f259d9"; // Your Paystack Test Secret Key

// Get form data
$name = $_POST['name'];
$email = $_POST['email'];
$amount = $_POST['amount'] * 100; // Convert to kobo
$account_number = $_POST['account_number'];

// Initialize transaction
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.paystack.co/transaction/initialize");
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $secretKey",
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);

$data = [
    "email" => $email,
    "amount" => $amount,
    "currency" => "KES",
    // For now, no channels restriction — M-Pesa will appear once enabled
    "metadata" => [
        "custom_fields" => [
            [
                "display_name" => "Account Number",
                "variable_name" => "account_number",
                "value" => $account_number
            ]
        ]
    ]
];

curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

$response = curl_exec($ch);
curl_close($ch);

$result = json_decode($response, true);

if ($result['status']) {
    header("Location: " . $result['data']['authorization_url']);
    exit;
} else {
    echo "Error: " . $result['message'];
}
?>
