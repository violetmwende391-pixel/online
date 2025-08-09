<?php
require_once 'config.php';

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
    $stmt = $pdo->prepare("
        SELECT 
            flow_rate, 
            volume, 
            total_volume, 
            balance, 
            valve_status 
        FROM flow_data 
        WHERE meter_id = ? 
        ORDER BY recorded_at DESC 
        LIMIT 1
    ");
    $stmt->execute([$meter_id]);
    $flow_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$flow_data) {
        // If no data exists, get basic meter info
        $stmt = $pdo->prepare("
            SELECT 
                m.meter_id,
                COALESCE(SUM(p.amount), 0) - COALESCE(MAX(f.total_volume), 0) as balance
            FROM meters m
            LEFT JOIN payments p ON p.meter_id = m.meter_id
            LEFT JOIN flow_data f ON f.meter_id = m.meter_id
            WHERE m.meter_id = ?
            GROUP BY m.meter_id
        ");
        $stmt->execute([$meter_id]);
        $meter_info = $stmt->fetch(PDO::FETCH_ASSOC);

        $flow_data = [
            'flow_rate' => 0,
            'volume' => 0,
            'total_volume' => 0,
            'balance' => $meter_info['balance'] ?? 0,
            'valve_status' => 'closed'
        ];
    }

    echo json_encode([
        'status' => 'success',
        'flow_rate' => (float)$flow_data['flow_rate'],
        'volume' => (float)$flow_data['volume'],
        'total_volume' => (float)$flow_data['total_volume'],
        'balance' => (float)$flow_data['balance'],
        'valve_status' => $flow_data['valve_status']
    ]);

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