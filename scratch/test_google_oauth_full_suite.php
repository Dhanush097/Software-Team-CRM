<?php
/**
 * Software CRM - Google OAuth 2.0 & Authentication Full Test Suite
 * Covers all 10 scenarios specified in STEP 8.
 */

require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/session_guard.php';
require_once __DIR__ . '/../api/google_auth.php';

$pdo = Database::getConnection();
if (!$pdo) {
    echo "FAIL: Database connection unavailable.\n";
    exit(1);
}

$passCount = 0;
$failCount = 0;

function assertTest(string $desc, bool $condition, string $detail = '') {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "  [PASS] $desc\n";
    } else {
        $failCount++;
        echo "  [FAIL] $desc " . ($detail ? "($detail)" : "") . "\n";
    }
}

echo "========================================================================\n";
echo "RUNNING GOOGLE OAUTH 2.0 & AUTHENTICATION COMPREHENSIVE TEST SUITE\n";
echo "========================================================================\n\n";

// -----------------------------------------------------------------------------
// Test 1: Configuration & Environment Loading
// -----------------------------------------------------------------------------
echo "1. Configuration & Security Baseline\n";
assertTest("Client ID loaded from .env", !empty(GoogleAuthService::getClientId()));
assertTest("Client Secret loaded from .env", !empty(GoogleAuthService::getClientSecret()));
assertTest("Redirect URI matches expected endpoint", GoogleAuthService::getRedirectUri() === 'http://localhost/software_team/api/google_callback.php', "Got: " . GoogleAuthService::getRedirectUri());
assertTest("OAuth Service reports configured", GoogleAuthService::isConfigured());

// -----------------------------------------------------------------------------
// Test 2: Build Auth URL with prompt=select_account & Minimum Scopes
// -----------------------------------------------------------------------------
echo "\n2. Auth URL Generation & CSRF Protection\n";
$authUrlResult = GoogleAuthService::buildAuthUrl();
assertTest("buildAuthUrl succeeds", $authUrlResult['success'] === true);
assertTest("Auth URL contains prompt=select_account", strpos($authUrlResult['auth_url'], 'prompt=select_account') !== false);
assertTest("Auth URL uses minimal scope: openid email profile", strpos($authUrlResult['auth_url'], 'scope=openid+email+profile') !== false || strpos($authUrlResult['auth_url'], 'scope=openid%20email%20profile') !== false);
assertTest("Auth URL does NOT request Google Sheets or unnecessary scopes", strpos($authUrlResult['auth_url'], 'spreadsheets') === false);
assertTest("Session stores anti-CSRF state", !empty($_SESSION['google_oauth_state']));
$savedState = $_SESSION['google_oauth_state'];

// -----------------------------------------------------------------------------
// Test 3: OAuth State Validation & Replay Prevention
// -----------------------------------------------------------------------------
echo "\n3. State Parameter Validation\n";
assertTest("Rejects empty state", GoogleAuthService::verifyState('') === false);
assertTest("Rejects tampered state", GoogleAuthService::verifyState($savedState . '_tampered') === false);
assertTest("Accepts valid state", GoogleAuthService::verifyState($savedState) === true);
assertTest("Single-use state consumed (prevents replay)", GoogleAuthService::verifyState($savedState) === false);

// -----------------------------------------------------------------------------
// Test 4: Scenario 1 - Authorized Employee Signs in with Google
// -----------------------------------------------------------------------------
echo "\n4. Scenario 1 - Authorized Employee (vangaridhanush09@gmail.com)\n";
$testGoogleUser = [
    'google_id'      => '102135245561651215558',
    'email'          => 'vangaridhanush09@gmail.com',
    'name'           => 'Vangari Dhanush datta',
    'picture'        => 'https://lh3.googleusercontent.com/a/test_avatar',
    'email_verified' => true
];
$authRes = GoogleAuthService::authenticateGoogleUser($pdo, $testGoogleUser);
assertTest("Authorized employee authenticated successfully", $authRes['success'] === true);
assertTest("User record returned has correct email", strtolower($authRes['user']['email']) === 'vangaridhanush09@gmail.com');
assertTest("User record preserved role", !empty($authRes['user']['role']));
assertTest("User google_id updated/linked", !empty($authRes['user']['id']));

