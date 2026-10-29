<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();

echo "--- COMPANY TABLES ---\n";
$tables = $pdo->query("SHOW TABLES LIKE '%compan%'")->fetchAll(PDO::FETCH_COLUMN);
print_r($tables);

foreach ($tables as $t) {
    echo "\nDESCRIBE $t:\n";
    $cols = $pdo->query("DESCRIBE $t")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "  {$c['Field']} ({$c['Type']}) Null={$c['Null']} Key={$c['Key']} Default={$c['Default']}\n";
    }
}

echo "\n--- DESCRIBE crm_groups ---\n";
$grpCols = $pdo->query("DESCRIBE crm_groups")->fetchAll(PDO::FETCH_ASSOC);
foreach ($grpCols as $c) {
    echo "  {$c['Field']} ({$c['Type']}) Null={$c['Null']} Key={$c['Key']} Default={$c['Default']}\n";
}

echo "\n--- SAMPLE COMPANIES ---\n";
if (in_array('crm_companies', $tables) || in_array('companies', $tables)) {
    $cTable = in_array('crm_companies', $tables) ? 'crm_companies' : 'companies';
    $sample = $pdo->query("SELECT * FROM $cTable LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    print_r($sample);
}
