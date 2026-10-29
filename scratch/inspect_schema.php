<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();

echo "Schema of crm_team_staff:\n";
$cols = $pdo->query("DESCRIBE `crm_team_staff`")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo "  {$c['Field']} - {$c['Type']} - {$c['Null']} - {$c['Key']} - {$c['Default']}\n";
}

echo "\nRows in crm_team_staff:\n";
$rows = $pdo->query("SELECT * FROM `crm_team_staff` LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);

echo "\nRows in crm_users:\n";
$users = $pdo->query("SELECT id, name, email, employee_id, role, status FROM `crm_users` LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
print_r($users);
