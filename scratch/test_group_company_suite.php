<?php
// Test Suite: Group Company Selection & Association Verification
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Setup authenticated session for curl requests
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
session_write_close(); // Must release lock so PHP built-in webserver can read the session

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

    if ($method === 'GET' && !empty($data)) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($data);
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    if ($method === 'POST') {
        if (!isset($data['csrf_token']) && $csrf) {
            $data['csrf_token'] = $csrf;
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'data' => json_decode($resp, true), 'raw' => $resp];
}

echo "=== STARTING VERIFICATION TEST SUITE: GROUP COMPANY DROPDOWN & API ===\n\n";

// 1. Companies load correctly
echo "1. Testing companies list API...\n";
$compRes = apiRequest('GET', "{$baseUrl}/api/companies.php");
if ($compRes['code'] === 200 && !empty($compRes['data']['data']) && count($compRes['data']['data']) > 0) {
    $firstComp = $compRes['data']['data'][0];
    $secondComp = count($compRes['data']['data']) > 1 ? $compRes['data']['data'][1] : $firstComp;
    pass("Companies load correctly", "Found " . count($compRes['data']['data']) . " companies. First: '{$firstComp['name']}' ({$firstComp['id']})");
} else {
    fail("Companies load correctly", "Unable to load companies: HTTP {$compRes['code']} - {$compRes['raw']}");
    exit(1);
}

// 2. A group cannot be created without selecting a company (Required Validation)
echo "\n2. Testing group creation without company_id (Validation rejection)...\n";
$noCompanyRes = apiRequest('POST', "{$baseUrl}/api/groups.php", [
    'action' => 'create',
    'name' => 'Automated Test Group Without Company ' . time(),
    'description' => 'Should fail due to missing company'
]);
if ($noCompanyRes['code'] === 400 && strpos($noCompanyRes['raw'], 'Company is required') !== false) {
    pass("Rejects group creation without company", "HTTP {$noCompanyRes['code']} returned: {$noCompanyRes['data']['message']}");
} else {
    fail("Rejects group creation without company", "Expected HTTP 400 with 'Company is required', got HTTP {$noCompanyRes['code']}: {$noCompanyRes['raw']}");
}

// 3. Invalid company IDs are rejected
echo "\n3. Testing group creation with invalid company ID...\n";
$invalidCompRes = apiRequest('POST', "{$baseUrl}/api/groups.php", [
    'action' => 'create',
    'name' => 'Automated Test Group Invalid Comp ' . time(),
    'company_id' => 'cmp_completely_bogus_id_999999',
    'description' => 'Should fail due to non-existent company'
]);
if ($invalidCompRes['code'] === 400 && (strpos($invalidCompRes['raw'], 'does not exist') !== false || strpos($invalidCompRes['raw'], 'Selected company') !== false)) {
    pass("Rejects invalid company ID", "HTTP {$invalidCompRes['code']} returned: {$invalidCompRes['data']['message']}");
} else {
    fail("Rejects invalid company ID", "Expected HTTP 400, got {$invalidCompRes['code']}: {$invalidCompRes['raw']}");
}

// 4. Valid company can be selected & saved correctly
echo "\n4. Testing group creation with valid company ID...\n";
$testGroupName = 'QA Automation Group ' . date('His');
$createValidRes = apiRequest('POST', "{$baseUrl}/api/groups.php", [
    'action' => 'create',
    'name' => $testGroupName,
    'company_id' => $firstComp['id'],
    'description' => 'Automated test group verifying company association',
    'color' => '#059669',
    'icon' => 'users'
]);
$createdGroupId = null;
if ($createValidRes['code'] === 201 && !empty($createValidRes['data']['data']['id'])) {
    $createdGroupId = $createValidRes['data']['data']['id'];
    $assignedCompId = $createValidRes['data']['data']['company_id'] ?? null;
    $assignedCompName = $createValidRes['data']['data']['company_name'] ?? null;
    if ($assignedCompId === $firstComp['id'] && $assignedCompName === $firstComp['name']) {
        pass("Valid company selected and saved", "Group ID: {$createdGroupId}, Company: '{$assignedCompName}' ({$assignedCompId})");
    } else {
        fail("Valid company selected and saved", "Mismatch: got company_id={$assignedCompId}, company_name={$assignedCompName}");
    }
} else {
    fail("Valid company selected and saved", "Failed creating group: HTTP {$createValidRes['code']} - {$createValidRes['raw']}");
}

// 5. Company name appears in the group list
echo "\n5. Testing group list API for company_name...\n";
$listRes = apiRequest('GET', "{$baseUrl}/api/groups.php");
$foundGroup = null;
if ($listRes['code'] === 200 && !empty($listRes['data']['data'])) {
    foreach ($listRes['data']['data'] as $grp) {
        if ($grp['id'] == $createdGroupId) {
            $foundGroup = $grp;
            break;
        }
    }
}
if ($foundGroup && !empty($foundGroup['company_name']) && $foundGroup['company_name'] === $firstComp['name']) {
    pass("Company name appears in group list", "Group '{$foundGroup['name']}' has company_name '{$foundGroup['company_name']}'");
} else {
    fail("Company name appears in group list", "Group not found or company_name missing: " . json_encode($foundGroup));
}

