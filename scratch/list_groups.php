<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();
$rows = $pdo->query("SELECT id, name FROM crm_groups")->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);
