<?php
require_once __DIR__ . '/../api/db.php';
$db = Database::getConnection();

// Get staff IDs for Dhanush and Akash and Priya
$staff = $db->query("SELECT id, name FROM crm_team_staff WHERE name IN ('Dhanush', 'Akash Dev', 'Priya Patel', 'Suresh Kumar', 'Rahul Sharma') LIMIT 4")->fetchAll(PDO::FETCH_ASSOC);
echo "Staff found: " . count($staff) . "\n";

// Update task 'tsk_1' or first task to have multiple assignees
$task = $db->query("SELECT id, title FROM crm_tasks LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($task) {
    echo "Updating task: {$task['id']} - {$task['title']}\n";
    $names = array_column($staff, 'name');
    $db->prepare("UPDATE crm_tasks SET assigned_staff = ? WHERE id = ?")->execute([implode(', ', $names), $task['id']]);
    
    // Clear and insert into crm_task_members
    $db->prepare("DELETE FROM crm_task_members WHERE task_id = ?")->execute([$task['id']]);
    $ins = $db->prepare("INSERT INTO crm_task_members (task_id, employee_id) VALUES (?, ?)");
    foreach ($staff as $s) {
        $ins->execute([$task['id'], $s['id']]);
    }
    echo "Assigned " . count($staff) . " members to {$task['id']}\n";
}
