<?php
/**
 * Software CRM - Task Management API
 * Fetches tasks, filters by priority/overdue, and toggles completion state
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$seed = Database::getSeedData();
$tasks = $seed['tasks'] ?? [];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $filter = strtolower(trim($_GET['filter'] ?? 'my'));

    $filtered = [];
    foreach ($tasks as $t) {
        $cat = strtolower($t['category'] ?? '');
        $isOverdue = !empty($t['is_overdue']);

        if ($filter === 'all') {
            $filtered[] = $t;
        } elseif ($filter === 'overdue' && $isOverdue) {
            $filtered[] = $t;
        } elseif ($filter === 'my' && ($cat === 'my' || !$isOverdue)) {
            $filtered[] = $t;
        }
    }

    jsonResponse(true, [
        'total' => count($tasks),
        'count' => count($filtered),
        'tasks' => $filtered
    ], 'Tasks retrieved');
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $action = $input['action'] ?? 'toggle';

    if ($action === 'toggle') {
        $taskId = $input['id'] ?? '';
        $completed = !empty($input['completed']);

        jsonResponse(true, [
            'id' => $taskId,
            'completed' => $completed
        ], 'Task status updated');
    }

    if ($action === 'create') {
        $title = trim($input['title'] ?? '');
        if (empty($title)) {
            jsonResponse(false, null, 'Task title is required', 400);
        }

        $newTask = [
            'id'         => 't_' . time() . '_' . rand(10, 99),
            'title'      => $title,
            'project'    => trim($input['project'] ?? 'General'),
            'priority'   => trim($input['priority'] ?? 'Medium'),
            'due_str'    => trim($input['due_str'] ?? 'Today'),
            'is_overdue' => false,
            'completed'  => false,
            'category'   => 'my'
        ];

        jsonResponse(true, $newTask, 'Task created successfully');
    }
}
