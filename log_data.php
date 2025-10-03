<?php
require_once 'config.php';
define('ENVIRONMENT', 'development');

// Start enhanced logging
$logFile = 'debug_log.txt';
$debugData = [
    'timestamp' => date('Y-m-d H:i:s'),
    'method' => $_SERVER['REQUEST_METHOD'],
    'headers' => getallheaders(),
    'raw_input' => file_get_contents('php://input')
];

// Set response content type to JSON
header('Content-Type: application/json');

// Allow only POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $debugData['error'] = 'Invalid request method';
    file_put_contents($logFile, json_encode($debugData, JSON_PRETTY_PRINT) . PHP_EOL, FILE_APPEND);
    
    http_response_code(405);
    echo json_encode(['status' => 'error', 'error' => 'Only POST requests are allowed']);
    exit;
}

// Read and decode JSON input
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
file_put_contents('log.txt', $rawInput . PHP_EOL, FILE_APPEND);

// Enhanced JSON debug logging
$debugData['json_data'] = $data;
$debugData['json_last_error'] = json_last_error_msg();

// Handle invalid JSON
if (!is_array($data)) {
    http_response_code(400); // Bad Request
    echo json_encode([
        'status' => 'error',
        'error' => 'Invalid JSON format'
    ]);
    exit;
}

// Log the received flow data specifically
$flowDebug = [
    'flow_rate' => $data['flow'] ?? 'NOT RECEIVED',
    'volume' => $data['volume'] ?? 'NOT RECEIVED',
    'total_volume' => $data['total_volume'] ?? 'NOT RECEIVED',
    'valve_status' => $data['valve_status'] ?? 'NOT RECEIVED'
];
file_put_contents('flow_debug.txt', print_r($flowDebug, true) . PHP_EOL, FILE_APPEND);

// Define required fields
$required = ['flow', 'volume', 'total_volume', 'valve_status', 'meter_serial'];

// Check for missing fields
$missing = [];
foreach ($required as $field) {
    if (!isset($data[$field])) {
        $missing[] = $field;
    }
}

if (!empty($missing)) {
    http_response_code(400); // Bad Request
    echo json_encode([
        'status' => 'error',
        'error' => 'Missing required fields',
        'missing_fields' => $missing
    ]);
    exit;
}

// Validate numeric fields
$numericFields = ['flow', 'volume', 'total_volume'];
foreach ($numericFields as $field) {
    if (!is_numeric($data[$field])) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'error' => "Field '{$field}' must be numeric",
            'received_value' => $data[$field]
        ]);
        exit;
    }
}

try {
    // Find the meter_id based on meter_serial
    $stmt = $pdo->prepare("SELECT meter_id FROM meters WHERE meter_serial = ?");
    $stmt->execute([$data['meter_serial']]);
    $meter = $stmt->fetch();

    if (!$meter) {
        http_response_code(404); // Not Found
        echo json_encode([
            'status' => 'error',
            'error' => "Meter with serial '{$data['meter_serial']}' not found"
        ]);
        exit;
    }

    $meter_id = $meter['meter_id'];


 // Use the balance reported by the device (slave) directly
$balance = isset($data['balance']) ? floatval($data['balance']) : 0.00;



    // Insert flow data
    $sql = "INSERT INTO flow_data 
            (meter_id, flow_rate, volume, total_volume, balance, valve_status, recorded_at) 
            VALUES (?, ?, ?, ?, ?, ?, NOW())";
    
    $stmt = $pdo->prepare($sql);
    $success = $stmt->execute([
        $meter_id,
        floatval($data['flow']),          // Flow rate in L/min
        floatval($data['volume']),        // Session volume in liters
        floatval($data['total_volume']),  // Total volume in liters
        floatval($balance),               // Calculated balance
        $data['valve_status']             // Valve status
    ]);

    if ($success) {
        // Update meter last activity timestamp
        $updateStmt = $pdo->prepare("UPDATE meters SET last_activity = NOW() WHERE meter_id = ?");
        $updateStmt->execute([$meter_id]);
        
        // Success response
        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'message' => 'Data logged successfully',
            'meter_id' => $meter_id,
            'data_received' => [
                'flow_rate' => floatval($data['flow']),
                'volume' => floatval($data['volume']),
                'total_volume' => floatval($data['total_volume']),
                'balance' => floatval($balance),
                'valve_status' => $data['valve_status']
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'error' => 'Failed to insert flow data'
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500); // Internal Server Error
    error_log("Database error in log_data.php: " . $e->getMessage());
    
    $response = [
        'status' => 'error',
        'error' => 'Database error'
    ];
    
    if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
        $response['details'] = $e->getMessage();
        $response['trace'] = $e->getTrace();
    }
    
    echo json_encode($response);
}

file_put_contents('log.txt', $rawInput . PHP_EOL, FILE_APPEND);
file_put_contents($logFile, json_encode($debugData, JSON_PRETTY_PRINT) . PHP_EOL, FILE_APPEND);
?>
