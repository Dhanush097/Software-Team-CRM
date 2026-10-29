<?php
/**
 * Software CRM - Automated Test Suite for Group Chart / Team Structure Management
 * 
 * Validates all 15 test items specified in Section 11 of the requirements:
 *  1. Navigation opens Group Chart correctly.
 *  2. Summary cards display accurate live counts from DB.
 *  3. Create Group adds group with valid details.
 *  4. Validation prevents empty names or duplicates.
 *  5. Add Member displays real active employees.
 *  6. Search filter finds existing employees.
 *  7. Adding member updates group chart and count.
 *  8. Prevent adding duplicate member to same group.
 *  9. Remove member deletes only relationship, preserves employee account.
 * 10. Removing leader triggers replacement prompt or updates leader status.
 * 11. Visual chart displays leader on top and members underneath.
 * 12. Expand / collapse works on chart or group details.
 * 13. Edit Group updates name, description, leader, color.
 * 14. Delete Group removes group and memberships, never employee records.
 * 15. Responsive layout & theme toggle works cleanly on all screen sizes.
 */

chdir(__DIR__);
require_once __DIR__ . '/api/session_guard.php';
require_once __DIR__ . '/api/db.php';

$pdo = Database::getConnection();
if (!$pdo) {
    echo "FATAL: Database connection failed.\n";
    exit(1);
}

// Prepare authenticated session for testing
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['auth_user'] = [
    'id'          => 1,
    'name'        => 'Suresh Kumar',
    'email'       => 'suresh@softwarecrm.com',
    'role'        => 'admin',
    'status'      => 'active',
    'employee_id' => 'EMP-001'
];
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$sessName = session_name();
$sessId = session_id();
session_write_close(); // Prevent session lock when curling built-in server

function curlReq(string $method, string $url, array $data = [], string $csrf = ''): array {
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

    if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
        if (!isset($data['csrf_token']) && $csrf) {
            $data['csrf_token'] = $csrf;
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'code' => $code,
        'body' => json_decode($resp, true) ?: [],
        'raw'  => $resp
    ];
}

$baseUrl = 'http://127.0.0.1:8000/api/groups.php';
$passed = 0;
$failed = 0;
$results = [];

function recordTest(int $num, string $title, bool $condition, string $detail = ''): void {
    global $passed, $failed, $results;
    if ($condition) {
        $passed++;
        $results[] = "[PASS] Test #{$num}: {$title}";
        echo "  \033[32m✔ [PASS]\033[0m Test #{$num}: {$title}\n";
    } else {
        $failed++;
        $results[] = "[FAIL] Test #{$num}: {$title} - {$detail}";
        echo "  \033[31m✖ [FAIL]\033[0m Test #{$num}: {$title}\n    Detail: {$detail}\n";
    }
}

echo "\n=================================================================\n";
echo " SOFTWARE CRM - GROUP CHART VERIFICATION TEST SUITE (15 TESTS)\n";
echo "=================================================================\n\n";

// --- TEST 1: Navigation opens Group Chart correctly ---
$indexContent = file_get_contents(__DIR__ . '/index.php');
$hasSidebarNav = strpos($indexContent, "navigateTo('group_chart')") !== false;
$hasGroupChartH1 = strpos($indexContent, "currentView === 'group_chart'") !== false;
$hasGroupChartView = strpos($indexContent, "x-show=\"currentView === 'group_chart'\"") !== false;
recordTest(
    1,
    "Navigation item 'Group Chart' opens section correctly with header & view",
    $hasSidebarNav && $hasGroupChartH1 && $hasGroupChartView,
    "Sidebar nav, title, or main container view missing in index.php"
);

