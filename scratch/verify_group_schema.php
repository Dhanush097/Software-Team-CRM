<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();

echo "crm_groups schema:\n";
foreach ($pdo->query("DESCRIBE crm_groups")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo "  {$c['Field']} ({$c['Type']})\n";
}

echo "\ncrm_group_members schema:\n";
foreach ($pdo->query("DESCRIBE crm_group_members")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo "  {$c['Field']} ({$c['Type']})\n";
}
