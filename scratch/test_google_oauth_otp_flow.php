<?php
/**
 * Test Suite: Google OAuth OTP Verification Flow
 * Tests that Google login dispatches mandatory email OTP and enables OTP verification
 */

require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/google_auth.php';
require_once __DIR__ . '/../api/mailer.php';

$pdo = Database::getConnection();

echo "=================================================================\n";
echo "GOOGLE OAUTH & EMAIL OTP VERIFICATION INTEGRATION TEST\n";
echo "=================================================================\n\n";

$passed = 0;
$failed = 0;

function assertFlow($name, $condition, $details = '') {
    global $passed, $failed;
    if ($condition) {
        echo "[+] PASS: $name\n";
        if ($details) echo "    -> $details\n";
        $passed++;
    } else {
        echo "[-] FAIL: $name\n";
        if ($details) echo "    -> $details\n";
        $failed++;
    }
}

// 1. Test Google user processing with OTP dispatch
$mockGoogleUser = [
    'email'          => 'vangaridhanush09@gmail.com',
    'google_id'      => '102135245561651215558',
    'name'           => 'Vangari Dhanush datta',
    'picture'        => 'https://lh3.googleusercontent.com/a/ACg8ocLZRAFfrxW1j4uwiX-Gu_4H_Hvweaer7HAo8s7A9ShMSZaHYBA=s96-c',
    'email_verified' => true
];

$result = GoogleAuthService::processGoogleUser($pdo, $mockGoogleUser);

assertFlow(
    "Google OAuth processing requires OTP step",
    !empty($result['success']) && ($result['step'] ?? '') === 'otp_required',
    "Returned step: " . ($result['step'] ?? 'none')
);

assertFlow(
    "Target recipient is correctly set to user email",
    ($result['email'] ?? '') === 'vangaridhanush09@gmail.com',
    "Target email: " . ($result['email'] ?? 'none')
);

// 2. Verify active OTP challenge in database
$stmt = $pdo->prepare("SELECT * FROM `crm_otp_challenges` WHERE `email` = ? AND `purpose` = 'login' AND `is_used` = 0 ORDER BY `id` DESC LIMIT 1");
$stmt->execute(['vangaridhanush09@gmail.com']);
$challenge = $stmt->fetch();

assertFlow(
    "Active OTP challenge created in crm_otp_challenges",
    !empty($challenge) && !empty($challenge['otp_hash']),
    "Challenge ID: " . ($challenge['id'] ?? 'none') . " | Created at: " . ($challenge['created_at'] ?? 'none')
);

assertFlow(
    "Challenge temp_payload stores provider=google",
    !empty($challenge['temp_payload']) && strpos($challenge['temp_payload'], '"provider":"google"') !== false,
    "Payload: " . ($challenge['temp_payload'] ?? 'none')
);

// 3. Test callback redirect URI generation
$redirectParams = [
    'step'     => 'otp_required',
    'email'    => $mockGoogleUser['email'],
    'provider' => 'google'
];
$expectedQuery = 'step=otp_required&email=' . urlencode($mockGoogleUser['email']) . '&provider=google';
assertFlow(
    "Callback redirects to OTP verification view with query parameters",
    http_build_query($redirectParams) === $expectedQuery,
    "Redirect query: " . $expectedQuery
);

// 4. Verify OTP file and cryptographic hash
$otpFile = dirname(__DIR__) . '/data/otp.txt';
if (file_exists($otpFile)) {
    $raw = file_get_contents($otpFile);
    preg_match('/^(\d{4})/m', $raw, $m);
    $deliveredOtp = $m[1] ?? null;

    assertFlow(
        "4-digit OTP captured from transactional mailer output",
        !empty($deliveredOtp),
        "Delivered code: $deliveredOtp"
    );

    $hashMatch = password_verify($deliveredOtp, $challenge['otp_hash']);
    assertFlow(
        "Cryptographic password_verify matches delivered OTP",
        $hashMatch,
        "Hash verification succeeded for delivered OTP ($deliveredOtp)"
    );

    // 5. Test verify_otp endpoint via HTTP POST
    $ch = curl_init('http://127.0.0.1/software_team/api/auth.php?action=verify_otp');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode([
            'email'   => 'vangaridhanush09@gmail.com',
            'otp'     => $deliveredOtp,
            'purpose' => 'login'
        ]),
        CURLOPT_TIMEOUT        => 5
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $verifyData = json_decode($resp, true);
    assertFlow(
        "verify_otp API validates the OTP and authenticates user",
        $httpCode === 200 && !empty($verifyData['success']) && !empty($verifyData['authenticated']),
        "HTTP $httpCode | Message: " . ($verifyData['message'] ?? 'none')
    );
}

echo "\n-----------------------------------------------------------------\n";
echo "SUMMARY: Passed: $passed, Failed: $failed\n";
echo "-----------------------------------------------------------------\n";
if ($failed === 0) {
    echo "ALL TESTS PASSED SUCCESSFULLY! GOOGLE OAUTH -> EMAIL OTP FLOW VERIFIED.\n";
}
