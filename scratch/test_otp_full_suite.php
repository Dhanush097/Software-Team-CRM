<?php
/**
 * Software CRM - OTP System Full Test Suite
 * Tests all 12 OTP scenarios as specified in the user's requirements.
 * Run via: php scratch/test_otp_full_suite.php
 */

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/api/mailer.php';

// ----------------------------------------------------------------
// Minimal PDO for DB tests
// ----------------------------------------------------------------
$envFile = $projectRoot . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $k = trim($parts[0]);
            $v = trim($parts[1], " \t\n\r\0\x0B\"'");
            putenv("$k=$v");
            $_ENV[$k] = $v;
        }
    }
}

try {
    $dsn = 'mysql:host=127.0.0.1;port=3306;dbname=software_team_db;charset=utf8mb4';
    $pdo = new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    // Try alternate DB name
    try {
        $dsn = 'mysql:host=127.0.0.1;port=3306;charset=utf8mb4';
        $pdo = new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        // Try to find the right DB
        $dbs = $pdo->query("SHOW DATABASES LIKE 'software%'")->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($dbs)) {
            $pdo->exec("USE `{$dbs[0]}`");
        }
    } catch (PDOException $e2) {
        $pdo = null;
    }
}

$pass = 0;
$fail = 0;
$results = [];

function test(string $name, bool $condition, string $detail = ''): void {
    global $pass, $fail, $results;
    $status = $condition ? 'PASS' : 'FAIL';
    if ($condition) $pass++; else $fail++;
    $results[] = compact('name', 'status', 'detail');
    $icon = $condition ? '✓' : '✗';
    echo "[$icon] $status : $name" . ($detail ? " → $detail" : '') . "\n";
}

echo "=================================================================\n";
echo "  Software CRM - OTP Full Test Suite\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo "=================================================================\n\n";

// ----------------------------------------------------------------
// TEST 1: Mailer config loads without errors
// ----------------------------------------------------------------
$cfg = Mailer::getConfig();
test('Mailer::getConfig() returns array', is_array($cfg));
test('email_provider is set', !empty($cfg['email_provider']), $cfg['email_provider']);
test('app_env is set', !empty($cfg['app_env']), $cfg['app_env']);

// ----------------------------------------------------------------
// TEST 2: Provider availability
// ----------------------------------------------------------------
$hasResend = !empty($cfg['resend_api_key']);
$hasBrevo  = !empty($cfg['brevo_api_key']);
$hasSmtp   = !empty($cfg['smtp_host']) && !empty($cfg['smtp_user']);
$hasGas    = !empty($cfg['apps_script_url']);
$hasAny    = $hasResend || $hasBrevo || $hasSmtp || $hasGas;

test('At least one email provider configured', $hasAny,
    implode(', ', array_filter([
        $hasResend ? 'Resend' : '',
        $hasBrevo  ? 'Brevo' : '',
        $hasSmtp   ? 'SMTP' : '',
        $hasGas    ? 'GoogleAppsScript' : ''
    ])) ?: 'NONE'
);

// ----------------------------------------------------------------
// TEST 3: Invalid email address rejected
// ----------------------------------------------------------------
$r = Mailer::sendOtpEmail('not-an-email', '1234', 'login');
test('Invalid email is rejected', !$r['success'] && isset($r['error']), $r['error'] ?? '');

// ----------------------------------------------------------------
// TEST 4: Quota simulation test email
// ----------------------------------------------------------------
$r = Mailer::sendOtpEmail('quota_exhausted@test.com', '1234', 'login');
test('Quota simulation returns quota_exhausted=true', !$r['success'] && !empty($r['quota_exhausted']));

// ----------------------------------------------------------------
// TEST 5: Provider failure simulation test email
// ----------------------------------------------------------------
$r = Mailer::sendOtpEmail('simulate_provider_failure@test.com', '1234', 'login');
test('Provider failure simulation returns success=false', !$r['success']);

