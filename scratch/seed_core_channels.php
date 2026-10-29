<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();

$channels = [
    [
        'name' => 'Development & Engineering',
        'description' => 'Core application architecture, frontend UX, backend API services, and infrastructure.',
        'color' => '#2563eb',
        'icon' => 'code'
    ],
    [
        'name' => 'Design & Product Experience',
        'description' => 'UI/UX systems, product design workflows, user research, and prototyping.',
        'color' => '#8b5cf6',
        'icon' => 'palette'
    ]
];

$staff = $pdo->query("SELECT id, name FROM crm_team_staff WHERE status = 'Active'")->fetchAll(PDO::FETCH_ASSOC);

foreach ($channels as $idx => $ch) {
    $existing = $pdo->prepare("SELECT id FROM crm_groups WHERE name = ?");
    $existing->execute([$ch['name']]);
    $gId = $existing->fetchColumn();

    if (!$gId) {
        $leaderId = $staff[$idx]['id'] ?? ($staff[0]['id'] ?? null);
        $stmt = $pdo->prepare("INSERT INTO crm_groups (name, description, leader_id, color, icon, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'Admin', NOW(), NOW())");
        $stmt->execute([$ch['name'], $ch['description'], $leaderId, $ch['color'], $ch['icon']]);
        $gId = (int)$pdo->lastInsertId();
        echo "Created channel: {$ch['name']} (ID: $gId)\n";
    } else {
        echo "Channel {$ch['name']} already exists (ID: $gId)\n";
    }

    // Add all active staff to these channels
    $mStmt = $pdo->prepare("INSERT IGNORE INTO crm_group_members (group_id, employee_id, role_in_group) VALUES (?, ?, ?)");
    foreach ($staff as $sIdx => $s) {
        $role = ($sIdx === $idx) ? 'leader' : 'member';
        $mStmt->execute([$gId, $s['id'], $role]);
    }
}
