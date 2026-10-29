<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();
$stmt = $pdo->query('DESCRIBE crm_users');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
$stmt2 = $pdo->query('SELECT id, name, email, role, employee_id FROM crm_users LIMIT 5');
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));