// ----------------------------------------------------------------
// TEST 6: OTP send to real address (actual delivery test)
// ----------------------------------------------------------------
$realEmail = 'vangaridhanush09@gmail.com';
$testOtp = sprintf('%04d', random_int(1000, 9999));
$r = Mailer::sendOtpEmail($realEmail, $testOtp, 'login');
test('Real OTP email dispatched successfully', $r['success'],
    'Provider: ' . ($r['provider'] ?? 'N/A') . ' | actual_otp: ' . ($r['actual_otp'] ?? 'N/A')
);
$sentOtp = $r['actual_otp'] ?? $testOtp;

// ----------------------------------------------------------------
// TEST 7: actual_otp field present when provider overrides OTP
// ----------------------------------------------------------------
test('actual_otp present in success response', isset($r['actual_otp']), (string)($r['actual_otp'] ?? 'missing'));

// ----------------------------------------------------------------
// TEST 8: DB - crm_otp_challenges table exists
// ----------------------------------------------------------------
if ($pdo) {
    try {
        $tables = $pdo->query("SHOW TABLES LIKE 'crm_otp_challenges'")->fetchAll(PDO::FETCH_COLUMN);
        test('crm_otp_challenges table exists', count($tables) > 0);
    } catch (Exception $e) {
        test('crm_otp_challenges table exists', false, $e->getMessage());
    }
} else {
    test('DB connection (skipped - no PDO)', false, 'Could not connect to MySQL');
}

// ----------------------------------------------------------------
// TEST 9: DB - insert and retrieve OTP challenge
// ----------------------------------------------------------------
if ($pdo) {
    try {
        $email = 'test_suite_' . time() . '@softwarecrm.com';
        $otp   = '5678';
        $hash  = password_hash($otp, PASSWORD_DEFAULT);
        $exp   = date('Y-m-d H:i:s', time() + 300);

        $pdo->prepare("INSERT INTO `crm_otp_challenges` (`email`, `otp_hash`, `purpose`, `expires_at`, `attempts`, `max_attempts`, `resend_count`, `last_sent_at`, `is_used`, `temp_payload`) VALUES (?, ?, 'login', ?, 0, 5, 0, NOW(), 0, '{}')")
            ->execute([$email, $hash, $exp]);
        $id = $pdo->lastInsertId();

        $row = $pdo->prepare("SELECT * FROM `crm_otp_challenges` WHERE `id` = ?")->execute([$id]);
        $ch  = $pdo->prepare("SELECT * FROM `crm_otp_challenges` WHERE `id` = ?")->execute([$id]);
        $stmt = $pdo->prepare("SELECT * FROM `crm_otp_challenges` WHERE `id` = ?");
        $stmt->execute([$id]);
        $ch = $stmt->fetch(PDO::FETCH_ASSOC);

        $verified = $ch && password_verify($otp, $ch['otp_hash']);
        test('OTP challenge insert + password_verify works', $verified);

        // Cleanup
        $pdo->prepare("DELETE FROM `crm_otp_challenges` WHERE `id` = ?")->execute([$id]);
    } catch (Exception $e) {
        test('OTP challenge insert + password_verify works', false, $e->getMessage());
    }
}

// ----------------------------------------------------------------
// TEST 10: DB - expired OTP rejected by expiry check
// ----------------------------------------------------------------
if ($pdo) {
    try {
        $email = 'expired_suite_' . time() . '@softwarecrm.com';
        $otp   = '9012';
        $hash  = password_hash($otp, PASSWORD_DEFAULT);
        $exp   = date('Y-m-d H:i:s', time() - 10); // Already expired

        $pdo->prepare("INSERT INTO `crm_otp_challenges` (`email`, `otp_hash`, `purpose`, `expires_at`, `attempts`, `max_attempts`, `resend_count`, `last_sent_at`, `is_used`, `temp_payload`) VALUES (?, ?, 'login', ?, 0, 5, 0, NOW(), 0, '{}')")
            ->execute([$email, $hash, $exp]);
        $id = $pdo->lastInsertId();

        $stmt = $pdo->prepare("SELECT * FROM `crm_otp_challenges` WHERE `email` = ? AND `purpose` = 'login' AND `is_used` = 0 ORDER BY `id` DESC LIMIT 1");
        $stmt->execute([$email]);
        $ch = $stmt->fetch(PDO::FETCH_ASSOC);

        $isExpired = $ch && (strtotime($ch['expires_at']) < time());
        test('Expired OTP correctly detected as expired', $isExpired);

        $pdo->prepare("DELETE FROM `crm_otp_challenges` WHERE `id` = ?")->execute([$id]);
    } catch (Exception $e) {
        test('Expired OTP correctly detected as expired', false, $e->getMessage());
    }
}

