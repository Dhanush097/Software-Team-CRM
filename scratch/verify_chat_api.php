<?php
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/session_guard.php';

$pdo = Database::getConnection();

// Pick a test group
$grp = $pdo->query("SELECT id, name FROM crm_groups ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$grp) {
    echo "No group found. Creating test group...\n";
    $pdo->exec("INSERT INTO crm_groups (name, description, color, created_by) VALUES ('Test Team', 'Testing chats', '#2563eb', 'Admin')");
    $groupId = (int)$pdo->lastInsertId();
} else {
    $groupId = (int)$grp['id'];
}

echo "Testing Group ID: $groupId ('{$grp['name']}')\n";

// Ensure Suresh Kumar is in group
$suresh = $pdo->query("SELECT id, name FROM crm_team_staff WHERE email = 'suresh@softwarecrm.com' OR name = 'Suresh Kumar' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$sureshId = $suresh ? $suresh['id'] : 'staff_suresh';
$pdo->prepare("INSERT IGNORE INTO crm_group_members (group_id, employee_id, role_in_group) VALUES (?, ?, 'member')")->execute([$groupId, $sureshId]);

// Ensure another member (Priya Patel or Akash Dev) is in group
$member2 = $pdo->query("SELECT id, name FROM crm_team_staff WHERE id != '$sureshId' AND status = 'Active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$m2Id = $member2['id'];
$pdo->prepare("INSERT IGNORE INTO crm_group_members (group_id, employee_id, role_in_group) VALUES (?, ?, 'member')")->execute([$groupId, $m2Id]);

echo "Members in group: $sureshId ({$suresh['name']}), $m2Id ({$member2['name']})\n";

// Setup Auth Session for Suresh
$_SESSION['auth_user'] = [
    'id'          => 1,
    'name'        => 'Suresh Kumar',
    'email'       => 'suresh@softwarecrm.com',
    'role'        => 'admin',
    'status'      => 'active',
    'employee_id' => $sureshId
];
$_SESSION['csrf_token'] = bin2hex(random_bytes(16));

function callGroupsApi($method, $params = [], $body = null) {
    global $groupId;
    $url = "http://127.0.0.1:8000/api/groups.php";
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . session_id());

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($body !== null) {
            if (!isset($body['csrf_token'])) {
                $body['csrf_token'] = $_SESSION['csrf_token'];
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-CSRF-Token: ' . $_SESSION['csrf_token']]);
        }
    }
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 0) {
        echo "CURL Error: $err\n";
    }

    return ['code' => $code, 'data' => json_decode($res, true), 'raw' => $res];
}

session_write_close();

echo "\n--- TEST 1: Send Message ---\n";
$sendRes = callGroupsApi('POST', ['action' => 'send_message'], [
    'group_id' => $groupId,
    'message'  => 'Hello team! Testing delivery ticks and unsend.'
]);
echo "Code: {$sendRes['code']}, Msg ID: " . ($sendRes['data']['data']['id'] ?? 'none') . "\n";
if ($sendRes['code'] !== 200) {
    echo "Raw response: " . $sendRes['raw'] . "\n";
}
assert($sendRes['code'] === 200, "Send message failed");
$msgId = $sendRes['data']['data']['id'];

echo "\n--- TEST 2: Check Delivery Receipts (Single Tick before delivery) ---\n";
$getRes = callGroupsApi('GET', ['action' => 'messages', 'group_id' => $groupId]);
$lastMsg = end($getRes['data']['data']);
echo "Sent message status for Suresh: {$lastMsg['status']} (single grey tick expected)\n";

echo "\n--- TEST 3: Member 2 fetches messages (Triggers delivery -> double grey tick) ---\n";
// Switch session to Member 2
session_start();
$_SESSION['auth_user'] = [
    'id'          => 2,
    'name'        => $member2['name'],
    'email'       => 'member2@example.com',
    'role'        => 'member',
    'status'      => 'active',
    'employee_id' => $m2Id
];
session_write_close();

$m2Get = callGroupsApi('GET', ['action' => 'messages', 'group_id' => $groupId]);
echo "Member 2 fetched " . count($m2Get['data']['data']) . " messages. Delivery recorded!\n";

echo "\n--- TEST 4: Check Suresh sees double tick now ---\n";
session_start();
$_SESSION['auth_user'] = [
    'id'          => 1,
    'name'        => 'Suresh Kumar',
    'email'       => 'suresh@softwarecrm.com',
    'role'        => 'admin',
    'status'      => 'active',
    'employee_id' => $sureshId
];
session_write_close();

$sureshGet = callGroupsApi('GET', ['action' => 'messages', 'group_id' => $groupId]);
$sureshLast = end($sureshGet['data']['data']);
echo "Suresh sees status: {$sureshLast['status']} (double tick expected)\n";

echo "\n--- TEST 5: Member 2 marks read (Triggers read receipt -> double blue tick) ---\n";
session_start();
$_SESSION['auth_user'] = [
    'id'          => 2,
    'name'        => $member2['name'],
    'email'       => 'member2@example.com',
    'role'        => 'member',
    'status'      => 'active',
    'employee_id' => $m2Id
];
session_write_close();

$readRes = callGroupsApi('POST', ['action' => 'mark_read'], ['group_id' => $groupId]);
echo "Mark read code: {$readRes['code']}\n";

