<?php
require_once 'config.php';

header('Content-Type: text/plain');

try {
    // Get all table names
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        echo "🔹 TABLE: $table\n";

        // Get column details
        $columns = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($columns as $col) {
            echo "   - " . $col['Field'] . " (" . $col['Type'] . ")\n";
        }

        echo "\n";
    }

} catch (PDOException $e) {
    echo "❌ DB ERROR: " . $e->getMessage();
}
?>
