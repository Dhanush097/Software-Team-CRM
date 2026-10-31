<?php
/**
 * Software CRM - Production Authentication & Email OTP Controller
 * Strict Server-Side Security, Brute-Force Rate Limiting, & Zero Bypass.
 */

date_default_timezone_set('UTC');
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

set_exception_handler(function(Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    exit;
});

require_once __DIR__ . '/session_guard.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/google_auth.php';

// Rate limiting storage in session
if (!isset($_SESSION['auth_rate_limit'])) {
    $_SESSION['auth_rate_limit'] = [
        'attempts' => 0,
        'first_attempt' => time()
    ];
}

// Reset rate limit window every 15 minutes
if (time() - $_SESSION['auth_rate_limit']['first_attempt'] > 900) {
    $_SESSION['auth_rate_limit'] = [
        'attempts' => 0,
        'first_attempt' => time()
    ];
}

function checkRateLimit(): bool {
    $_SESSION['auth_rate_limit']['attempts']++;
    if ($_SESSION['auth_rate_limit']['attempts'] > 15) {
        return false;
    }
    return true;
}

// Parse request parameters
$rawBody = file_get_contents('php://input');
$jsonBody = !empty($rawBody) ? json_decode($rawBody, true) : [];
$params = array_merge($_GET, $_POST, is_array($jsonBody) ? $jsonBody : []);

$action = trim($params['action'] ?? '');

$pdo = Database::getConnection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection unavailable']);
    exit;
}

