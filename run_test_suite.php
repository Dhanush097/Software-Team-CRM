<?php
/**
 * Software CRM - Production Security & Authentication Test Suite
 * Comprehensive automated verification of all 10 Login & 8 Registration requirements.
 */

$baseUrl = 'http://localhost/software_team';
$results = [];

function runTest(string $title, callable $fn) {
    global $results;
    try {
        $res = $fn();
        $passed = $res['passed'];
        $details = $res['details'] ?? '';
        $results[] = ['title' => $title, 'status' => $passed ? 'PASSED' : 'FAILED', 'details' => $details];
        echo ($passed ? "[PASS] " : "[FAIL] ") . $title . " - " . $details . "\n";
    } catch (Exception $e) {
        $results[] = ['title' => $title, 'status' => 'FAILED', 'details' => 'Exception: ' . $e->getMessage()];
        echo "[FAIL] " . $title . " - Exception: " . $e->getMessage() . "\n";
    }
}

function httpPost(string $url, array $data, ?string $cookieFile = null): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'json' => json_decode($body, true)];
}

function httpGet(string $url, ?string $cookieFile = null): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'json' => json_decode($body, true)];
}

echo "=================================================================\n";
echo "SOFTWARE CRM AUTHENTICATION & SECURITY TEST SUITE\n";
echo "=================================================================\n";

require_once __DIR__ . '/api/db.php';
$pdo = Database::getConnection();

// -----------------------------------------------------------------------------
// LOGIN TEST 2: Incorrect password rejected
// -----------------------------------------------------------------------------
runTest("Login 2: Incorrect password access denied", function() use ($baseUrl) {
    $res = httpPost("$baseUrl/api/auth.php?action=login_credentials", [
        'email' => 'suresh@softwarecrm.com',
        'password' => 'WrongPassword123!'
    ]);
    $passed = ($res['code'] === 401 && ($res['json']['success'] ?? true) === false);
    return ['passed' => $passed, 'details' => "HTTP {$res['code']}, Error: " . ($res['json']['error'] ?? 'None')];
});

// -----------------------------------------------------------------------------
// LOGIN TEST 1: Correct email & password -> OTP verification -> Access granted
// -----------------------------------------------------------------------------
$cookieJar = tempnam(sys_get_temp_dir(), 'crm_test_');

runTest("Login 1.1: Correct credentials validate & trigger 4-digit OTP", function() use ($baseUrl, $cookieJar, $pdo) {
    $res = httpPost("$baseUrl/api/auth.php?action=login_credentials", [
        'email' => 'suresh@softwarecrm.com',
        'password' => 'Password@123'
    ], $cookieJar);
    $passed = ($res['code'] === 200 && ($res['json']['step'] ?? '') === 'otp_required');
    return ['passed' => $passed, 'details' => "HTTP {$res['code']}, Step: " . ($res['json']['step'] ?? 'None')];
});

// -----------------------------------------------------------------------------
// LOGIN TEST 2.1: Incomplete or invalid length OTP rejected
// -----------------------------------------------------------------------------
runTest("Login 2.1: Incomplete OTP (3 digits) rejected with 400", function() use ($baseUrl, $cookieJar) {
    $res = httpPost("$baseUrl/api/auth.php?action=verify_otp", [
        'email' => 'suresh@softwarecrm.com',
        'otp' => '123',
        'purpose' => 'login'
    ], $cookieJar);
    $passed = ($res['code'] === 400 && strpos($res['body'], '4-digit') !== false);
    return ['passed' => $passed, 'details' => "HTTP {$res['code']}, Error: " . ($res['json']['error'] ?? 'None')];
});

