<?php
require_once __DIR__ . '/../api/db.php';
$db = Database::getConnection();
$comps = $db->query("SELECT DISTINCT company FROM crm_contacts WHERE company IS NOT NULL AND company != '' ORDER BY company ASC")->fetchAll(PDO::FETCH_COLUMN);
echo "Distinct companies in contacts (" . count($comps) . "):\n";
print_r(array_slice($comps, 0, 15));