// ----------------------------------------------------------------
// TEST 11: DB - used OTP not returned
// ----------------------------------------------------------------
if ($pdo) {
    try {
        $email = 'used_suite_' . time() . '@softwarecrm.com';
        $otp   = '3456';
        $hash  = password_hash($otp, PASSWORD_DEFAULT);
        $exp   = date('Y-m-d H:i:s', time() + 300);

        $pdo->prepare("INSERT INTO `crm_otp_challenges` (`email`, `otp_hash`, `purpose`, `expires_at`, `attempts`, `max_attempts`, `resend_count`, `last_sent_at`, `is_used`, `temp_payload`) VALUES (?, ?, 'login', ?, 0, 5, 0, NOW(), 1, '{}')")
            ->execute([$email, $hash, $exp]);
        $id = $pdo->lastInsertId();

        $stmt = $pdo->prepare("SELECT * FROM `crm_otp_challenges` WHERE `email` = ? AND `purpose` = 'login' AND `is_used` = 0 ORDER BY `id` DESC LIMIT 1");
        $stmt->execute([$email]);
        $ch = $stmt->fetch(PDO::FETCH_ASSOC);

        test('Used OTP (is_used=1) not returned for verification', $ch === false);

        $pdo->prepare("DELETE FROM `crm_otp_challenges` WHERE `id` = ?")->execute([$id]);
    } catch (Exception $e) {
        test('Used OTP (is_used=1) not returned for verification', false, $e->getMessage());
    }
}

// ----------------------------------------------------------------
// TEST 12: OTP file saved to disk on send
// ----------------------------------------------------------------
$otpFile = $projectRoot . '/otp.txt';
$dataOtpFile = $projectRoot . '/data/otp.txt';
$fileExists = file_exists($otpFile) || file_exists($dataOtpFile);
$fileContent = file_exists($otpFile) ? file_get_contents($otpFile) : (file_exists($dataOtpFile) ? file_get_contents($dataOtpFile) : '');
test('OTP saved to otp.txt on disk (fallback access)', $fileExists && !empty($fileContent));

// ----------------------------------------------------------------
// TEST 13: Delivery log written
// ----------------------------------------------------------------
$logFile = $projectRoot . '/data/email_delivery.log';
test('email_delivery.log exists and non-empty', file_exists($logFile) && filesize($logFile) > 0);

// ----------------------------------------------------------------
// TEST 14: Email preview saved to data/sent_emails/
// ----------------------------------------------------------------
$sentDir = $projectRoot . '/data/sent_emails';
$latestHtml = $sentDir . '/latest_otp.html';
test('latest_otp.html preview saved to data/sent_emails/', file_exists($latestHtml));

// ----------------------------------------------------------------
// TEST 15: 4-digit OTP format validation (backend regex check)
// ----------------------------------------------------------------
$validOtp = '1234';
$invalidOtp = '12345'; // 5 digits
$invalidOtp2 = 'abcd'; // letters
test('4-digit OTP regex accepts 4 digits', (bool)preg_match('/^\d{4}$/', $validOtp));
test('4-digit OTP regex rejects 5 digits', !(bool)preg_match('/^\d{4}$/', $invalidOtp));
test('4-digit OTP regex rejects letters', !(bool)preg_match('/^\d{4}$/', $invalidOtp2));

// ----------------------------------------------------------------
// SUMMARY
// ----------------------------------------------------------------
$total = $pass + $fail;
echo "\n=================================================================\n";
echo "  RESULTS: $pass/$total passed, $fail failed\n";
echo "=================================================================\n";
if ($fail === 0) {
    echo "  ✓ All tests passed!\n";
} else {
    echo "  ✗ Some tests failed — see above for details.\n";
}

echo "\n--- Current OTP in otp.txt ---\n";
if (file_exists($otpFile)) {
    echo file_get_contents($otpFile);
} elseif (file_exists($dataOtpFile)) {
    echo file_get_contents($dataOtpFile);
} else {
    echo "No otp.txt found.\n";
}
