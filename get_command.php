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
header('Access-Control-Allow-Methods: GET');

// Sanitize input function
if (!function_exists('sanitize')) {
    function sanitize($input) {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

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

$meter_serial = sanitize($_GET['meter_serial']);

// Validate meter serial format
if (!preg_match('/^[A-Z0-9_-]{4,30}$/i', $meter_serial)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Invalid meter serial format']);
    exit;
}

try {
    // Verify database connection
    if (!isset($pdo)) {
        throw new PDOException('Database connection not initialized');
    }

    // Get meter information with lock
    $pdo->beginTransaction();
    
    $stmt = $pdo->prepare("
        SELECT meter_id, status, control_mode, current_valve_state 
        FROM meters 
        WHERE meter_serial = ? 
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$meter_serial]);
    $meter = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$meter) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['status' => 'error', 'error' => 'Meter not found']);
        exit;
    }

    if ($meter['status'] !== 'active') {
        $pdo->rollBack();
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
        'meter_status' => $meter['status'],
        'current_valve_state' => $meter['current_valve_state'],
        'control_mode' => $meter['control_mode']
    ];

    // Check for pending commands (not executed and not acknowledged)
    $stmt = $pdo->prepare("
        SELECT command_id, command_type, command_value 
        FROM commands 
        WHERE meter_id = ? 
        AND executed = FALSE 
        AND acknowledged = FALSE
        ORDER BY issued_at ASC 
        LIMIT 1
    ");
    $stmt->execute([$meter_id]);
    $command = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($command) {
        // Add command to response
        if ($command['command_type'] === 'valve') {
            $response['valve_command'] = $command['command_value'];
        } elseif ($command['command_type'] === 'mode') {
            $response['mode'] = $command['command_value'];
        }

        // Mark command as sent to device (but not yet executed)
        $update = $pdo->prepare("
            UPDATE commands 
            SET sent_to_device = TRUE,
                sent_at = NOW() 
            WHERE command_id = ?
        ");
        $update->execute([$command['command_id']]);
    } else {
        // Check if there are any commands that were sent but not acknowledged
        // This helps recover from cases where ESP32 didn't confirm receipt
        $stmt = $pdo->prepare("
            SELECT command_id, command_type, command_value 
            FROM commands 
            WHERE meter_id = ? 
            AND executed = FALSE 
            AND acknowledged = FALSE
            AND sent_to_device = TRUE
            AND sent_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            ORDER BY issued_at ASC 
            LIMIT 1
        ");
        $stmt->execute([$meter_id]);
        $stale_command = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($stale_command) {
            // Resend stale command that wasn't acknowledged within 5 minutes
            if ($stale_command['command_type'] === 'valve') {
                $response['valve_command'] = $stale_command['command_value'];
            } elseif ($stale_command['command_type'] === 'mode') {
                $response['mode'] = $stale_command['command_value'];
            }
        }
    }

    // Update meter's last_poll time
    $update = $pdo->prepare("
        UPDATE meters 
        SET last_poll = NOW() 
        WHERE meter_id = ?
    ");
    $update->execute([$meter_id]);

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