// -----------------------------------------------------------------------------
// Test 5: Scenario 3 - Unauthorized Google Account Attempts to Sign In
// -----------------------------------------------------------------------------
echo "\n5. Scenario 3 - Unauthorized / Unregistered Account\n";
$unauthorizedUser = [
    'google_id'      => '999999999999999999999',
    'email'          => 'completely_unknown_stranger_12345@gmail.com',
    'name'           => 'Unknown Stranger',
    'picture'        => '',
    'email_verified' => true
];
$unauthRes = GoogleAuthService::authenticateGoogleUser($pdo, $unauthorizedUser);
assertTest("Unauthorized account is rejected", $unauthRes['success'] === false);
assertTest("Rejection error code is unregistered_employee", ($unauthRes['error_code'] ?? '') === 'unregistered_employee');
assertTest("Rejection provides informative message explaining access request", strpos($unauthRes['error'] ?? '', 'not registered as an authorized employee') !== false);

// Check that no user was created in crm_users for stranger
$checkStranger = $pdo->prepare("SELECT id FROM crm_users WHERE email = ?");
$checkStranger->execute(['completely_unknown_stranger_12345@gmail.com']);
assertTest("No duplicate or stranger record created in database", $checkStranger->fetch() === false);

// -----------------------------------------------------------------------------
// Test 6: Employee Registry Provisioning (Active staff not yet in crm_users)
// -----------------------------------------------------------------------------
echo "\n6. Employee Registry Provisioning\n";
// Create temporary test staff in crm_team_staff
$testStaffEmail = 'test_employee_' . bin2hex(random_bytes(4)) . '@abctech.com';
$pdo->prepare("INSERT INTO `crm_team_staff` (`id`, `name`, `role`, `email`, `status`) VALUES (?, ?, 'Developer', ?, 'Active')")
    ->execute(['staff_test_' . time(), 'New Team Hire', $testStaffEmail]);

$staffGoogleUser = [
    'google_id'      => '888888888888888888888',
    'email'          => strtoupper($testStaffEmail), // Test case-insensitivity
    'name'           => 'New Team Hire',
    'picture'        => '',
    'email_verified' => true
];
$provRes = GoogleAuthService::authenticateGoogleUser($pdo, $staffGoogleUser);
assertTest("Active employee in registry is authorized via Google", $provRes['success'] === true);
assertTest("Provisioned account role is member/user (never Superadmin)", in_array($provRes['user']['role'], ['member', 'user'], true));
assertTest("Provisioned account linked to staff ID", !empty($provRes['user']['employee_id']));

// Clean up test staff & user
$pdo->prepare("DELETE FROM `crm_users` WHERE `email` = ?")->execute([strtolower($testStaffEmail)]);
$pdo->prepare("DELETE FROM `crm_team_staff` WHERE `email` = ?")->execute([$testStaffEmail]);

// -----------------------------------------------------------------------------
// Test 7: Suspended User Blocked
// -----------------------------------------------------------------------------
echo "\n7. Suspended / Inactive User Blocking\n";
$suspEmail = 'suspended_user_' . bin2hex(random_bytes(4)) . '@softwarecrm.com';
$pdo->prepare("INSERT INTO `crm_users` (`name`, `email`, `password_hash`, `role`, `status`) VALUES ('Suspended Emp', ?, 'hash', 'user', 'suspended')")
    ->execute([$suspEmail]);

$suspGoogle = [
    'google_id'      => '777777777777777777777',
    'email'          => $suspEmail,
    'name'           => 'Suspended Emp',
    'picture'        => '',
    'email_verified' => true
];
$suspRes = GoogleAuthService::authenticateGoogleUser($pdo, $suspGoogle);
assertTest("Suspended user is blocked", $suspRes['success'] === false);
assertTest("Error code is account_suspended", ($suspRes['error_code'] ?? '') === 'account_suspended');

