<?php
require_once 'config.php'; // Make sure this connects to your DB

// Get meter_id of main supply
$supplyStmt = $pdo->prepare("
    SELECT meter_id FROM meters WHERE meter_serial = 'main_supply'
");
$supplyStmt->execute();
$supplyResult = $supplyStmt->fetch();

if (!$supplyResult) {
    echo json_encode(['error' => 'Supply meter not found']);
    exit;
}

$supplyMeterId = $supplyResult['meter_id'];

// Get latest total_volume from the supply meter
$supplyVolumeStmt = $pdo->prepare("
    SELECT total_volume 
    FROM flow_data 
    WHERE meter_id = ? 
    ORDER BY recorded_at DESC 
    LIMIT 1
");
$supplyVolumeStmt->execute([$supplyMeterId]);
$supplyVolume = $supplyVolumeStmt->fetchColumn();

// Get latest total_volume for each consumer (excluding supply)
$consumerVolumesStmt = $pdo->query("
    SELECT meter_id, MAX(recorded_at) AS latest
    FROM flow_data
    WHERE meter_id != $supplyMeterId
    GROUP BY meter_id
");

$consumerTotal = 0;
while ($row = $consumerVolumesStmt->fetch(PDO::FETCH_ASSOC)) {
    $latestStmt = $pdo->prepare("
        SELECT total_volume FROM flow_data
        WHERE meter_id = ? AND recorded_at = ?
    ");
    $latestStmt->execute([$row['meter_id'], $row['latest']]);
    $volume = $latestStmt->fetchColumn();
    $consumerTotal += $volume;
}

// Calculate leakage
$leakage = $supplyVolume - $consumerTotal;
$tolerance = 5.0; // in liters

$response = [
    'supply_total_volume' => $supplyVolume,
    'consumer_total_volume' => $consumerTotal,
    'leakage_volume' => $leakage,
    'leakage_status' => ($leakage > $tolerance) ? '⚠️ POSSIBLE LEAK DETECTED' : '✅ No leakage'
];

header('Content-Type: application/json');
echo json_encode($response, JSON_PRETTY_PRINT);