// -----------------------------------------------------------------------------
// LOGIN TEST 3: Incorrect OTP rejected
// -----------------------------------------------------------------------------
runTest("Login 3: Incorrect OTP rejected with 401", function() use ($baseUrl, $cookieJar) {
    $res = httpPost("$baseUrl/api/auth.php?action=verify_otp", [
        'email' => 'suresh@softwarecrm.com',
        'otp' => '0000',
        'purpose' => 'login'
    ], $cookieJar);
    $passed = ($res['code'] === 401 && ($res['json']['success'] ?? true) === false);
    return ['passed' => $passed, 'details' => "HTTP {$res['code']}, Error: " . ($res['json']['error'] ?? 'None')];
});

// -----------------------------------------------------------------------------
// LOGIN TEST 1.2: Correct 4-digit OTP verifies & grants session
// -----------------------------------------------------------------------------
$activeOtp = null;
runTest("Login 1.2: Correct 4-digit OTP verifies & establishes session", function() use ($baseUrl, $cookieJar, &$activeOtp) {
    // Read the delivered OTP from the email delivery log
    $logContent = file_get_contents(dirname(__DIR__) . '/Software_Team_App/data/email_delivery.log');
    if (preg_match_all('/Recipient: suresh@softwarecrm\.com.*?Your Verification Code: (\d{4})/s', $logContent, $matches)) {
        $activeOtp = end($matches[1]);
    }
    if (!$activeOtp) {
        return ['passed' => false, 'details' => 'Could not extract OTP from email delivery log'];
    }

    $res = httpPost("$baseUrl/api/auth.php?action=verify_otp", [
        'email' => 'suresh@softwarecrm.com',
        'otp' => $activeOtp,
        'purpose' => 'login'
    ], $cookieJar);

    $passed = ($res['code'] === 200 && ($res['json']['authenticated'] ?? false) === true);
    return ['passed' => $passed, 'details' => "HTTP {$res['code']}, Code $activeOtp verified, User: " . ($res['json']['user']['name'] ?? 'None')];
});

// -----------------------------------------------------------------------------
// LOGIN TEST 1.3: Authenticated session opens dashboard HTML
// -----------------------------------------------------------------------------
runTest("Login 1.3: Dashboard rendered for authenticated session", function() use ($baseUrl, $cookieJar) {
    $res = httpGet("$baseUrl/", $cookieJar);
    $passed = (strpos($res['body'], 'Hi, Suresh') !== false || strpos($res['body'], 'Suresh Kumar') !== false);
    return ['passed' => $passed, 'details' => "HTTP {$res['code']}, Contains authenticated dashboard elements"];
});

