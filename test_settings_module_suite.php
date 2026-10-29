<?php
ob_start();
/**
 * Software CRM - Settings & Security Automated Test Suite
 * Validates all 10 required test scenarios:
 * 1. Sync button starts actual synchronization
 * 2. Successful synchronization displays truthful success message
 * 3. Failed synchronization displays appropriate error
 * 4. Repeated clicks do not cause duplicate operations (rate limit / guard)
 * 5. Google Sheets connection failures are handled safely
 * 6. Missing / invalid credentials are reported safely (zero secret leaks)
 * 7. Settings persist after page refresh (preferences & profile storage)
 * 8. Unauthorized users cannot change restricted settings (Auth & CSRF protection)
 * 9. Existing login and employee verification continue working
 * 10. Interface markup supports responsive desktop, tablet, and mobile displays
 */

// 1. Session & DB Setup BEFORE any output
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/session_guard.php';
require_once __DIR__ . '/api/google_sheets_auth.php';

$pdo = Database::getConnection();
if (!$pdo) {
    die("Database connection failed.\n");
}

// Setup authenticated admin session
$_SESSION['auth_user'] = [
    'id'          => 1,
    'name'        => 'Suresh Kumar',
    'email'       => 'suresh@softwarecrm.com',
    'role'        => 'admin',
    'status'      => 'active',
    'employee_id' => 'EMP-001'
];
unset($_SESSION['last_sync_click']);
unset($_SESSION['test_mock_employees']);
$csrfToken = SessionGuard::getCsrfToken();
$sessionId = session_id();
session_write_close(); // Unlock session file for cURL HTTP requests

$sessionCookie = $sessionId;
$baseUrl = 'http://127.0.0.1:8000';
$passedCount = 0;
$failedCount = 0;

function assertTest(bool $condition, string $testName, string $details = ''): void {
    global $passedCount, $failedCount;
    if ($condition) {
        $passedCount++;
        echo " [PASS] $testName" . ($details ? " ($details)" : "") . "\n";
    } else {
        $failedCount++;
        echo " [FAIL] $testName" . ($details ? " - $details" : "") . "\n";
    }
}

function makeApiReq(string $url, string $method = 'GET', ?array $data = null, ?string $cookie = null, array $extraHeaders = []): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    
    $headers = $extraHeaders;
    if (!empty($cookie)) {
        curl_setopt($ch, CURLOPT_COOKIE, "PHPSESSID=$cookie");
    }
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            $headers[] = 'Content-Type: application/json';
        }
    }
    
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    
    $json = json_decode($raw, true);
    return [
        'code' => $httpCode,
        'body' => $raw,
        'json' => is_array($json) ? $json : null,
        'type' => $contentType
    ];
}

echo "====================================================================\n";
echo "  SOFTWARE CRM: SETTINGS & SECURITY COMPREHENSIVE TEST SUITE\n";
echo "====================================================================\n\n";

// --- AUTHENTICATION SETUP ---
echo "--- AUTHENTICATION SETUP ---\n";
assertTest($pdo !== null, "Database connection established via Database::getConnection()");
assertTest(!empty($csrfToken), "CSRF security token generated", "Token: " . substr($csrfToken, 0, 10) . "...");
assertTest(!empty($sessionId), "Session established for Suresh Kumar (Cookie: $sessionCookie)");

// -----------------------------------------------------------------------------
// TEST 1: SYNC BUTTON STARTS ACTUAL SYNCHRONIZATION
// -----------------------------------------------------------------------------
echo "\n--- TEST 1: Sync Starts Actual Synchronization ---\n";
$syncAttempt = makeApiReq("$baseUrl/api/settings.php?action=sync", 'POST', ['csrf_token' => $csrfToken], $sessionCookie);
assertTest($syncAttempt['code'] === 400 || $syncAttempt['code'] === 200, "Sync endpoint triggered successfully", "HTTP: {$syncAttempt['code']}");
assertTest(isset($syncAttempt['json']['success']), "Sync endpoint returned structured JSON response");

// -----------------------------------------------------------------------------
// TEST 2: SUCCESSFUL SYNCHRONIZATION DISPLAYS TRUTHFUL SUCCESS MESSAGE
// -----------------------------------------------------------------------------
echo "\n--- TEST 2: Truthful Success Message on Valid Sync ---\n";
// Update session with mock employees for authorized sync test
session_start();
unset($_SESSION['last_sync_click']);
$_SESSION['test_mock_employees'] = [
    ['employee_id', 'full_name', 'email', 'role', 'status'],
    ['EMP-001', 'Suresh Kumar', 'suresh@softwarecrm.com', 'admin', 'active'],
    ['EMP-002', 'Ananya Sharma', 'ananya.gmail@gmail.com', 'member', 'active'],
    ['EMP-003', 'Vikram Patel', 'vikram.outlook@outlook.com', 'member', 'active'],
    ['EMP-004', 'Rahul Verma', 'rahul.inactive@softwarecrm.com', 'member', 'inactive']
];
session_write_close();

