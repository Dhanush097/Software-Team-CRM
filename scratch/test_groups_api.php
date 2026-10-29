<?php
/**
 * Test Suite for Groups API
 */
session_start();
$_SESSION['auth_user'] = [
    'id' => 1,
    'name' => 'Suresh Kumar',
    'email' => 'suresh@softwarecrm.com',
    'role' => 'admin'
];
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$sessName = session_name();
$sessId = session_id();
session_write_close(); // Release session file lock before making HTTP requests!

function makeRequest($method, $url, $data = [], $csrf = '') {
    global $sessName, $sessId;
    $ch = curl_init();
    $headers = [
        'Content-Type: application/json',
        'X-CSRF-Token: ' . $csrf,
        'Cookie: ' . $sessName . '=' . $sessId
    ];

    if ($method === 'GET' && !empty($data)) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($data);
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    if ($method === 'POST' || $method === 'DELETE' || $method === 'PUT') {
        if (!isset($data['csrf_token']) && $csrf) {
            $data['csrf_token'] = $csrf;
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'body' => json_decode($resp, true), 'raw' => $resp];
}

$baseUrl = 'http://127.0.0.1:8000/api/groups.php';
$testsPassed = 0;
$testsFailed = 0;

function assertTest($name, $condition, $details = '') {
    global $testsPassed, $testsFailed;
    if ($condition) {
        echo " [PASS] $name\n";
        $testsPassed++;
    } else {
        echo " [FAIL] $name - $details\n";
        $testsFailed++;
    }
}

echo "=== STARTING GROUPS API TESTS ===\n\n";

// 1. Initial Summary
$res = makeRequest('GET', $baseUrl, ['action' => 'summary'], $csrf);
assertTest("GET summary returns 200", $res['code'] === 200, "Code was " . $res['code'] . " raw: " . $res['raw']);
assertTest("Summary contains total_active_members >= 10", ($res['body']['data']['total_active_members'] ?? 0) >= 10, "Count: " . ($res['body']['data']['total_active_members'] ?? 'none'));

// 2. Available Employees
$res = makeRequest('GET', $baseUrl, ['action' => 'available_employees'], $csrf);
assertTest("GET available_employees returns active employees", !empty($res['body']['data']), "Data: " . json_encode($res['body']));
$firstStaff = $res['body']['data'][0] ?? null;
$secondStaff = $res['body']['data'][1] ?? null;
assertTest("Employee has id, name, role, status", !empty($firstStaff['id']) && !empty($firstStaff['name']) && !empty($firstStaff['role']), "Missing keys in staff record");

// 3. Create Group
$testGroupName = 'Engineering Core Test ' . time();
$createPayload = [
    'action' => 'create',
    'name' => $testGroupName,
    'description' => 'Cross-functional engineering and architecture team',
    'leader_id' => $firstStaff['id'],
    'color' => '#10b981',
    'icon' => 'code',
    'member_ids' => [$firstStaff['id'], $secondStaff['id']]
];
$res = makeRequest('POST', $baseUrl, $createPayload, $csrf);
assertTest("Create group returns 201", $res['code'] === 201, "Code: " . $res['code'] . " - " . ($res['body']['message'] ?? '') . " raw: " . $res['raw']);
$createdGroup = $res['body']['data'] ?? null;
$createdGroupId = $createdGroup['id'] ?? null;
assertTest("Created group has leader and members", !empty($createdGroup['leader']) && count($createdGroup['members']) >= 2, "Members count: " . count($createdGroup['members'] ?? []));
assertTest("Visual chart tree contains leader and member children", !empty($createdGroup['tree']['leader']) && count($createdGroup['tree']['members']) >= 1, "Tree: " . json_encode($createdGroup['tree'] ?? []));

// 4. Duplicate Group Name Validation
$res = makeRequest('POST', $baseUrl, $createPayload, $csrf);
assertTest("Duplicate group creation is rejected with 400", $res['code'] === 400, "Code: " . $res['code']);
assertTest("Error message indicates duplicate name", strpos($res['body']['message'] ?? '', 'already exists') !== false, "Msg: " . ($res['body']['message'] ?? ''));

// 5. Empty Group Name Validation
$res = makeRequest('POST', $baseUrl, ['action' => 'create', 'name' => '   '], $csrf);
assertTest("Empty group name is rejected with 400", $res['code'] === 400, "Code: " . $res['code']);

// 6. List Groups
$res = makeRequest('GET', $baseUrl, [], $csrf);
assertTest("GET list includes newly created group", array_search($createdGroupId, array_column($res['body']['data'] ?? [], 'id')) !== false, "Group ID not found in list");

// 7. Get Group by ID
$res = makeRequest('GET', $baseUrl, ['id' => $createdGroupId], $csrf);
assertTest("GET group by ID returns 200", $res['code'] === 200, "Code: " . $res['code']);
assertTest("Group data matches name", ($res['body']['data']['name'] ?? '') === $testGroupName, "Name: " . ($res['body']['data']['name'] ?? ''));

// 8. Add Members to Group
$allStaffRes = makeRequest('GET', $baseUrl, ['action' => 'available_employees', 'group_id' => $createdGroupId], $csrf);
$unaddedStaff = array_values(array_filter($allStaffRes['body']['data'], fn($s) => empty($s['already_in_group'])));
assertTest("Found unadded staff for group", !empty($unaddedStaff), "No unadded staff found");

if (!empty($unaddedStaff)) {
    $toAdd = $unaddedStaff[0]['id'];
    $res = makeRequest('POST', $baseUrl, [
        'action' => 'add_members',
        'group_id' => $createdGroupId,
        'employee_ids' => [$toAdd]
    ], $csrf);
    assertTest("Add member returns success", $res['code'] === 200 && ($res['body']['added_count'] ?? 0) === 1, "Added count: " . ($res['body']['added_count'] ?? '0'));
    
    // Test duplicate add prevention
    $resDup = makeRequest('POST', $baseUrl, [
        'action' => 'add_members',
        'group_id' => $createdGroupId,
        'employee_ids' => [$toAdd]
    ], $csrf);
    assertTest("Duplicate add handles gracefully with 0 added", ($resDup['body']['added_count'] ?? 0) === 0, "Duplicate added count: " . ($resDup['body']['added_count'] ?? ''));
}

// 9. Update Group Details
$res = makeRequest('POST', $baseUrl, [
    'action' => 'update',
    'id' => $createdGroupId,
    'name' => $testGroupName . ' Updated',
    'description' => 'Updated description for testing',
    'color' => '#6366f1',
    'icon' => 'sparkles'
], $csrf);
assertTest("Update group returns success", $res['code'] === 200 && ($res['body']['data']['name'] ?? '') === ($testGroupName . ' Updated'), "Name: " . ($res['body']['data']['name'] ?? ''));

// 10. Change Group Leader
$res = makeRequest('POST', $baseUrl, [
    'action' => 'change_leader',
    'group_id' => $createdGroupId,
    'leader_id' => $secondStaff['id']
], $csrf);
assertTest("Change leader updates leader_id", ($res['body']['data']['leader_id'] ?? '') === $secondStaff['id'], "Leader ID: " . ($res['body']['data']['leader_id'] ?? ''));

// 11. Remove Member from Group (Preserves CRM account)
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();
$staffCountBefore = $pdo->query("SELECT COUNT(*) FROM crm_team_staff")->fetchColumn();

$res = makeRequest('POST', $baseUrl, [
    'action' => 'remove_member',
    'group_id' => $createdGroupId,
    'employee_id' => $firstStaff['id']
], $csrf);
assertTest("Remove member returns success", $res['code'] === 200, "Code: " . $res['code']);

$staffCountAfter = $pdo->query("SELECT COUNT(*) FROM crm_team_staff")->fetchColumn();
assertTest("CRITICAL: Team staff record preserved after member removal", $staffCountBefore === $staffCountAfter, "Before: $staffCountBefore, After: $staffCountAfter");

// 12. Delete Group (Preserves CRM account)
$res = makeRequest('POST', $baseUrl, [
    'action' => 'delete',
    'id' => $createdGroupId
], $csrf);
assertTest("Delete group returns success", $res['code'] === 200, "Code: " . $res['code']);

$staffCountAfterDelete = $pdo->query("SELECT COUNT(*) FROM crm_team_staff")->fetchColumn();
assertTest("CRITICAL: Team staff records preserved after group deletion", $staffCountBefore === $staffCountAfterDelete, "Before: $staffCountBefore, After: $staffCountAfterDelete");

echo "\n=== SUMMARY: $testsPassed PASSED, $testsFailed FAILED ===\n";
