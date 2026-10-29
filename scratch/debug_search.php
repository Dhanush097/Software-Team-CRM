<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();

$q = 'Anan';
$sql = "SELECT id, name, role, email, department, initials, color_class, avatar_url, status FROM crm_team_staff WHERE LOWER(status) = 'active'";
$sql .= " AND (name LIKE :q OR email LIKE :q OR role LIKE :q OR department LIKE :q)";
$stmt = $pdo->prepare($sql);
$stmt->execute([':q' => "%$q%"]);
$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Direct PDO results for '%Anan%':\n";
print_r($res);
