<?php
require_once 'config.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['error' => 'Method not allowed']));
}

// Get raw POST data
$data = json_decode(file_get_contents('php://input'), true);

// Validate required fields
$required = ['meter_serial', 'flow', 'volume', 'total_volume', 'balance', 'valve_status'];
foreach ($required as $field) {
    if (!isset($data[$field]) || empty($data[$field])) {
        http_response_code(400);
        die(json_encode(['error' => "Missing required field: $field"]));
    }
}

try {
    // Get meter ID from serial
    $stmt = $pdo->prepare("SELECT meter_id FROM meters WHERE meter_serial = ?");
    $stmt->execute([$data['meter_serial']]);
    $meter = $stmt->fetch();
    
    if (!$meter) {
        http_response_code(404);
        die(json_encode(['error' => 'Meter not found']));
    }
    
    $meter_id = $meter['meter_id'];
    
    // Insert flow data
    $sql = "INSERT INTO flow_data (meter_id, flow_rate, volume, total_volume, balance, valve_status) 
            VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $meter_id,
        floatval($data['flow']),
        floatval($data['volume']),
        floatval($data['total_volume']),
        floatval($data['balance']),
        $data['valve_status']
    ]);
    
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Data logged successfully']);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>


















get <serial class="php">
    <?php
require_once 'config.php';
header('Content-Type: text/plain');

// Enable detailed error logging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/serial_errors.log');

// Get and sanitize input
$device_id = isset($_POST['device_id']) ? trim($_POST['device_id']) : null;
$channel = isset($_POST['channel']) ? intval($_POST['channel']) : 0;

// Log the incoming request
error_log("[".date('Y-m-d H:i:s')."] Received request - Device ID: ".($device_id ?? 'NULL').", Channel: $channel");

// Validate input
if (!$device_id || !preg_match('/^[a-fA-F0-9]+$/', $device_id)) {
    http_response_code(400);
    echo "INVALID_DEVICE_ID";
    error_log("ERROR: Invalid or missing device_id");
    exit;
}

if ($channel < 0 || $channel > 255) {
    http_response_code(400);
    echo "INVALID_CHANNEL";
    error_log("ERROR: Invalid channel number: $channel");
    exit;
}

try {
    // Begin transaction for atomic operations
    $pdo->beginTransaction();

    // STEP 1: Check if device is already registered
    $stmt = $pdo->prepare("SELECT meter_serial FROM meters WHERE device_id = ? LIMIT 1 FOR UPDATE");
    $stmt->execute([$device_id]);
    $existing_serial = $stmt->fetchColumn();

    if ($existing_serial) {
        $pdo->commit();
        error_log("SUCCESS: Returning existing serial $existing_serial for device $device_id");
        echo trim($existing_serial);
        exit;
    }

    // STEP 2: Find and assign an available meter
    $stmt = $pdo->prepare("
        SELECT meter_id, meter_serial 
        FROM meters 
        WHERE device_id IS NULL 
        AND status = 'active' 
        AND channel_number = ? 
        LIMIT 1 
        FOR UPDATE SKIP LOCKED
    ");
    $stmt->execute([$channel]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $meter_id = $row['meter_id'];
        $meter_serial = $row['meter_serial'];

        // STEP 3: Assign to this device
        $update = $pdo->prepare("UPDATE meters SET device_id = ?, registered_at = NOW() WHERE meter_id = ?");
        $update->execute([$device_id, $meter_id]);
        
        $pdo->commit();
        error_log("SUCCESS: Assigned new serial $meter_serial to device $device_id on channel $channel");
        echo trim($meter_serial);
        exit;
    } else {
        $pdo->commit();
        http_response_code(404);
        error_log("ERROR: No available meter for channel $channel");
        echo "NO_AVAILABLE_METER";
        exit;
    }

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    error_log("DATABASE_ERROR: ".$e->getMessage());
    echo "DATABASE_ERROR";
    exit;
}
</serial>