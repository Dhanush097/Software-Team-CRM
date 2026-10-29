<?php
// Test Suite: Task Multi-Member Assignment & Assign To Suite
error_reporting(E_ALL);
ini_set('display_errors', '1');

session_start();
$_SESSION['auth_user'] = [
    'id' => 1,
    'name' => 'Suresh Kumar',
    'email' => 'suresh@softwarecrm.com',
    'role' => 'admin',
    'status' => 'active',
    'employee_id' => 'EMP-001'
];
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$sessName = session_name();
$sessId = session_id();
session_write_close(); // Release session lock for webserver

$baseUrl = 'http://127.0.0.1:8000';
$results = [];

function pass($name, $detail = '') {
    global $results;
    $results[] = ['status' => 'PASS', 'test' => $name, 'detail' => $detail];
    echo "  [PASS] {$name}" . ($detail ? " - {$detail}" : "") . "\n";
}

function fail($name, $detail = '') {
    global $results;
    $results[] = ['status' => 'FAIL', 'test' => $name, 'detail' => $detail];
    echo "  [FAIL] {$name}" . ($detail ? " - {$detail}" : "") . "\n";
}

function apiRequest($method, $url, $data = []) {
    global $sessName, $sessId, $csrf;
    $ch = curl_init();
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-CSRF-Token: ' . $csrf,
        'Cookie: ' . $sessName . '=' . $sessId
    ];

    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 10
    ];

    if (strtoupper($method) === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($data);
    }

    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['code' => 0, 'error' => $err, 'json' => null, 'raw' => ''];
    }

    $json = json_decode($response, true);
    return ['code' => $httpCode, 'error' => null, 'json' => $json, 'raw' => $response];
}

echo "========================================================\n";
echo "Starting Task Multi-Member Assignment Test Suite\n";
echo "========================================================\n\n";

// 1. Fetch available team staff from api/teams.php
echo "Test 1: Verify team members are available for task assignment\n";
$teamsRes = apiRequest('GET', "{$baseUrl}/api/teams.php?action=list");
if ($teamsRes['code'] === 200 && !empty($teamsRes['json']['data'])) {
    $allStaff = $teamsRes['json']['data'];
    $staffCount = count($allStaff);
    pass("Fetch Team Staff", "Found {$staffCount} staff members available for assignment");
} else {
    fail("Fetch Team Staff", "HTTP {$teamsRes['code']} - " . substr($teamsRes['raw'], 0, 150));
    exit(1);
}

// Pick 3 test members
if (count($allStaff) < 3) {
    fail("Staff Availability", "Need at least 3 staff members for testing");
    exit(1);
}
$member1 = $allStaff[0];
$member2 = $allStaff[1];
$member3 = $allStaff[2];

echo "\nSelected test assignees:\n";
echo "  - {$member1['name']} ({$member1['id']}) [{$member1['email']}]\n";
echo "  - {$member2['name']} ({$member2['id']}) [{$member2['email']}]\n";
echo "  - {$member3['name']} ({$member3['id']}) [{$member3['email']}]\n\n";

// 2. Reject task creation with invalid member ID
echo "Test 2: Backend validation rejects invalid / non-existent member IDs\n";
$invalidPayload = [
    'action' => 'create',
    'title' => 'QA Invalid Member Task ' . time(),
    'description' => 'Testing backend member ID validation',
    'priority' => 'High',
    'status' => 'To Do',
    'assigned_member_ids' => ['non_existent_staff_xyz_99999']
];
$invalidRes = apiRequest('POST', "{$baseUrl}/api/tasks.php", $invalidPayload);
if ($invalidRes['code'] === 400 && (!empty($invalidRes['json']['error']) || !empty($invalidRes['json']['message']))) {
    pass("Reject Invalid Member ID", "Successfully rejected with HTTP 400: " . ($invalidRes['json']['message'] ?? $invalidRes['json']['error']));
} else {
    fail("Reject Invalid Member ID", "Expected 400 Bad Request, got HTTP {$invalidRes['code']}: {$invalidRes['raw']}");
}

// 3. Create task with multiple assignees & duplicate ID submission
echo "\nTest 3: Create task with multiple assignees and handle duplicate member IDs gracefully\n";
$testTitle = 'QA Multi Assignee Task ' . time();
$createPayload = [
    'action' => 'create',
    'title' => $testTitle,
    'description' => 'Comprehensive multi-member assignment test',
    'priority' => 'High',
    'status' => 'In Progress',
    'due_date' => date('Y-m-d', strtotime('+7 days')),
    'due_time' => '17:00',
    // Submit duplicate of member1 to test de-duplication
    'assigned_member_ids' => [$member1['id'], $member2['id'], $member1['id']]
];
$createRes = apiRequest('POST', "{$baseUrl}/api/tasks.php", $createPayload);
$createdTaskId = null;

