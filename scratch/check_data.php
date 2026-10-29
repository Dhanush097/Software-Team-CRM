<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();
echo "--- COMPANIES ---\n";
$companies = $pdo->query('SELECT id, name, industry FROM crm_companies')->fetchAll(PDO::FETCH_ASSOC);
foreach ($companies as $c) {
    echo "ID: {$c['id']} | Name: {$c['name']} | Industry: {$c['industry']}\n";
}

echo "\n--- PROJECTS ---\n";
$projects = $pdo->query('SELECT id, title, company_id, client_name FROM crm_projects')->fetchAll(PDO::FETCH_ASSOC);
foreach ($projects as $p) {
    echo "ID: {$p['id']} | Title: {$p['title']} | Company ID: {$p['company_id']} | Client: {$p['client_name']}\n";
}
