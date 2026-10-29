<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();
$pdo->exec("DELETE FROM crm_groups WHERE name LIKE 'QA Automation Group%'");
echo "Cleaned up QA test groups.\n";
