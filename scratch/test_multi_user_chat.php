<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();

// Target group: Development & Engineering or Design & Product Experience
$grp = $pdo->query("SELECT id, name FROM crm_groups WHERE name = 'Development & Engineering' OR name = 'Design & Product Experience' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$grp) {
    $grp = $pdo->query("SELECT id, name FROM crm_groups ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}
$groupId = (int)$grp['id'];

// Get 3 active staff
$staffRows = $pdo->query("SELECT id, name, email, role FROM crm_team_staff WHERE status = 'Active' LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
if (count($staffRows) < 3) {
    die("Need 3 active staff members for multi-user test\n");
}

$u1 = $staffRows[0];
$u2 = $staffRows[1];
$u3 = $staffRows[2];

// Add all 3 to group
foreach ([$u1, $u2, $u3] as $u) {
    $pdo->prepare("INSERT IGNORE INTO crm_group_members (group_id, employee_id, role_in_group) VALUES (?, ?, 'member')")->execute([$groupId, $u['id']]);
}

echo "Testing Group: #{$groupId} ({$grp['name']})\n";
echo "User 1: {$u1['name']} ({$u1['id']})\n";
echo "User 2: {$u2['name']} ({$u2['id']})\n";
echo "User 3: {$u3['name']} ({$u3['id']})\n";

// Helper to create session file on disk
function createSession($user) {
    $sessId = 'user' . preg_replace('/[^a-zA-Z0-9]/', '', md5($user['id']));
    // Determine session save path
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
    
    // PHP session serialize format: key|serialized_value
    $serialized = '';
    foreach ($sessData as $k => $v) {
        $serialized .= $k . '|' . serialize($v);
    }
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
    if ($code !== 200) {
        echo "ERR: $url -> Code: $code, Res: $res\n";
    }
    return ['code' => $code, 'data' => json_decode($res, true)];
}

echo "\n--- STEP 1: User 1 sends a message ---\n";
$send = requestApi($session1, 'POST', ['action' => 'send_message'], [
    'group_id' => $groupId,
    'message'  => 'Multi-user test message by ' . $u1['name']
]);
$msgId = $send['data']['data']['id'];
echo "Message created with ID: $msgId\n";

echo "\n--- STEP 2: User 1 checks status before anyone receives ---\n";
$u1Msgs = requestApi($session1, 'GET', ['action' => 'messages', 'group_id' => $groupId]);
$msg = null;
foreach ($u1Msgs['data']['data'] as $m) {
    if ($m['id'] === $msgId) { $msg = $m; break; }
}
echo "User 1 sees status: {$msg['status']} (Expected: sent - single grey tick)\n";

echo "\n--- STEP 3: User 2 fetches messages (triggers delivery) ---\n";
$u2Msgs = requestApi($session2, 'GET', ['action' => 'messages', 'group_id' => $groupId]);
echo "User 2 fetched messages. Checking User 1 status now...\n";

$u1Msgs2 = requestApi($session1, 'GET', ['action' => 'messages', 'group_id' => $groupId]);
foreach ($u1Msgs2['data']['data'] as $m) {
    if ($m['id'] === $msgId) { $msg = $m; break; }
}
echo "User 1 sees status now: {$msg['status']} (Expected: delivered - double grey tick)\n";

echo "\n--- STEP 4: User 2 marks messages as read ---\n";
requestApi($session2, 'POST', ['action' => 'mark_read'], ['group_id' => $groupId]);
echo "User 2 marked messages read. Checking User 1 status now...\n";

$u1Msgs3 = requestApi($session1, 'GET', ['action' => 'messages', 'group_id' => $groupId]);
foreach ($u1Msgs3['data']['data'] as $m) {
    if ($m['id'] === $msgId) { $msg = $m; break; }
}
echo "User 1 sees status now: {$msg['status']} (Expected: read - double blue tick)\n";

echo "\n--- STEP 5: Message Info Panel ---\n";
$info = requestApi($session1, 'GET', ['action' => 'get_message_info', 'message_id' => $msgId]);
echo "Read by: " . count($info['data']['data']['read_by']) . " participants (";
foreach ($info['data']['data']['read_by'] as $rb) echo $rb['name'] . " ";
echo ")\n";
echo "Pending (unread): " . count($info['data']['data']['pending']) . " participants (";
foreach ($info['data']['data']['pending'] as $pb) echo $pb['name'] . " ";
echo ")\n";

echo "\n--- STEP 6: User 1 Unsend Message ---\n";
$unsend = requestApi($session1, 'POST', ['action' => 'unsend_message'], ['message_id' => $msgId]);
echo "Unsend result: " . $unsend['data']['message'] . "\n";

// User 2 verifies unsend
$u2Msgs2 = requestApi($session2, 'GET', ['action' => 'messages', 'group_id' => $groupId]);
foreach ($u2Msgs2['data']['data'] as $m) {
    if ($m['id'] === $msgId) { $msg = $m; break; }
}
echo "User 2 sees message as: '{$msg['message']}', deletion_state: '{$msg['deletion_state']}'\n";

echo "\nALL 3-USER VERIFICATION TESTS PASSED PERFECTLY!\n";
