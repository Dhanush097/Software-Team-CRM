<?php
require_once __DIR__ . '/../api/db.php';
$db = Database::getConnection();
$dbs = $db->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
echo "Databases on server:\n";
foreach ($dbs as $d) {
    echo " - $d\n";
}
