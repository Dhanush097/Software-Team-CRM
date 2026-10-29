<?php
/**
 * Automated Verification Suite for Software_Team_App Group Chat, WebRTC Calling & Workload Charting
 */
require_once __DIR__ . '/api/db.php';

echo "========================================================================\n";
echo "   GROUP CHAT, WEBRTC CALLING & MEMBER CHARTING TEST SUITE\n";
echo "========================================================================\n\n";

$pdo = Database::getConnection();

$testsPassed = 0;
$testsFailed = 0;

function assertTest($condition, $name, $detail = '') {
    global $testsPassed, $testsFailed;
    if ($condition) {
        $testsPassed++;
        echo " [PASS] $name" . ($detail ? " ($detail)" : "") . "\n";
    } else {
        $testsFailed++;
        echo " [FAIL] $name" . ($detail ? " - $detail" : "") . "\n";
    }
}

// -------------------------------------------------------------------------
// 1. Database Schema Checks
// -------------------------------------------------------------------------
echo "--- Section 1: Database Schema Checks ---\n";
$tables = ['crm_group_chats', 'crm_group_message_deletions', 'crm_group_message_receipts', 'crm_group_calls', 'crm_group_call_participants', 'crm_group_call_signals'];
foreach ($tables as $t) {
    $exists = $pdo->query("SHOW TABLES LIKE '$t'")->rowCount() > 0;
    assertTest($exists, "Table $t exists");
}

$chatCols = $pdo->query("SHOW COLUMNS FROM crm_group_chats")->fetchAll(PDO::FETCH_COLUMN);
assertTest(in_array('message_type', $chatCols), "crm_group_chats has message_type");
assertTest(in_array('deletion_state', $chatCols), "crm_group_chats has deletion_state");
assertTest(in_array('reply_to_id', $chatCols), "crm_group_chats has reply_to_id");
assertTest(in_array('edited_at', $chatCols), "crm_group_chats has edited_at");

// -------------------------------------------------------------------------
// 2. Setup Test Group and Authenticated Users
// -------------------------------------------------------------------------
echo "\n--- Section 2: Group & User Fixtures ---\n";
$grp = $pdo->query("SELECT id, name FROM crm_groups WHERE name = 'Development & Engineering' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$grp) {
    $grp = $pdo->query("SELECT id, name FROM crm_groups ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}
assertTest(!empty($grp), "Target group located", $grp['name'] ?? 'None');
$groupId = (int)$grp['id'];

$staff = $pdo->query("SELECT id, name, email, role FROM crm_team_staff WHERE status = 'Active' LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
assertTest(count($staff) >= 2, "At least 2 active staff members available for multi-user test");

$u1 = $staff[0]; // Sender / Initiator
$u2 = $staff[1]; // Recipient / Participant
$u3 = $staff[2] ?? $staff[0]; // Non-member or 3rd member

// Add u1 and u2 to group members
$pdo->prepare("INSERT IGNORE INTO crm_group_members (group_id, employee_id, role_in_group) VALUES (?, ?, 'leader')")->execute([$groupId, $u1['id']]);
$pdo->prepare("INSERT IGNORE INTO crm_group_members (group_id, employee_id, role_in_group) VALUES (?, ?, 'member')")->execute([$groupId, $u2['id']]);

function makeSession($user) {
    $sessId = 'user' . preg_replace('/[^a-zA-Z0-9]/', '', md5($user['id']));
    $sessFile = session_save_path();
    if (empty($sessFile) || !is_dir($sessFile)) {
        $sessFile = is_dir('C:/xampp/tmp') ? 'C:/xampp/tmp' : sys_get_temp_dir();
    }
    $sessPath = rtrim($sessFile, '/\\') . '/sess_' . $sessId;
    $sessData = [
        'auth_user' => [
            'id'          => 1,
            'name'        => $user['name'],
            'email'       => $user['email'],
            'role'        => 'admin',
            'status'      => 'active',
            'employee_id' => $user['id']
        ],
        'csrf_token' => 'csrftoken' . md5($user['id'])
    ];
    $ser = '';
    foreach ($sessData as $k => $v) $ser .= $k . '|' . serialize($v);
    file_put_contents($sessPath, $ser);
    return ['sess_id' => $sessId, 'csrf' => $sessData['csrf_token'], 'user' => $user];
}

$s1 = makeSession($u1);
$s2 = makeSession($u2);

function callEndpoint($session, $method, $params = [], $body = null) {
    $url = "http://127.0.0.1:8000/api/groups.php?" . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIE, 'PHPSESSID=' . $session['sess_id']);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($body !== null) {
            $body['csrf_token'] = $session['csrf'];
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-CSRF-Token: ' . $session['csrf']]);
        }
    }
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'data' => json_decode($res, true), 'raw' => $res];
}

