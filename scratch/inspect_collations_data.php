<?php
require_once __DIR__ . '/../api/db.php';
$db = Database::getConnection();

echo "=== COLLATIONS ===\n";
$q = $db->query("
    SELECT TABLE_NAME, COLUMN_NAME, COLLATION_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME IN ('crm_companies', 'crm_projects')
      AND COLUMN_NAME IN ('id', 'name', 'title', 'client_name')
");
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "{$row['TABLE_NAME']}.{$row['COLUMN_NAME']} -> {$row['DATA_TYPE']} - {$row['COLLATION_NAME']}\n";
}

echo "\n=== EXISTING COMPANIES ===\n";
$comps = $db->query("SELECT id, name FROM crm_companies")->fetchAll(PDO::FETCH_ASSOC);
foreach ($comps as $c) {
    echo "ID: {$c['id']} | Name: '{$c['name']}'\n";
}

echo "\n=== EXISTING PROJECTS ===\n";
$projs = $db->query("SELECT id, title, client_name FROM crm_projects")->fetchAll(PDO::FETCH_ASSOC);
foreach ($projs as $p) {
    echo "ID: {$p['id']} | Title: '{$p['title']}' | Client: '{$p['client_name']}'\n";
}
