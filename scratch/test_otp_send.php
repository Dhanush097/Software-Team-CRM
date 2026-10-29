<?php
/**
 * OTP Send Test - Software CRM
 * Tests actual Mailer::sendOtpEmail() dispatch and reports result.
 * SAFE: does not store or expose credentials; uses safe_env approach.
 */

$projectRoot = dirname(__DIR__);

// Load Mailer class
require_once $projectRoot . '/api/mailer.php';

$testEmail = 'vangaridhanush09@gmail.com';
$testOtp   = sprintf('%04d', random_int(1000, 9999));
$purpose   = 'login';

echo "=== OTP Send Test ===\n";
echo "Recipient : $testEmail\n";
echo "Generated OTP : $testOtp\n";
echo "Timestamp : " . date('Y-m-d H:i:s') . "\n\n";

// Show config (masked)
$cfg = Mailer::getConfig();
echo "--- Mailer Config ---\n";
echo "email_provider  : " . $cfg['email_provider'] . "\n";
echo "app_env         : " . $cfg['app_env'] . "\n";
echo "resend_api_key  : " . (empty($cfg['resend_api_key']) ? '[NOT SET]' : $cfg['resend_api_key']) . "\n";
echo "brevo_api_key   : " . (empty($cfg['brevo_api_key']) ? '[NOT SET]' : $cfg['brevo_api_key']) . "\n";
echo "smtp_host       : " . (empty($cfg['smtp_host']) ? '[NOT SET]' : $cfg['smtp_host']) . "\n";
echo "smtp_user       : " . (empty($cfg['smtp_user']) ? '[NOT SET]' : $cfg['smtp_user']) . "\n";
echo "smtp_pass       : " . (empty($cfg['smtp_pass']) ? '[NOT SET]' : '***configured***') . "\n";
echo "apps_script_url : " . (empty($cfg['apps_script_url']) ? '[NOT SET]' : substr($cfg['apps_script_url'], 0, 60) . '...') . "\n\n";

echo "--- Sending OTP Email ---\n";
$result = Mailer::sendOtpEmail($testEmail, $testOtp, $purpose);

echo "Result:\n";
echo "  success         : " . ($result['success'] ? 'true' : 'false') . "\n";
echo "  provider        : " . ($result['provider'] ?? 'N/A') . "\n";
echo "  email_sent      : " . (isset($result['email_sent']) ? ($result['email_sent'] ? 'true' : 'false') : 'N/A') . "\n";
echo "  quota_exhausted : " . (!empty($result['quota_exhausted']) ? 'true' : 'false') . "\n";
echo "  actual_otp      : " . ($result['actual_otp'] ?? 'N/A') . "\n";
echo "  message         : " . ($result['message'] ?? 'N/A') . "\n";
echo "  notice          : " . ($result['notice'] ?? 'N/A') . "\n";
echo "  error           : " . ($result['error'] ?? 'None') . "\n";

if (!empty($result['diagnostic_info'])) {
    echo "\n  --- Diagnostic Info ---\n";
    foreach ($result['diagnostic_info'] as $provider => $err) {
        echo "  [$provider] $err\n";
    }
}

echo "\n--- Recent Email Delivery Log (last 5 lines) ---\n";
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
echo "\nDone.\n";
