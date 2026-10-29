<?php
require_once __DIR__ . '/../api/db.php';
$db = Database::getConnection();

echo "=== TABLES ===\n";
$tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    if (strpos($t, 'compan') !== false || strpos($t, 'project') !== false) {
        echo " - $t\n";
    }
}

foreach ($tables as $t) {
    if (strpos($t, 'compan') !== false || strpos($t, 'project') !== false) {
        echo "\n=== SCHEMA: $t ===\n";
        $cols = $db->query("DESCRIBE `$t`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) {
            echo "   {$c['Field']} - {$c['Type']} - Null:{$c['Null']} - Key:{$c['Key']} - Default:{$c['Default']}\n";
        }
    }
}
