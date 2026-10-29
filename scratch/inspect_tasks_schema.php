<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();

echo "--- Tables with 'task' ---\n";
$tables = $pdo->query("SHOW TABLES LIKE '%task%'")->fetchAll(PDO::FETCH_COLUMN);
print_r($tables);

echo "\n--- Columns of crm_tasks ---\n";
$cols = $pdo->query("DESCRIBE crm_tasks")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $col) {
    echo "{$col['Field']} | {$col['Type']} | {$col['Null']} | {$col['Key']} | {$col['Default']}\n";
}