if ($createRes['code'] === 201 && !empty($createRes['json']['data']['id'])) {
    $createdTask = $createRes['json']['data'];
    $createdTaskId = $createdTask['id'];
    $assignedMembers = $createdTask['assigned_members'] ?? [];
    $assignedIds = $createdTask['assigned_member_ids'] ?? [];

    pass("Create Multi-Assignee Task", "Task created ID: {$createdTaskId}");

    // Check de-duplication: should have exactly 2 members (member1 and member2)
    if (count($assignedIds) === 2 && in_array($member1['id'], $assignedIds) && in_array($member2['id'], $assignedIds)) {
        pass("Duplicate Assignee Deduplication", "Successfully deduplicated to 2 unique assignees");
    } else {
        fail("Duplicate Assignee Deduplication", "Expected 2 IDs, got: " . json_encode($assignedIds));
    }

    // Check assigned_staff summary string contains both names
    if (strpos($createdTask['assigned_staff'], $member1['name']) !== false && strpos($createdTask['assigned_staff'], $member2['name']) !== false) {
        pass("Backward-Compatible assigned_staff String", "Summary string: '{$createdTask['assigned_staff']}'");
    } else {
        fail("Backward-Compatible assigned_staff String", "Unexpected assigned_staff: '{$createdTask['assigned_staff']}'");
    }
} else {
    fail("Create Multi-Assignee Task", "HTTP {$createRes['code']} - {$createRes['raw']}");
    exit(1);
}

// 4. Verify junction table crm_task_members in DB
echo "\nTest 4: Verify crm_task_members junction table records in database\n";
require_once __DIR__ . '/../api/db.php';
$db = Database::getConnection();
$stmt = $db->prepare("SELECT employee_id FROM crm_task_members WHERE task_id = ? ORDER BY employee_id");
$stmt->execute([$createdTaskId]);
$dbMembers = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (count($dbMembers) === 2 && in_array($member1['id'], $dbMembers) && in_array($member2['id'], $dbMembers)) {
    pass("Database crm_task_members Junction Records", "2 records exist matching task_id {$createdTaskId}");
} else {
    fail("Database crm_task_members Junction Records", "Expected 2 records, found: " . json_encode($dbMembers));
}

// 5. Test single task get endpoint (api/tasks.php?action=get&id=...)
echo "\nTest 5: Fetch single task details via api/tasks.php?action=get\n";
$getRes = apiRequest('GET', "{$baseUrl}/api/tasks.php?action=get&id=" . urlencode($createdTaskId));
if ($getRes['code'] === 200 && !empty($getRes['json']['data'])) {
    $fetchedTask = $getRes['json']['data'];
    $mems = $fetchedTask['assigned_members'] ?? [];
    if (count($mems) === 2) {
        $names = array_column($mems, 'name');
        pass("Fetch Single Task Assignees", "Found assignees: " . implode(', ', $names));
    } else {
        fail("Fetch Single Task Assignees", "Expected 2 assignees, got " . count($mems));
    }
} else {
    fail("Fetch Single Task Assignees", "HTTP {$getRes['code']} - {$getRes['raw']}");
}

// 6. Test task listing batch hydration (api/tasks.php?action=list)
echo "\nTest 6: Verify tasks list batch hydration includes assigned_members\n";
$listRes = apiRequest('GET', "{$baseUrl}/api/tasks.php?action=list");
if ($listRes['code'] === 200 && !empty($listRes['json']['data'])) {
    $foundInList = null;
    foreach ($listRes['json']['data'] as $t) {
        if ($t['id'] == $createdTaskId) {
            $foundInList = $t;
            break;
        }
    }
    if ($foundInList && !empty($foundInList['assigned_members']) && count($foundInList['assigned_members']) === 2) {
        pass("Task List Hydration", "Task in list has hydrated assigned_members array with " . count($foundInList['assigned_members']) . " members");
    } else {
        fail("Task List Hydration", "Task missing in list or missing assigned_members: " . json_encode($foundInList));
    }
} else {
    fail("Task List Hydration", "HTTP {$listRes['code']} - {$listRes['raw']}");
}