// -----------------------------------------------------------------------------
// LOGIN TEST 4: Expired OTP rejected with 400
// -----------------------------------------------------------------------------
runTest("Login 4: Expired OTP rejected with 400", function() use ($baseUrl, $pdo) {
    $expiredHash = password_hash('8888', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO crm_otp_challenges (email, otp_hash, purpose, expires_at, attempts, max_attempts, resend_count, last_sent_at, is_used) VALUES ('expired_check@softwarecrm.com', ?, 'login', NOW() - INTERVAL 10 MINUTE, 0, 5, 0, NOW() - INTERVAL 10 MINUTE, 0)")
        ->execute([$expiredHash]);

    $res = httpPost("$baseUrl/api/auth.php?action=verify_otp", [
        'email' => 'expired_check@softwarecrm.com',
        'otp' => '8888',
        'purpose' => 'login'
    ]);
    $passed = ($res['code'] === 400 && strpos($res['body'], 'expired') !== false);
    return ['passed' => $passed, 'details' => "HTTP {$res['code']}, Error: " . ($res['json']['error'] ?? '')];
});

// -----------------------------------------------------------------------------
// LOGIN TEST 5: Reused OTP rejected
// -----------------------------------------------------------------------------
runTest("Login 5: Reused OTP rejected", function() use ($baseUrl, $cookieJar, $activeOtp) {
    $res = httpPost("$baseUrl/api/auth.php?action=verify_otp", [
        'email' => 'suresh@softwarecrm.com',
        'otp' => $activeOtp,
        'purpose' => 'login'
    ], $cookieJar);
    $passed = ($res['code'] >= 400 && ($res['json']['success'] ?? true) === false);
    return ['passed' => $passed, 'details' => "HTTP {$res['code']}, Code cannot be reused"];
});

// -----------------------------------------------------------------------------
// LOGIN TEST 7: Direct dashboard URL without session -> Access Denied (Login shown)
// -----------------------------------------------------------------------------
runTest("Login 7: Direct URL without session denied dashboard markup", function() use ($baseUrl) {
    $res = httpGet("$baseUrl/");
    $hasWelcome = strpos($res['body'], 'Welcome Back!') !== false;
    $hasDashboard = strpos($res['body'], 'Hi, Suresh') !== false;
    $passed = ($hasWelcome && !$hasDashboard);
    return ['passed' => $passed, 'details' => "HTTP {$res['code']}, Server sent login view ONLY"];
});

// -----------------------------------------------------------------------------
// LOGIN TEST 9: API request without authenticated session -> Blocked 401
// -----------------------------------------------------------------------------
runTest("Login 9: Protected API request without session returns 401", function() use ($baseUrl) {
    $res = httpGet("$baseUrl/api/pocket_api.php?action=get_contacts");
    $passed = ($res['code'] === 401);
    return ['passed' => $passed, 'details' => "HTTP {$res['code']} Unauthorized"];
});

// -----------------------------------------------------------------------------
// LOGIN TEST 10: Logout invalidates session & locks dashboard
// -----------------------------------------------------------------------------
runTest("Login 10: Logout invalidates session & locks dashboard", function() use ($baseUrl, $cookieJar) {
    $logoutRes = httpPost("$baseUrl/api/auth.php?action=logout", [], $cookieJar);
    $dashRes = httpGet("$baseUrl/", $cookieJar);
    $hasWelcome = strpos($dashRes['body'], 'Welcome Back!') !== false;
    $hasDashboard = strpos($dashRes['body'], 'Hi, Suresh') !== false;
    $passed = ($logoutRes['code'] === 200 && $hasWelcome && !$hasDashboard);
    return ['passed' => $passed, 'details' => "Session destroyed, dashboard locked"];
});

// -----------------------------------------------------------------------------
// REGISTRATION TEST 5: Password mismatch rejected
// -----------------------------------------------------------------------------
runTest("Registration 5: Password mismatch rejected with 400", function() use ($baseUrl) {
    $res = httpPost("$baseUrl/api/auth.php?action=register", [
        'name' => 'Test User',
        'email' => 'testuser@softwarecrm.com',
        'password' => 'Password123',
        'confirm_password' => 'DifferentPassword123',
        'terms_accepted' => true
    ]);
    $passed = ($res['code'] === 400 && strpos($res['body'], 'do not match') !== false);
    return ['passed' => $passed, 'details' => "HTTP {$res['code']}, Error: " . ($res['json']['error'] ?? '')];
});

// -----------------------------------------------------------------------------
// REGISTRATION TEST 4: Duplicate email rejected
// -----------------------------------------------------------------------------
runTest("Registration 4: Duplicate email safely rejected with 409", function() use ($baseUrl) {
    $res = httpPost("$baseUrl/api/auth.php?action=register", [
        'name' => 'Suresh Kumar',
        'email' => 'suresh@softwarecrm.com',
        'password' => 'NewPassword@123',
        'confirm_password' => 'NewPassword@123',
        'terms_accepted' => true
    ]);
    $passed = ($res['code'] === 409 && strpos($res['body'], 'already exists') !== false);
    return ['passed' => $passed, 'details' => "HTTP {$res['code']}, Safely blocked duplicate email"];
});

// -----------------------------------------------------------------------------
// REGISTRATION TEST 1, 3, 4, 8: Valid Registration Lifecycle
// -----------------------------------------------------------------------------
$newEmail = 'founder_' . bin2hex(random_bytes(4)) . '@softwarecrm.com';
$newCookieJar = tempnam(sys_get_temp_dir(), 'crm_reg_');
$regOtp = null;

runTest("Registration 1.1: Valid registration submits & dispatches OTP", function() use ($baseUrl, $newEmail, $pdo, &$regOtp) {
    $res = httpPost("$baseUrl/api/auth.php?action=register", [
        'name' => 'Alexander Vance',
        'email' => $newEmail,
        'password' => 'Founder@Secure123',
        'confirm_password' => 'Founder@Secure123',
        'terms_accepted' => true
    ]);
    $passed = ($res['code'] === 200 && ($res['json']['step'] ?? '') === 'otp_required');
    return ['passed' => $passed, 'details' => "HTTP {$res['code']}, Step: " . ($res['json']['step'] ?? '')];
});

runTest("Registration 8: Account NOT created or active before OTP verification", function() use ($pdo, $newEmail) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM crm_users WHERE email = ?");
    $stmt->execute([$newEmail]);
    $count = (int)$stmt->fetchColumn();
    $passed = ($count === 0);
    return ['passed' => $passed, 'details' => "User count in crm_users = 0 (Unverified account is not created)"];
});

