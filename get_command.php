<?php
require_once 'config.php';

// Set default timezone
date_default_timezone_set('Africa/Nairobi');

// Ensure PDO is set
if (!isset($pdo)) {
    http_response_code(500);
    die(json_encode(['status' => 'error', 'error' => 'Database not initialized']));
}

// Define environment if not already defined
if (!defined('ENVIRONMENT')) {
    define('ENVIRONMENT', 'development');
}

// Sanitize function
if (!function_exists('sanitize')) {
    function sanitize($input) {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

// Set response headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

// Allow only GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'error' => 'Method not allowed']);
    exit;
}

// Validate meter_serial parameter
if (empty($_GET['meter_serial'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Meter serial number is required']);
    exit;
}

$meter_serial = sanitize($_GET['meter_serial']);

// Optional: stricter format validation
if (!preg_match('/^[A-Z0-9_-]{4,30}$/i', $meter_serial)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Invalid meter serial format']);
    exit;
}

try {
    // Fetch meter information
    $stmt = $pdo->prepare("SELECT meter_id, status FROM meters WHERE meter_serial = ?");
    $stmt->execute([$meter_serial]);
    $meter = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$meter) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'error' => 'Meter not found']);
        exit;
    }

    if ($meter['status'] !== 'active') {
        http_response_code(423);
        echo json_encode([
            'status' => 'error',
            'error' => 'Meter not active',
            'meter_status' => $meter['status']
        ]);
        exit;
    }

    $meter_id = $meter['meter_id'];

    // Calculate balance: total top-up - total usage
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE meter_id = ?");
$stmt->execute([$meter_id]);
$total_payments = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COALESCE(total_volume, 0) 
    FROM flow_data 
    WHERE meter_id = ? 
    ORDER BY recorded_at DESC 
    LIMIT 1
");
$stmt->execute([$meter_id]);
$total_volume = (float)$stmt->fetchColumn();

$balance = $total_payments - $total_volume;

    // Prepare base response
    $response = [
        'status' => 'success',
        'balance' => round($balance, 2),
        'valve_command' => '',
        'mode' => '',
        'meter_status' => $meter['status']
    ];

    // Start transaction for safe command handling
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT * FROM commands 
                           WHERE meter_id = ? AND executed = FALSE 
                           ORDER BY issued_at ASC 
                           LIMIT 1 FOR UPDATE");
    $stmt->execute([$meter_id]);
    $command = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($command) {
        if ($command['command_type'] === 'valve') {
            $response['valve_command'] = $command['command_value'];
        } elseif ($command['command_type'] === 'mode') {
            $response['mode'] = $command['command_value'];
        }

        // Mark as executed
        $stmt = $pdo->prepare("UPDATE commands SET executed = TRUE, executed_at = NOW() 
                               WHERE command_id = ?");
        $stmt->execute([$command['command_id']]);
    }

    $pdo->commit();

    echo json_encode($response);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    error_log("get_command.php error: " . $e->getMessage());

    $response = [
        'status' => 'error',
        'error' => 'Database error',
        'details' => (ENVIRONMENT === 'development') ? $e->getMessage() : null
    ];
    echo json_encode($response);
}
?>
