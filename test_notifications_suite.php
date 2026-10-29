<?php
/**
 * Comprehensive Automated Test Suite for CRM Notifications System
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$appDir = dirname(__DIR__) . '/Software_Team_App';
require_once $appDir . '/api/db.php';
require_once $appDir . '/api/notification_service.php';

$pdo = Database::getConnection();

echo "========================================================\n";
echo "  CRM NOTIFICATION SYSTEM AUTOMATED TEST SUITE\n";
echo "========================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($condition, $testName, $detail = '') {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo " [PASS] $testName" . ($detail ? " ($detail)" : "") . "\n";
    } else {
        $failCount++;
        echo " [FAIL] $testName" . ($detail ? " - $detail" : "") . "\n";
    }
}

// -------------------------------------------------------------
// TEST 1: Database Table and Schema
// -------------------------------------------------------------
echo "\n--- TEST 1: Database Schema & Migration ---\n";
NotificationService::ensureTable($pdo);
$stmt = $pdo->query("SHOW TABLES LIKE 'crm_notifications'");
$tableExists = (bool)$stmt->fetch();
assertTest($tableExists, "crm_notifications table exists in database");

$colsStmt = $pdo->query("SHOW COLUMNS FROM crm_notifications");
$columns = $colsStmt->fetchAll(PDO::FETCH_COLUMN, 0);
$requiredCols = ['id', 'user_id', 'category', 'event_type', 'title', 'message', 'link_type', 'link_id', 'action_label', 'is_read', 'read_at', 'metadata', 'created_at'];
$allColsPresent = count(array_intersect($requiredCols, $columns)) === count($requiredCols);
assertTest($allColsPresent, "All required columns present in crm_notifications", implode(', ', $requiredCols));

// -------------------------------------------------------------
// TEST 2: Real CRM Event Sync (Invoices, Tasks, Contacts, System)
// -------------------------------------------------------------
echo "\n--- TEST 2: Real CRM Event Sync ---\n";
$syncResult = NotificationService::syncRealCrmEvents($pdo, 0);
assertTest(!empty($syncResult['success']), "syncRealCrmEvents executed successfully", "Total: " . ($syncResult['count'] ?? 0));

// Verify that records exist for multiple categories
$catCounts = $pdo->query("SELECT category, COUNT(*) as cnt FROM crm_notifications GROUP BY category")->fetchAll(PDO::FETCH_KEY_PAIR);
assertTest(isset($catCounts['invoices']) && $catCounts['invoices'] > 0, "Invoices category has real CRM notifications", "Count: " . ($catCounts['invoices'] ?? 0));
assertTest(isset($catCounts['clients']) && $catCounts['clients'] > 0, "Clients category has real CRM notifications", "Count: " . ($catCounts['clients'] ?? 0));
assertTest(isset($catCounts['tasks']) && $catCounts['tasks'] > 0, "Tasks category has real CRM notifications", "Count: " . ($catCounts['tasks'] ?? 0));
assertTest(isset($catCounts['system']) && $catCounts['system'] > 0, "System category has real CRM notifications", "Count: " . ($catCounts['system'] ?? 0));

// Verify idempotency (re-syncing does not duplicate)
$beforeCount = (int)$pdo->query("SELECT COUNT(*) FROM crm_notifications")->fetchColumn();
$syncResult2 = NotificationService::syncRealCrmEvents($pdo, 0);
$afterCount = (int)$pdo->query("SELECT COUNT(*) FROM crm_notifications")->fetchColumn();
assertTest($beforeCount === $afterCount, "Sync is idempotent (no duplicates created)", "Before: $beforeCount, After: $afterCount");

// -------------------------------------------------------------
// TEST 3: HTTP API Authentication Guard
// -------------------------------------------------------------
echo "\n--- TEST 3: HTTP API Authentication Guard ---\n";
$ch = curl_init('http://127.0.0.1:8000/api/notifications.php?action=get_notifications');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
assertTest($httpCode === 401, "Unauthenticated request returns HTTP 401 Unauthorized", "HTTP Code: $httpCode");

// -------------------------------------------------------------
// TEST 4: Authenticated API Operations via Cookie Session
// -------------------------------------------------------------
echo "\n--- TEST 4: Authenticated API Operations ---\n";

$baseUrl = 'http://127.0.0.1:8000';
$cookieFile = sys_get_temp_dir() . '/test_notif_cookie.txt';
if (file_exists($cookieFile)) @unlink($cookieFile);

// Step 1: Set up valid challenge in crm_otp_challenges for suresh@softwarecrm.com
$testOtp = '1234';
$otpHash = password_hash($testOtp, PASSWORD_DEFAULT);
$pdo->prepare("UPDATE `crm_otp_challenges` SET `is_used` = 1 WHERE `email` = 'suresh@softwarecrm.com'")->execute();
$insChallenge = $pdo->prepare("INSERT INTO `crm_otp_challenges` (`email`, `otp_hash`, `purpose`, `expires_at`, `attempts`, `max_attempts`, `resend_count`, `last_sent_at`, `is_used`, `temp_payload`) VALUES ('suresh@softwarecrm.com', ?, 'login', DATE_ADD(NOW(), INTERVAL 5 MINUTE), 0, 5, 0, NOW(), 0, ?)");
$insChallenge->execute([
    $otpHash,
    json_encode(['user_id' => 1, 'name' => 'Suresh Kumar', 'email' => 'suresh@softwarecrm.com', 'role' => 'admin'])
]);

// Step 2: Verify OTP to establish authenticated session
$ch = curl_init("$baseUrl/api/auth.php?action=verify_otp");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'email' => 'suresh@softwarecrm.com',
    'otp' => $testOtp,
    'purpose' => 'login'
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$otpResp = curl_exec($ch);
curl_close($ch);
$otpData = json_decode($otpResp, true);

$authOk = ($otpData && (!empty($otpData['success']) || !empty($otpData['authenticated'])));
assertTest($authOk, "Authenticated test session established for suresh@softwarecrm.com");

// Ensure unread notifications exist for testing read/unread
$pdo->prepare("UPDATE crm_notifications SET is_read = 0 WHERE is_read = 1 LIMIT 5")->execute();

// Fetch Notifications: Category = 'all'
$ch = curl_init('http://127.0.0.1:8000/api/notifications.php?action=get_notifications&category=all');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$apiResp = curl_exec($ch);
curl_close($ch);
$notifData = json_decode($apiResp, true);

assertTest(isset($notifData['status']) && $notifData['status'] === 'success', "API get_notifications returned success");
assertTest(isset($notifData['data']) && is_array($notifData['data']), "API returns data list", "Found " . count($notifData['data'] ?? []) . " notifications");
assertTest(isset($notifData['counts']) && isset($notifData['counts']['all']), "API returns counts breakdown for categories");
assertTest(isset($notifData['unread_count']), "API returns unread_count", "Unread: " . ($notifData['unread_count'] ?? 0));

// Check time_ago and formatted_date formatting
if (!empty($notifData['data'])) {
    $first = $notifData['data'][0];
    assertTest(!empty($first['time_ago']) && !empty($first['formatted_date']), "Notification card has human-readable time_ago and formatted_date", $first['time_ago']);
}

// Category Filtering: 'invoices', 'clients', 'tasks', 'system'
foreach (['invoices', 'clients', 'tasks', 'system'] as $cat) {
    $ch = curl_init('http://127.0.0.1:8000/api/notifications.php?action=get_notifications&category=' . $cat);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    $catResp = curl_exec($ch);
    curl_close($ch);
    $cData = json_decode($catResp, true);
    $validCat = true;
    foreach ($cData['data'] ?? [] as $row) {
        if ($row['category'] !== $cat) { $validCat = false; break; }
    }
    assertTest($validCat, "Category filter '$cat' returns only '$cat' notifications", "Count: " . count($cData['data'] ?? []));
}

// Polling Endpoint: get_unread_count
$ch = curl_init('http://127.0.0.1:8000/api/notifications.php?action=get_unread_count');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$pollResp = curl_exec($ch);
curl_close($ch);
$pollData = json_decode($pollResp, true);
assertTest(isset($pollData['status']) && $pollData['status'] === 'success' && isset($pollData['unread_count']), "Fast polling get_unread_count endpoint returns success", "Count: " . ($pollData['unread_count'] ?? 0));

// -------------------------------------------------------------
// TEST 5: Mark Single Notification As Read
// -------------------------------------------------------------
echo "\n--- TEST 5: Mark Single Notification As Read ---\n";
// Find an unread notification to test
$target = null;
if (!empty($notifData['data'])) {
    foreach ($notifData['data'] as $item) {
        if (!$item['is_read']) {
            $target = $item;
            break;
        }
    }
}
if (!$target) {
    // Insert a test unread notification
    $pdo->prepare("INSERT INTO crm_notifications (id, user_id, category, event_type, title, message, is_read, created_at) VALUES (999999, 0, 'system', 'test_event', 'Test Unread', 'Testing read action', 0, NOW()) ON DUPLICATE KEY UPDATE is_read=0")->execute();
    $target = ['id' => 999999];
}

$ch = curl_init('http://127.0.0.1:8000/api/notifications.php?action=mark_read');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['id' => $target['id']]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$readResp = curl_exec($ch);
curl_close($ch);
$readData = json_decode($readResp, true);

assertTest(isset($readData['status']) && $readData['status'] === 'success', "mark_read API returns success for notification ID {$target['id']}");

// Verify DB persistence of read state
$checkStmt = $pdo->prepare("SELECT is_read, read_at FROM crm_notifications WHERE id = ?");
$checkStmt->execute([$target['id']]);
$dbRow = $checkStmt->fetch(PDO::FETCH_ASSOC);
assertTest((int)$dbRow['is_read'] === 1 && !empty($dbRow['read_at']), "Notification {$target['id']} is marked is_read=1 with timestamp in DB");

// -------------------------------------------------------------
// TEST 6: Mark All Notifications As Read
// -------------------------------------------------------------
echo "\n--- TEST 6: Mark All Notifications As Read ---\n";
$ch = curl_init('http://127.0.0.1:8000/api/notifications.php?action=mark_all_read');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$allReadResp = curl_exec($ch);
curl_close($ch);
$allReadData = json_decode($allReadResp, true);

assertTest(isset($allReadData['status']) && $allReadData['status'] === 'success', "mark_all_read API returns success");
assertTest((int)($allReadData['unread_count'] ?? 1) === 0, "mark_all_read returns unread_count = 0");

// Verify DB persistence for current user
$unreadInDb = (int)$pdo->query("SELECT COUNT(*) FROM crm_notifications WHERE (user_id = 0 OR user_id = 1) AND is_read = 0")->fetchColumn();
assertTest($unreadInDb === 0, "All notifications for user are marked is_read=1 across DB persistence");

// -------------------------------------------------------------
// TEST 7: Privacy & Authorization Check
// -------------------------------------------------------------
echo "\n--- TEST 7: Privacy & Authorization Guard ---\n";
// Create a private notification belonging strictly to user ID 999
$pdo->prepare("INSERT INTO crm_notifications (id, user_id, category, event_type, title, message, is_read, created_at) VALUES (888888, 999, 'system', 'private_alert', 'Private to User 999', 'Secret', 0, NOW()) ON DUPLICATE KEY UPDATE is_read=0")->execute();

// Establish session for non-admin employee (User ID 51, role 'member')
$nonAdminCookie = sys_get_temp_dir() . '/test_notif_nonadmin_cookie.txt';
if (file_exists($nonAdminCookie)) @unlink($nonAdminCookie);
$insChallenge = $pdo->prepare("INSERT INTO `crm_otp_challenges` (`email`, `otp_hash`, `purpose`, `expires_at`, `attempts`, `max_attempts`, `resend_count`, `last_sent_at`, `is_used`, `temp_payload`) VALUES ('ananya.gmail@gmail.com', ?, 'login', DATE_ADD(NOW(), INTERVAL 5 MINUTE), 0, 5, 0, NOW(), 0, ?)");
$insChallenge->execute([
    $otpHash,
    json_encode(['user_id' => 51, 'name' => 'Ananya Sharma', 'email' => 'ananya.gmail@gmail.com', 'role' => 'member'])
]);

$ch = curl_init("$baseUrl/api/auth.php?action=verify_otp");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'email' => 'ananya.gmail@gmail.com',
    'otp' => $testOtp,
    'purpose' => 'login'
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_COOKIEJAR, $nonAdminCookie);
curl_setopt($ch, CURLOPT_COOKIEFILE, $nonAdminCookie);
curl_exec($ch);
curl_close($ch);

// Non-admin user (ID 51) tries to mark user 999's private notification
$ch = curl_init('http://127.0.0.1:8000/api/notifications.php?action=mark_read');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['id' => 888888]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_COOKIEFILE, $nonAdminCookie);
$privResp = curl_exec($ch);
$privCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$privData = json_decode($privResp, true);

// Clean up test private record & nonAdminCookie
$pdo->prepare("DELETE FROM crm_notifications WHERE id = 888888")->execute();
if (file_exists($nonAdminCookie)) @unlink($nonAdminCookie);

assertTest($privCode === 403 || (isset($privData['status']) && $privData['status'] === 'error'), "Non-owner user cannot modify another user's private notification", "HTTP: $privCode");

// -------------------------------------------------------------
// TEST 8: CRM Event Creation Hooks Integration
// -------------------------------------------------------------
echo "\n--- TEST 8: Live CRM Event Creation Hooks ---\n";

// 8A. Create Task -> verify task notification created
$testTaskTitle = "Automated Test Task " . time();
$ch = curl_init('http://127.0.0.1:8000/api/tasks.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'action' => 'create',
    'title' => $testTaskTitle,
    'priority' => 'High',
    'assigned_staff' => 'Rahul Sharma'
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$taskResp = curl_exec($ch);
curl_close($ch);
$taskData = json_decode($taskResp, true);

$createdTaskId = $taskData['id'] ?? '';
$stNotif = $pdo->prepare("SELECT * FROM crm_notifications WHERE category = 'tasks' AND link_id = ?");
$stNotif->execute([$createdTaskId]);
$taskNotif = $stNotif->fetch(PDO::FETCH_ASSOC);
assertTest(!empty($taskNotif) && strpos($taskNotif['title'], $testTaskTitle) !== false, "Task creation triggered a live 'tasks' notification in crm_notifications", "Title: " . ($taskNotif['title'] ?? 'none'));

// 8B. Toggle Task to Completed -> verify completion notification created
if ($createdTaskId) {
    $ch = curl_init('http://127.0.0.1:8000/api/tasks.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'action' => 'toggle',
        'id' => $createdTaskId
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    $toggleResp = curl_exec($ch);
    curl_close($ch);

    $stComp = $pdo->prepare("SELECT * FROM crm_notifications WHERE category = 'tasks' AND event_type = 'task_completed' AND link_id = ?");
    $stComp->execute([$createdTaskId]);
    $compNotif = $stComp->fetch(PDO::FETCH_ASSOC);
    assertTest(!empty($compNotif), "Task completion triggered a 'task_completed' notification", "ID: " . ($compNotif['id'] ?? 'none'));

    // Clean up test task
    $pdo->prepare("DELETE FROM crm_tasks WHERE id = ?")->execute([$createdTaskId]);
    $pdo->prepare("DELETE FROM crm_notifications WHERE link_id = ?")->execute([$createdTaskId]);
}

// -------------------------------------------------------------
// TEST 9: Frontend Index.php UI Structure & Alpine Integration
// -------------------------------------------------------------
echo "\n--- TEST 9: Frontend UI & Alpine Markup ---\n";
$indexContent = file_get_contents($appDir . '/index.php');

assertTest(strpos($indexContent, 'openNotificationsModal()') !== false, "index.php has clickable bell calling openNotificationsModal()");
assertTest(strpos($indexContent, 'unreadNotificationsCount > 0') !== false, "index.php has dynamic unread numeric badge on bell");
assertTest(strpos($indexContent, 'modalNotificationsOpen') !== false, "index.php has modalNotificationsOpen state");
assertTest(strpos($indexContent, 'closeNotificationsModal()') !== false, "index.php has close button and close method");
assertTest(strpos($indexContent, 'markAllNotificationsAsRead()') !== false, "index.php has markAllNotificationsAsRead button & handler");
assertTest(strpos($indexContent, 'setNotificationsFilter') !== false, "index.php has category tabs filtering");
assertTest(strpos($indexContent, 'handleNotificationClick') !== false, "index.php has item click handler for CRM record navigation");
assertTest(strpos($indexContent, 'notificationsLoading') !== false, "index.php has skeleton loading state");
assertTest(strpos($indexContent, 'notificationsError') !== false, "index.php has error retry state");
assertTest(strpos($indexContent, 'notificationsPollingTimer') !== false, "index.php has background polling timer for unread alerts");

// Clean up temp cookie
if (file_exists($cookieFile)) @unlink($cookieFile);

echo "\n========================================================\n";
echo "  TEST SUMMARY: $passCount Passed, $failCount Failed\n";
echo "========================================================\n";

if ($failCount > 0) exit(1);
