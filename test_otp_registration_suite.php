<?php
/**
 * Software CRM - Dedicated OTP Email & Registration Flow Test Suite
 */

$baseUrl = 'http://127.0.0.1:8000';
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/mailer.php';

$pdo = Database::getConnection();

function makeHttpReq($url, $method = 'POST', $data = [], $cookieFile = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }

    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'code' => $code,
        'body' => $body,
        'json' => json_decode($body, true)
    ];
}

$testsPassed = 0;
$testsFailed = 0;

function assertTest($name, $condition, $details = '') {
    global $testsPassed, $testsFailed;
    if ($condition) {
        $testsPassed++;
        echo "  [PASS] $name" . ($details ? " - $details" : "") . "\n";
    } else {
        $testsFailed++;
        echo "  [FAIL] $name" . ($details ? " - $details" : "") . "\n";
    }
}

echo "=======================================================================\n";
echo " SOFTWARE CRM: OTP EMAIL & REGISTRATION SYSTEM VERIFICATION SUITE\n";
echo "=======================================================================\n\n";

// TEST 1: First Attempt Registration Generates and Dispatches OTP
echo "--- TEST 1: FIRST-ATTEMPT REGISTRATION FLOW ---\n";
$regEmail = 'first_attempt_' . bin2hex(random_bytes(4)) . '@softwarecrm.com';
$cookieJar = tempnam(sys_get_temp_dir(), 'crm_otp_test_');

$res1 = makeHttpReq("$baseUrl/api/auth.php?action=register", 'POST', [
    'name' => 'First Attempt User',
    'email' => $regEmail,
    'password' => 'SecurePassword123!',
    'confirm_password' => 'SecurePassword123!',
    'terms_accepted' => true
], $cookieJar);

assertTest("First-attempt registration returns HTTP 200", $res1['code'] === 200, "HTTP " . $res1['code']);
assertTest("Response indicates step=otp_required", ($res1['json']['step'] ?? '') === 'otp_required', "Step: " . ($res1['json']['step'] ?? 'None'));
assertTest("Challenge exists in database with attempts=0, is_used=0", (function() use ($pdo, $regEmail) {
    $stmt = $pdo->prepare("SELECT * FROM crm_otp_challenges WHERE email = ? AND purpose = 'registration' AND is_used = 0");
    $stmt->execute([$regEmail]);
    $ch = $stmt->fetch();
    return $ch && (int)$ch['attempts'] === 0 && (int)$ch['is_used'] === 0;
})(), "Active challenge in crm_otp_challenges");

// TEST 2: OTP Verification & Account Activation on First Attempt
echo "\n--- TEST 2: FIRST-ATTEMPT OTP VERIFICATION & ACTIVATION ---\n";
// Read OTP from log
$logContent = file_get_contents(__DIR__ . '/data/email_delivery.log');
$regOtp = null;
if (preg_match_all('/Recipient: ' . preg_quote($regEmail, '/') . '.*?Verification Code: (\d{4})/s', $logContent, $m)) {
    $regOtp = end($m[1]);
}

assertTest("OTP was logged upon dispatch", !empty($regOtp), "Delivered Code: $regOtp");

$verifyRes = makeHttpReq("$baseUrl/api/auth.php?action=verify_otp", 'POST', [
    'email' => $regEmail,
    'otp' => $regOtp,
    'purpose' => 'registration'
], $cookieJar);

assertTest("First-attempt OTP verification succeeds with HTTP 200", $verifyRes['code'] === 200, "HTTP " . $verifyRes['code']);
assertTest("User account successfully activated and authenticated", ($verifyRes['json']['authenticated'] ?? false) === true, "User: " . ($verifyRes['json']['user']['name'] ?? 'None'));
assertTest("Challenge is marked is_used=1 after verification", (function() use ($pdo, $regEmail) {
    $stmt = $pdo->prepare("SELECT is_used FROM crm_otp_challenges WHERE email = ? AND purpose = 'registration'");
    $stmt->execute([$regEmail]);
    return (int)$stmt->fetchColumn() === 1;
})(), "One-time challenge consumed");

// TEST 3: Resend OTP Functionality
echo "\n--- TEST 3: RESEND OTP FUNCTIONALITY & COOLDOWN ---\n";
$resendEmail = 'resend_' . bin2hex(random_bytes(4)) . '@softwarecrm.com';
$resendJar = tempnam(sys_get_temp_dir(), 'crm_resend_');