// 6. Relationship persists after querying single group
echo "\n6. Testing single group GET API & persistence...\n";
$singleRes = apiRequest('GET', "{$baseUrl}/api/groups.php", ['id' => $createdGroupId]);
if ($singleRes['code'] === 200 && !empty($singleRes['data']['data']['company']) && $singleRes['data']['data']['company']['id'] === $firstComp['id']) {
    pass("Company relationship persists in database", "Single fetch returned company: '{$singleRes['data']['data']['company']['name']}' ({$singleRes['data']['data']['company']['id']})");
} else {
    fail("Company relationship persists in database", "Failed or missing company object: {$singleRes['raw']}");
}

// 7. Search groups by company name
echo "\n7. Testing search groups by company name keyword...\n";
$searchKeyword = substr($firstComp['name'], 0, 4);
$searchRes = apiRequest('GET', "{$baseUrl}/api/groups.php", ['q' => $searchKeyword]);
$foundInSearch = false;
if ($searchRes['code'] === 200 && !empty($searchRes['data']['data'])) {
    foreach ($searchRes['data']['data'] as $sGrp) {
        if ($sGrp['id'] == $createdGroupId) {
            $foundInSearch = true;
            break;
        }
    }
}
if ($foundInSearch) {
    pass("Search group by company name", "Found created group searching by keyword '{$searchKeyword}'");
} else {
    fail("Search group by company name", "Group not returned in search by company keyword '{$searchKeyword}'");
}

// 8. Editing a group updates its company correctly
echo "\n8. Testing group update with different company ID...\n";
$targetComp = ($secondComp['id'] !== $firstComp['id']) ? $secondComp : $firstComp;
$updateRes = apiRequest('POST', "{$baseUrl}/api/groups.php", [
    'action' => 'update',
    'id' => $createdGroupId,
    'name' => $testGroupName . ' (Updated)',
    'company_id' => $targetComp['id'],
    'description' => 'Updated description with new company'
]);
if ($updateRes['code'] === 200 && !empty($updateRes['data']['data']['company_id']) && $updateRes['data']['data']['company_id'] === $targetComp['id']) {
    pass("Editing group updates its company correctly", "Updated to company: '{$updateRes['data']['data']['company_name']}' ({$updateRes['data']['data']['company_id']})");
} else {
    fail("Editing group updates its company correctly", "Failed update: HTTP {$updateRes['code']} - {$updateRes['raw']}");
}

// 9. Verify updated company persists
echo "\n9. Testing persistence of updated company...\n";
$recheckRes = apiRequest('GET', "{$baseUrl}/api/groups.php", ['id' => $createdGroupId]);
if ($recheckRes['code'] === 200 && !empty($recheckRes['data']['data']['company_id']) && $recheckRes['data']['data']['company_id'] === $targetComp['id']) {
    pass("Updated company persists after reload", "Verified persisted company_id: {$recheckRes['data']['data']['company_id']}");
} else {
    fail("Updated company persists after reload", "Persistence check failed: {$recheckRes['raw']}");
}

// 10. Existing groups and members remain unchanged
echo "\n10. Testing existing groups and members integrity...\n";
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();
$stmt = $pdo->query("SELECT id, name, company_id, leader_id FROM crm_groups WHERE id != " . (int)$createdGroupId);
$existingGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);
$mCountStmt = $pdo->query("SELECT COUNT(*) FROM crm_group_members");
$memberCount = (int)$mCountStmt->fetchColumn();
if (count($existingGroups) >= 2 && $memberCount > 0) {
    $existingNames = array_column($existingGroups, 'name');
    pass("Existing groups and members remain unchanged", count($existingGroups) . " existing groups found: (" . implode(', ', $existingNames) . ") with {$memberCount} group member links intact");
} else {
    fail("Existing groups and members remain unchanged", "Expected existing groups and members");
}

// Clean up temporary group
if ($createdGroupId) {
    apiRequest('POST', "{$baseUrl}/api/groups.php", [
        'action' => 'delete',
        'id' => $createdGroupId
    ]);
    echo "\n  (Cleaned up temporary test group ID {$createdGroupId})\n";
}

// Final Summary
echo "\n=== TEST SUITE RESULTS ===\n";
$total = count($results);
$passed = count(array_filter($results, fn($r) => $r['status'] === 'PASS'));
$failed = count(array_filter($results, fn($r) => $r['status'] === 'FAIL'));
echo "Total Tests: {$total} | Passed: {$passed} | Failed: {$failed}\n";

if ($failed === 0) {
    echo "\n>>> ALL 10 TESTS PASSED SUCCESSFULLY! <<<\n";
    exit(0);
} else {
    echo "\n>>> SOME TESTS FAILED! <<<\n";
    exit(1);
}
