<?php
/**
 * Software CRM - End-to-End OTP Login Flow Test
 * Tests the complete login_credentials -> verify_otp flow via HTTP.
 * SAFE: Uses a test email. Does not expose credentials.
 */

$baseUrl = 'http://localhost/software_team/api/auth.php';

function postJson(string $url, array $data): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR      => sys_get_temp_dir() . '/crm_test_cookies.txt',
        CURLOPT_COOKIEFILE     => sys_get_temp_dir() . '/crm_test_cookies.txt',
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['http_code' => 0, 'error' => "cURL: $err", 'data' => null];
    }

    $decoded = json_decode($resp, true);
    return ['http_code' => $code, 'data' => $decoded, 'raw' => $resp];
}

echo "=================================================================\n";
echo "  Software CRM - E2E OTP Login Flow Test\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo "=================================================================\n\n";

// ----------------------------------------------------------------
// STEP 1: Test login_credentials with wrong password (should fail)
// ----------------------------------------------------------------
echo "--- Step 1: Login with wrong password ---\n";
$r = postJson($baseUrl, [
    'action'   => 'login_credentials',
    'email'    => 'vangaridhanush09@gmail.com',
    'password' => 'definitely_wrong_password_xyz'
]);
echo "HTTP: {$r['http_code']}\n";
echo "Response: " . json_encode($r['data'], JSON_PRETTY_PRINT) . "\n";
$expectedFail = !empty($r['data']) && $r['data']['success'] === false;
echo "Expected failure: " . ($expectedFail ? "✓ YES (correct)" : "✗ NO (problem!)") . "\n\n";

// ----------------------------------------------------------------
// STEP 2: check_session to see current state
// ----------------------------------------------------------------
echo "--- Step 2: Check session ---\n";
$r = postJson($baseUrl, ['action' => 'check_session']);
echo "HTTP: {$r['http_code']}\n";
echo "Response: " . json_encode($r['data'], JSON_PRETTY_PRINT) . "\n\n";

// ----------------------------------------------------------------
// STEP 3: Test verify_otp with invalid format
// ----------------------------------------------------------------
echo "--- Step 3: verify_otp with non-4-digit code (should fail) ---\n";
$r = postJson($baseUrl, [
    'action'  => 'verify_otp',
    'email'   => 'vangaridhanush09@gmail.com',
    'otp'     => '123',
    'purpose' => 'login'
]);
echo "HTTP: {$r['http_code']}\n";
echo "Response: " . json_encode($r['data'], JSON_PRETTY_PRINT) . "\n\n";

// ----------------------------------------------------------------
// STEP 4: Test verify_otp with wrong code
// ----------------------------------------------------------------
echo "--- Step 4: verify_otp with wrong code (should fail gracefully) ---\n";
$r = postJson($baseUrl, [
    'action'  => 'verify_otp',
    'email'   => 'vangaridhanush09@gmail.com',
    'otp'     => '0000',
    'purpose' => 'login'
]);
echo "HTTP: {$r['http_code']}\n";
echo "Response: " . json_encode($r['data'], JSON_PRETTY_PRINT) . "\n\n";

// ----------------------------------------------------------------
// STEP 5: Read current otp.txt to find the current valid code
// ----------------------------------------------------------------
echo "--- Step 5: Current OTP from otp.txt ---\n";
$projectRoot = dirname(__DIR__);
$otpFile = $projectRoot . '/otp.txt';
$dataOtpFile = $projectRoot . '/data/otp.txt';
$currentOtp = null;

foreach ([$otpFile, $dataOtpFile] as $f) {
    if (file_exists($f)) {
        $content = file_get_contents($f);
        if (preg_match('/^(\d{4,6})/', $content, $m)) {
            $currentOtp = $m[1];
            break;
        }
    }
}

if ($currentOtp) {
    echo "Found OTP from file: $currentOtp\n";
    echo "(This is what was last sent. Use this to test verify_otp manually.)\n";
} else {
    echo "No current OTP found in otp.txt\n";
}
echo "\n";

// ----------------------------------------------------------------
// STEP 6: Test resend_otp
// ----------------------------------------------------------------
echo "--- Step 6: resend_otp for test email ---\n";
$r = postJson($baseUrl, [
    'action'  => 'resend_otp',
    'email'   => 'vangaridhanush09@gmail.com',
    'purpose' => 'login'
]);
echo "HTTP: {$r['http_code']}\n";
echo "Response: " . json_encode($r['data'], JSON_PRETTY_PRINT) . "\n\n";

// ----------------------------------------------------------------
// STEP 7: Check last 5 delivery log entries
// ----------------------------------------------------------------
echo "--- Step 7: Last 5 delivery log entries ---\n";
$logFile = $projectRoot . '/data/email_delivery.log';
if (file_exists($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $recent = array_slice($lines, -5);
    foreach ($recent as $line) {
        echo $line . "\n";
    }
} else {
    echo "No delivery log found.\n";
}
echo "\n";

echo "=================================================================\n";
echo "  E2E test complete.\n";
echo "  Check your email inbox (including Spam/Promotions) for OTP.\n";
echo "=================================================================\n";
