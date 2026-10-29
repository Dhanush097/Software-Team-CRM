<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();
$c = $pdo->query("SELECT id FROM crm_companies ORDER BY id ASC LIMIT 1")->fetchColumn();
if ($c) {
    $pdo->prepare("UPDATE crm_groups SET company_id = ? WHERE name = 'dd'")->execute([$c]);
    echo "Updated dd with company {$c}\n";
}
