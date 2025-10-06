<?php
require_once 'config.php';

if (!is_admin_logged_in()) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$meter_id = intval($_POST['meter_id'] ?? 0);
$action = sanitize($_POST['valve_action'] ?? '');

if (!$meter_id || !$action) {
    echo json_encode(['status' => 'error', 'message' => 'Missing data']);
    exit();
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        UPDATE commands 
        SET executed = TRUE, executed_at = NOW(), notes = 'Overridden by new command'
        WHERE meter_id = ? AND command_type = 'valve' AND executed = FALSE
    ");
    $stmt->execute([$meter_id]);

    $stmt = $pdo->prepare("
        INSERT INTO commands (meter_id, command_type, command_value, issued_by, issued_at, executed)
        VALUES (?, 'valve', ?, ?, NOW(), FALSE)
        RETURNING command_id
    ");
    $stmt->execute([$meter_id, $action, $_SESSION['admin_id']]);
    $command_id = $stmt->fetchColumn();

    $pdo->commit();

    echo json_encode(['status' => 'success', 'command_id' => $command_id]);
} catch (PDOException $e) {
    $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
