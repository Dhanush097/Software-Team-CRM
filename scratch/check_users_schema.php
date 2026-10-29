<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();

echo "--- crm_users columns ---\n";
$cols = $pdo->query("DESCRIBE crm_users")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo "  {$c['Field']} ({$c['Type']}) Null:{$c['Null']} Key:{$c['Key']} Default:{$c['Default']}\n";
}

echo "\n--- crm_team_staff columns ---\n";
$cols = $pdo->query("DESCRIBE crm_team_staff")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo "  {$c['Field']} ({$c['Type']}) Null:{$c['Null']} Key:{$c['Key']} Default:{$c['Default']}\n";
}

echo "\n--- crm_users sample ---\n";
$users = $pdo->query("SELECT id, name, email, role, status, google_id FROM crm_users")->fetchAll(PDO::FETCH_ASSOC);
foreach ($users as $u) {
    echo "  ID:{$u['id']} Name:{$u['name']} Email:{$u['email']} Role:{$u['role']} Status:{$u['status']} GID:{$u['google_id']}\n";
}

echo "\n--- crm_team_staff sample ---\n";
$staff = $pdo->query("SELECT * FROM crm_team_staff LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
foreach ($staff as $s) {
    echo "  " . json_encode($s) . "\n";
}
