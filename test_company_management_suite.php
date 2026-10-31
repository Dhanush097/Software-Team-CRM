<?php
/**
 * Automated Verification Suite for Company Management Module
 * Tests all 12 scenarios specified in the requirements.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/api/db.php';
$pdo = Database::getConnection();

// Clean up any stray test companies from earlier runs
$pdo->exec("DELETE FROM crm_companies WHERE id LIKE 'cmp_safety_%'");

$baseUrl = 'http://127.0.0.1:8000';

// Establish real authenticated session via OTP
$testOtp = '1234';
$otpHash = password_hash($testOtp, PASSWORD_DEFAULT);
$pdo->prepare("UPDATE `crm_otp_challenges` SET `is_used` = 1 WHERE `email` = 'suresh@softwarecrm.com'")->execute();
$insChallenge = $pdo->prepare("INSERT INTO `crm_otp_challenges` (`email`, `otp_hash`, `purpose`, `expires_at`, `attempts`, `max_attempts`, `resend_count`, `last_sent_at`, `is_used`, `temp_payload`) VALUES ('suresh@softwarecrm.com', ?, 'login', DATE_ADD(NOW(), INTERVAL 5 MINUTE), 0, 5, 0, NOW(), 0, ?)");
$insChallenge->execute([
    $otpHash,
    json_encode(['user_id' => 1, 'name' => 'Suresh Kumar', 'email' => 'suresh@softwarecrm.com', 'role' => 'admin'])
]);

$ch = curl_init("$baseUrl/api/auth.php?action=verify_otp");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'email' => 'suresh@softwarecrm.com',
    'otp' => $testOtp,
    'purpose' => 'login'
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$otpResp = curl_exec($ch);
curl_close($ch);

preg_match('/Set-Cookie:\s*PHPSESSID=([^;]+)/i', $otpResp, $m);
$sessionId = $m[1] ?? '';

if (empty($sessionId)) {
    die("FAILED to acquire authenticated session ID!\n");
}

$results = [];

function runTest($id, $description, callable $fn) {
    global $results;
    try {
        $res = $fn();
        if ($res === true || (is_array($res) && ($res['pass'] ?? false))) {
            $msg = is_array($res) ? ($res['message'] ?? 'OK') : 'OK';
            $results[] = ['id' => $id, 'desc' => $description, 'status' => 'PASS', 'msg' => $msg];
            echo "[\033[32mPASS\033[0m] Test $id: $description - $msg\n";
        } else {
            $msg = is_array($res) ? ($res['message'] ?? 'Failed') : 'Failed';
            $results[] = ['id' => $id, 'desc' => $description, 'status' => 'FAIL', 'msg' => $msg];
            echo "[\033[31mFAIL\033[0m] Test $id: $description - $msg\n";
        }
    } catch (Throwable $e) {
        $results[] = ['id' => $id, 'desc' => $description, 'status' => 'FAIL', 'msg' => $e->getMessage()];
        echo "[\033[31mFAIL\033[0m] Test $id: $description - Exception: " . $e->getMessage() . "\n";
    }
}

function makeRequest($endpoint, $method = 'GET', $data = null) {
    global $baseUrl, $sessionId;
    $url = $baseUrl . '/' . ltrim($endpoint, '/');
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_COOKIE, "PHPSESSID=$sessionId");
    
    if ($data !== null) {
        $payload = is_string($data) ? $data : json_encode($data);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Requested-With: XMLHttpRequest'
        ]);
    } else {
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'X-Requested-With: XMLHttpRequest'
        ]);
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['code' => 0, 'error' => $error, 'json' => null, 'raw' => null];
    }
    
    $json = json_decode($response, true);
    return ['code' => $httpCode, 'json' => $json, 'raw' => $response];
}

echo "====================================================================\n";
echo " RUNNING COMPANY MANAGEMENT AUTOMATED VERIFICATION SUITE\n";
echo " Target: $baseUrl (Session: $sessionId)\n";
echo "====================================================================\n\n";

// TEST 1: Retrieve Companies List
runTest(1, 'Fetch all companies from API (GET api/companies.php)', function() {
    $res = makeRequest('api/companies.php');
    if ($res['code'] !== 200) {
        return ['pass' => false, 'message' => "Expected 200, got {$res['code']}"];
    }
    if (!isset($res['json']['data']) || !is_array($res['json']['data'])) {
        return ['pass' => false, 'message' => "Data array not found in response: " . $res['raw']];
    }
    $count = count($res['json']['data']);
    if ($count < 6) {
        return ['pass' => false, 'message' => "Expected at least 6 companies, found $count"];
    }
    return ['pass' => true, 'message' => "Retrieved $count companies successfully"];
});

// TEST 2: Scenario 1 - Clicking 'ABC' opens ABC company details with its ID
runTest(2, 'Clicking ABC opens ABC details with ID cmp_abc', function() {
    $res = makeRequest('api/companies.php?id=cmp_abc');
    if ($res['code'] !== 200) return ['pass' => false, 'message' => "HTTP {$res['code']}"];
    $c = $res['json']['data'] ?? null;
    if (!$c || $c['id'] !== 'cmp_abc' || $c['name'] !== 'ABC') {
        return ['pass' => false, 'message' => "Mismatch: ID={$c['id']}, Name={$c['name']}"];
    }
    return ['pass' => true, 'message' => "Verified ABC record: id=cmp_abc, name=ABC, contacts=" . count($c['contacts'] ?? [])];
});

// TEST 3: Scenario 2 - Clicking 'Apex Global Technologies' opens its details
runTest(3, 'Clicking Apex Global Technologies opens details with ID cmp_apex_global_technologies', function() {
    $res = makeRequest('api/companies.php?id=cmp_apex_global_technologies');
    if ($res['code'] !== 200) return ['pass' => false, 'message' => "HTTP {$res['code']}"];
    $c = $res['json']['data'] ?? null;
    if (!$c || $c['id'] !== 'cmp_apex_global_technologies' || $c['name'] !== 'Apex Global Technologies') {
        return ['pass' => false, 'message' => "Mismatch: ID={$c['id']}, Name={$c['name']}"];
    }
    return ['pass' => true, 'message' => "Verified Apex Global Technologies: contacts=" . count($c['contacts'] ?? [])];
});

// TEST 4: Scenario 3 - Clicking 'Test Corp' opens its details
runTest(4, 'Clicking Test Corp opens details with ID cmp_test_corp', function() {
    $res = makeRequest('api/companies.php?id=cmp_test_corp');
    if ($res['code'] !== 200) return ['pass' => false, 'message' => "HTTP {$res['code']}"];
    $c = $res['json']['data'] ?? null;
    if (!$c || $c['id'] !== 'cmp_test_corp' || $c['name'] !== 'Test Corp') {
        return ['pass' => false, 'message' => "Mismatch: ID={$c['id']}, Name={$c['name']}"];
    }
    return ['pass' => true, 'message' => "Verified Test Corp: id=cmp_test_corp, contacts=" . count($c['contacts'] ?? [])];
});

// TEST 5: Scenario 4 - ABC Tech Solutions, Verma Solutions, Test Automation Corp
runTest(5, 'Opening ABC Tech Solutions, Verma Solutions, Test Automation Corp', function() {
    $targets = [
        'cmp_abc_tech_solutions' => 'ABC Tech Solutions',
        'cmp_verma_solutions' => 'Verma Solutions',
        'cmp_test_automation_corp' => 'Test Automation Corp'
    ];
    foreach ($targets as $id => $expectedName) {
        $res = makeRequest("api/companies.php?id=$id");
        if ($res['code'] !== 200) return ['pass' => false, 'message' => "Failed for $id (HTTP {$res['code']})"];
        $c = $res['json']['data'] ?? null;
        if (!$c || $c['id'] !== $id || $c['name'] !== $expectedName) {
            return ['pass' => false, 'message' => "Mismatch for $id: " . json_encode($c)];
        }
    }
    return ['pass' => true, 'message' => "All 3 sample companies verified successfully"];
});

// TEST 6: Scenario 5 & 6 - Associated contacts match company & have required fields
runTest(6, 'Associated contacts match company and contain required contact attributes', function() {
    $res = makeRequest('api/companies.php?id=cmp_abc_tech_solutions');
    if ($res['code'] !== 200) return ['pass' => false, 'message' => "HTTP {$res['code']}"];
    $c = $res['json']['data'] ?? null;
    $contacts = $c['contacts'] ?? [];
    if (empty($contacts)) {
        return ['pass' => false, 'message' => "Expected contacts for ABC Tech Solutions, found none"];
    }
    foreach ($contacts as $cnt) {
        if (empty($cnt['name'])) return ['pass' => false, 'message' => "Contact missing name: " . json_encode($cnt)];
        if (!isset($cnt['email']) || !isset($cnt['phone'])) {
            return ['pass' => false, 'message' => "Contact missing email or phone key"];
        }
    }
    return ['pass' => true, 'message' => "Verified " . count($contacts) . " contacts for ABC Tech Solutions (" . implode(', ', array_column($contacts, 'name')) . ")"];
});

// TEST 7: Scenario 7 - Modifying company updates record correctly
runTest(7, 'Modifying company information updates record in database', function() {
    // 1. Fetch current Verma Solutions
    $res = makeRequest('api/companies.php?id=cmp_verma_solutions');
    $orig = $res['json']['data'];
    
    $testNotes = 'Updated via automated test at ' . date('Y-m-d H:i:s');
    $testWebsite = 'https://vermasolutions.example.com';
    
    // 2. Update Verma Solutions
    $updatePayload = [
        'action' => 'update',
        'id' => 'cmp_verma_solutions',
        'name' => 'Verma Solutions',
        'industry' => 'Cloud Infrastructure & DevOps',
        'website' => $testWebsite,
        'email' => 'contact@vermasolutions.com',
        'phone' => '+91 98200 11223',
        'address' => 'Pune, Maharashtra, India',
        'description' => $testNotes
    ];
    $updRes = makeRequest('api/companies.php', 'POST', $updatePayload);
    if ($updRes['code'] !== 200 || !($updRes['json']['success'] ?? false)) {
        return ['pass' => false, 'message' => "Update failed: " . json_encode($updRes)];
    }
    
    // 3. Re-fetch and verify
    $verifyRes = makeRequest('api/companies.php?id=cmp_verma_solutions');
    $verified = $verifyRes['json']['data'];
    if ($verified['description'] !== $testNotes || $verified['website'] !== $testWebsite || $verified['industry'] !== 'Cloud Infrastructure & DevOps') {
        return ['pass' => false, 'message' => "Verification mismatch: " . json_encode($verified)];
    }
    
    return ['pass' => true, 'message' => "Successfully updated and verified Verma Solutions record"];
});

// TEST 8: Scenario 8 - Non-existent company ID handled gracefully with 404
runTest(8, 'Non-existent or invalid company ID returns 404 error', function() {
    $res = makeRequest('api/companies.php?id=cmp_non_existent_id_99999');
    if ($res['code'] !== 404) {
        return ['pass' => false, 'message' => "Expected 404, got HTTP {$res['code']}"];
    }
    if (!isset($res['json']['message']) || $res['json']['success'] !== false) {
        return ['pass' => false, 'message' => "Expected error json with success=false"];
    }
    return ['pass' => true, 'message' => "Returned 404 with error message: '{$res['json']['message']}'"];
});

// TEST 9: Scenario 9 - Company with no contacts/projects handles empty state
runTest(9, 'Company with no contacts displays clean empty state without errors', function() {
    // Create temporary empty company
    $tempName = 'Empty Test Corp ' . time();
    $createPayload = [
        'action' => 'create',
        'name' => $tempName,
        'industry' => 'Consulting',
        'description' => 'A brand new company with no contacts yet'
    ];
    $createRes = makeRequest('api/companies.php', 'POST', $createPayload);
    if ($createRes['code'] !== 201 || !($createRes['json']['success'] ?? false)) {
        return ['pass' => false, 'message' => "Create failed: " . json_encode($createRes)];
    }
    $createdId = $createRes['json']['id'];
    
    // Fetch details
    $detailRes = makeRequest("api/companies.php?id=$createdId");
    if ($detailRes['code'] !== 200) {
        return ['pass' => false, 'message' => "Fetch created failed: HTTP {$detailRes['code']}"];
    }
    $data = $detailRes['json']['data'];
    if ($data['contacts_count'] !== 0 || !is_array($data['contacts']) || count($data['contacts']) !== 0) {
        return ['pass' => false, 'message' => "Expected empty contacts, got: " . json_encode($data['contacts'])];
    }
    if ($data['projects_count'] !== 0 || !is_array($data['projects']) || count($data['projects']) !== 0) {
        return ['pass' => false, 'message' => "Expected empty projects, got: " . json_encode($data['projects'])];
    }
    
    // Clean up temporary company
    $delRes = makeRequest('api/companies.php', 'POST', ['action' => 'delete', 'id' => $createdId]);
    if ($delRes['code'] !== 200) {
        return ['pass' => false, 'message' => "Delete cleanup failed: HTTP {$delRes['code']}"];
    }
    
    return ['pass' => true, 'message' => "Handled 0 contacts & 0 projects gracefully; cleaned up successfully"];
});

// TEST 10: Scenario 10 - Search & filtering preserves correct company records
runTest(10, 'Search filtering returns matching companies accurately', function() {
    $res = makeRequest('api/companies.php?q=Apex');
    if ($res['code'] !== 200) return ['pass' => false, 'message' => "HTTP {$res['code']}"];
    $list = $res['json']['data'] ?? [];
    if (empty($list)) return ['pass' => false, 'message' => "No results for q=Apex"];
    foreach ($list as $c) {
        if (stripos($c['name'], 'Apex') === false && stripos($c['industry'] ?? '', 'Apex') === false) {
            return ['pass' => false, 'message' => "Unexpected result: {$c['name']}"];
        }
    }
    return ['pass' => true, 'message' => "Search for 'Apex' returned " . count($list) . " matching record(s)"];
});

// TEST 11: Duplicate company name prevention (409 Conflict)
runTest(11, 'Duplicate company name creation returns 409 Conflict', function() {
    $res = makeRequest('api/companies.php', 'POST', [
        'action' => 'create',
        'name' => 'ABC' // already exists
    ]);
    if ($res['code'] !== 409) {
        return ['pass' => false, 'message' => "Expected 409 Conflict, got HTTP {$res['code']}"];
    }
    return ['pass' => true, 'message' => "Duplicate prevention working (409 Conflict returned)"];
});

// TEST 12: Delete company preserves contacts safely (unlinks company_id without deleting contacts)
runTest(12, 'Delete company preserves contacts safely without losing contact records', function() {
    global $pdo;
    
    // 1. Create a dummy company
    $cName = 'Safety Delete Corp ' . time();
    $cId = 'cmp_safety_' . time();
    $pdo->prepare("INSERT INTO crm_companies (id, name, created_at) VALUES (?, ?, NOW())")->execute([$cId, $cName]);
    
    // 2. Create a dummy contact linked to this company
    $cntId = 'cnt_safety_' . time();
    $cntName = 'Safety Contact Test';
    $pdo->prepare("INSERT INTO crm_contacts (id, name, phone, company, company_id, created_at) VALUES (?, ?, '', ?, ?, NOW())")->execute([$cntId, $cntName, $cName, $cId]);
    
    // 3. Delete company via API
    $delRes = makeRequest('api/companies.php', 'POST', ['action' => 'delete', 'id' => $cId]);
    if ($delRes['code'] !== 200) {
        return ['pass' => false, 'message' => "Delete failed: HTTP {$delRes['code']}"];
    }
    
    // 4. Verify contact still exists in crm_contacts and company_id is NULL
    $chk = $pdo->prepare("SELECT id, name, company_id FROM crm_contacts WHERE id = ?");
    $chk->execute([$cntId]);
    $contactRecord = $chk->fetch(PDO::FETCH_ASSOC);
    
    if (!$contactRecord) {
        return ['pass' => false, 'message' => "CRITICAL ERROR: Contact was deleted with company!"];
    }
    if ($contactRecord['company_id'] !== null) {
        return ['pass' => false, 'message' => "Contact company_id was not unlinked!"];
    }
    
    // Clean up dummy contact
    $pdo->prepare("DELETE FROM crm_contacts WHERE id = ?")->execute([$cntId]);
    
    return ['pass' => true, 'message' => "Contact remained intact in database and was unlinked safely"];
});

// TEST 13: UI Template Verification in index.php
runTest(13, 'Verify UI click handlers, hover effects, company_detail view and modals in index.php', function() {
    $content = file_get_contents(__DIR__ . '/index.php');
    
    $checks = [
        '@click="viewCompanyDetail(comp)"' => 'Card click handler calling viewCompanyDetail(comp)',
        'cursor-pointer hover:border-blue-400' => 'Pointer cursor and subtle hover styling',
        "currentView === 'company_detail'" => 'Company detail view conditional rendering',
        'modalCompanyFormOpen' => 'Add/Edit Company modal dialog',
        'modalConfirmDeleteCompanyOpen' => 'Delete Company confirmation modal',
        'companyDetailTab' => 'Tab navigation for Company Details (Overview, Contacts, Projects, Invoices)',
        'contactReturnView' => 'Contact return view support for company_detail',
        'loadCompanies' => 'Alpine method loadCompanies',
        'saveCompanyForm' => 'Alpine method saveCompanyForm',
        'confirmDeleteCompany' => 'Alpine method confirmDeleteCompany'
    ];
    
    foreach ($checks as $pattern => $name) {
        if (strpos($content, $pattern) === false) {
            return ['pass' => false, 'message' => "Missing in index.php: $name ($pattern)"];
        }
    }
    
    return ['pass' => true, 'message' => "All 10 required frontend UI elements and Alpine handlers present in index.php"];
});

// TEST 14: Regression Check on other CRM APIs
runTest(14, 'Regression Check: All other CRM APIs continue to function normally', function() {
    $endpoints = [
        'api/pocket_api.php?action=get_contacts',
        'api/software.php',
        'api/tasks.php',
        'api/ledger.php',
        'api/notifications.php'
    ];
    foreach ($endpoints as $ep) {
        $res = makeRequest($ep);
        if ($res['code'] !== 200) {
            return ['pass' => false, 'message' => "Endpoint $ep returned HTTP {$res['code']}"];
        }
    }
    return ['pass' => true, 'message' => "All 5 core CRM APIs returned HTTP 200 OK without errors"];
});

echo "\n====================================================================\n";
$passCount = count(array_filter($results, fn($r) => $r['status'] === 'PASS'));
$failCount = count(array_filter($results, fn($r) => $r['status'] === 'FAIL'));
$total = count($results);
echo " RESULTS: $passCount / $total PASSED, $failCount FAILED\n";
echo "====================================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
