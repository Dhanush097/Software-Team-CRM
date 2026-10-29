<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();
echo "Active DB: " . $pdo->query("SELECT DATABASE()")->fetchColumn() . "\n";