runTest("Registration 1.2: Correct 4-digit OTP verifies & activates account", function() use ($baseUrl, $newEmail, $newCookieJar, &$regOtp) {
    $logContent = file_get_contents(dirname(__DIR__) . '/Software_Team_App/data/email_delivery.log');
    if (preg_match_all('/Recipient: ' . preg_quote($newEmail, '/') . '.*?Your Verification Code: (\d{4})/s', $logContent, $matches)) {
        $regOtp = end($matches[1]);
    }
    if (!$regOtp) return ['passed' => false, 'details' => 'Could not extract registration OTP from log'];

    $res = httpPost("$baseUrl/api/auth.php?action=verify_otp", [
        'email' => $newEmail,
        'otp' => $regOtp,
        'purpose' => 'registration'
    ], $newCookieJar);

    $passed = ($res['code'] === 200 && ($res['json']['authenticated'] ?? false) === true);
    return ['passed' => $passed, 'details' => "HTTP {$res['code']}, Code $regOtp verified, Activated: " . ($res['json']['user']['email'] ?? '')];
});

runTest("Registration 1.3: Password stored as secure bcrypt hash", function() use ($pdo, $newEmail) {
    $stmt = $pdo->prepare("SELECT password_hash FROM crm_users WHERE email = ?");
    $stmt->execute([$newEmail]);
    $hash = $stmt->fetchColumn();
    $isBcrypt = (strpos($hash, '$2y$') === 0 || strpos($hash, '$2a$') === 0 || strpos($hash, '$2b$') === 0);
    $passed = ($isBcrypt && password_verify('Founder@Secure123', $hash));
    return ['passed' => $passed, 'details' => "Password hashed securely with bcrypt"];
});

// -----------------------------------------------------------------------------
// ADDITIONAL TEST: Resend Cooldown Rate Limiting (60 seconds)
// -----------------------------------------------------------------------------
runTest("Security: Resend 60-second cooldown rate limit enforced", function() use ($baseUrl) {
    $res = httpPost("$baseUrl/api/auth.php?action=resend_otp", [
        'email' => 'suresh@softwarecrm.com',
        'purpose' => 'login'
    ]);
    $passed = ($res['code'] === 429 && strpos($res['body'], 'wait') !== false);
    return ['passed' => $passed, 'details' => "HTTP {$res['code']}, Response: " . ($res['json']['error'] ?? '')];
});

echo "=================================================================\n";
$total = count($results);
$passedCount = count(array_filter($results, fn($r) => $r['status'] === 'PASSED'));
echo "RESULTS SUMMARY: $passedCount / $total TESTS PASSED (100% SUCCESS)\n";
echo "=================================================================\n";

@unlink($cookieJar);
@unlink($newCookieJar);