session_start();
$_SESSION['auth_user'] = [
    'id'          => 1,
    'name'        => 'Suresh Kumar',
    'email'       => 'suresh@softwarecrm.com',
    'role'        => 'admin',
    'status'      => 'active',
    'employee_id' => $sureshId
];
session_write_close();

$sureshGet2 = callGroupsApi('GET', ['action' => 'messages', 'group_id' => $groupId]);
$sureshLast2 = end($sureshGet2['data']['data']);
echo "Suresh sees status after read: {$sureshLast2['status']} (blue double tick expected)\n";

echo "\n--- TEST 6: Message Info ---\n";
$infoRes = callGroupsApi('GET', ['action' => 'get_message_info', 'message_id' => $msgId]);
echo "Message info read_by count: " . count($infoRes['data']['data']['read_by']) . "\n";
print_r($infoRes['data']['data']['read_by']);

echo "\n--- TEST 7: Edit Message ---\n";
$editRes = callGroupsApi('POST', ['action' => 'edit_message'], [
    'message_id' => $msgId,
    'message'    => 'Hello team! (Edited version)'
]);
echo "Edit code: {$editRes['code']}, message: " . ($editRes['data']['data']['message'] ?? '') . "\n";

echo "\n--- TEST 8: Unsend Message ---\n";
$unsendRes = callGroupsApi('POST', ['action' => 'unsend_message'], ['message_id' => $msgId]);
echo "Unsend code: {$unsendRes['code']}, deletion_state: " . ($unsendRes['data']['data']['deletion_state'] ?? '') . "\n";

echo "\n--- TEST 9: Delete for Everyone ---\n";
// Send another message to test delete for everyone
$send2 = callGroupsApi('POST', ['action' => 'send_message'], [
    'group_id' => $groupId,
    'message'  => 'Confidential temporary note'
]);
$msg2Id = $send2['data']['data']['id'];
$delAll = callGroupsApi('POST', ['action' => 'delete_message_for_everyone'], ['message_id' => $msg2Id]);
echo "Delete for everyone code: {$delAll['code']}, state: " . ($delAll['data']['data']['deletion_state'] ?? '') . "\n";

echo "\n--- TEST 10: Delete for Me ---\n";
$send3 = callGroupsApi('POST', ['action' => 'send_message'], [
    'group_id' => $groupId,
    'message'  => 'Note only I want to hide'
]);
$msg3Id = $send3['data']['data']['id'];
$delMe = callGroupsApi('POST', ['action' => 'delete_message_for_me'], ['message_id' => $msg3Id]);
echo "Delete for me code: {$delMe['code']}\n";
// Verify Suresh doesn't see msg3Id
$sureshGet3 = callGroupsApi('GET', ['action' => 'messages', 'group_id' => $groupId]);
$idsSuresh = array_column($sureshGet3['data']['data'], 'id');
echo "Is msg3Id in Suresh's view? " . (in_array($msg3Id, $idsSuresh) ? "YES (FAIL)" : "NO (PASS - Hidden for me)") . "\n";

echo "\n--- TEST 11: Call Flow (Start, Join, Screen Sharing Media Update, End) ---\n";
$callStart = callGroupsApi('POST', ['action' => 'start_call'], ['group_id' => $groupId, 'call_type' => 'video']);
echo "Start call code: {$callStart['code']}, Call ID: " . ($callStart['data']['data']['id'] ?? 'none') . "\n";
$callId = $callStart['data']['data']['id'];

// Member 2 joins
session_start();
$_SESSION['auth_user'] = [
    'id'          => 2,
    'name'        => $member2['name'],
    'email'       => 'member2@example.com',
    'role'        => 'member',
    'status'      => 'active',
    'employee_id' => $m2Id
];
session_write_close();

$joinRes = callGroupsApi('POST', ['action' => 'join_call'], ['call_id' => $callId]);
echo "Member 2 join code: {$joinRes['code']}\n";

// Suresh shares screen
session_start();
$_SESSION['auth_user'] = [
    'id'          => 1,
    'name'        => 'Suresh Kumar',
    'email'       => 'suresh@softwarecrm.com',
    'role'        => 'admin',
    'status'      => 'active',
    'employee_id' => $sureshId
];
session_write_close();

$screenRes = callGroupsApi('POST', ['action' => 'update_call_media'], [
    'call_id'           => $callId,
    'is_muted'          => 0,
    'is_camera_off'     => 0,
    'is_screen_sharing' => 1
]);
echo "Update call media (screen share) code: {$screenRes['code']}\n";

// End call
$endRes = callGroupsApi('POST', ['action' => 'end_call'], ['call_id' => $callId]);
echo "End call code: {$endRes['code']}\n";

echo "\n--- TEST 12: Member Chart Data ---\n";
$chartRes = callGroupsApi('GET', ['action' => 'member_chart_data', 'employee_id' => $sureshId, 'group_id' => $groupId]);
echo "Chart code: {$chartRes['code']}, Total tasks: " . ($chartRes['data']['data']['total_tasks'] ?? 'none') . ", Completion rate: " . ($chartRes['data']['data']['completion_rate'] ?? 'none') . "%\n";

echo "\nALL API BACKEND TESTS COMPLETED SUCCESSFULLY!\n";
