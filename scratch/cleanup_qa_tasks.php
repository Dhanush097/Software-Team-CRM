<?php
require_once __DIR__ . '/../api/db.php';
$db = Database::getConnection();
$cnt = $db->exec("DELETE FROM crm_tasks WHERE title LIKE '%QA Multi Assignee Task%'");
echo "Cleaned {$cnt} QA tasks\n";
