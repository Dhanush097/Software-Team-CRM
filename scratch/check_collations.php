<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();

$c1 = $pdo->query("SHOW FULL COLUMNS FROM crm_companies WHERE Field = 'id'")->fetch(PDO::FETCH_ASSOC);
echo "crm_companies.id:\n";
print_r($c1);

$c2 = $pdo->query("SHOW FULL COLUMNS FROM crm_groups WHERE Field = 'company_id'")->fetch(PDO::FETCH_ASSOC);
echo "crm_groups.company_id:\n";
print_r($c2);