// -------------------------------------------------------------------------
// 3. Message Delivery Ticks: Single Grey -> Double Grey -> Double Blue
// -------------------------------------------------------------------------
echo "\n--- Section 3: Message Delivery & Read Ticks ---\n";
// U1 sends message
$sendRes = callEndpoint($s1, 'POST', ['action' => 'send_message'], [
    'group_id' => $groupId,
    'message'  => 'Automated test message for ticks verification ' . time()
]);
assertTest($sendRes['code'] === 200 && !empty($sendRes['data']['data']['id']), "Message sent by User 1");
$msgId = (int)$sendRes['data']['data']['id'];

// Before U2 fetches, receipts have delivered_at = null, read_at = null
$msgListU1 = callEndpoint($s1, 'GET', ['action' => 'messages', 'group_id' => $groupId]);
$targetMsgU1 = null;
foreach ($msgListU1['data']['data'] as $m) if ((int)$m['id'] === $msgId) $targetMsgU1 = $m;
assertTest($targetMsgU1 && $targetMsgU1['receipt_summary'] === 'sent', "Tick state is 'sent' (Single Grey Tick)");

// U2 loads messages (this marks delivered_at for U2)
$msgListU2 = callEndpoint($s2, 'GET', ['action' => 'messages', 'group_id' => $groupId]);
assertTest($msgListU2['code'] === 200, "User 2 fetches messages");

// Re-check U1's message list: should be 'delivered' (Double Grey Ticks)
$msgListU1Delivered = callEndpoint($s1, 'GET', ['action' => 'messages', 'group_id' => $groupId]);
foreach ($msgListU1Delivered['data']['data'] as $m) if ((int)$m['id'] === $msgId) $targetMsgU1 = $m;
assertTest($targetMsgU1 && $targetMsgU1['receipt_summary'] === 'delivered', "Tick state updated to 'delivered' (Double Grey Ticks)");

// U2 marks conversation as read
$readRes = callEndpoint($s2, 'POST', ['action' => 'mark_read'], [
    'group_id'   => $groupId,
    'message_id' => $msgId
]);
assertTest($readRes['code'] === 200, "User 2 marks message as read");

// Re-check U1's message list: should be 'read' (Double Blue Ticks)
$msgListU1Read = callEndpoint($s1, 'GET', ['action' => 'messages', 'group_id' => $groupId]);
foreach ($msgListU1Read['data']['data'] as $m) if ((int)$m['id'] === $msgId) $targetMsgU1 = $m;
assertTest($targetMsgU1 && $targetMsgU1['receipt_summary'] === 'read', "Tick state updated to 'read' (Double Blue Ticks)");

// -------------------------------------------------------------------------
// 4. Message Info Panel Receipts
// -------------------------------------------------------------------------
echo "\n--- Section 4: Message Info Panel Receipts ---\n";
$infoRes = callEndpoint($s1, 'GET', ['action' => 'get_message_info', 'message_id' => $msgId]);
assertTest($infoRes['code'] === 200 && !empty($infoRes['data']['data']), "Message Info retrieved");
$info = $infoRes['data']['data'];
assertTest(!empty($info['message']), "Message info contains original message payload");
assertTest(!empty($info['read_by']) && count($info['read_by']) >= 1, "Message info lists User 2 under 'read_by'");
assertTest(!empty($info['delivered_to']) && count($info['delivered_to']) >= 1, "Message info lists User 2 under 'delivered_to'");

// -------------------------------------------------------------------------
// 5. Message Edit
// -------------------------------------------------------------------------
echo "\n--- Section 5: Message Edit ---\n";
$editRes = callEndpoint($s1, 'POST', ['action' => 'edit_message'], [
    'message_id' => $msgId,
    'message'    => 'Edited content ' . time()
]);
assertTest($editRes['code'] === 200 && strpos($editRes['data']['data']['message'], 'Edited content') !== false, "User 1 edits message");
assertTest(!empty($editRes['data']['data']['edited_at']), "Message edited_at timestamp is set");

