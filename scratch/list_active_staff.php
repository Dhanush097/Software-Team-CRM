<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();

$staff = $pdo->query("SELECT id, name, role, department FROM crm_team_staff WHERE LOWER(status) = 'active'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($staff as $s) {
    echo "{$s['id']} | {$s['name']} | {$s['role']} | {$s['department']}\n";
}
