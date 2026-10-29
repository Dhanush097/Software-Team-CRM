<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();

// Check if any groups currently exist
$count = (int)$pdo->query("SELECT COUNT(*) FROM crm_groups")->fetchColumn();
if ($count > 0) {
    echo "Groups already exist ($count groups). Seeding skipped.\n";
    exit;
}

// Fetch staff IDs
$staff = $pdo->query("SELECT id, name, role FROM crm_team_staff WHERE LOWER(status) = 'active'")->fetchAll(PDO::FETCH_ASSOC);
$byName = [];
foreach ($staff as $s) {
    $byName[$s['name']] = $s['id'];
}

// 1. Engineering Core
$leader1 = $byName['Akash Dev'] ?? ($staff[0]['id'] ?? null);
$members1 = array_filter([
    $byName['Suresh Kumar'] ?? null,
    $byName['Vikram Patel'] ?? null,
    $byName['Rohan Joshi'] ?? null,
    $byName['Amit Patel'] ?? null
]);

$stmt1 = $pdo->prepare("INSERT INTO crm_groups (name, description, leader_id, color, icon, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
$stmt1->execute([
    'Development & Engineering',
    'Core application architecture, frontend user experience, and backend API platforms.',
    $leader1,
    '#2563eb',
    'code',
    'Admin'
]);
$g1Id = (int)$pdo->lastInsertId();

$mStmt = $pdo->prepare("INSERT IGNORE INTO crm_group_members (group_id, employee_id, role_in_group) VALUES (?, ?, ?)");
if ($leader1) $mStmt->execute([$g1Id, $leader1, 'leader']);
foreach ($members1 as $mId) {
    $mStmt->execute([$g1Id, $mId, 'member']);
}

// 2. Product Design & UI
$leader2 = $byName['Priya Patel'] ?? ($staff[1]['id'] ?? null);
$members2 = array_filter([
    $byName['Ananya Sharma'] ?? null,
    $byName['Neha Gupta'] ?? null
]);

$stmt2 = $pdo->prepare("INSERT INTO crm_groups (name, description, leader_id, color, icon, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
$stmt2->execute([
    'Design & Product Experience',
    'Design systems, mobile application UI/UX, user research, and wireframes.',
    $leader2,
    '#7c3aed',
    'palette',
    'Admin'
]);
$g2Id = (int)$pdo->lastInsertId();

if ($leader2) $mStmt->execute([$g2Id, $leader2, 'leader']);
foreach ($members2 as $mId) {
    $mStmt->execute([$g2Id, $mId, 'member']);
}

echo "Seeded 2 realistic groups successfully!\n";