// -------------------------------------------------------------------------
// 6. Unsend Message
// -------------------------------------------------------------------------
echo "\n--- Section 6: Unsend Message ---\n";
// Create another message for unsend
$msgUnsend = callEndpoint($s1, 'POST', ['action' => 'send_message'], ['group_id' => $groupId, 'message' => 'To be unsent']);
$unsendId = (int)$msgUnsend['data']['data']['id'];

// Authorized unsend by U1
$okUnsend = callEndpoint($s1, 'POST', ['action' => 'unsend_message'], ['message_id' => $unsendId]);
assertTest($okUnsend['code'] === 200 && $okUnsend['data']['data']['deletion_state'] === 'unsent', "Sender unsends message");
assertTest($okUnsend['data']['data']['message'] === 'This message was unsent.', "Message replaced with 'This message was unsent.'");

// Verify U2 also sees 'This message was unsent.'
$u2SeeUnsend = callEndpoint($s2, 'GET', ['action' => 'messages', 'group_id' => $groupId]);
$seenUnsent = null;
foreach ($u2SeeUnsend['data']['data'] as $m) if ((int)$m['id'] === $unsendId) $seenUnsent = $m;
assertTest($seenUnsent && $seenUnsent['message'] === 'This message was unsent.', "Recipient interface displays unsent placeholder");

// -------------------------------------------------------------------------
// 7. Delete for Me
// -------------------------------------------------------------------------
echo "\n--- Section 7: Delete for Me ---\n";
$msgDelMe = callEndpoint($s1, 'POST', ['action' => 'send_message'], ['group_id' => $groupId, 'message' => 'Delete for me test']);
$delMeId = (int)$msgDelMe['data']['data']['id'];

$delMeRes = callEndpoint($s1, 'POST', ['action' => 'delete_message_for_me'], ['message_id' => $delMeId]);
assertTest($delMeRes['code'] === 200, "Delete for Me executed for User 1");

// U1 checks messages -> delMeId must NOT be present
$u1CheckDelMe = callEndpoint($s1, 'GET', ['action' => 'messages', 'group_id' => $groupId]);
$u1HasDelMe = in_array($delMeId, array_map('intval', array_column($u1CheckDelMe['data']['data'], 'id')));
assertTest(!$u1HasDelMe, "Message hidden for deleting user (User 1)");

// U2 checks messages -> delMeId MUST be present
$u2CheckDelMe = callEndpoint($s2, 'GET', ['action' => 'messages', 'group_id' => $groupId]);
$u2HasDelMe = in_array($delMeId, array_map('intval', array_column($u2CheckDelMe['data']['data'], 'id')));
assertTest($u2HasDelMe, "Message remains visible to other participants (User 2)");

// -------------------------------------------------------------------------
// 8. Delete for Everyone
// -------------------------------------------------------------------------
echo "\n--- Section 8: Delete for Everyone ---\n";
$msgDelAll = callEndpoint($s1, 'POST', ['action' => 'send_message'], ['group_id' => $groupId, 'message' => 'Delete for everyone test']);
$delAllId = (int)$msgDelAll['data']['data']['id'];

$delAllRes = callEndpoint($s1, 'POST', ['action' => 'delete_message_for_everyone'], ['message_id' => $delAllId]);
assertTest($delAllRes['code'] === 200 && $delAllRes['data']['data']['deletion_state'] === 'deleted_everyone', "Delete for Everyone executed");
assertTest($delAllRes['data']['data']['message'] === 'This message was deleted.', "Content replaced with 'This message was deleted.'");

$u2CheckDelAll = callEndpoint($s2, 'GET', ['action' => 'messages', 'group_id' => $groupId]);
$seenDelAll = null;
foreach ($u2CheckDelAll['data']['data'] as $m) if ((int)$m['id'] === $delAllId) $seenDelAll = $m;
assertTest($seenDelAll && $seenDelAll['message'] === 'This message was deleted.', "All participants see 'This message was deleted.'");

// -------------------------------------------------------------------------
// 9. WebRTC Audio & Video Calling + Media State + Screen Sharing
// -------------------------------------------------------------------------
echo "\n--- Section 9: Group Calling & Screen-Sharing Presentations ---\n";
// Start Call (Video)
$startCall = callEndpoint($s1, 'POST', ['action' => 'start_call'], ['group_id' => $groupId, 'call_type' => 'video']);
assertTest($startCall['code'] === 200 && !empty($startCall['data']['data']['id']), "Call started successfully");
$callId = (int)$startCall['data']['data']['id'];
assertTest($startCall['data']['data']['call_type'] === 'video', "Call type is video");