// 1. Initial registration
$regInit = makeHttpReq("$baseUrl/api/auth.php?action=register", 'POST', [
    'name' => 'Resend Tester',
    'email' => $resendEmail,
    'password' => 'SecurePassword123!',
    'confirm_password' => 'SecurePassword123!',
    'terms_accepted' => true
], $resendJar);

assertTest("Initial registration triggers first OTP", $regInit['code'] === 200);

// 2. Immediate resend within 60s should be blocked by cooldown
$resendImmediate = makeHttpReq("$baseUrl/api/auth.php?action=resend_otp", 'POST', [
    'email' => $resendEmail,
    'purpose' => 'registration'
], $resendJar);

assertTest("Immediate resend within cooldown rejected with HTTP 429", $resendImmediate['code'] === 429, "Error: " . ($resendImmediate['json']['error'] ?? ''));

// 3. Fast-forward cooldown by updating last_sent_at in database
$pdo->prepare("UPDATE crm_otp_challenges SET last_sent_at = DATE_SUB(NOW(), INTERVAL 65 SECOND) WHERE email = ?")->execute([$resendEmail]);

// 4. Resend after cooldown expires
$resendAfterCooldown = makeHttpReq("$baseUrl/api/auth.php?action=resend_otp", 'POST', [
    'email' => $resendEmail,
    'purpose' => 'registration'
], $resendJar);

assertTest("Resend after cooldown succeeds with HTTP 200", $resendAfterCooldown['code'] === 200, "Msg: " . ($resendAfterCooldown['json']['message'] ?? ''));

// Read new OTP
$logContent = file_get_contents(__DIR__ . '/data/email_delivery.log');
$newOtp = null;
if (preg_match_all('/Recipient: ' . preg_quote($resendEmail, '/') . '.*?Verification Code: (\d{4})/s', $logContent, $m)) {
    $newOtp = end($m[1]);
}
assertTest("New OTP generated and logged for resend", !empty($newOtp), "New Code: $newOtp");

// 5. Verify using the new OTP
$verifyResend = makeHttpReq("$baseUrl/api/auth.php?action=verify_otp", 'POST', [
    'email' => $resendEmail,
    'otp' => $newOtp,
    'purpose' => 'registration'
], $resendJar);

assertTest("Verification with resent OTP succeeds", $verifyResend['code'] === 200 && ($verifyResend['json']['authenticated'] ?? false) === true);

// TEST 4: Real Email Delivery Failure Error Reporting
echo "\n--- TEST 4: TRANSPARENT ERROR REPORTING ON DELIVERY FAILURE ---\n";
// Attempt to register with an external address when external delivery quota is exhausted
$realEmail = 'simulate_provider_failure_' . bin2hex(random_bytes(3)) . '@softwarecrm.com';
$realJar = tempnam(sys_get_temp_dir(), 'crm_real_');

$realRes = makeHttpReq("$baseUrl/api/auth.php?action=register", 'POST', [
    'name' => 'Real User',
    'email' => $realEmail,
    'password' => 'SecurePassword123!',
    'confirm_password' => 'SecurePassword123!',
    'terms_accepted' => true
], $realJar);

assertTest("Failed delivery does NOT report false success", ($realRes['json']['success'] ?? true) === false, "Success is false");
assertTest("Appropriate HTTP error code returned (503/500)", in_array($realRes['code'], [500, 503]), "HTTP Code: " . $realRes['code']);
assertTest("Clear descriptive error message returned to user", !empty($realRes['json']['error']) && strpos($realRes['json']['error'], 'Unable to deliver') !== false, "Error: " . ($realRes['json']['error'] ?? ''));
assertTest("No orphaned unused challenge left in database", (function() use ($pdo, $realEmail) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM crm_otp_challenges WHERE email = ? AND is_used = 0");
    $stmt->execute([$realEmail]);
    return (int)$stmt->fetchColumn() === 0;
})(), "Database kept clean on failed dispatch");

echo "\n=======================================================================\n";
echo " TEST SUITE SUMMARY: $testsPassed PASSED, $testsFailed FAILED\n";
echo "=======================================================================\n";

if ($testsFailed > 0) exit(1);
exit(0);
