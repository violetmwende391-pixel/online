<?php
require_once 'config.php';

// Set default timezone
date_default_timezone_set('Africa/Nairobi');

// Define environment if not already defined
if (!defined('ENVIRONMENT')) {
    define('ENVIRONMENT', 'development');
}

// Set response headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
// Allow POST because ESP32 will POST confirmation to this same endpoint
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Sanitize input function (only declare if not already exists)
if (!function_exists('sanitize')) {
    function sanitize($input) {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

try {
    // ----- POST: confirmation handler (mark command executed) -----
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data['command_id']) || !is_numeric($data['command_id'])) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'error' => 'command_id is required']);
            exit;
        }

        if (!isset($pdo)) {
            throw new PDOException('Database connection not initialized');
        }

        $stmt = $pdo->prepare("
            UPDATE commands 
            SET executed = TRUE, executed_at = NOW()
            WHERE command_id = ? AND executed = FALSE
        ");
        $stmt->execute([(int)$data['command_id']]);

        if ($stmt->rowCount() > 0) {
            echo json_encode(['status' => 'success', 'message' => 'Command marked executed']);
        } else {
            // Either command doesn't exist, already executed, or wrong id
            echo json_encode(['status' => 'warning', 'message' => 'Command not updated (may already be executed or not exist)']);
        }
        exit;
    }

    // ----- GET: original behavior (fetch command) -----
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

    // Validate meter serial format
    if (!preg_match('/^[A-Z0-9_-]{4,30}$/i', $meter_serial)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'error' => 'Invalid meter serial format']);
        exit;
    }

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

    // Calculate balance
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

    // Prepare base response
    $response = [
        'status' => 'success',
        'balance' => round($balance, 2),
        'valve_command' => '',
        'mode' => '',
        'meter_status' => $meter['status']
    ];

    // Process commands
    // NOTE: We DO NOT mark commands as executed here. We send the command + command_id to the device.
    // The hardware must POST back with that command_id to confirm actual execution.
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT command_id, command_type, command_value 
        FROM commands 
        WHERE meter_id = ? 
        AND executed = FALSE 
        ORDER BY issued_at ASC 
        LIMIT 1 
        FOR UPDATE SKIP LOCKED
    ");
    $stmt->execute([$meter_id]);
    $command = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($command) {
        // Add command to response but DO NOT mark as executed here.
        if ($command['command_type'] === 'valve') {
            $response['valve_command'] = $command['command_value'];
        } elseif ($command['command_type'] === 'mode') {
            $response['mode'] = $command['command_value'];
        }

        // Send ID so ESP32 can confirm later (hardware will POST this id back)
        $response['command_id'] = (int)$command['command_id'];
    }

    $pdo->commit();

    // Send response
    echo json_encode($response);

} catch (PDOException $e) {
    // Rollback transaction if active
    if (isset($pdo) && $pdo->inTransaction()) {
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

} catch (Exception $e) {
    http_response_code(500);
    error_log("get_command.php error: " . $e->getMessage());

    $response = [
        'status' => 'error',
        'error' => 'Server error',
        'details' => (ENVIRONMENT === 'development') ? $e->getMessage() : null
    ];
    echo json_encode($response);
}
?>




 