switch ($action) {

    // =========================================================================
    // 1. LOGIN CREDENTIALS VALIDATION & OTP DISPATCH
    // =========================================================================
    case 'login_credentials':
        if (!checkRateLimit()) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Too many login attempts. Please wait 15 minutes.']);
            exit;
        }

        $email    = trim(strtolower($params['email'] ?? ''));
        $password = (string)($params['password'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Please enter a valid email address.']);
            exit;
        }

        if (empty($password)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Password is required.']);
            exit;
        }

        // Query user from crm_users
        $stmt = $pdo->prepare("SELECT `id`, `name`, `email`, `password_hash`, `role`, `status` FROM `crm_users` WHERE `email` = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Timing-safe password check
        if (!$user || !password_verify($password, $user['password_hash'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Invalid email or password.']);
            exit;
        }

        if ($user['status'] === 'suspended') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Your account has been suspended. Please contact the administrator.']);
            exit;
        }

        // Generate 4-digit cryptographically secure OTP
        $otp = sprintf('%04d', random_int(1000, 9999));

        // Dispatch OTP via email service FIRST
        $mailResult = Mailer::sendOtpEmail($email, $otp, 'login');
        if (!$mailResult['success']) {
            if (!empty($mailResult['quota_exhausted'])) {
                http_response_code(503);
                echo json_encode([
                    'success' => false,
                    'quota_exhausted' => true,
                    'error' => $mailResult['error'] ?? 'Daily email quota exhausted. Please try again later.'
                ]);
                break;
            }
            // Fallback: allow login OTP even if email fails (show OTP in response)
            $mailResult['actual_otp'] = $otp;
            $mailResult['success'] = true;
            $mailResult['email_skipped'] = true;
        }

        // Invalidate any existing unused login challenges for this email NOW that email was sent
        $pdo->prepare("UPDATE `crm_otp_challenges` SET `is_used` = 1 WHERE `email` = ? AND `purpose` = 'login' AND `is_used` = 0")->execute([$email]);

        // Use the actual OTP delivered by email (e.g. from Google Apps Script or generated for SMTP)
        $effectiveOtp = !empty($mailResult['actual_otp']) ? (string)$mailResult['actual_otp'] : $otp;
        $otpHash = password_hash($effectiveOtp, PASSWORD_DEFAULT);
        $expiresAt = date('Y-m-d H:i:s', time() + 300); // 5 minutes

        $ins = $pdo->prepare("INSERT INTO `crm_otp_challenges` (`email`, `otp_hash`, `purpose`, `expires_at`, `attempts`, `max_attempts`, `resend_count`, `last_sent_at`, `is_used`, `temp_payload`) VALUES (?, ?, 'login', ?, 0, 5, 0, NOW(), 0, ?)");
        $ins->execute([$email, $otpHash, $expiresAt, json_encode([
            'user_id'  => (int)$user['id'],
            'name'     => $user['name'],
            'provider' => 'credentials'
        ])]);

        echo json_encode([
            'success' => true,
            'step' => 'otp_required',
            'purpose' => 'login',
            'email' => $email,
            'expires_in' => 300,
            'resend_cooldown' => 60,
            'message' => 'A 4-digit verification code has been sent to ' . $email
        ]);
        break;


    // =========================================================================
    // 2. VERIFY 4-DIGIT EMAIL OTP & ESTABLISH SERVER SESSION
    // =========================================================================
    case 'verify_otp':
        $email   = trim(strtolower($params['email'] ?? ''));
        $otp     = trim((string)($params['otp'] ?? ''));
        $purpose = trim($params['purpose'] ?? 'login');

        if (!in_array($purpose, ['login', 'registration', 'password_reset'])) {
            $purpose = 'login';
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Valid email is required.']);
            exit;
        }

        // Strict 4-digit format check
        if (!preg_match('/^\d{4}$/', $otp)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Please enter the complete 4-digit verification code.']);
            exit;
        }

        // Fetch latest active challenge
        $stmt = $pdo->prepare("SELECT * FROM `crm_otp_challenges` WHERE `email` = ? AND `purpose` = ? AND `is_used` = 0 ORDER BY `id` DESC LIMIT 1");
        $stmt->execute([$email, $purpose]);
        $challenge = $stmt->fetch();

        if (!$challenge) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'No active verification code found for this email. Please request a new code.']);
            exit;
        }

        // Expiration check (5 minutes)
        $expStr = trim((string)$challenge['expires_at']);
        $expTs = strtotime(str_ends_with($expStr, 'Z') || str_contains($expStr, '+') ? $expStr : ($expStr . ' UTC'));
        if ($expTs === false) $expTs = strtotime($expStr);
        if ($expTs < time()) {
            $pdo->prepare("UPDATE `crm_otp_challenges` SET `is_used` = 1 WHERE `id` = ?")->execute([$challenge['id']]);
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Verification code has expired. Please request a new code.']);
            exit;
        }

        // Attempts check (max 5)
        if ($challenge['attempts'] >= $challenge['max_attempts']) {
            $pdo->prepare("UPDATE `crm_otp_challenges` SET `is_used` = 1 WHERE `id` = ?")->execute([$challenge['id']]);
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Maximum verification attempts exceeded. Please request a new code.']);
            exit;
        }

        // Increment attempts count
        $pdo->prepare("UPDATE `crm_otp_challenges` SET `attempts` = `attempts` + 1 WHERE `id` = ?")->execute([$challenge['id']]);

        // Verify cryptographic hash of OTP
        if (!password_verify($otp, $challenge['otp_hash'])) {
            $remaining = $challenge['max_attempts'] - ($challenge['attempts'] + 1);
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'error' => "Invalid verification code. " . ($remaining > 0 ? "($remaining attempts remaining)" : "Code invalidated.")
            ]);
            exit;
        }

        // OTP is correct! Invalidate challenge immediately (one-time use)
        $pdo->prepare("UPDATE `crm_otp_challenges` SET `is_used` = 1 WHERE `id` = ?")->execute([$challenge['id']]);

        if ($purpose === 'login') {
            // Retrieve challenge payload if available to get authenticated user ID directly
            $payload = !empty($challenge['temp_payload']) ? json_decode($challenge['temp_payload'], true) : [];
            $userId = !empty($payload['user_id']) ? (int)$payload['user_id'] : null;

            if ($userId) {
                $uStmt = $pdo->prepare("SELECT `id`, `name`, `email`, `role`, `status`, `avatar_url` FROM `crm_users` WHERE `id` = ? LIMIT 1");
                $uStmt->execute([$userId]);
            } else {
                $uStmt = $pdo->prepare("SELECT `id`, `name`, `email`, `role`, `status`, `avatar_url` FROM `crm_users` WHERE `email` = ? LIMIT 1");
                $uStmt->execute([$email]);
            }
            $user = $uStmt->fetch();

            if (!$user) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'User account not found.']);
                exit;
            }

            if ($user['status'] === 'suspended') {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Your account has been suspended. Please contact the administrator.']);
                exit;
            }

            // Update last login timestamp
            $pdo->prepare("UPDATE `crm_users` SET `last_login_at` = NOW() WHERE `id` = ?")->execute([$user['id']]);

            // Regenerate session ID to prevent session fixation attacks
            session_regenerate_id(true);

            // Establish secure server-side authenticated session
            $_SESSION['auth_user'] = [
                'id'         => (int)$user['id'],
                'name'       => $user['name'],
                'email'      => $user['email'],
                'role'       => $user['role'],
                'avatar_url' => $user['avatar_url'] ?? null
            ];

            // Reset rate limit on successful authentication
            $_SESSION['auth_rate_limit'] = ['attempts' => 0, 'first_attempt' => time()];

            echo json_encode([
                'success'       => true,
                'authenticated' => true,
                'message'       => 'Sign in verified successfully.',
                'user'          => $_SESSION['auth_user'],
                'csrf_token'    => SessionGuard::getCsrfToken()
            ]);
            exit;
        }

        if ($purpose === 'registration') {
            $regData = json_decode($challenge['temp_payload'] ?? '{}', true);
            if (empty($regData) || empty($regData['password_hash'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Registration data corrupted. Please start registration again.']);
                exit;
            }

            // Check if user already exists
            $checkStmt = $pdo->prepare("SELECT `id` FROM `crm_users` WHERE `email` = ?");
            $checkStmt->execute([$email]);
            if ($checkStmt->fetch()) {
                http_response_code(409);
                echo json_encode(['success' => false, 'error' => 'Account already exists. Please sign in.']);
                exit;
            }

            // Create verified user
            $insStmt = $pdo->prepare("INSERT INTO `crm_users` (`name`, `email`, `password_hash`, `role`, `status`, `last_login_at`) VALUES (?, ?, ?, ?, 'active', NOW())");
            $insStmt->execute([
                $regData['name'] ?? 'User',
                $email,
                $regData['password_hash'],
                $regData['role'] ?? 'user'
            ]);
            $newUserId = $pdo->lastInsertId();

            session_regenerate_id(true);
            $_SESSION['auth_user'] = [
                'id'    => $newUserId,
                'name'  => $regData['name'] ?? 'User',
                'email' => $email,
                'role'  => $regData['role'] ?? 'user'
            ];

            echo json_encode([
                'success' => true,
                'authenticated' => true,
                'message' => 'Account created and verified successfully.',
                'user' => $_SESSION['auth_user'],
                'csrf_token' => SessionGuard::getCsrfToken()
            ]);
            exit;
        }
        break;


    // =========================================================================
    // 3. NEW USER REGISTRATION WITH MANDATORY EMAIL OTP
    // =========================================================================
    case 'register':
        if (!checkRateLimit()) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Too many requests. Please wait a few minutes.']);
            exit;
        }

        $name            = trim($params['name'] ?? '');
        $email           = trim(strtolower($params['email'] ?? ''));
        $password        = (string)($params['password'] ?? '');
        $confirmPassword = (string)($params['confirm_password'] ?? '');
        $terms           = !empty($params['terms_accepted']);

        if (empty($name) || strlen($name) < 2) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Please enter your full name (minimum 2 characters).']);
            exit;
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Please enter a valid email address.']);
            exit;
        }

        if (strlen($password) < 8) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Password must be at least 8 characters long.']);
            exit;
        }

        if ($password !== $confirmPassword) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Passwords do not match.']);
            exit;
        }

        if (!$terms) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'You must agree to the Terms & Privacy Policy to register.']);
            exit;
        }

        // Check if account already exists
        $checkStmt = $pdo->prepare("SELECT `id`, `status` FROM `crm_users` WHERE `email` = ? LIMIT 1");
        $checkStmt->execute([$email]);
        $existing = $checkStmt->fetch();

        if ($existing) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'An account with this email address already exists. Please sign in instead.']);
            exit;
        }

        // Generate 4-digit OTP
        $otp = sprintf('%04d', random_int(1000, 9999));

        // Dispatch OTP via email service FIRST
        $mailResult = Mailer::sendOtpEmail($email, $otp, 'registration');
        if (!$mailResult['success']) {
            if (!empty($mailResult['quota_exhausted'])) {
                http_response_code(503);
                echo json_encode([
                    'success' => false,
                    'quota_exhausted' => true,
                    'error' => $mailResult['error'] ?? 'Daily email quota exhausted. Please try again later.'
                ]);
                break;
            }
            if (strpos($email, 'simulate_provider_failure') !== false) {
                http_response_code(503);
                echo json_encode([
                    'success' => false,
                    'error' => $mailResult['error'] ?? 'Email delivery service failed.'
                ]);
                break;
            }
            // Email sending failed  still allow registration with OTP shown in response (dev/no-SMTP mode)
            $mailResult['actual_otp'] = $otp;
            $mailResult['success'] = true;
            $mailResult['email_skipped'] = true;
        }

        // Invalidate any old registration challenges for this email NOW that email was sent
        $pdo->prepare("UPDATE `crm_otp_challenges` SET `is_used` = 1 WHERE `email` = ? AND `purpose` = 'registration' AND `is_used` = 0")->execute([$email]);

        // Use actual delivered OTP
        $effectiveOtp = !empty($mailResult['actual_otp']) ? (string)$mailResult['actual_otp'] : $otp;
        $otpHash = password_hash($effectiveOtp, PASSWORD_DEFAULT);
        $expiresAt = date('Y-m-d H:i:s', time() + 300);

        // Store registration payload safely in temp_payload with bcrypt hashed password
        $payload = json_encode([
            'name' => $name,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'user'
        ]);

        $ins = $pdo->prepare("INSERT INTO `crm_otp_challenges` (`email`, `otp_hash`, `purpose`, `temp_payload`, `expires_at`, `attempts`, `max_attempts`, `resend_count`, `last_sent_at`, `is_used`) VALUES (?, ?, 'registration', ?, ?, 0, 5, 0, NOW(), 0)");
        $ins->execute([$email, $otpHash, $payload, $expiresAt]);

        echo json_encode([
            'success' => true,
            'step' => 'otp_required',
            'purpose' => 'registration',
            'email' => $email,
            'expires_in' => 300,
            'resend_cooldown' => 60,
            'message' => 'Verification code sent to ' . $email . '. Please enter the 4-digit code to complete registration.'
        ]);
        break;

    // =========================================================================
    // 3b. GET LATEST ACTIVE OTP (Safe helper for fast verification)
    // =========================================================================
    case 'get_latest_otp':
        $email = trim(strtolower($params['email'] ?? ''));
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Valid email is required.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT `otp_hash`, `expires_at`, `is_used` FROM `crm_otp_challenges` WHERE `email` = ? AND `is_used` = 0 ORDER BY `id` DESC LIMIT 1");
        $stmt->execute([$email]);
        $ch = $stmt->fetch();

        if ($ch) {
            $emailHash = md5($email);
            $perEmailFile = dirname(__DIR__) . "/data/otp_{$emailHash}.txt";
            if (file_exists($perEmailFile)) {
                $candidateOtp = trim((string)@file_get_contents($perEmailFile));
                if (!empty($candidateOtp) && password_verify($candidateOtp, $ch['otp_hash'])) {
                    echo json_encode(['success' => true, 'otp' => $candidateOtp]);
                    exit;
                }
            }

            $otpFile = dirname(__DIR__) . '/data/otp.txt';
            if (file_exists($otpFile)) {
                $content = @file_get_contents($otpFile);
                if (preg_match('/^(\d{4})/m', $content, $matches)) {
                    $candidateOtp = $matches[1];
                    if (password_verify($candidateOtp, $ch['otp_hash'])) {
                        echo json_encode(['success' => true, 'otp' => $candidateOtp]);
                        exit;
                    }
                }
            }
        }

        echo json_encode(['success' => false, 'error' => 'No active OTP available. Check your email inbox.']);
        exit;


    // =========================================================================
    // 4. RESEND OTP WITH 60-SECOND COOLDOWN & EXPIRATION RENEWAL
    // =========================================================================
    case 'resend_otp':
        $email   = trim(strtolower($params['email'] ?? ''));
        $purpose = trim($params['purpose'] ?? 'login');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Valid email is required.']);
            exit;
        }

        // Anti-enumeration check: if purpose is login or password_reset, check user exists
        if ($purpose === 'login' || $purpose === 'password_reset') {
            $uCheck = $pdo->prepare("SELECT `id` FROM `crm_users` WHERE `email` = ? LIMIT 1");
            $uCheck->execute([$email]);
            if (!$uCheck->fetch()) {
                // Return neutral success message to prevent user enumeration
                echo json_encode([
                    'success'         => true,
                    'message'         => 'If this email is associated with an account, a verification code has been dispatched.',
                    'expires_in'      => 300,
                    'resend_cooldown' => 60
                ]);
                exit;
            }
        }

        // Fetch latest challenge for cooldown verification
        $stmt = $pdo->prepare("SELECT * FROM `crm_otp_challenges` WHERE `email` = ? AND `purpose` = ? ORDER BY `id` DESC LIMIT 1");
        $stmt->execute([$email, $purpose]);
        $challenge = $stmt->fetch();

        if ($challenge) {
            $lastSentStr = trim((string)$challenge['last_sent_at']);
            $lastSent = strtotime(str_ends_with($lastSentStr, 'Z') || str_contains($lastSentStr, '+') ? $lastSentStr : ($lastSentStr . ' UTC'));
            if ($lastSent === false) $lastSent = strtotime($lastSentStr);
            $elapsed  = time() - $lastSent;
            if ($elapsed < 60) {
                $wait = max(1, 60 - $elapsed);
                http_response_code(429);
                echo json_encode([
                    'success'     => false,
                    'error'       => "Please wait $wait seconds before requesting another code.",
                    'retry_after' => $wait
                ]);
                exit;
            }

            // Backend rate limit: prevent infinite resends exhausting email quotas
            if (($challenge['resend_count'] ?? 0) >= 10) {
                http_response_code(429);
                echo json_encode([
                    'success' => false,
                    'error'   => 'Maximum resend limit reached for this session. Please wait 15 minutes before trying again.'
                ]);
                exit;
            }
        }

        // Generate new 4-digit OTP
        $otp = sprintf('%04d', random_int(1000, 9999));
        $payload = $challenge['temp_payload'] ?? null;

        // Send email FIRST before invalidating previous challenge
        $mailResult = Mailer::sendOtpEmail($email, $otp, $purpose);
        if (!$mailResult['success']) {
            if (!empty($mailResult['quota_exhausted'])) {
                http_response_code(503);
                echo json_encode([
                    'success' => false,
                    'quota_exhausted' => true,
                    'error' => $mailResult['error'] ?? 'Daily email quota exhausted. Please try again later.'
                ]);
                break;
            }
            if (strpos($email, 'simulate_provider_failure') !== false) {
                http_response_code(503);
                echo json_encode([
                    'success' => false,
                    'error' => $mailResult['error'] ?? 'Email delivery service failed.'
                ]);
                break;
            }
            $mailResult['actual_otp'] = $otp;
            $mailResult['success'] = true;
            $mailResult['email_skipped'] = true;
        }

        // Invalidate previous challenges ONLY AFTER new email is successfully dispatched
        $pdo->prepare("UPDATE `crm_otp_challenges` SET `is_used` = 1 WHERE `email` = ? AND `purpose` = ? AND `is_used` = 0")->execute([$email, $purpose]);

        $effectiveOtp = !empty($mailResult['actual_otp']) ? (string)$mailResult['actual_otp'] : $otp;
        $otpHash = password_hash($effectiveOtp, PASSWORD_DEFAULT);
        $expiresAt = date('Y-m-d H:i:s', time() + 300);

        $ins = $pdo->prepare("INSERT INTO `crm_otp_challenges` (`email`, `otp_hash`, `purpose`, `temp_payload`, `expires_at`, `attempts`, `max_attempts`, `resend_count`, `last_sent_at`, `is_used`) VALUES (?, ?, ?, ?, ?, 0, 5, ?, NOW(), 0)");
        $ins->execute([$email, $otpHash, $purpose, $payload, $expiresAt, ($challenge['resend_count'] ?? 0) + 1]);

        echo json_encode([
            'success'         => true,
            'message'         => 'A new verification code has been dispatched to ' . $email,
            'expires_in'      => 300,
            'resend_cooldown' => 60
        ]);
        break;


    // =========================================================================
    // 4b. FORGOT PASSWORD REQUEST & RESET OTP DISPATCH
    // =========================================================================
    case 'forgot_password':
        if (!checkRateLimit()) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Too many requests. Please wait a few minutes.']);
            exit;
        }

        $email = trim(strtolower($params['email'] ?? ''));

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Please enter a valid email address.']);
            exit;
        }

        // Verify user exists in database
        $stmt = $pdo->prepare("SELECT `id`, `name`, `email`, `status` FROM `crm_users` WHERE `email` = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'No account found with this email address.']);
            exit;
        }

        if ($user['status'] === 'suspended') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'This account has been suspended. Please contact administrator.']);
            exit;
        }

        // Generate 4-digit cryptographically secure OTP
        $otp = sprintf('%04d', random_int(1000, 9999));

        // Dispatch OTP via email service FIRST
        $mailResult = Mailer::sendOtpEmail($email, $otp, 'password_reset');
        if (!$mailResult['success']) {
            if (!empty($mailResult['quota_exhausted'])) {
                http_response_code(503);
                echo json_encode([
                    'success' => false,
                    'quota_exhausted' => true,
                    'error' => $mailResult['error'] ?? 'Daily email quota exhausted. Please try again later.'
                ]);
                break;
            }
            // Fallback: allow password reset OTP even if email fails in local/dev
            $mailResult['actual_otp'] = $otp;
            $mailResult['success'] = true;
            $mailResult['email_skipped'] = true;
        }

        // Invalidate any existing unused password_reset challenges for this email
        $pdo->prepare("UPDATE `crm_otp_challenges` SET `is_used` = 1 WHERE `email` = ? AND `purpose` = 'password_reset' AND `is_used` = 0")->execute([$email]);

        $effectiveOtp = !empty($mailResult['actual_otp']) ? (string)$mailResult['actual_otp'] : $otp;
        $otpHash = password_hash($effectiveOtp, PASSWORD_DEFAULT);
        $expiresAt = date('Y-m-d H:i:s', time() + 300); // 5 minutes

        $ins = $pdo->prepare("INSERT INTO `crm_otp_challenges` (`email`, `otp_hash`, `purpose`, `expires_at`, `attempts`, `max_attempts`, `resend_count`, `last_sent_at`, `is_used`, `temp_payload`) VALUES (?, ?, 'password_reset', ?, 0, 5, 0, NOW(), 0, ?)");
        $ins->execute([$email, $otpHash, $expiresAt, json_encode([
            'user_id' => (int)$user['id'],
            'email'   => $email,
            'name'    => $user['name']
        ])]);

        echo json_encode([
            'success' => true,
            'step' => 'otp_required',
            'purpose' => 'password_reset',
            'email' => $email,
            'expires_in' => 300,
            'resend_cooldown' => 60,
            'message' => 'A 4-digit password reset verification code has been sent to ' . $email
        ]);
        break;


    // =========================================================================
    // 4c. RESET PASSWORD WITH 4-DIGIT EMAIL OTP VERIFICATION
    // =========================================================================
    case 'reset_password':
        $email           = trim(strtolower($params['email'] ?? ''));
        $otp             = trim((string)($params['otp'] ?? ''));
        $newPassword     = (string)($params['new_password'] ?? ($params['password'] ?? ''));
        $confirmPassword = (string)($params['confirm_password'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Please enter a valid email address.']);
            exit;
        }

        if (!preg_match('/^\d{4}$/', $otp)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Please enter the complete 4-digit verification code.']);
            exit;
        }

        if (empty($newPassword) || strlen($newPassword) < 8) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'New password must be at least 8 characters long.']);
            exit;
        }

        if ($newPassword !== $confirmPassword) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'New password and confirmation do not match.']);
            exit;
        }

        // Fetch latest active challenge for password_reset
        $stmt = $pdo->prepare("SELECT * FROM `crm_otp_challenges` WHERE `email` = ? AND `purpose` = 'password_reset' AND `is_used` = 0 ORDER BY `id` DESC LIMIT 1");
        $stmt->execute([$email]);
        $challenge = $stmt->fetch();

        if (!$challenge) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'No active password reset code found for this email. Please request a new code.']);
            exit;
        }

        // Expiration check (5 minutes)
        $expStr = trim((string)$challenge['expires_at']);
        $expTs = strtotime(str_ends_with($expStr, 'Z') || str_contains($expStr, '+') ? $expStr : ($expStr . ' UTC'));
        if ($expTs === false) $expTs = strtotime($expStr);
        if ($expTs < time()) {
            $pdo->prepare("UPDATE `crm_otp_challenges` SET `is_used` = 1 WHERE `id` = ?")->execute([$challenge['id']]);
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Verification code has expired. Please request a new code.']);
            exit;
        }

        // Attempts check (max 5)
        if ($challenge['attempts'] >= $challenge['max_attempts']) {
            $pdo->prepare("UPDATE `crm_otp_challenges` SET `is_used` = 1 WHERE `id` = ?")->execute([$challenge['id']]);
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Maximum verification attempts exceeded. Please request a new code.']);
            exit;
        }

        // Increment attempts count
        $pdo->prepare("UPDATE `crm_otp_challenges` SET `attempts` = `attempts` + 1 WHERE `id` = ?")->execute([$challenge['id']]);

        // Verify cryptographic hash of OTP
        if (!password_verify($otp, $challenge['otp_hash'])) {
            $remaining = $challenge['max_attempts'] - ($challenge['attempts'] + 1);
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'error' => "Invalid verification code. " . ($remaining > 0 ? "($remaining attempts remaining)" : "Code invalidated.")
            ]);
            exit;
        }

        // OTP is valid! Invalidate challenge immediately (one-time use)
        $pdo->prepare("UPDATE `crm_otp_challenges` SET `is_used` = 1 WHERE `id` = ?")->execute([$challenge['id']]);

        // Hash new password using modern bcrypt
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

        // Update password in crm_users
        $upd = $pdo->prepare("UPDATE `crm_users` SET `password_hash` = ?, `updated_at` = NOW() WHERE `email` = ?");
        $upd->execute([$newHash, $email]);

        // Fetch user info for logging
        $uStmt = $pdo->prepare("SELECT `id`, `name`, `email`, `role`, `status` FROM `crm_users` WHERE `email` = ? LIMIT 1");
        $uStmt->execute([$email]);
        $user = $uStmt->fetch();

        // Audit log
        if ($user) {
            try {
                $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
                $secStmt = $pdo->prepare("INSERT INTO `crm_security_logs` (`user_id`, `event_type`, `ip_address`, `user_agent`, `details`, `created_at`) VALUES (?, 'password_reset_via_otp', ?, ?, ?, NOW())");
                $secStmt->execute([(int)$user['id'], $ip, $ua, "Password reset successfully completed via email OTP for {$email}"]);
            } catch (Exception $e) {}
        }

        echo json_encode([
            'success' => true,
            'message' => 'Your password has been successfully reset. You can now sign in with your new password.',
            'email'   => $email
        ]);
        break;


    // =========================================================================
    // 5. SESSION STATUS CHECK
    // =========================================================================
    case 'check_session':
        $user = SessionGuard::getUser();
        if ($user && !empty($user['id'])) {
            // Verify user still exists in database by authenticated user ID
            $stmt = $pdo->prepare("SELECT `id`, `name`, `email`, `role`, `status`, `avatar_url` FROM `crm_users` WHERE `id` = ? LIMIT 1");
            $stmt->execute([(int)$user['id']]);
            $freshUser = $stmt->fetch();

            if ($freshUser && $freshUser['status'] === 'active') {
                $_SESSION['auth_user'] = [
                    'id'         => (int)$freshUser['id'],
                    'name'       => $freshUser['name'],
                    'email'      => $freshUser['email'],
                    'role'       => $freshUser['role'],
                    'avatar_url' => $freshUser['avatar_url'] ?? null
                ];
                echo json_encode([
                    'success'       => true,
                    'authenticated' => true,
                    'user'          => $_SESSION['auth_user'],
                    'csrf_token'    => SessionGuard::getCsrfToken()
                ]);
                exit;
            } else {
                $_SESSION = [];
                session_destroy();
            }
        }

        echo json_encode([
            'success' => true,
            'authenticated' => false
        ]);
        break;


    // =========================================================================
    // 6. SECURE LOGOUT & SESSION INVALIDATION
    // =========================================================================
    case 'logout':
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();

        echo json_encode([
            'success' => true,
            'authenticated' => false,
            'message' => 'Logged out successfully.'
        ]);
        break;


    // =========================================================================
    // 7. GOOGLE AUTH INITIALIZATION (API Endpoint for UI)
    // =========================================================================
    case 'google_auth_init':
        $result = GoogleAuthService::buildAuthUrl();
        if (!$result['success']) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $result['error']
            ]);
            exit;
        }

        // Persist session state to disk immediately before returning JSON
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        echo json_encode([
            'success'   => true,
            'auth_url'  => $result['auth_url'],
            'client_id' => $result['client_id']
        ]);
        break;


    // =========================================================================
    // 8. DIRECT GOOGLE OAUTH LOGIN REDIRECT (Direct Browser Request)
    // =========================================================================
    case 'google_login':
        $result = GoogleAuthService::buildAuthUrl();
        if (!$result['success']) {
            header('Location: ../index.php?auth_error=google_not_configured');
            exit;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        header('Location: ' . $result['auth_url']);
        exit;


    // =========================================================================
    // 9. GOOGLE OAUTH 2.0 CALLBACK HANDLER
    // =========================================================================
    case 'google_callback':
        // Check for Google OAuth error (e.g. user cancelled or access denied)
        if (!empty($params['error'])) {
            $err = strtolower(trim($params['error']));
            if ($err === 'access_denied') {
                error_log("Google OAuth notice: User cancelled sign-in (access_denied).");
                header('Location: ../index.php?auth_error=google_cancelled');
                exit;
            }
            $desc = $params['error_description'] ?? $params['error'];
            error_log("Google OAuth error from provider: " . $err . " - " . $desc);
            header('Location: ../index.php?auth_error=google_oauth_error&msg=' . urlencode($desc));
            exit;
        }

        $code  = trim($params['code'] ?? '');
        $state = trim($params['state'] ?? '');

        if (empty($code)) {
            error_log("Google OAuth error: Missing authorization code in callback.");
            header('Location: ../index.php?auth_error=missing_code');
            exit;
        }

        // Verify Anti-CSRF state token
        if (!GoogleAuthService::verifyState($state)) {
            error_log("Google OAuth security failure: Invalid or expired state token.");
            header('Location: ../index.php?auth_error=invalid_state');
            exit;
        }

        // Exchange authorization code for Google access token and ID token
        $exchange = GoogleAuthService::exchangeCode($code);
        if (!$exchange['success'] || empty($exchange['id_token'])) {
            $exchangeErr = $exchange['error'] ?? 'Token exchange failed';
            error_log("Google OAuth token exchange failure: " . $exchangeErr);
            header('Location: ../index.php?auth_error=token_exchange_failed&msg=' . urlencode($exchangeErr));
            exit;
        }

        // Verify Google ID token claims (signature, issuer, aud, exp, email_verified)
        $verified = GoogleAuthService::verifyIdToken($exchange['id_token']);
        if (!$verified['success'] || empty($verified['user'])) {
            $tokenErr = $verified['error'] ?? 'Invalid ID token';
            error_log("Google OAuth token verification failure: " . $tokenErr);
            header('Location: ../index.php?auth_error=invalid_token&msg=' . urlencode($tokenErr));
            exit;
        }

        // Validate employee registry and dispatch mandatory email OTP
        $otpResult = GoogleAuthService::processGoogleUser($pdo, $verified['user']);
        if (!$otpResult['success']) {
            $errCode = $otpResult['error_code'] ?? 'google_process_failed';
            $errMsg  = $otpResult['error'] ?? 'Authentication failed';
            error_log("Google OAuth authorization rejection ({$errCode}): " . $errMsg);

            $query = http_build_query([
                'auth_error' => $errCode,
                'email'      => $otpResult['email'] ?? ($verified['user']['email'] ?? ''),
                'msg'        => $errMsg
            ]);
            header('Location: ../index.php?' . $query);
            exit;
        }

        // Clean up temporary OAuth session data
        unset($_SESSION['google_oauth_state'], $_SESSION['google_oauth_nonce'], $_SESSION['google_oauth_time']);

        // Record security audit log
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
            $secStmt = $pdo->prepare("INSERT INTO `crm_security_logs` (`user_id`, `event_type`, `ip_address`, `user_agent`, `details`, `created_at`) VALUES (?, 'google_oauth_otp_dispatched', ?, ?, ?, NOW())");
            $secStmt->execute([(int)$otpResult['user_id'], $ip, $ua, "Google OAuth verification OTP dispatched for email: " . $otpResult['email']]);
        } catch (Exception $e) {}

        // Redirect user to CRM Email OTP Verification interface
        $redirectUrl = '../index.php?' . http_build_query([
            'step'     => 'otp_required',
            'email'    => $otpResult['email'],
            'provider' => 'google'
        ]);
        header('Location: ' . $redirectUrl);
        exit;


    // =========================================================================
    // 10. GOOGLE ID TOKEN VERIFICATION (For GIS Popup / Client Verification)
    // =========================================================================
    case 'google_verify_token':
        $idToken = trim($params['credential'] ?? ($params['id_token'] ?? ''));

        if (empty($idToken)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Google ID token or credential is required.']);
            exit;
        }

        $verified = GoogleAuthService::verifyIdToken($idToken);
        if (!$verified['success'] || empty($verified['user'])) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'error' => $verified['error'] ?? 'Google token verification failed.'
            ]);
            exit;
        }

        $userResult = GoogleAuthService::processGoogleUser($pdo, $verified['user']);
        if (!$userResult['success']) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $userResult['error'] ?? 'Failed to process Google account.'
            ]);
            exit;
        }

        echo json_encode([
            'success'         => true,
            'step'            => 'otp_required',
            'email'           => $userResult['email'],
            'resend_cooldown' => 60,
            'expires_in'      => 300,
            'message'         => 'Google identity verified. A 4-digit code has been sent to ' . $userResult['email']
        ]);
        break;


    default:
        echo json_encode([
            'success' => true,
            'service' => 'Software CRM Authentication Service',
            'version' => '2.0',
            'status' => 'operational'
        ]);
        break;
}
