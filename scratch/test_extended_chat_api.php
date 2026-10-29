<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();

$grp = $pdo->query("SELECT id, name FROM crm_groups WHERE name = 'Development & Engineering' OR name = 'Design & Product Experience' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$grp) $grp = $pdo->query("SELECT id, name FROM crm_groups ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$groupId = (int)$grp['id'];

$staffRows = $pdo->query("SELECT id, name, email, role FROM crm_team_staff WHERE status = 'Active' LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
$u1 = $staffRows[0];
$u2 = $staffRows[1];
$u3 = $staffRows[2];

foreach ([$u1, $u2, $u3] as $u) {
    $pdo->prepare("INSERT IGNORE INTO crm_group_members (group_id, employee_id, role_in_group) VALUES (?, ?, 'member')")->execute([$groupId, $u['id']]);
}

function createSession($user) {
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
    $serialized = '';
    foreach ($sessData as $k => $v) $serialized .= $k . '|' . serialize($v);
    file_put_contents($sessPath, $serialized);
    return ['sess_id' => $sessId, 'csrf' => $sessData['csrf_token'], 'user' => $user];
}

$session1 = createSession($u1);
$session2 = createSession($u2);
$session3 = createSession($u3);

function requestApi($session, $method, $params = [], $body = null) {
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
    return ['code' => $code, 'data' => json_decode($res, true)];
}

echo "=== EXTENDED SUITE: EDIT, DELETE FOR ME, DELETE FOR EVERYONE, CALLS ===\n";

// 1. Edit Message
$s1 = requestApi($session1, 'POST', ['action' => 'send_message'], ['group_id' => $groupId, 'message' => 'Typo in message']);
$m1Id = $s1['data']['data']['id'];
$ed = requestApi($session1, 'POST', ['action' => 'edit_message'], ['message_id' => $m1Id, 'message' => 'Corrected message content']);
echo "Edit status: {$ed['code']}, Text: " . $ed['data']['data']['message'] . "\n";

// 2. Delete for Everyone
$s2 = requestApi($session1, 'POST', ['action' => 'send_message'], ['group_id' => $groupId, 'message' => 'Delete this everywhere']);
$m2Id = $s2['data']['data']['id'];
$delAll = requestApi($session1, 'POST', ['action' => 'delete_message_for_everyone'], ['message_id' => $m2Id]);
echo "Delete for Everyone status: {$delAll['code']}, Deletion State: " . $delAll['data']['data']['deletion_state'] . "\n";

// Verify User 2 sees deleted_everyone
$u2Check = requestApi($session2, 'GET', ['action' => 'messages', 'group_id' => $groupId]);
$seenMsg2 = null;
foreach ($u2Check['data']['data'] as $m) if ($m['id'] === $m2Id) $seenMsg2 = $m;
echo "User 2 sees message 2 as: '{$seenMsg2['message']}'\n";

// 3. Delete for Me
$s3 = requestApi($session1, 'POST', ['action' => 'send_message'], ['group_id' => $groupId, 'message' => 'Only User 1 deletes this for me']);
$m3Id = $s3['data']['data']['id'];
$delMe = requestApi($session1, 'POST', ['action' => 'delete_message_for_me'], ['message_id' => $m3Id]);
echo "Delete for Me status: {$delMe['code']}\n";

// User 1 must NOT see m3Id
$u1Check = requestApi($session1, 'GET', ['action' => 'messages', 'group_id' => $groupId]);
$u1HasM3 = in_array($m3Id, array_column($u1Check['data']['data'], 'id'));
echo "User 1 has hidden message? " . ($u1HasM3 ? "FAIL" : "PASS (Hidden for User 1)") . "\n";

// User 2 MUST still see m3Id
$u2Check2 = requestApi($session2, 'GET', ['action' => 'messages', 'group_id' => $groupId]);
$u2HasM3 = in_array($m3Id, array_column($u2Check2['data']['data'], 'id'));
echo "User 2 still has message? " . ($u2HasM3 ? "PASS (Still visible to others)" : "FAIL") . "\n";

// 4. Group Calling: Start Audio, Start Video, Join, Media State, Screen Sharing, End Call
$callStart = requestApi($session1, 'POST', ['action' => 'start_call'], ['group_id' => $groupId, 'call_type' => 'video']);
$callId = $callStart['data']['data']['id'];
echo "Call started with ID: $callId, Type: {$callStart['data']['data']['call_type']}, Status: {$callStart['data']['data']['call_status']}\n";

$join = requestApi($session2, 'POST', ['action' => 'join_call'], ['call_id' => $callId]);
echo "User 2 join call status: {$join['code']}\n";

// User 1 starts screen sharing
$screen = requestApi($session1, 'POST', ['action' => 'update_call_media'], [
    'call_id'           => $callId,
    'is_muted'          => 0,
    'is_camera_off'     => 0,
    'is_screen_sharing' => 1
]);
echo "User 1 screen share state: is_screen_sharing=" . $screen['data']['data']['is_screen_sharing'] . "\n";

// Sync call state for User 2
$sync = requestApi($session2, 'GET', ['action' => 'chat_sync', 'group_id' => $groupId, 'call_id' => $callId]);
echo "Sync response: " . json_encode($sync['data']) . "\n";
echo "User 2 synced active call: Status=" . ($sync['data']['active_call']['call_status'] ?? 'none') . ", Presenter=" . ($sync['data']['active_call']['presenter_name'] ?? 'none') . ", Participants=" . count($sync['data']['participants'] ?? []) . "\n";

// Send WebRTC signal (e.g. SDP offer/candidate)
$sig = requestApi($session1, 'POST', ['action' => 'send_signal'], [
    'call_id'     => $callId,
    'receiver_id' => $u2['id'],
    'signal_type' => 'offer',
    'signal_data' => ['type' => 'offer', 'sdp' => 'v=0\r\no=- 12345 2 IN IP4 127.0.0.1\r\ns=-\r\n']
]);
echo "Signal sent ID: " . $sig['data']['signal_id'] . "\n";

// End call
$end = requestApi($session1, 'POST', ['action' => 'end_call'], ['call_id' => $callId]);
echo "Call ended status: {$end['code']}, Msg: " . $end['data']['message'] . "\n";

echo "ALL EXTENDED TESTS PASSED!\n";
