<?php
require_once __DIR__ . '/../api/db.php';
$db = Database::getConnection();
echo "Active DB: " . $db->query("SELECT DATABASE()")->fetchColumn() . "\n";
echo "Companies count: " . $db->query("SELECT count(*) FROM crm_companies")->fetchColumn() . "\n";
echo "Projects count: " . $db->query("SELECT count(*) FROM crm_projects")->fetchColumn() . "\n";
echo "Contacts count: " . $db->query("SELECT count(*) FROM crm_contacts")->fetchColumn() . "\n";
$companies = $db->query("SELECT id, name FROM crm_companies")->fetchAll(PDO::FETCH_ASSOC);
print_r($companies);
$projects = $db->query("SELECT id, title, client_name FROM crm_projects")->fetchAll(PDO::FETCH_ASSOC);
print_r($projects);