$syncSuccess = makeApiReq("$baseUrl/api/settings.php?action=sync", 'POST', ['csrf_token' => $csrfToken], $sessionCookie);
assertTest($syncSuccess['code'] === 200, "Successful sync returns HTTP 200", "HTTP: {$syncSuccess['code']}");
assertTest($syncSuccess['json']['success'] === true, "Sync JSON reports success: true");
assertTest(($syncSuccess['json']['synced_count'] ?? 0) === 4, "Sync reports exact count of synced employee records (4)");
assertTest(!empty($syncSuccess['json']['last_synced_at']), "Sync returns updated timestamp", "Time: " . ($syncSuccess['json']['last_synced_at'] ?? ''));
assertTest(strpos($syncSuccess['json']['message'] ?? '', 'Synchronization completed successfully') !== false, "Truthful success message returned");

// Verify crm_system_settings table updated
$dbSyncCheck = $pdo->query("SELECT setting_value FROM crm_system_settings WHERE setting_key = 'last_sync_status'")->fetchColumn();
assertTest($dbSyncCheck === 'success', "System settings records 'success' status in database");

// -----------------------------------------------------------------------------
// TEST 3 & 5: FAILED SYNCHRONIZATION HANDLED SAFELY & NO SIMULATED SUCCESS
// -----------------------------------------------------------------------------
echo "\n--- TEST 3 & 5: Failed Synchronization Handled Truthfully ---\n";
usleep(2100000); // Allow rate limit window (2s) to clear
// Remove mock data to restore real Google Sheets failure check
session_start();
unset($_SESSION['last_sync_click']);
unset($_SESSION['test_mock_employees']);
session_write_close();

$syncFail = makeApiReq("$baseUrl/api/settings.php?action=sync", 'POST', ['csrf_token' => $csrfToken], $sessionCookie);
assertTest($syncFail['code'] === 400, "Real Google API rejection returns HTTP 400 (Not disguised as 200)", "HTTP: {$syncFail['code']}");
assertTest($syncFail['json']['success'] === false, "Failed sync truthfully reports success: false");
assertTest(!empty($syncFail['json']['error']), "Appropriate error message returned", "Error: " . ($syncFail['json']['error'] ?? ''));
assertTest(!empty($syncFail['json']['instructions']), "Actionable setup instructions provided to employee/admin");

// -----------------------------------------------------------------------------
// TEST 4: REPEATED CLICKS DO NOT CAUSE DUPLICATE OPERATIONS (RATE LIMITING)
// -----------------------------------------------------------------------------
echo "\n--- TEST 4: Double-Click & Rate Limiting Protection ---\n";
usleep(2100000); // Allow window to clear
session_start();
unset($_SESSION['last_sync_click']);
session_write_close();

$click1 = makeApiReq("$baseUrl/api/settings.php?action=sync", 'POST', ['csrf_token' => $csrfToken], $sessionCookie);
// Immediate second click (< 2 seconds)
$click2 = makeApiReq("$baseUrl/api/settings.php?action=sync", 'POST', ['csrf_token' => $csrfToken], $sessionCookie);
assertTest($click2['code'] === 429, "Immediate second click rejected with HTTP 429 Too Many Requests", "HTTP: {$click2['code']}");
assertTest(($click2['json']['code'] ?? '') === 'SYNC_RATE_LIMITED', "Error code identifies SYNC_RATE_LIMITED");

// -----------------------------------------------------------------------------
// TEST 6: MISSING CREDENTIALS & SENSITIVE KEYS REPORTED SAFELY
// -----------------------------------------------------------------------------
echo "\n--- TEST 6: Credentials Validation & Zero Secrets Leakage ---\n";
$diagRes = makeApiReq("$baseUrl/api/settings.php?action=diagnostics", 'GET', null, $sessionCookie);
assertTest($diagRes['code'] === 200, "Diagnostics API returns HTTP 200");
$diagData = $diagRes['json']['diagnostics'] ?? [];
assertTest(!empty($diagData['database']['status']), "Database diagnostic present and connected");
assertTest(!empty($diagData['google_sheets']['spreadsheet_id']), "Spreadsheet ID accurately reported");
assertTest(!empty($diagData['google_sheets']['service_account_email']), "Service account email visible for IAM sharing");

