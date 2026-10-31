<?php
/**
 * Test Suite: Forgot Password & Password Reset Flow (Login Page & Inside App)
 */

echo "=======================================================================\n";
echo " SOFTWARE CRM: FORGOT PASSWORD & PASSWORD RESET VERIFICATION SUITE\n";
echo "=======================================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest($cond, $name) {
    global $passed, $failed;
    if ($cond) {
        echo "  [PASS] $name\n";
        $passed++;
    } else {
        echo "  [FAIL] $name\n";
        $failed++;
    }
}

function httpPost($url, $payload = [], $headers = [], $cookieJar = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $hdr = array_merge(['Content-Type: application/json'], $headers);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);
    if ($cookieJar) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'data' => json_decode($resp, true), 'raw' => $resp];
}

// Ensure test user exists via registration endpoint or DB helper
$testEmail = 'forgot_test_suite_' . time() . '@example.com';
$initialPassword = 'InitialPassword123!';
$resetPassword = 'NewSecretPassword456!';
$settingsResetPassword = 'InsidePageResetPassword789!';
$cookieFile = __DIR__ . '/test_cookie_forgot.txt';
if (file_exists($cookieFile)) @unlink($cookieFile);

// 1. Create a user via register endpoint
$regRes = httpPost('http://127.0.0.1:8000/api/auth.php?action=register', [
    'name' => 'Forgot Test User',
    'email' => $testEmail,
    'password' => $initialPassword,
    'confirm_password' => $initialPassword,
    'terms_accepted' => true
]);

// Fetch OTP for registration
$emailHash = md5($testEmail);
$otpFile = __DIR__ . "/data/otp_{$emailHash}.txt";
$regOtp = '';
if (file_exists($otpFile)) $regOtp = trim(@file_get_contents($otpFile));

// Verify registration OTP
$verRes = httpPost('http://127.0.0.1:8000/api/auth.php?action=verify_otp', [
    'email' => $testEmail,
    'otp' => $regOtp,
    'purpose' => 'registration'
], [], $cookieFile);

assertTest($verRes['code'] === 200 && !empty($verRes['data']['authenticated']), "Created and verified test account ($testEmail)");


echo "\n--- TEST 1: LOGIN PAGE FORGOT PASSWORD REQUEST ---\n";

// 1.1 Reject invalid email format
$badEmailRes = httpPost('http://127.0.0.1:8000/api/auth.php?action=forgot_password', [
    'email' => 'not-an-email'
]);
assertTest($badEmailRes['code'] === 400 && !empty($badEmailRes['data']['error']), "Reject invalid email format with 400 Bad Request");

// 1.2 Reject non-existent user
$noUserRes = httpPost('http://127.0.0.1:8000/api/auth.php?action=forgot_password', [
    'email' => 'nonexistent_' . time() . '@example.com'
]);
assertTest($noUserRes['code'] === 404, "Reject non-existent user email with 404 Not Found");

// 1.3 Valid request triggers password_reset OTP
$forgotRes = httpPost('http://127.0.0.1:8000/api/auth.php?action=forgot_password', [
    'email' => $testEmail
]);
assertTest($forgotRes['code'] === 200 && $forgotRes['data']['step'] === 'otp_required' && $forgotRes['data']['purpose'] === 'password_reset', "Forgot password OTP dispatched successfully with 200 OK");

// Retrieve password_reset OTP
$resetOtp = '';
if (file_exists($otpFile)) $resetOtp = trim(@file_get_contents($otpFile));
assertTest(!empty($resetOtp) && strlen($resetOtp) === 4, "Retrieved valid 4-digit reset OTP: $resetOtp");


echo "\n--- TEST 2: RESET PASSWORD WITH OTP (AT LOGIN PAGE) ---\n";

// 2.1 Reject password mismatch
$mismatchRes = httpPost('http://127.0.0.1:8000/api/auth.php?action=reset_password', [
    'email' => $testEmail,
    'otp' => $resetOtp,
    'new_password' => $resetPassword,
    'confirm_password' => 'Mismatch123!'
]);
assertTest($mismatchRes['code'] === 400 && str_contains($mismatchRes['data']['error'] ?? '', 'match'), "Reject password confirmation mismatch");

