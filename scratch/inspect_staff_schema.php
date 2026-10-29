<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();

echo "\n--- Columns of crm_team_staff ---\n";
$cols = $pdo->query("DESCRIBE crm_team_staff")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $col) {
    echo "{$col['Field']} | {$col['Type']} | {$col['Null']} | {$col['Key']} | {$col['Default']}\n";
}

echo "\n--- Sample crm_team_staff records ---\n";
$staff = $pdo->query("SELECT id, name, email, role, department, initials, color_class, avatar_url, status FROM crm_team_staff LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
print_r($staff);
