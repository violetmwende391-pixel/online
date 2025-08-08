<?php
require_once 'config.php';

function getDatabaseSchema($pdo) {
    $database = DB_NAME;
    $schema = [];

    // Get all tables
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        $tableInfo = [
            'columns' => [],
            'primary_key' => [],
            'foreign_keys' => [],
        ];

        // Get columns
        $stmt = $pdo->query("SHOW FULL COLUMNS FROM `$table`");
        $columns = $stmt->fetchAll();
        foreach ($columns as $column) {
            $tableInfo['columns'][] = [
                'Field' => $column['Field'],
                'Type' => $column['Type'],
                'Null' => $column['Null'],
                'Key' => $column['Key'],
                'Default' => $column['Default'],
                'Extra' => $column['Extra'],
                'Comment' => $column['Comment']
            ];
            if ($column['Key'] == 'PRI') {
                $tableInfo['primary_key'][] = $column['Field'];
            }
        }

        // Get foreign keys
        $stmt = $pdo->prepare("
            SELECT
                COLUMN_NAME,
                REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME
            FROM
                INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE
                TABLE_NAME = :table AND
                TABLE_SCHEMA = :schema AND
                REFERENCED_TABLE_NAME IS NOT NULL
        ");
        $stmt->execute(['table' => $table, 'schema' => $database]);
        $fks = $stmt->fetchAll();
        foreach ($fks as $fk) {
            $tableInfo['foreign_keys'][] = $fk;
        }

        $schema[$table] = $tableInfo;
    }

    return $schema;
}

// Output schema as JSON
header('Content-Type: application/json');
$schema = getDatabaseSchema($pdo);
echo json_encode($schema, JSON_PRETTY_PRINT);
?>
