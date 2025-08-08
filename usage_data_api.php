<?php
require_once 'config.php';

if (!is_logged_in()) exit;

$meter_id = intval($_GET['id']);
$from_date = $_GET['from_date'] ?? date('Y-m-d', strtotime('-7 days'));
$to_date = $_GET['to_date'] ?? date('Y-m-d');

// Access check
$stmt = $pdo->prepare("SELECT 1 FROM meters WHERE meter_id = ? AND (user_id = ? OR ? = 1)");
$stmt->execute([$meter_id, $_SESSION['user_id'], is_admin_logged_in()]);
if (!$stmt->fetch()) exit(json_encode(['error' => 'Unauthorized.']));

function queryData($pdo, $sql, $params) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return [
        'labels' => array_column($data, 'label'),
        'data' => array_map(fn($v) => round($v['total_volume'], 2), $data)
    ];
}

$params = [$meter_id, $from_date, $to_date];
echo json_encode([
    'hourly' => queryData($pdo, "SELECT HOUR(recorded_at) AS label, SUM(volume) AS total_volume FROM flow_data WHERE meter_id = ? AND DATE(recorded_at) BETWEEN ? AND ? GROUP BY HOUR(recorded_at)", $params),
    'daily'  => queryData($pdo, "SELECT DATE(recorded_at) AS label, SUM(volume) AS total_volume FROM flow_data WHERE meter_id = ? AND DATE(recorded_at) BETWEEN ? AND ? GROUP BY DATE(recorded_at)", $params),
    'weekly' => queryData($pdo, "SELECT YEARWEEK(recorded_at, 1) AS label, SUM(volume) AS total_volume FROM flow_data WHERE meter_id = ? AND DATE(recorded_at) BETWEEN ? AND ? GROUP BY YEARWEEK(recorded_at, 1)", $params),
    'monthly'=> queryData($pdo, "SELECT DATE_FORMAT(recorded_at, '%Y-%m') AS label, SUM(volume) AS total_volume FROM flow_data WHERE meter_id = ? AND DATE(recorded_at) BETWEEN ? AND ? GROUP BY DATE_FORMAT(recorded_at, '%Y-%m')", $params)
]);
