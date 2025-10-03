<?php
require_once 'config.php'; // Make sure this sets up $pdo correctly

header('Content-Type: text/plain');

try {
    // 1. Check if 'meters' table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'meters'");
    if ($stmt->rowCount() === 0) {
        echo "❌ Table 'meters' does not exist.\n";
        exit;
    }

    // 2. Check required columns
    $requiredColumns = ['ip_address', 'channel_number', 'meter_serial', 'status'];
    $stmt = $pdo->query("DESCRIBE meters");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $missing = array_diff($requiredColumns, $columns);
    if (!empty($missing)) {
        echo "⚠️ Missing columns in 'meters' table:\n";
        foreach ($missing as $col) {
            echo " - $col\n";
        }
    } else {
        echo "✅ Database structure is OK.\n";
    }

} catch (PDOException $e) {
    echo "❌ Database error: " . $e->getMessage();
}