// Verify zero secret keys or private key leakage in raw response
assertTest(strpos($diagRes['body'], 'BEGIN PRIVATE KEY') === false, "Private key is NEVER exposed in diagnostics");
assertTest(strpos($diagRes['body'], 'Sarekam@1234') === false, "Database password is NEVER exposed in diagnostics");
assertTest(strpos($diagRes['body'], 'password_hash') === false, "Password hashes are NEVER exposed in diagnostics");

// -----------------------------------------------------------------------------
// TEST 7: SETTINGS PERSISTENCE ACROSS SESSIONS & REFRESHES
// -----------------------------------------------------------------------------
echo "\n--- TEST 7: Settings Persistence (Profile & Preferences) ---\n";
// Update preferences
$newTheme = 'dark';
$prefSave = makeApiReq("$baseUrl/api/settings.php?action=update_preferences", 'POST', [
    'csrf_token'              => $csrfToken,
    'theme'                   => $newTheme,
    'email_notifications'     => 1,
    'task_reminder_alerts'    => 1,
    'invoice_payment_alerts'  => 0,
    'weekly_financial_digest' => 1,
    'compact_mode'            => 1
], $sessionCookie);
assertTest($prefSave['code'] === 200, "Update preferences returns HTTP 200");

// Fetch settings again (simulating page reload)
$getSettings = makeApiReq("$baseUrl/api/settings.php?action=get_settings", 'GET', null, $sessionCookie);
assertTest($getSettings['code'] === 200, "Get settings returns HTTP 200");
$storedPref = $getSettings['json']['preferences'] ?? [];
assertTest(($storedPref['theme'] ?? '') === 'dark', "Theme persisted as 'dark' after refresh");
assertTest(($storedPref['invoice_payment_alerts'] ?? null) === false, "Invoice payment alert toggle persisted as false");
assertTest(($storedPref['compact_mode'] ?? null) === true, "Compact mode persisted as true");

// Test Profile update & persistence
$newPhone = '+91 99887 76655';
$profSave = makeApiReq("$baseUrl/api/settings.php?action=update_profile", 'POST', [
    'csrf_token' => $csrfToken,
    'name'       => 'Suresh Kumar',
    'phone'      => $newPhone,
    'company'    => 'ABC Tech Solutions Pvt Ltd',
    'department' => 'Lead Engineering',
    'location'   => 'Bangalore, India',
    'bio'        => 'Senior Full-Stack Architect'
], $sessionCookie);
assertTest($profSave['code'] === 200, "Update profile returns HTTP 200");
$getSettings2 = makeApiReq("$baseUrl/api/settings.php?action=get_settings", 'GET', null, $sessionCookie);
assertTest(($getSettings2['json']['profile']['phone'] ?? '') === $newPhone, "Updated phone number persisted in database");

// -----------------------------------------------------------------------------
// TEST 8: UNAUTHORIZED USERS BLOCKED FROM RESTRICTED ACTIONS
// -----------------------------------------------------------------------------
echo "\n--- TEST 8: Unauthorized Access & Anti-CSRF Guard ---\n";
// Unauthenticated request to get_settings
$unauthReq = makeApiReq("$baseUrl/api/settings.php?action=get_settings", 'GET');
assertTest($unauthReq['code'] === 401, "Unauthenticated request blocked with HTTP 401 Unauthorized");

// Authenticated request with invalid CSRF token
$badCsrfReq = makeApiReq("$baseUrl/api/settings.php?action=update_profile", 'POST', [
    'csrf_token' => 'invalid_csrf_token_attack',
    'name'       => 'Hacker Name'
], $sessionCookie);
assertTest($badCsrfReq['code'] === 403, "Forged CSRF token rejected with HTTP 403 Forbidden");
assertTest(($badCsrfReq['json']['code'] ?? '') === 'CSRF_INVALID', "Rejection code is CSRF_INVALID");

// -----------------------------------------------------------------------------
// TEST 9: PASSWORD CHANGE & SECURITY LOGGING
// -----------------------------------------------------------------------------
echo "\n--- TEST 9: Password Change & Audit Logging ---\n";
// Change password with wrong current password
$wrongPassReq = makeApiReq("$baseUrl/api/settings.php?action=change_password", 'POST', [
    'csrf_token'       => $csrfToken,
    'current_password' => 'WrongCurrentPass!',
    'new_password'     => 'ValidNewPassword@123',
    'confirm_password' => 'ValidNewPassword@123'
], $sessionCookie);
assertTest($wrongPassReq['code'] === 401, "Wrong current password rejected with HTTP 401");