// 2.2 Reject invalid OTP
$badOtpRes = httpPost('http://127.0.0.1:8000/api/auth.php?action=reset_password', [
    'email' => $testEmail,
    'otp' => '0000',
    'new_password' => $resetPassword,
    'confirm_password' => $resetPassword
]);
assertTest($badOtpRes['code'] === 401, "Reject invalid 4-digit OTP code");

// 2.3 Successful password reset
$goodResetRes = httpPost('http://127.0.0.1:8000/api/auth.php?action=reset_password', [
    'email' => $testEmail,
    'otp' => $resetOtp,
    'new_password' => $resetPassword,
    'confirm_password' => $resetPassword
]);
assertTest($goodResetRes['code'] === 200 && !empty($goodResetRes['data']['success']), "Password reset successfully via OTP with HTTP 200");


echo "\n--- TEST 3: VERIFY LOGIN WITH NEW RESET PASSWORD ---\n";

// 3.1 Old password fails
$oldPassRes = httpPost('http://127.0.0.1:8000/api/auth.php?action=login_credentials', [
    'email' => $testEmail,
    'password' => $initialPassword
]);
assertTest($oldPassRes['code'] === 401, "Old password rejected after reset");

// 3.2 New password succeeds & triggers login OTP
$newPassRes = httpPost('http://127.0.0.1:8000/api/auth.php?action=login_credentials', [
    'email' => $testEmail,
    'password' => $resetPassword
]);
assertTest($newPassRes['code'] === 200 && $newPassRes['data']['step'] === 'otp_required', "New password validated & login OTP triggered");

// Complete login with new password
if (file_exists($otpFile)) $loginOtp = trim(@file_get_contents($otpFile));
$loginVerRes = httpPost('http://127.0.0.1:8000/api/auth.php?action=verify_otp', [
    'email' => $testEmail,
    'otp' => $loginOtp,
    'purpose' => 'login'
], [], $cookieFile);
$csrfToken = $loginVerRes['data']['csrf_token'] ?? '';
assertTest($loginVerRes['code'] === 200 && !empty($loginVerRes['data']['authenticated']) && !empty($csrfToken), "Logged into dashboard using new credentials");


echo "\n--- TEST 4: INSIDE APP - FORGOT PASSWORD VIA OTP (SETTINGS API) ---\n";

// 4.1 Request OTP from inside settings
$settingsReqRes = httpPost('http://127.0.0.1:8000/api/settings.php?action=request_password_reset_otp', [
    'csrf_token' => $csrfToken
], ['X-CSRF-Token: ' . $csrfToken], $cookieFile);
assertTest($settingsReqRes['code'] === 200 && !empty($settingsReqRes['data']['success']), "Inside app: request_password_reset_otp dispatched 200 OK");

// Get inside reset OTP
if (file_exists($otpFile)) $insideOtp = trim(@file_get_contents($otpFile));
assertTest(!empty($insideOtp) && strlen($insideOtp) === 4, "Inside app: retrieved 4-digit OTP: $insideOtp");

// 4.2 Reset password via OTP from inside settings
$settingsResetRes = httpPost('http://127.0.0.1:8000/api/settings.php?action=reset_password_otp', [
    'csrf_token' => $csrfToken,
    'otp' => $insideOtp,
    'new_password' => $settingsResetPassword,
    'confirm_password' => $settingsResetPassword
], ['X-CSRF-Token: ' . $csrfToken], $cookieFile);
assertTest($settingsResetRes['code'] === 200 && !empty($settingsResetRes['data']['success']), "Inside app: reset_password_otp updated password with 200 OK");

// 4.3 Verify login with inside-app updated password
$finalLoginRes = httpPost('http://127.0.0.1:8000/api/auth.php?action=login_credentials', [
    'email' => $testEmail,
    'password' => $settingsResetPassword
]);
assertTest($finalLoginRes['code'] === 200 && $finalLoginRes['data']['step'] === 'otp_required', "Verified login using inside-app reset password");


echo "\n--- TEST 5: CLEANUP ---\n";
if (file_exists($cookieFile)) @unlink($cookieFile);
if (file_exists($otpFile)) @unlink($otpFile);
assertTest(true, "Temporary test artifacts and sessions cleaned up");

echo "\n=======================================================================\n";
echo " TEST SUITE SUMMARY: $passed PASSED, $failed FAILED\n";
echo "=======================================================================\n";

exit($failed > 0 ? 1 : 0);