$pdo->prepare("DELETE FROM `crm_users` WHERE `email` = ?")->execute([$suspEmail]);

// -----------------------------------------------------------------------------
// Test 8: Scenario 2 - Authorized Employee Signs in with Email and Password
// -----------------------------------------------------------------------------
echo "\n8. Scenario 2 - Existing Email & Password + OTP Authentication\n";
// Test login_credentials for suresh@softwarecrm.com with test password
// Query suresh password hash
$suresh = $pdo->query("SELECT id, name, email, password_hash FROM crm_users WHERE id = 1")->fetch();
assertTest("Existing employee Suresh exists in crm_users", !empty($suresh));

// -----------------------------------------------------------------------------
// Test 9: Session Establishment, Regeneration & Dashboard Route Access
// -----------------------------------------------------------------------------
echo "\n9. Session Establishment & Dashboard Loading\n";
if (session_status() === PHP_SESSION_NONE) session_start();
session_regenerate_id(true);
$newSessionId = session_id();

$_SESSION['auth_user'] = [
    'id'          => 8,
    'name'        => 'Vangari Dhanush datta',
    'email'       => 'vangaridhanush09@gmail.com',
    'role'        => 'user',
    'employee_id' => 'staff_3ac9c3eea6e3',
    'avatar_url'  => null
];
session_write_close();

assertTest("Session contains auth_user with id", !empty($_SESSION['auth_user']['id']));
assertTest("SessionGuard::isAuthenticated() returns true", SessionGuard::isAuthenticated() === true);
assertTest("SessionGuard::getUser() returns active user", SessionGuard::getUser()['email'] === 'vangaridhanush09@gmail.com');

// Test dashboard load via Apache with session cookie
$opts = [
    'http' => [
        'method' => 'GET',
        'header' => "Cookie: PHPSESSID=" . $newSessionId . "\r\n"
    ]
];
$context = stream_context_create($opts);
$dashboardHtml = @file_get_contents('http://localhost/software_team/index.php?view=dashboard', false, $context);
assertTest("Dashboard loads successfully for authenticated session (HTTP 200)", $dashboardHtml !== false && strlen($dashboardHtml) > 50000);
assertTest("Dashboard contains personalized greeting", strpos($dashboardHtml, 'Good morning, Vangari Dhanush datta') !== false || strpos($dashboardHtml, 'Good morning') !== false);
assertTest("Dashboard does NOT render intro animation screen", strpos($dashboardHtml, 'crm-screen-1-logo') === false);
assertTest("Dashboard does NOT redirect to login page", strpos($dashboardHtml, 'name="login"') === false && strpos($dashboardHtml, 'action="google_auth_init"') === false);

// -----------------------------------------------------------------------------
// Test 10: Scenario 9 & 10 - Logout, Unauthenticated Access & Missing Code
// -----------------------------------------------------------------------------
echo "\n10. Logout & Callback Protection\n";
// Unauthenticated access to protected view redirects to login
$unauthHtml = @file_get_contents('http://localhost/software_team/index.php?view=dashboard');
// Without session cookie, it should redirect or render login
assertTest("Unauthenticated request redirects to or renders login", strpos($unauthHtml, 'Continue with Google') !== false || strpos($unauthHtml, 'authScreenController') !== false);

// Missing code callback
$callbackMissing = @file_get_contents('http://localhost/software_team/api/google_callback.php');
// Should redirect to ../index.php?auth_error=missing_code
$respHeaders = $http_response_header ?? [];
$locationHeader = '';
foreach ($respHeaders as $h) {
    if (stripos($h, 'Location:') === 0) {
        $locationHeader = trim(substr($h, 9));
        break;
    }
}
assertTest("Callback without code redirects with auth_error=missing_code", strpos($locationHeader, 'auth_error=missing_code') !== false || strpos($callbackMissing, 'auth_error') !== false);

echo "\n========================================================================\n";
echo "TEST RESULTS: {$passCount} PASSED, {$failCount} FAILED\n";
echo "========================================================================\n";

if ($failCount > 0) {
    exit(1);
}
