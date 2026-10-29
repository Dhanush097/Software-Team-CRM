<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();
$stmt = $pdo->query('DESCRIBE crm_project_chats');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