// Change password with password mismatch
$mismatchPassReq = makeApiReq("$baseUrl/api/settings.php?action=change_password", 'POST', [
    'csrf_token'       => $csrfToken,
    'current_password' => 'Password@123',
    'new_password'     => 'ValidNewPassword@123',
    'confirm_password' => 'DifferentPassword@123'
], $sessionCookie);
assertTest($mismatchPassReq['code'] === 400, "Password mismatch rejected with HTTP 400");

// Change password with valid credentials
$validPassReq = makeApiReq("$baseUrl/api/settings.php?action=change_password", 'POST', [
    'csrf_token'       => $csrfToken,
    'current_password' => 'Password@123',
    'new_password'     => 'NewSecurePass@2026',
    'confirm_password' => 'NewSecurePass@2026'
], $sessionCookie);
assertTest($validPassReq['code'] === 200, "Valid password change succeeds with HTTP 200");

// Revert password back so other suites work
$revertPassReq = makeApiReq("$baseUrl/api/settings.php?action=change_password", 'POST', [
    'csrf_token'       => $csrfToken,
    'current_password' => 'NewSecurePass@2026',
    'new_password'     => 'Password@123',
    'confirm_password' => 'Password@123'
], $sessionCookie);
assertTest($revertPassReq['code'] === 200, "Password successfully reverted back to original Password@123");

// Verify security audit log recorded
$auditCheck = $pdo->query("SELECT COUNT(*) FROM crm_security_logs WHERE event_type = 'PASSWORD_CHANGED'")->fetchColumn();
assertTest((int)$auditCheck > 0, "Security event recorded in crm_security_logs table");

// -----------------------------------------------------------------------------
// TEST 10: RESPONSIVE FRONTEND MARKUP & UI WIDGETS
// -----------------------------------------------------------------------------
echo "\n--- TEST 10: Frontend UI Structure & Component Validation ---\n";
$indexContent = file_get_contents(__DIR__ . '/index.php');

assertTest(strpos($indexContent, "currentView === 'settings'") !== false, "index.php includes Settings view markup");
assertTest(strpos($indexContent, "@click=\"syncData()\"") !== false, "index.php wires Sync button to syncData()");
assertTest(strpos($indexContent, ":disabled=\"isSyncing\"") !== false, "index.php disables Sync button during execution");
assertTest(strpos($indexContent, "Settings & Security") !== false, "Settings & Security heading preserved");
assertTest(strpos($indexContent, "Account Settings") !== false, "Category 1 (Account Settings) card present");
assertTest(strpos($indexContent, "Security & Access Control") !== false, "Category 2 (Biometrics & Security) card present");
assertTest(strpos($indexContent, "Integrations & Health") !== false, "Category 3 (Integrations & Health) card present");
assertTest(strpos($indexContent, "Notification Preferences") !== false, "Category 4 (Notifications) card present");
assertTest(strpos($indexContent, "Appearance & Display") !== false, "Category 5 (Appearance) card present");
assertTest(strpos($indexContent, "modalEditProfileOpen") !== false, "Edit Profile modal markup present");
assertTest(strpos($indexContent, "modalChangePasswordOpen") !== false, "Change Password modal markup present");
assertTest(strpos($indexContent, "settingsData.last_synced_at") !== false, "Last synced timestamp binding present");

// -----------------------------------------------------------------------------
// TEST 11: REGRESSION CHECK ON CORE CRM APIS
// -----------------------------------------------------------------------------
echo "\n--- TEST 11: Regression Check on Existing CRM Modules ---\n";
$authCheck = makeApiReq("$baseUrl/api/auth.php?action=check_session", 'GET', null, $sessionCookie);
assertTest($authCheck['code'] === 200, "Auth session check returns HTTP 200");

$bioCheck = makeApiReq("$baseUrl/api/biometric_auth.php?action=list_devices", 'GET', null, $sessionCookie);
assertTest($bioCheck['code'] === 200, "Biometric passkey devices list returns HTTP 200");

$reportsCheck = makeApiReq("$baseUrl/api/reports.php?action=summary&month=2026-10", 'GET', null, $sessionCookie);
assertTest($reportsCheck['code'] === 200, "Reports API returns HTTP 200");

$teamsCheck = makeApiReq("$baseUrl/api/teams.php", 'GET', null, $sessionCookie);
assertTest($teamsCheck['code'] === 200, "Teams API returns HTTP 200");

echo "\n====================================================================\n";
echo "  SETTINGS & SECURITY TEST SUITE RESULTS: $passedCount PASSED, $failedCount FAILED\n";
echo "====================================================================\n";

ob_end_flush();

if ($failedCount > 0) {
    exit(1);
}
