<?php
require_once 'config.php';
require_once 'functions.php';

header('Content-Type: application/json');

// Validate meter_id parameter
if (!isset($_GET['meter_id']) || !is_numeric($_GET['meter_id'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Invalid meter ID']);
    exit;
}

$meter_id = (int)$_GET['meter_id'];

try {
    // Get latest flow data
    $flow_data = get_latest_flow_data($pdo, $meter_id);
    
    if (!$flow_data) {
        // If no data exists, get basic meter info
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(amount), 0) as total_payments 
            FROM payments 
            WHERE meter_id = ?
        ");
        $stmt->execute([$meter_id]);
        $total_payments = (float)$stmt->fetchColumn();
        
        $flow_data = [
            'flow_rate' => 0,
            'volume' => 0,
            'total_volume' => 0,
            'balance' => $total_payments,
            'valve_status' => 'closed'
        ];
    }

    // Add pending command info
    $stmt = $pdo->prepare("
        SELECT command_type, command_value 
        FROM commands 
        WHERE meter_id = ? AND executed = FALSE
        ORDER BY issued_at DESC 
        LIMIT 1
    ");
    $stmt->execute([$meter_id]);
    $pending_command = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $response = [
        'status' => 'success',
        'flow_rate' => (float)$flow_data['flow_rate'],
        'volume' => (float)$flow_data['volume'],
        'total_volume' => (float)$flow_data['total_volume'],
        'balance' => (float)$flow_data['balance'],
        'valve_status' => $flow_data['valve_status']
    ];
    
    if ($pending_command) {
        $response['pending_command'] = $pending_command;
    }

    echo json_encode($response);

} catch (PDOException $e) {
    http_response_code(500);
    error_log("fetch_flow_data.php error: " . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'error' => 'Database error',
        'details' => (defined('ENVIRONMENT') && ENVIRONMENT === 'development') ? $e->getMessage() : null
    ]);
}
?>