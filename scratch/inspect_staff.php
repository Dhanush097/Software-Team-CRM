<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();
$stmt = $pdo->query('SELECT id, name, role, email, status FROM crm_team_staff LIMIT 10');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