// 7. Update task assignments: Add 3rd member, remove 1st member
echo "\nTest 7: Update task assignees (Remove member 1, add member 3)\n";
$updatePayload = [
    'action' => 'update',
    'id' => $createdTaskId,
    'title' => $testTitle . ' (Updated)',
    'priority' => 'Medium',
    'status' => 'In Progress',
    // New assignees: member2 and member3
    'assigned_member_ids' => [$member2['id'], $member3['id']]
];
$updateRes = apiRequest('POST', "{$baseUrl}/api/tasks.php", $updatePayload);
if ($updateRes['code'] === 200 && !empty($updateRes['json']['data'])) {
    $updatedTask = $updateRes['json']['data'];
    $updatedIds = $updatedTask['assigned_member_ids'] ?? [];
    if (count($updatedIds) === 2 && in_array($member2['id'], $updatedIds) && in_array($member3['id'], $updatedIds) && !in_array($member1['id'], $updatedIds)) {
        pass("Update Task Assignees", "Assignees successfully updated to: " . implode(', ', array_column($updatedTask['assigned_members'], 'name')));
    } else {
        fail("Update Task Assignees", "Assignee IDs mismatch after update: " . json_encode($updatedIds));
    }
} else {
    fail("Update Task Assignees", "HTTP {$updateRes['code']} - {$updateRes['raw']}");
}

// 8. Verify DB after update
echo "\nTest 8: Verify crm_task_members after update\n";
$stmt->execute([$createdTaskId]);
$updatedDbMembers = $stmt->fetchAll(PDO::FETCH_COLUMN);
if (count($updatedDbMembers) === 2 && in_array($member2['id'], $updatedDbMembers) && in_array($member3['id'], $updatedDbMembers) && !in_array($member1['id'], $updatedDbMembers)) {
    pass("Database Sync on Update", "crm_task_members accurately synced: member 1 removed, member 3 added");
} else {
    fail("Database Sync on Update", "Unexpected DB members after update: " . json_encode($updatedDbMembers));
}

// 9. Test Task Duplication copies assignees
echo "\nTest 9: Verify task duplication copies member assignments\n";
$dupPayload = [
    'action' => 'duplicate',
    'id' => $createdTaskId
];
$dupRes = apiRequest('POST', "{$baseUrl}/api/tasks.php", $dupPayload);
$dupTaskId = $dupRes['json']['id'] ?? ($dupRes['json']['data']['id'] ?? null);
if (($dupRes['code'] === 200 || $dupRes['code'] === 201) && !empty($dupTaskId)) {
    $stmt->execute([$dupTaskId]);
    $dupDbMembers = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($dupDbMembers) === 2 && in_array($member2['id'], $dupDbMembers) && in_array($member3['id'], $dupDbMembers)) {
        pass("Task Duplication Assignees Copy", "Duplicated task {$dupTaskId} inherited assignees in crm_task_members");
    } else {
        fail("Task Duplication Assignees Copy", "Duplicated task missing assignees in DB: " . json_encode($dupDbMembers));
    }
} else {
    fail("Task Duplication Assignees Copy", "HTTP {$dupRes['code']} - {$dupRes['raw']}");
}

// 10. Clean up test records
echo "\nTest 10: Clean up test tasks\n";
$db->prepare("DELETE FROM crm_tasks WHERE id IN (?, ?)")->execute([$createdTaskId, $dupTaskId]);
// Note: Foreign keys will automatically CASCADE delete from crm_task_members
$stmt->execute([$createdTaskId]);
$remaining = $stmt->fetchAll(PDO::FETCH_COLUMN);
if (count($remaining) === 0) {
    pass("Cascade Deletion Verification", "Foreign key CASCADE cleanly removed crm_task_members rows");
} else {
    fail("Cascade Deletion Verification", "Orphaned rows remained in crm_task_members");
}

echo "\n========================================================\n";
echo "SUMMARY OF RESULTS\n";
echo "========================================================\n";
$total = count($results);
$passed = count(array_filter($results, fn($r) => $r['status'] === 'PASS'));
$failed = count(array_filter($results, fn($r) => $r['status'] === 'FAIL'));
echo "Total: {$total} | Passed: {$passed} | Failed: {$failed}\n";
if ($failed === 0) {
    echo ">>> ALL BACKEND & DATABASE TESTS PASSED! <<<\n";
} else {
    echo ">>> SOME TESTS FAILED! <<<\n";
    exit(1);
}
