<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();
$tables = $pdo->query("SHOW TABLES LIKE '%group%'")->fetchAll(PDO::FETCH_COLUMN);
print_r($tables);
