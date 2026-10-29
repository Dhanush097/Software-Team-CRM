<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();
$stmt = $pdo->query('SHOW TABLES');
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
