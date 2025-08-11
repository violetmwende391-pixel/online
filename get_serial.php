<?php
require_once 'config.php';
header('Content-Type: text/plain');

$device_id = isset($_POST['device_id']) ? trim($_POST['device_id']) : null;

if (!$device_id) {
    http_response_code(400);
    echo "Missing device_id";
    exit;
}

try {
    // STEP 1: Check if device is already registered
    $stmt = $pdo->prepare("SELECT meter_serial FROM meters WHERE device_id = ? LIMIT 1");
    $stmt->execute([$device_id]);
    $existing_serial = $stmt->fetchColumn();

    if ($existing_serial) {
        echo trim($existing_serial);
        exit;
    }

    // STEP 2: Assign an available meter (unassigned, active)
    $stmt = $pdo->prepare("SELECT meter_id, meter_serial FROM meters WHERE device_id IS NULL AND status = 'active' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $meter_id = $row['meter_id'];
        $meter_serial = $row['meter_serial'];

        // STEP 3: Lock it to this device
        $update = $pdo->prepare("UPDATE meters SET device_id = ?, registered_at = NOW() WHERE meter_id = ?");
        $update->execute([$device_id, $meter_id]);

        echo trim($meter_serial);
        exit;
    } else {
        http_response_code(404);
        echo "No available meters";
        exit;
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo "DB_ERROR: " . $e->getMessage();
    exit;
}