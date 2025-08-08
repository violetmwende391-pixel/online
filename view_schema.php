<?php
require_once 'config.php';

try {
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!$tables) {
        die("No tables found in the database.");
    }

    echo "<h2>Database Schema for " . DB_NAME . "</h2>";

    foreach ($tables as $table) {
        echo "<h3>Table: $table</h3>";
        $stmt = $pdo->query("DESCRIBE `$table`");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";

        foreach ($columns as $col) {
            echo "<tr>";
            foreach ($col as $value) {
                echo "<td>" . htmlspecialchars($value) . "</td>";
            }
            echo "</tr>";
        }
        echo "</table><br>";
    }
} catch (PDOException $e) {
    echo "Error retrieving schema: " . $e->getMessage();
}
?>
