<?php
require_once 'config.php';

// Accept both JSON input (for Safaricom in future) and POST (for testing now)
$data = json_decode(file_get_contents('php://input'), true);

// Fallback to POST if JSON is not set (useful for form testing)
$account = $data['BillRefNumber'] ?? $_POST['BillRefNumber'] ?? null;
$amount = isset($data['TransAmount']) ? floatval($data['TransAmount']) : floatval($_POST['TransAmount'] ?? 0);
$transaction_code = $data['TransID'] ?? $_POST['TransID'] ?? null;
$method = 'mpesa';

// Simple validation
if (!$account || !$amount || !$transaction_code) {
    http_response_code(400);
    die("Invalid request. Missing required payment details.");
}

try {
    $pdo->beginTransaction();

    // Check if meter with this serial exists
    $stmt = $pdo->prepare("SELECT * FROM meters WHERE meter_serial = ?");
    $stmt->execute([$account]);
    $meter = $stmt->fetch();

    if (!$meter) {
        throw new Exception("Meter not found with serial: $account");
    }

    // Prevent duplicate transaction codes
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE transaction_code = ?");
    $stmt->execute([$transaction_code]);
    if ($stmt->fetchColumn() > 0) {
        throw new Exception("Duplicate transaction code. This payment already exists.");
    }

    // Insert payment
    $stmt = $pdo->prepare("INSERT INTO payments 
        (user_id, meter_id, amount, payment_method, transaction_code) 
        VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $meter['user_id'],
        $meter['meter_id'],
        $amount,
        $method,
        $transaction_code
    ]);

    // Send top-up command to ESP32
    add_command($pdo, $meter['meter_id'], 'topup', $amount);

    $pdo->commit();
    echo "✅ Payment processed successfully for Meter: $account, Amount: " . CURRENCY . " $amount";

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo "❌ Error: " . $e->getMessage();
}