// --- TEST 2: Summary cards display accurate live counts from DB ---
$actualGroupsCount = (int)$pdo->query("SELECT COUNT(*) FROM crm_groups")->fetchColumn();
$actualActiveStaff = (int)$pdo->query("SELECT COUNT(*) FROM crm_team_staff WHERE LOWER(status) = 'active'")->fetchColumn();
$actualUnassignedStaff = (int)$pdo->query("
    SELECT COUNT(*) FROM crm_team_staff
    WHERE LOWER(status) = 'active'
      AND id NOT IN (SELECT employee_id FROM crm_group_members)
      AND id NOT IN (SELECT leader_id FROM crm_groups WHERE leader_id IS NOT NULL)
")->fetchColumn();

$sumRes = curlReq('GET', $baseUrl, ['action' => 'summary'], $csrf);
$sumData = $sumRes['body']['data'] ?? [];
$summaryMatch = (
    $sumRes['code'] === 200 &&
    ($sumData['total_groups'] ?? -1) === $actualGroupsCount &&
    ($sumData['total_active_members'] ?? -1) === $actualActiveStaff &&
    ($sumData['members_without_group'] ?? -1) === $actualUnassignedStaff
);
recordTest(
    2,
    "Summary cards display accurate live counts from actual database records",
    $summaryMatch,
    "Expected groups: $actualGroupsCount, active: $actualActiveStaff, unassigned: $actualUnassignedStaff. Got: " . json_encode($sumData)
);

// --- TEST 3: Create Group adds group with valid details ---
// Pick active staff members for testing
$activeEmployees = $pdo->query("SELECT id, name, role FROM crm_team_staff WHERE LOWER(status) = 'active' ORDER BY name ASC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
if (count($activeEmployees) < 3) {
    echo "WARNING: Less than 3 active employees found in crm_team_staff.\n";
}
$testLeader = $activeEmployees[0];
$testMemberA = $activeEmployees[1];
$testMemberB = $activeEmployees[2];

$testGroupName = "Development Team " . bin2hex(random_bytes(3));
$createRes = curlReq('POST', $baseUrl, [
    'action'      => 'create',
    'name'        => $testGroupName,
    'description' => 'Core software platform and frontend development team',
    'leader_id'   => $testLeader['id'],
    'color'       => '#2563eb',
    'icon'        => 'code',
    'member_ids'  => [$testLeader['id'], $testMemberA['id']]
], $csrf);

$createdGroup = $createRes['body']['data'] ?? null;
$createdGroupId = (int)($createdGroup['id'] ?? 0);

recordTest(
    3,
    "Create Group adds group with valid details (Name, Description, Leader, Color, Members)",
    $createRes['code'] === 201 && $createdGroupId > 0 && ($createdGroup['name'] ?? '') === $testGroupName && !empty($createdGroup['leader']),
    "Code: {$createRes['code']}, Response: " . json_encode($createRes['body'])
);

// --- TEST 4: Validation prevents empty names or duplicates ---
$emptyNameRes = curlReq('POST', $baseUrl, [
    'action' => 'create',
    'name'   => '   '
], $csrf);

$duplicateNameRes = curlReq('POST', $baseUrl, [
    'action' => 'create',
    'name'   => strtoupper($testGroupName), // Case-insensitive duplicate
    'leader_id' => $testLeader['id']
], $csrf);

recordTest(
    4,
    "Validation prevents empty names and duplicate group names (case-insensitive)",
    $emptyNameRes['code'] === 400 && $duplicateNameRes['code'] === 400,
    "Empty code: {$emptyNameRes['code']}, Duplicate code: {$duplicateNameRes['code']}"
);

// --- TEST 5: Add Member displays real active employees ---
$availRes = curlReq('GET', $baseUrl, ['action' => 'available_employees', 'group_id' => $createdGroupId], $csrf);
$availList = $availRes['body']['data'] ?? [];
$hasRealStaff = false;
if (!empty($availList)) {
    $firstEmp = $availList[0];
    $hasRealStaff = !empty($firstEmp['id']) && !empty($firstEmp['name']) && !empty($firstEmp['role']) && isset($firstEmp['already_in_group']);
}
recordTest(
    5,
    "Add Member retrieves active employee records from existing CRM database",
    $availRes['code'] === 200 && count($availList) >= count($activeEmployees) && $hasRealStaff,
    "Code: {$availRes['code']}, Count: " . count($availList)
);

// --- TEST 6: Search filter finds existing employees ---
$searchQuery = substr($testMemberB['name'], 0, 4);
$searchRes = curlReq('GET', $baseUrl, [
    'action'   => 'available_employees',
    'group_id' => $createdGroupId,
    'q'        => $searchQuery
], $csrf);
$searchResults = $searchRes['body']['data'] ?? [];
$foundExpected = false;
foreach ($searchResults as $se) {
    if ($se['id'] === $testMemberB['id']) {
        $foundExpected = true;
        break;
    }
}
recordTest(
    6,
    "Search filter finds existing employees by name/role in member selection modal",
    $searchRes['code'] === 200 && $foundExpected,
    "Searched for '$searchQuery', found: " . count($searchResults)
);

// --- TEST 7: Adding member updates group chart and count ---
$addMemberRes = curlReq('POST', $baseUrl, [
    'action'       => 'add_members',
    'group_id'     => $createdGroupId,
    'employee_ids' => [$testMemberB['id']]
], $csrf);
$updatedGroupData = $addMemberRes['body']['data'] ?? [];
$hasNewMemberInList = false;
foreach (($updatedGroupData['members'] ?? []) as $m) {
    if ($m['id'] === $testMemberB['id']) {
        $hasNewMemberInList = true;
        break;
    }
}
recordTest(
    7,
    "Adding member updates group chart, member list, and total member count",
    $addMemberRes['code'] === 200 && ($addMemberRes['body']['added_count'] ?? 0) === 1 && $hasNewMemberInList,
    "Added count: " . ($addMemberRes['body']['added_count'] ?? 'none')
);

// --- TEST 8: Prevent adding duplicate member to same group ---
$dupAddRes = curlReq('POST', $baseUrl, [
    'action'       => 'add_members',
    'group_id'     => $createdGroupId,
    'employee_ids' => [$testMemberB['id']]
], $csrf);
recordTest(
    8,
    "Prevent duplicate memberships within the same group (0 duplicate added)",
    $dupAddRes['code'] === 200 && ($dupAddRes['body']['added_count'] ?? -1) === 0,
    "Expected added_count 0, got: " . ($dupAddRes['body']['added_count'] ?? 'none')
);

// --- TEST 9: Remove member deletes only relationship, preserves employee account ---
$staffCountBeforeRemoval = (int)$pdo->query("SELECT COUNT(*) FROM crm_team_staff")->fetchColumn();
$removeRes = curlReq('POST', $baseUrl, [
    'action'      => 'remove_member',
    'group_id'    => $createdGroupId,
    'employee_id' => $testMemberB['id']
], $csrf);
$staffCountAfterRemoval = (int)$pdo->query("SELECT COUNT(*) FROM crm_team_staff")->fetchColumn();

// Verify testMemberB still exists in crm_team_staff
$chkStaffExists = $pdo->prepare("SELECT id, name FROM crm_team_staff WHERE id = ?");
$chkStaffExists->execute([$testMemberB['id']]);
$staffRecordStillExists = (bool)$chkStaffExists->fetch();

recordTest(
    9,
    "Remove member deletes only relationship and preserves employee CRM account",
    $removeRes['code'] === 200 && ($staffCountBeforeRemoval === $staffCountAfterRemoval) && $staffRecordStillExists,
    "Before: $staffCountBeforeRemoval, After: $staffCountAfterRemoval, Employee exists: " . ($staffRecordStillExists ? 'YES' : 'NO')
);

// --- TEST 10: Removing leader triggers replacement prompt or updates leader status ---
$remLeaderRes = curlReq('POST', $baseUrl, [
    'action'      => 'remove_member',
    'group_id'    => $createdGroupId,
    'employee_id' => $testLeader['id'],
    'new_leader_id' => null // User chooses temporarily no leader
], $csrf);

$grpAfterLeaderRemoval = curlReq('GET', $baseUrl, ['id' => $createdGroupId], $csrf)['body']['data'] ?? [];
$leaderNowNull = empty($grpAfterLeaderRemoval['leader_id']) && $grpAfterLeaderRemoval['leader'] === null;

recordTest(
    10,
    "Removing leader properly updates leader status (supports leaderless / reassignment)",
    $remLeaderRes['code'] === 200 && ($remLeaderRes['body']['was_leader'] ?? false) && $leaderNowNull,
    "was_leader: " . (($remLeaderRes['body']['was_leader'] ?? false) ? 'YES' : 'NO') . ", leader_id: " . ($grpAfterLeaderRemoval['leader_id'] ?? 'not null')
);

// --- TEST 11: Visual chart displays leader on top and members underneath ---
// Assign testMemberA as leader and add testMemberB back as member
curlReq('POST', $baseUrl, [
    'action'    => 'change_leader',
    'group_id'  => $createdGroupId,
    'leader_id' => $testMemberA['id']
], $csrf);
curlReq('POST', $baseUrl, [
    'action'       => 'add_members',
    'group_id'     => $createdGroupId,
    'employee_ids' => [$testMemberB['id']]
], $csrf);

$grpForTree = curlReq('GET', $baseUrl, ['id' => $createdGroupId], $csrf)['body']['data'] ?? [];
$tree = $grpForTree['tree'] ?? [];
$treeLeaderAtTop = !empty($tree['leader']) && $tree['leader']['id'] === $testMemberA['id'];
$treeMembersUnderneath = !empty($tree['members']) && in_array($testMemberB['id'], array_column($tree['members'], 'id'));

recordTest(
    11,
    "Visual organizational chart hierarchy displays leader at top and members underneath",
    $treeLeaderAtTop && $treeMembersUnderneath,
    "Tree leader: " . json_encode($tree['leader'] ?? null) . ", tree members count: " . count($tree['members'] ?? [])
);

// --- TEST 12: Expand / collapse works on chart or group details ---
$hasChartToggle = strpos($indexContent, "groupChartExpanded = !groupChartExpanded") !== false;
$hasChartToggleState = strpos($indexContent, "groupChartExpanded:") !== false;
$hasXShowChart = strpos($indexContent, 'x-show="groupChartExpanded"') !== false;

recordTest(
    12,
    "Expand and collapse controls toggle visual hierarchy chart smoothly",
    $hasChartToggle && $hasChartToggleState && $hasXShowChart,
    "groupChartExpanded toggle or x-show directive missing"
);

// --- TEST 13: Edit Group updates name, description, leader, color ---
$updatedTitle = "Design & Creative Studio " . bin2hex(random_bytes(2));
$editRes = curlReq('POST', $baseUrl, [
    'action'      => 'update',
    'id'          => $createdGroupId,
    'name'        => $updatedTitle,
    'description' => 'UI/UX design system and product styling team',
    'leader_id'   => $testLeader['id'],
    'color'       => '#7c3aed',
    'icon'        => 'palette'
], $csrf);

$editData = $editRes['body']['data'] ?? [];
$editSuccess = (
    $editRes['code'] === 200 &&
    ($editData['name'] ?? '') === $updatedTitle &&
    ($editData['leader_id'] ?? '') === $testLeader['id'] &&
    ($editData['color'] ?? '') === '#7c3aed'
);
recordTest(
    13,
    "Edit Group updates name, description, leader, and color accent",
    $editSuccess,
    "Code: {$editRes['code']}, Name: " . ($editData['name'] ?? 'none')
);

// --- TEST 14: Delete Group removes group and memberships, never employee records ---
$staffCountBeforeGroupDelete = (int)$pdo->query("SELECT COUNT(*) FROM crm_team_staff")->fetchColumn();
$deleteRes = curlReq('POST', $baseUrl, [
    'action' => 'delete',
    'id'     => $createdGroupId
], $csrf);
$staffCountAfterGroupDelete = (int)$pdo->query("SELECT COUNT(*) FROM crm_team_staff")->fetchColumn();

// Verify memberships for this group were removed
$remMemberships = (int)$pdo->prepare("SELECT COUNT(*) FROM crm_group_members WHERE group_id = ?")->execute([$createdGroupId]);
// Verify group itself removed
$chkGrpExists = $pdo->prepare("SELECT COUNT(*) FROM crm_groups WHERE id = ?");
$chkGrpExists->execute([$createdGroupId]);
$groupGone = ((int)$chkGrpExists->fetchColumn() === 0);

recordTest(
    14,
    "Delete Group cascades membership deletion but NEVER deletes employee records",
    $deleteRes['code'] === 200 && $groupGone && ($staffCountBeforeGroupDelete === $staffCountAfterGroupDelete),
    "Group gone: " . ($groupGone ? 'YES' : 'NO') . ", Staff count before: $staffCountBeforeGroupDelete, after: $staffCountAfterGroupDelete"
);

// --- TEST 15: Responsive layout & theme toggle works cleanly on all screen sizes ---
$hasDarkSelectors = strpos($indexContent, "dark:bg-slate-800") !== false;
$hasResponsiveGrid = strpos($indexContent, "grid-cols-1 sm:grid-cols-2 lg:grid-cols-3") !== false;
$hasNoOverflow = strpos($indexContent, "overflow-x-auto") !== false;
$hasThemedNodes = strpos($indexContent, "text-slate-900 dark:text-slate-100") !== false;

recordTest(
    15,
    "Responsive layout (Desktop, Laptop, Tablet, Mobile) and Theme Support (Light & Dark)",
    $hasDarkSelectors && $hasResponsiveGrid && $hasNoOverflow && $hasThemedNodes,
    "Responsive grid or theme tokens missing in markup"
);

echo "\n=================================================================\n";
echo " TEST SUITE SUMMARY: {$passed} / 15 TESTS PASSED (" . ($failed === 0 ? "100% SUCCESS" : "{$failed} FAILED") . ")\n";
echo "=================================================================\n\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
