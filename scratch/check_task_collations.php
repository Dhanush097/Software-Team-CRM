<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();

function getCollation($pdo, $table, $col) {
    $stmt = $pdo->prepare("SELECT TABLE_NAME, COLUMN_NAME, COLLATION_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $col]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

echo "crm_tasks.id:\n";
print_r(getCollation($pdo, 'crm_tasks', 'id'));

echo "crm_team_staff.id:\n";
print_r(getCollation($pdo, 'crm_team_staff', 'id'));
