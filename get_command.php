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

// Sanitize input
// Sanitize input function (only declare if not already exists)
if (!function_exists('sanitize')) {
    function sanitize($input) {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

try {
    // ----- POST: confirmation handler -----
    // ----- POST: confirmation handler (mark command executed) -----
    // Keep POST confirmation for non-valve commands
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

        // Mark the specific command as executed (for non-valve or manual cases)
        $stmt = $pdo->prepare("
            UPDATE commands
            SET executed = TRUE, executed_at = NOW()
            WHERE command_id = ? AND executed = FALSE
        ");
        $stmt->execute([(int)$data['command_id']]);

        if ($stmt->rowCount() > 0) {
            echo json_encode(['status' => 'success', 'message' => 'Command marked executed']);
        } else {
            echo json_encode(['status' => 'warning', 'message' => 'Command not updated (already executed or not found)']);
            echo json_encode(['status' => 'warning', 'message' => 'Command not updated (may already be executed or not exist)']);
        }
        exit;
    }

    // ----- GET: fetch command -----
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

    // Get meter info
    $stmt = $pdo->prepare("SELECT meter_id, status FROM meters WHERE meter_serial = ? LIMIT 1");
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

    // ✅ Get the latest balance directly from flow_data
    // Calculate balance
// Calculate balance: Prefer latest balance from flow_data, fallback to payments if no flow_data exists
$stmt = $pdo->prepare("
    SELECT balance
    FROM flow_data
    WHERE meter_id = ?
    ORDER BY recorded_at DESC
    LIMIT 1
");
$stmt->execute([$meter_id]);
$last_balance = $stmt->fetchColumn();

if ($last_balance === false) {
    // No flow_data yet → fallback to total payments
    $stmt = $pdo->prepare("
        SELECT balance
        FROM flow_data
        SELECT COALESCE(SUM(amount), 0)
        FROM payments
        WHERE meter_id = ?
        ORDER BY recorded_at DESC
        LIMIT 1
    ");
    $stmt->execute([$meter_id]);
    $last_balance = $stmt->fetchColumn();

    if ($last_balance !== false) {
        $balance = (float)$last_balance;
    } else {
        // If no flow_data yet, fall back to total payments
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE meter_id = ?");
        $stmt->execute([$meter_id]);
        $balance = (float)$stmt->fetchColumn();
    }
    $last_balance = (float)$stmt->fetchColumn();
}

$balance = (float)$last_balance;

    // Prepare base response
    $response = [
        'status' => 'success',
        'balance' => round($balance, 2),
        'valve_command' => '',
        'mode' => '',
        'meter_status' => $meter['status']
    ];

    // Auto-mark stale valve commands
    // Auto-mark valve commands older than 6 seconds as executed
    $pdo->prepare("
        UPDATE commands
        SET executed = TRUE, executed_at = NOW()
        WHERE command_type = 'valve'
          AND executed = FALSE
          AND issued_at <= NOW() - INTERVAL 12 SECOND
          AND issued_at <= NOW() - INTERVAL '12 SECOND'
    ")->execute();

    // Fetch latest valve command
    // Fetch the latest unexecuted valve command only
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("
        SELECT command_id, command_type, command_value
        FROM commands
        WHERE meter_id = ?
          AND executed = FALSE
          AND command_type = 'valve'
        ORDER BY issued_at DESC
        LIMIT 1
    ");
    $stmt->execute([$meter_id]);
    $command = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($command) {
        $response['valve_command'] = $command['command_value'];
        $response['command_id'] = (int)$command['command_id'];
    }

    $pdo->commit();

    // Send response
    echo json_encode($response);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    error_log("get_command.php error: " . $e->getMessage());
    echo json_encode([
    $response = [
        'status' => 'error',
        'error' => 'Database error',
        'details' => (ENVIRONMENT === 'development') ? $e->getMessage() : null
    ]
    ]);
    
    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    error_log("get_command.php error: " . $e->getMessage());
    echo json_encode([
    $response = [
        'status' => 'error',
        'error' => 'Server error',
        'details' => (ENVIRONMENT === 'development') ? $e->getMessage() : null
    ]);
    ];
    echo json_encode($response);
}
?>
