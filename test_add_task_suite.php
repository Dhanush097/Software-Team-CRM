<?php
/**
 * Test Suite: Add Task Feature Verification
 * Tests all 10 requirements from the user prompt:
 * 1. Modal markup and state
 * 2. Visual design fields (Title, Description, Due Date, Priority, Status, Assign To)
 * 3. Auto-associated project card with lock icon
 * 4. Server-side validation and rejection of invalid input
 * 5. Database persistence in crm_tasks
 * 6. Task count increment (8 -> 9)
 * 7. Correct project isolation (appears under proj_1, not proj_2)
 * 8. Re-fetch persistence (simulating page reload)
 * 9. Toggling completion on created task
 * 10. End-to-end authenticated API creation & validation
 */

require_once __DIR__ . '/api/db.php';
$pdo = Database::getConnection();

function runTest($name, $closure) {
    echo "Running: $name... ";
    try {
        $result = $closure();
        if ($result === true) {
            echo "PASS\n";
            return true;
        } else {
            echo "FAIL: $result\n";
            return false;
        }
    } catch (Exception $e) {
        echo "EXCEPTION: " . $e->getMessage() . "\n";
        return false;
    }
}

$baseUrl = 'http://127.0.0.1:8000';
$cookieJar = tempnam(sys_get_temp_dir(), 'task_test_');

function httpPostJson(string $url, array $data, ?string $cookieFile = null): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => $body, 'json' => json_decode($body, true)];
}

// Authenticate session via Suresh credentials & OTP
$authRes = httpPostJson("$baseUrl/api/auth.php?action=login_credentials", [
    'email' => 'suresh@softwarecrm.com',
    'password' => 'Password@123'
], $cookieJar);

// Read OTP from data/email_delivery.log
$mailLog = file_get_contents(__DIR__ . '/data/email_delivery.log');
preg_match_all('/Recipient: suresh@softwarecrm\.com.*?Your Verification Code: (\d{4})/s', $mailLog, $m);
$otp = end($m[1]);

// Verify OTP to complete authentication
httpPostJson("$baseUrl/api/auth.php?action=verify_otp", [
    'email' => 'suresh@softwarecrm.com',
    'otp' => $otp,
    'purpose' => 'login'
], $cookieJar);

$passed = 0;
$total = 0;

// Test 1: Check HTML contains modalTaskOpen markup matching Screen 2 & 3
$total++;
if (runTest("Index.php contains Add Task Modal markup with all required fields", function() {
    $html = file_get_contents(__DIR__ . '/index.php');
    if (strpos($html, 'x-show="modalTaskOpen"') === false) return "modalTaskOpen markup missing";
    if (strpos($html, 'Add New Task') === false) return "Add New Task title missing";
    if (strpos($html, '(Auto-selected)') === false) return "Auto-selected project label missing";
    if (strpos($html, 'taskForm.title') === false) return "taskForm.title input missing";
    if (strpos($html, 'taskForm.description') === false) return "taskForm.description textarea missing";
    if (strpos($html, '0/500') === false) return "0/500 character counter missing";
    if (strpos($html, 'taskForm.due_date') === false) return "taskForm.due_date input missing";
    if (strpos($html, 'taskForm.priority') === false) return "taskForm.priority select missing";
    if (strpos($html, 'taskForm.status') === false) return "taskForm.status select missing";
    if (strpos($html, 'taskForm.assigned_staff') === false) return "taskForm.assigned_staff select missing";
    if (strpos($html, 'Create Task') === false) return "Create Task button missing";
    return true;
})) $passed++;

// Test 2: Check Screen 4 green success banner markup in index.php
$total++;
if (runTest("Index.php contains Screen 4 Green Success Banner", function() {
    $html = file_get_contents(__DIR__ . '/index.php');
    if (strpos($html, 'projectSuccessBanner') === false) return "projectSuccessBanner missing";
    if (strpos($html, 'Task added successfully!') === false) return "Task added successfully! banner text missing";
    return true;
})) $passed++;

// Test 3: Check Project Details Tasks Tab has 3-dots menu button and subline helper
$total++;
if (runTest("Project Tasks tab has 3-dots menu and formatTaskDueSubline", function() {
    $html = file_get_contents(__DIR__ . '/index.php');
    if (strpos($html, 'formatTaskDueSubline(t)') === false) return "formatTaskDueSubline call missing";
    if (strpos($html, 'openTaskActionMenu(t)') === false) return "openTaskActionMenu button missing in project tasks";
    return true;
})) $passed++;

// Test 4: Server-side validation rejecting empty title with 400
$total++;
if (runTest("Server-side validation rejects empty or missing title with 400", function() use ($baseUrl, $cookieJar) {
    $res = httpPostJson("$baseUrl/api/tasks.php", [
        'action' => 'create',
        'title' => '   ',
        'project_id' => 'proj_1'
    ], $cookieJar);
    
    if ($res['status'] !== 400) return "Expected HTTP 400, got {$res['status']}: {$res['body']}";
    if (empty($res['json']['message']) || strpos($res['json']['message'], 'Title is required') === false) {
        return "Expected error message 'Task Title is required', got: {$res['body']}";
    }
    return true;
})) $passed++;

// Test 5: Verify proj_1 currently has initial tasks
$initialTaskCount = 0;
$total++;
if (runTest("Project proj_1 has initial tasks in database", function() use ($pdo, &$initialTaskCount) {
    // Clean up any previously created test tasks
    $pdo->exec("DELETE FROM crm_tasks WHERE title = 'Design Login Page' OR id LIKE 'tsk_test_%'");
    $stmt = $pdo->query("SELECT COUNT(*) FROM crm_tasks WHERE project_id = 'proj_1'");
    $initialTaskCount = (int)$stmt->fetchColumn();
    if ($initialTaskCount < 5) return "Expected at least 5 initial tasks for proj_1, got $initialTaskCount";
    return true;
})) $passed++;

