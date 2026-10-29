<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();

$res = $pdo->query("SHOW CREATE TABLE crm_team_staff")->fetch(PDO::FETCH_ASSOC);
echo $res['Create Table'] . "\n";
