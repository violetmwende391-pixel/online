<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

file_put_contents('callback_trace.txt', "✅ Callback hit at " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);

require_once 'config.php';

// Step 1: Capture Raw Input
$rawData = file_get_contents('php://input');
file_put_contents('stk_log.txt', $rawData . "\n\n", FILE_APPEND);

$data = json_decode($rawData, true);

// Step 2: Validate JSON
if (!isset($data['Body']['stkCallback'])) {
    file_put_contents('stk_errors.txt', "❌ Invalid JSON structure received:\n$rawData\n\n", FILE_APPEND);
    http_response_code(200);  // Respond 200 even if data is bad (to prevent Daraja from retrying forever)
    exit('❌ Invalid callback structure.');
}

$callback = $data['Body']['stkCallback'];

// Step 3: Check for failure
if ($callback['ResultCode'] != 0) {
    $desc = $callback['ResultDesc'] ?? 'Unknown failure';
    file_put_contents('stk_errors.txt', "❌ STK Failed: $desc\n\n", FILE_APPEND);
    http_response_code(200);
    exit("STK not successful.");
}

// Step 4: Extract metadata
$transaction_code = $callback['CheckoutRequestID'];
$amount = null;
$account = null;
$receipt = null;

foreach ($callback['CallbackMetadata']['Item'] as $item) {
    if ($item['Name'] === 'Amount') {
        $amount = $item['Value'];
    } elseif ($item['Name'] === 'AccountReference') {
        $account = $item['Value'];
    } elseif ($item['Name'] === 'MpesaReceiptNumber') {
        $receipt = $item['Value'];
    }
}

if (!$amount || !$account || !$transaction_code) {
    file_put_contents('stk_errors.txt', "❌ Missing critical data. Amount: $amount, Account: $account, Code: $transaction_code\n\n", FILE_APPEND);
    http_response_code(200);
    exit("Missing data.");
}

$method = 'mpesa';

try {
    $pdo->beginTransaction();

    // Find meter
    $stmt = $pdo->prepare("SELECT * FROM meters WHERE meter_serial = ?");
    $stmt->execute([$account]);
    $meter = $stmt->fetch();

    if (!$meter) {
        throw new Exception("Meter not found for serial: $account");
    }

    // Check for duplicates
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE transaction_code = ?");
    $stmt->execute([$transaction_code]);
    if ($stmt->fetchColumn()) {
        throw new Exception("Duplicate transaction: $transaction_code");
    }

    // Record the payment
    $stmt = $pdo->prepare("INSERT INTO payments (user_id, meter_id, amount, payment_method, transaction_code)
                           VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $meter['user_id'],
        $meter['meter_id'],
        $amount,
        $method,
        $transaction_code
    ]);

    // Issue top-up command
    add_command($pdo, $meter['meter_id'], 'topup', $amount);

    $pdo->commit();
    file_put_contents('callback_trace.txt', "✅ Payment saved for $account - KES $amount\n", FILE_APPEND);
    echo "✅ Payment recorded successfully for Meter: $account, Amount: KES $amount";

} catch (Exception $e) {
    $pdo->rollBack();
    $error = $e->getMessage();
    file_put_contents('stk_errors.txt', "❌ Exception: $error\n\n", FILE_APPEND);
    http_response_code(200); // still respond OK to Daraja
    echo "❌ Error: $error";
}