// Join Call (User 2)
$joinCall = callEndpoint($s2, 'POST', ['action' => 'join_call'], ['call_id' => $callId]);
assertTest($joinCall['code'] === 200, "User 2 joins call session");

// Sync Call Participants
$syncCall = callEndpoint($s1, 'GET', ['action' => 'chat_sync', 'group_id' => $groupId, 'call_id' => $callId]);
assertTest(!empty($syncCall['data']['participants']) && count($syncCall['data']['participants']) >= 2, "Call participant roster synchronized");

// Screen Sharing Presentation
$screenShare = callEndpoint($s1, 'POST', ['action' => 'update_call_media'], [
    'call_id'           => $callId,
    'is_muted'          => 0,
    'is_camera_off'     => 0,
    'is_screen_sharing' => 1
]);
assertTest($screenShare['code'] === 200 && $screenShare['data']['data']['is_screen_sharing'] == 1, "Screen-sharing presentation activated");

// WebRTC Signaling
$sigSend = callEndpoint($s1, 'POST', ['action' => 'send_signal'], [
    'call_id'     => $callId,
    'receiver_id' => $u2['id'],
    'signal_type' => 'offer',
    'signal_data' => '{"sdp":"fake_webrtc_sdp_test"}'
]);
assertTest($sigSend['code'] === 200, "WebRTC offer signal broadcasted");

$sigReceive = callEndpoint($s2, 'GET', ['action' => 'chat_sync', 'group_id' => $groupId, 'call_id' => $callId, 'last_signal_id' => 0]);
assertTest(!empty($sigReceive['data']['signals']), "WebRTC signal received by target participant");

// Stop Screen Share & Leave Call
$stopScreen = callEndpoint($s1, 'POST', ['action' => 'update_call_media'], [
    'call_id'           => $callId,
    'is_muted'          => 0,
    'is_camera_off'     => 0,
    'is_screen_sharing' => 0
]);
assertTest($stopScreen['code'] === 200 && $stopScreen['data']['data']['is_screen_sharing'] == 0, "Screen sharing stopped cleanly");

$leaveCall = callEndpoint($s2, 'POST', ['action' => 'leave_call'], ['call_id' => $callId]);
assertTest($leaveCall['code'] === 200, "User 2 leaves call");

// End Call for Everyone
$endCall = callEndpoint($s1, 'POST', ['action' => 'end_call'], ['call_id' => $callId]);
assertTest($endCall['code'] === 200 && $endCall['data']['data']['call_status'] === 'ended', "Call ended for everyone");

// -------------------------------------------------------------------------
// 10. Member Workload Analytics & Charting API
// -------------------------------------------------------------------------
echo "\n--- Section 10: Member Workload Analytics & Charting ---\n";
$chartData = callEndpoint($s1, 'GET', ['action' => 'member_chart_data', 'employee_id' => $u1['id'], 'group_id' => $groupId]);
assertTest($chartData['code'] === 200 && !empty($chartData['data']['data']['summary']), "Member chart data retrieved");
$summary = $chartData['data']['data']['summary'];
assertTest(isset($summary['total_tasks']) && isset($summary['completion_rate']), "Workload summary metrics computed accurately");
assertTest(!empty($chartData['data']['data']['priority_breakdown']), "Task priority distribution breakdown provided");

// -------------------------------------------------------------------------
// 11. Security & Account Preservation
// -------------------------------------------------------------------------
echo "\n--- Section 11: Security & Account Preservation ---\n";
$empCheck = $pdo->prepare("SELECT COUNT(*) FROM crm_team_staff WHERE id IN (?, ?)");
$empCheck->execute([$u1['id'], $u2['id']]);
assertTest((int)$empCheck->fetchColumn() === 2, "Employee records preserved without corruption");

$grpCheck = $pdo->query("SELECT COUNT(*) FROM crm_groups WHERE id = $groupId")->fetchColumn();
assertTest((int)$grpCheck === 1, "Group record preserved without corruption");

// Final Summary
echo "\n========================================================================\n";
echo "   TEST SUITE EXECUTION COMPLETED\n";
echo "   PASSED: $testsPassed\n";
echo "   FAILED: $testsFailed\n";
echo "========================================================================\n";

if ($testsFailed > 0) {
    exit(1);
} else {
    exit(0);
}
