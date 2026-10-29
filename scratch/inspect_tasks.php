<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();
$stmt = $pdo->query('SELECT id, title, assigned_staff, status, completed, priority FROM crm_tasks LIMIT 10');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
