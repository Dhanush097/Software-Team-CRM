<?php
$dbs = ['pocket_crm_db', 'u213657911_crm'];
foreach ($dbs as $dbname) {
    try {
        $pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=$dbname;charset=utf8mb4", 'root', '');
        echo "\n=== Database: $dbname ===\n";
        $pCount = $pdo->query("SELECT count(*) FROM crm_projects")->fetchColumn();
        $cCount = $pdo->query("SELECT count(*) FROM crm_companies")->fetchColumn();
        echo "Projects: $pCount | Companies: $cCount\n";
        if ($pCount > 0) {
            $p = $pdo->query("SELECT id, title, client_name FROM crm_projects LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($p as $x) {
                echo "   Project: {$x['id']} - '{$x['title']}' (Client: '{$x['client_name']}')\n";
            }
        }
        if ($cCount > 0) {
            $c = $pdo->query("SELECT id, name FROM crm_companies LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($c as $x) {
                echo "   Company: {$x['id']} - '{$x['name']}'\n";
            }
        }
    } catch (Exception $e) {
        echo "Error on $dbname: " . $e->getMessage() . "\n";
    }
}