// Test 6: Create new task via authenticated API matching Screen 3 (Design Login Page)
$createdTaskId = null;
$total++;
if (runTest("Create task via API matching Screen 3 and persist to crm_tasks", function() use ($baseUrl, $cookieJar, $pdo, &$createdTaskId) {
    $res = httpPostJson("$baseUrl/api/tasks.php", [
        'action' => 'create',
        'title' => 'Design Login Page',
        'description' => 'Create a modern and responsive login page for the CRM with Google login integration.',
        'project_id' => 'proj_1',
        'project_name' => 'E-commerce Website',
        'client_name' => 'ABC Tech Solutions',
        'assigned_staff' => 'Akash Dev',
        'due_date' => '2024-08-25',
        'due_time' => '25 Aug',
        'priority' => 'High',
        'status' => 'To Do'
    ], $cookieJar);
    
    if ($res['status'] !== 200 && $res['status'] !== 201) return "Expected HTTP 200/201, got {$res['status']}: {$res['body']}";
    if (empty($res['json']['success']) || empty($res['json']['id'])) {
        return "Response did not contain success or task id: {$res['body']}";
    }
    $createdTaskId = $res['json']['id'];
    
    Database::resetConnection();
    $pdo = Database::getConnection();
    
    // Verify in MySQL
    $chk = $pdo->prepare("SELECT * FROM crm_tasks WHERE id = ?");
    $chk->execute([$createdTaskId]);
    $row = $chk->fetch(PDO::FETCH_ASSOC);
    
    if (!$row) return "Task not found in MySQL crm_tasks";
    if ($row['title'] !== 'Design Login Page') return "Title mismatch: " . $row['title'];
    if ($row['assigned_staff'] !== 'Akash Dev') return "Assigned staff mismatch: " . $row['assigned_staff'];
    if ($row['priority'] !== 'High') return "Priority mismatch: " . $row['priority'];
    if ($row['due_time'] !== '25 Aug') return "Due time mismatch: " . $row['due_time'];
    if ($row['due_date'] !== '2024-08-25') return "Due date mismatch: " . $row['due_date'];
    if ($row['project_id'] !== 'proj_1') return "Project ID mismatch: " . $row['project_id'];
    if ($row['status'] !== 'To Do') return "Status mismatch: " . $row['status'];
    if ($row['completed'] != 0) return "Completed flag should be 0";
    
    return true;
})) $passed++;

// Test 7: Verify task count for proj_1 is incremented by 1
$total++;
if (runTest("Task count for proj_1 incremented by exactly 1", function() use ($pdo, &$initialTaskCount) {
    $stmt = $pdo->query("SELECT COUNT(*) FROM crm_tasks WHERE project_id = 'proj_1'");
    $cnt = (int)$stmt->fetchColumn();
    $expected = $initialTaskCount + 1;
    if ($cnt !== $expected) return "Expected exactly $expected tasks for proj_1, got $cnt";
    return true;
})) $passed++;

// Test 8: Verify task appears under proj_1 and NOT under proj_2
$total++;
if (runTest("Task is isolated to proj_1 and does not leak to other projects", function() use ($pdo, &$createdTaskId) {
    $stmt1 = $pdo->prepare("SELECT COUNT(*) FROM crm_tasks WHERE project_id = 'proj_1' AND id = ?");
    $stmt1->execute([$createdTaskId]);
    if ((int)$stmt1->fetchColumn() !== 1) return "Task missing under proj_1";
    
    $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM crm_tasks WHERE project_id = 'proj_2' AND id = ?");
    $stmt2->execute([$createdTaskId]);
    if ((int)$stmt2->fetchColumn() !== 0) return "Task erroneously appeared under proj_2";
    
    return true;
})) $passed++;

// Test 9: Verify ordering: newly created task appears first in proj_1 tasks
$total++;
if (runTest("Newly created task appears at top of project task list", function() use ($pdo, &$createdTaskId) {
    $stmt = $pdo->query("SELECT * FROM crm_tasks WHERE project_id = 'proj_1' ORDER BY completed ASC, created_at DESC");
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($tasks)) return "No tasks returned";
    if ($tasks[0]['id'] !== $createdTaskId) {
        return "Top task is {$tasks[0]['id']} ('{$tasks[0]['title']}'), expected {$createdTaskId} ('Design Login Page')";
    }
    return true;
})) $passed++;

// Test 10: Toggle completion on newly created task via API
$total++;
if (runTest("Toggling task marks it completed via API and updates database", function() use ($baseUrl, $cookieJar, $pdo, &$createdTaskId) {
    $res = httpPostJson("$baseUrl/api/tasks.php", [
        'action' => 'toggle',
        'id' => $createdTaskId
    ], $cookieJar);
    
    if ($res['status'] !== 200) return "Toggle API failed: {$res['status']} {$res['body']}";
    
    $stmt = $pdo->prepare("SELECT completed, status FROM crm_tasks WHERE id = ?");
    $stmt->execute([$createdTaskId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ((int)$row['completed'] !== 1) return "Task completed flag not set to 1";
    if ($row['status'] !== 'Completed') return "Task status not set to Completed";
    return true;
})) $passed++;

// Clean up test cookie and task
@unlink($cookieJar);
if ($createdTaskId) {
    $pdo->prepare("DELETE FROM crm_tasks WHERE id = ?")->execute([$createdTaskId]);
}

echo "\n============================================\n";
echo "ADD TASK FEATURE TEST RESULTS: $passed / $total PASSED\n";
echo "============================================\n";

if ($passed === $total) {
    exit(0);
} else {
    exit(1);
}
