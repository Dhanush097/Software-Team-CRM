<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();

echo "User roles:\n";
print_r($pdo->query('SELECT DISTINCT role FROM crm_users')->fetchAll(PDO::FETCH_COLUMN));

echo "\nTeam staff roles:\n";
print_r($pdo->query('SELECT DISTINCT role FROM crm_team_staff')->fetchAll(PDO::FETCH_COLUMN));

echo "\nTeam staff statuses:\n";
print_r($pdo->query('SELECT DISTINCT status FROM crm_team_staff')->fetchAll(PDO::FETCH_COLUMN));

echo "\nTotal team staff: " . $pdo->query('SELECT COUNT(*) FROM crm_team_staff')->fetchColumn() . "\n";
echo "Active team staff: " . $pdo->query("SELECT COUNT(*) FROM crm_team_staff WHERE LOWER(status) = 'active'")->fetchColumn() . "\n";
