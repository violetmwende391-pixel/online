<?php
require_once 'config.php';

// Set default timezone
date_default_timezone_set('Africa/Nairobi');

// Set response headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

// Validate request method
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

$meter_serial = htmlspecialchars(trim($_GET['meter_serial']), ENT_QUOTES, 'UTF-8');

try {
    // Verify database connection
    if (!isset($pdo)) {
        throw new PDOException('Database connection not initialized');
    }

    // Get meter information
    $stmt = $pdo->prepare("
        SELECT meter_id, status 
        FROM meters 
        WHERE meter_serial = ? 
        LIMIT 1
    ");
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

    // Calculate balance (matches ESP32 expectation)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) 
        FROM payments 
        WHERE meter_id = ?
    ");
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

    // Prepare base response (matches exactly what ESP32 processes)
    $response = [
        'status' => 'success',
        'balance' => round($balance, 2),
        'valve_command' => '',
        'mode' => '',
        'meter_status' => $meter['status']
    ];

    // SIMPLIFIED COMMAND FETCH - matches ESP32's simple processing
    $stmt = $pdo->prepare("
        SELECT command_type, command_value 
        FROM commands 
        WHERE meter_id = ? 
        AND executed = FALSE
        ORDER BY issued_at ASC 
        LIMIT 1
    ");
    $stmt->execute([$meter_id]);
    $command = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($command) {
        // Add command to response (ESP32 looks for these exact fields)
        if ($command['command_type'] === 'valve') {
            $response['valve_command'] = $command['command_value'];
        } elseif ($command['command_type'] === 'mode') {
            $response['mode'] = $command['command_value'];
        }

        // Mark command as executed immediately (since ESP32 doesn't acknowledge)
        $update = $pdo->prepare("
            UPDATE commands 
            SET executed = TRUE, 
                executed_at = NOW() 
            WHERE meter_id = ? 
            AND command_type = ? 
            AND command_value = ?
            AND executed = FALSE
        ");
        $update->execute([
            $meter_id,
            $command['command_type'],
            $command['command_value']
        ]);
    }

    // Send response (format exactly matches ESP32 expectations)
    echo json_encode($response);

} catch (PDOException $e) {
    http_response_code(500);
    error_log("get_command.php error: " . $e->getMessage());

    $response = [
        'status' => 'error',
        'error' => 'Database error',
        'details' => (defined('ENVIRONMENT') && ENVIRONMENT === 'development') ? $e->getMessage() : null
    ];
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    error_log("get_command.php error: " . $e->getMessage());
    
    $response = [
        'status' => 'error',
        'error' => 'Server error',
        'details' => (defined('ENVIRONMENT') && ENVIRONMENT === 'development') ? $e->getMessage() : null
    ];
    echo json_encode($response);
}
?>