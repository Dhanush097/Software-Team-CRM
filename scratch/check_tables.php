<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();
if (!$pdo) {
    echo "DB connection failed!\n";
    exit(1);
}
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "Tables:\n";
foreach ($tables as $t) {
    echo " - $t\n";
}
