<?php
/**
 * Software CRM - Production Google OAuth 2.0 & Identity Service
 * Implements Google Account Selection (prompt=select_account),
 * Anti-CSRF State & Nonce, Secure ID Token Validation,
 * User Linking & Mandatory Email OTP Verification Integration.
 */

if (!defined('CRM_ACCESS')) {
    define('CRM_ACCESS', true);
}

require_once __DIR__ . '/session_guard.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

class GoogleAuthService {
    private static bool $envLoaded = false;
    private static $mockHttpHandler = null;

    /**
     * Load environment configuration from root or api directory
     */
    public static function loadEnv(): void {
        if (self::$envLoaded) return;

        $envPaths = [
            dirname(__DIR__) . '/.env',
            __DIR__ . '/.env'
        ];

        foreach ($envPaths as $envPath) {
            if (file_exists($envPath) && is_readable($envPath)) {
                $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#')) continue;
                    $parts = explode('=', $line, 2);
                    if (count($parts) === 2) {
                        $k = trim($parts[0]);
                        $v = trim($parts[1], " \t\n\r\0\x0B\"'");
                        if (getenv($k) === false) {
                            putenv("$k=$v");
                            $_ENV[$k] = $v;
                        }
                    }
                }
                break;
            }
        }
        self::$envLoaded = true;
    }

    public static function getClientId(): string {
        self::loadEnv();
        return trim((string)(getenv('GOOGLE_CLIENT_ID') ?: ($_ENV['GOOGLE_CLIENT_ID'] ?? '')));
    }

    public static function getClientSecret(): string {
        self::loadEnv();
        return trim((string)(getenv('GOOGLE_CLIENT_SECRET') ?: ($_ENV['GOOGLE_CLIENT_SECRET'] ?? '')));
    }

    public static function getRedirectUri(): string {
        self::loadEnv();
        $configured = trim((string)(getenv('GOOGLE_REDIRECT_URI') ?: ($_ENV['GOOGLE_REDIRECT_URI'] ?? '')));
        if (!empty($configured)) {
            return $configured;
        }

        // Auto-detect based on current server request
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
        $scheme = $isHttps ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '/software_team/api/auth.php';
        $dir = rtrim(str_replace('\\', '/', dirname($scriptPath)), '/');
        if (!str_ends_with($dir, '/api')) {
            $dir .= '/api';
        }

        return "{$scheme}://{$host}{$dir}/google_callback.php";
    }

    public static function getWorkspaceDomain(): string {
        self::loadEnv();
        return trim((string)(getenv('GOOGLE_WORKSPACE_DOMAIN') ?: ($_ENV['GOOGLE_WORKSPACE_DOMAIN'] ?? '')));
    }

    public static function isConfigured(): bool {
        return !empty(self::getClientId()) && !empty(self::getClientSecret());
    }

    /**
     * Generate secure state and nonce and build the Google OAuth 2.0 URL
     * Uses prompt=select_account to guarantee the account selection screen is shown.
     */
    public static function buildAuthUrl(): array {
        if (!self::isConfigured()) {
            return [
                'success' => false,
                'error' => 'Google Sign-In is not configured. Please set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in .env.'
            ];
        }

        // Generate 32-byte cryptographically secure random state (CSRF protection)
        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION['google_oauth_state'] = $state;
        $_SESSION['google_oauth_nonce'] = $nonce;
        $_SESSION['google_oauth_time']  = time();

        $params = [
            'client_id'     => self::getClientId(),
            'redirect_uri'  => self::getRedirectUri(),
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'prompt'        => 'select_account', // Mandatory: Forces account chooser screen
            'access_type'   => 'offline',
            'state'         => $state,
            'nonce'         => $nonce
        ];

        // Optional Google Workspace hosted domain restriction
        $hd = self::getWorkspaceDomain();
        if (!empty($hd)) {
            $params['hd'] = $hd;
        }

        $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);

        return [
            'success'   => true,
            'auth_url'  => $authUrl,
            'client_id' => self::getClientId()
        ];
    }

    /**
     * Verify that incoming state matches the session state (prevents CSRF)
     */
    public static function verifyState(?string $state): bool {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($state)) {
            error_log("Google OAuth state verification failed: Empty state parameter received.");
            return false;
        }

        if (empty($_SESSION['google_oauth_state'])) {
            error_log("Google OAuth state verification failed: Session state is missing or session expired.");
            return false;
        }

        $valid = hash_equals($_SESSION['google_oauth_state'], trim($state));

        if (!$valid) {
            error_log("Google OAuth state verification failed: State token mismatch.");
        }

        // State can only be used once upon successful verification
        if ($valid) {
            unset($_SESSION['google_oauth_state']);
        }
        return $valid;
    }

    /**
     * Exchange authorization code for Google access token & ID token
     */
    public static function exchangeCode(string $code): array {
        if (!self::isConfigured()) {
            return ['success' => false, 'error' => 'Google OAuth credentials not configured on server.'];
        }

        $tokenUrl = 'https://oauth2.googleapis.com/token';
        $postData = [
            'code'          => $code,
            'client_id'     => self::getClientId(),
            'client_secret' => self::getClientSecret(),
            'redirect_uri'  => self::getRedirectUri(),
            'grant_type'    => 'authorization_code'
        ];

        if (self::$mockHttpHandler !== null) {
            $response = call_user_func(self::$mockHttpHandler, $tokenUrl, $postData);
        } else {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $tokenUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
            $raw = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($raw === false) {
                return ['success' => false, 'error' => 'Network error contacting Google token endpoint: ' . $curlErr];
            }

            $response = ['code' => $httpCode, 'body' => $raw];
        }

        $json = json_decode($response['body'] ?? '{}', true);

        if (($response['code'] ?? 0) !== 200 || empty($json['id_token'])) {
            $errorDesc = $json['error_description'] ?? ($json['error'] ?? 'Token exchange failed');
            return ['success' => false, 'error' => $errorDesc, 'raw' => $json];
        }

        return [
            'success'      => true,
            'id_token'     => $json['id_token'],
            'access_token' => $json['access_token'] ?? null
        ];
    }

    /**
     * Validate Google ID Token via Google's tokeninfo endpoint
     * Validates signature, audience (client_id), issuer, expiration, and email_verified.
     */
    public static function verifyIdToken(string $idToken): array {
        $infoUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);

        if (self::$mockHttpHandler !== null) {
            $response = call_user_func(self::$mockHttpHandler, $infoUrl, null);
        } else {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $infoUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $raw = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($raw === false) {
                return ['success' => false, 'error' => 'Network error validating Google token'];
            }

            $response = ['code' => $httpCode, 'body' => $raw];
        }

        $tokenInfo = json_decode($response['body'] ?? '{}', true);

        if (($response['code'] ?? 0) !== 200 || empty($tokenInfo['sub'])) {
            $err = $tokenInfo['error_description'] ?? ($tokenInfo['error'] ?? 'Invalid Google ID token');
            return ['success' => false, 'error' => $err];
        }

        // 1. Audience verification (must match our configured Google Client ID)
        $expectedClientId = self::getClientId();
        if (!empty($expectedClientId) && ($tokenInfo['aud'] ?? '') !== $expectedClientId) {
            return ['success' => false, 'error' => 'Token audience mismatch. Token was not issued for this application.'];
        }

        // 2. Issuer verification
        $validIssuers = ['accounts.google.com', 'https://accounts.google.com'];
        if (!in_array($tokenInfo['iss'] ?? '', $validIssuers, true)) {
            return ['success' => false, 'error' => 'Token issuer is not valid Google authentication authority.'];
        }

        // 3. Expiration verification
        if (isset($tokenInfo['exp']) && (int)$tokenInfo['exp'] < time()) {
            return ['success' => false, 'error' => 'Google ID token has expired. Please sign in again.'];
        }

        // 4. Email verification claim
        $emailVerified = ($tokenInfo['email_verified'] ?? false);
        if ($emailVerified !== true && $emailVerified !== 'true' && $emailVerified !== 1 && $emailVerified !== '1') {
            return ['success' => false, 'error' => 'Google account email address is not verified.'];
        }

        // 5. Workspace Hosted Domain (hd) restriction (if configured)
        $workspaceDomain = self::getWorkspaceDomain();
        if (!empty($workspaceDomain)) {
            $tokenHd = strtolower((string)($tokenInfo['hd'] ?? ''));
            if ($tokenHd !== strtolower($workspaceDomain)) {
                return [
                    'success' => false,
                    'error' => "Sign-in restricted to members of the {$workspaceDomain} Google Workspace domain."
                ];
            }
        }

        return [
            'success' => true,
            'user' => [
                'google_id'      => (string)$tokenInfo['sub'],
                'email'          => strtolower(trim((string)$tokenInfo['email'])),
                'name'           => trim((string)($tokenInfo['name'] ?? 'Google User')),
                'picture'        => (string)($tokenInfo['picture'] ?? ''),
                'email_verified' => true,
                'hd'             => $tokenInfo['hd'] ?? null
            ]
        ];
    }

    /**
     * Authenticate and authorize a verified Google user against employee registry and database.
     * 
     * Requirements:
     * - Case-insensitive email comparison (LOWER(TRIM(email))).
     * - Checks crm_users and employee registry (crm_team_staff).
     * - Blocks suspended/inactive users.
     * - Rejects unregistered Google accounts with informative error message.
     * - Does not elevate privileges (preserves existing roles, sets 'member'/'user' for new registry employees).
     * - Updates google_id, avatar_url, last_login_at.
     * - Returns authenticated user array ready for session creation.
     */
    public static function authenticateGoogleUser(PDO $pdo, array $googleUser): array {
        $email    = strtolower(trim((string)($googleUser['email'] ?? '')));
        $googleId = trim((string)($googleUser['google_id'] ?? ($googleUser['sub'] ?? '')));
        $name     = trim((string)($googleUser['name'] ?? ''));
        $picture  = trim((string)($googleUser['picture'] ?? ''));

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'success'    => false,
                'error_code' => 'invalid_email',
                'error'      => 'Invalid email address returned by Google.'
            ];
        }

        // 1. Look up in crm_users (by google_id OR case-insensitive email)
        $userStmt = $pdo->prepare("SELECT `id`, `name`, `email`, `employee_id`, `role`, `status`, `google_id`, `avatar_url` FROM `crm_users` WHERE (`google_id` = ? AND `google_id` != '') OR LOWER(`email`) = ? LIMIT 1");
        $userStmt->execute([$googleId, $email]);
        $crmUser = $userStmt->fetch(PDO::FETCH_ASSOC);

        // 2. Look up in employee registry (crm_team_staff)
        $staffStmt = $pdo->prepare("SELECT `id`, `name`, `role`, `email`, `status`, `avatar_url` FROM `crm_team_staff` WHERE LOWER(`email`) = ? LIMIT 1");
        $staffStmt->execute([$email]);
        $staff = $staffStmt->fetch(PDO::FETCH_ASSOC);

        // 3. User Registry Authorization Check
        // If user exists NEITHER in crm_users NOR in crm_team_staff, block access!
        if (!$crmUser && !$staff) {
            return [
                'success'    => false,
                'error_code' => 'unregistered_employee',
                'email'      => $email,
                'error'      => "Your Google account ({$email}) is not registered as an authorized employee in Software Team CRM. Please contact your CRM administrator to request employee access."
            ];
        }

        // 4. Employee Inactive Check (from crm_team_staff registry)
        if ($staff && !empty($staff['status']) && strtolower(trim($staff['status'])) === 'inactive') {
            return [
                'success'    => false,
                'error_code' => 'employee_inactive',
                'email'      => $email,
                'error'      => "Your employee profile is currently marked as inactive in the company registry. Please contact your CRM administrator."
            ];
        }

        // 5. Account Suspension Check (from crm_users)
        if ($crmUser && $crmUser['status'] === 'suspended') {
            return [
                'success'    => false,
                'error_code' => 'account_suspended',
                'email'      => $email,
                'error'      => "Your employee account has been deactivated or suspended. Please contact your CRM administrator."
            ];
        }

        $userId = null;
        $resolvedUser = null;

        if ($crmUser) {
            // Existing CRM User - Preserve role and identity
            $userId = (int)$crmUser['id'];
            $finalName = (!empty($crmUser['name']) && $crmUser['name'] !== 'User' && $crmUser['name'] !== 'Google User')
                ? $crmUser['name']
                : (!empty($name) ? $name : ($staff['name'] ?? 'User'));
            
            $finalAvatar = !empty($crmUser['avatar_url'])
                ? $crmUser['avatar_url']
                : (!empty($picture) ? $picture : ($staff['avatar_url'] ?? null));

            $finalEmployeeId = !empty($crmUser['employee_id'])
                ? $crmUser['employee_id']
                : ($staff['id'] ?? null);

            // Update user record: link google_id, update avatar, set last_login_at
            $upd = $pdo->prepare("UPDATE `crm_users` SET `google_id` = COALESCE(NULLIF(?, ''), `google_id`), `avatar_url` = COALESCE(NULLIF(?, ''), `avatar_url`), `employee_id` = COALESCE(`employee_id`, ?), `name` = ?, `last_login_at` = NOW(), `updated_at` = NOW() WHERE `id` = ?");
            $upd->execute([$googleId, $finalAvatar, $finalEmployeeId, $finalName, $userId]);

            // Re-fetch fresh user record
            $refetch = $pdo->prepare("SELECT `id`, `name`, `email`, `employee_id`, `role`, `status`, `avatar_url` FROM `crm_users` WHERE `id` = ? LIMIT 1");
            $refetch->execute([$userId]);
            $resolvedUser = $refetch->fetch(PDO::FETCH_ASSOC);

        } else {
            // Found in crm_team_staff employee registry but not yet provisioned in crm_users
            $finalName = !empty($staff['name']) ? $staff['name'] : (!empty($name) ? $name : 'Employee');
            $staffRole = strtolower(trim($staff['role'] ?? 'member'));
            // Safety check: Never grant admin or Superadmin automatically to provisioned users
            $safeRole = (in_array($staffRole, ['member', 'user', 'developer', 'designer'], true)) ? 'member' : 'user';
            $finalAvatar = !empty($picture) ? $picture : ($staff['avatar_url'] ?? null);
            $randomHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

            $ins = $pdo->prepare("INSERT INTO `crm_users` (`name`, `email`, `employee_id`, `google_id`, `password_hash`, `role`, `auth_provider`, `avatar_url`, `status`, `created_at`, `last_login_at`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?, 'google', ?, 'active', NOW(), NOW(), NOW())");
            $ins->execute([$finalName, $email, $staff['id'] ?? null, $googleId, $randomHash, $safeRole, $finalAvatar]);
            $userId = (int)$pdo->lastInsertId();

            $resolvedUser = [
                'id'          => $userId,
                'name'        => $finalName,
                'email'       => $email,
                'employee_id' => $staff['id'] ?? null,
                'role'        => $safeRole,
                'status'      => 'active',
                'avatar_url'  => $finalAvatar
            ];
        }

        return [
            'success' => true,
            'user'    => $resolvedUser
        ];
    }

    /**
     * Process verified Google identity:
     * 1. Matches or creates user in crm_users (validates against crm_team_staff registry).
     * 2. Checks account suspension and inactive status.
     * 3. Dispatches mandatory 4-digit email OTP (preserving CRM 2FA security policy).
     * 4. Stores challenge in crm_otp_challenges for verify_otp completion.
     */
    public static function processGoogleUser(PDO $pdo, array $googleUser): array {
        $email    = strtolower(trim((string)($googleUser['email'] ?? '')));
        $googleId = trim((string)($googleUser['google_id'] ?? ($googleUser['sub'] ?? '')));
        $name     = trim((string)($googleUser['name'] ?? 'Google User'));
        $picture  = trim((string)($googleUser['picture'] ?? ''));

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Invalid email address returned by Google.'];
        }

        // 1. Look up in crm_users (by google_id OR case-insensitive email)
        $userStmt = $pdo->prepare("SELECT `id`, `name`, `email`, `employee_id`, `role`, `status`, `google_id`, `avatar_url` FROM `crm_users` WHERE (`google_id` = ? AND `google_id` != '') OR LOWER(`email`) = ? LIMIT 1");
        $userStmt->execute([$googleId, $email]);
        $crmUser = $userStmt->fetch(PDO::FETCH_ASSOC);

        // 2. Look up in employee registry (crm_team_staff)
        $staffStmt = $pdo->prepare("SELECT `id`, `name`, `role`, `email`, `status`, `avatar_url` FROM `crm_team_staff` WHERE LOWER(`email`) = ? LIMIT 1");
        $staffStmt->execute([$email]);
        $staff = $staffStmt->fetch(PDO::FETCH_ASSOC);

        // 3. User Registry Authorization Check
        if (!$crmUser && !$staff) {
            return [
                'success'    => false,
                'error_code' => 'unregistered_employee',
                'email'      => $email,
                'error'      => "Your Google account ({$email}) is not registered as an authorized employee in Software Team CRM. Please contact your CRM administrator to request employee access."
            ];
        }

        // 4. Employee Inactive Check (from crm_team_staff registry)
        if ($staff && !empty($staff['status']) && strtolower(trim($staff['status'])) === 'inactive') {
            return [
                'success'    => false,
                'error_code' => 'employee_inactive',
                'email'      => $email,
                'error'      => "Your employee profile is currently marked as inactive in the company registry. Please contact your CRM administrator."
            ];
        }

        // 5. Account Suspension Check (from crm_users)
        if ($crmUser && $crmUser['status'] === 'suspended') {
            return [
                'success'    => false,
                'error_code' => 'account_suspended',
                'email'      => $email,
                'error'      => "Your employee account has been deactivated or suspended. Please contact your CRM administrator."
            ];
        }

        $userId = null;
        $userRole = 'user';
        $finalName = (!empty($name) && $name !== 'Google User') ? $name : 'User';

        if ($crmUser) {
            // Existing CRM User - Preserve role and identity
            $userId = (int)$crmUser['id'];
            $userRole = $crmUser['role'];
            $finalName = (!empty($crmUser['name']) && $crmUser['name'] !== 'User' && $crmUser['name'] !== 'Google User')
                ? $crmUser['name']
                : (!empty($name) ? $name : ($staff['name'] ?? 'User'));
            
            $finalAvatar = !empty($crmUser['avatar_url'])
                ? $crmUser['avatar_url']
                : (!empty($picture) ? $picture : ($staff['avatar_url'] ?? null));

            $finalEmployeeId = !empty($crmUser['employee_id'])
                ? $crmUser['employee_id']
                : ($staff['id'] ?? null);

            // Update user record with verified Google profile data and ID
            $upd = $pdo->prepare("UPDATE `crm_users` SET `google_id` = COALESCE(NULLIF(?, ''), `google_id`), `avatar_url` = COALESCE(NULLIF(?, ''), `avatar_url`), `employee_id` = COALESCE(`employee_id`, ?), `name` = ?, `updated_at` = NOW() WHERE `id` = ?");
            $upd->execute([$googleId, $finalAvatar, $finalEmployeeId, $finalName, $userId]);

        } else {
            // Found in crm_team_staff employee registry but not yet provisioned in crm_users
            $finalName = !empty($staff['name']) ? $staff['name'] : (!empty($name) ? $name : 'Employee');
            $staffRole = strtolower(trim($staff['role'] ?? 'member'));
            $safeRole = (in_array($staffRole, ['member', 'user', 'developer', 'designer'], true)) ? 'member' : 'user';
            $finalAvatar = !empty($picture) ? $picture : ($staff['avatar_url'] ?? null);
            $randomHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

            $ins = $pdo->prepare("INSERT INTO `crm_users` (`name`, `email`, `employee_id`, `google_id`, `password_hash`, `role`, `auth_provider`, `avatar_url`, `status`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?, 'google', ?, 'active', NOW(), NOW())");
            $ins->execute([$finalName, $email, $staff['id'] ?? null, $googleId, $randomHash, $safeRole, $finalAvatar]);
            $userId = (int)$pdo->lastInsertId();
            $userRole = $safeRole;
        }

        // =====================================================================
        // MANDATORY 4-DIGIT EMAIL OTP VERIFICATION REQUIREMENT
        // =====================================================================
        // Invalidate any older login OTP challenges for this email
        $pdo->prepare("UPDATE `crm_otp_challenges` SET `is_used` = 1 WHERE `email` = ? AND `purpose` = 'login' AND `is_used` = 0")->execute([$email]);

        // Generate 4-digit cryptographically secure OTP
        $otp = sprintf('%04d', random_int(1000, 9999));

        // Dispatch OTP via multi-provider transactional mailer
        $mailResult = Mailer::sendOtpEmail($email, $otp, 'login');
        if (!$mailResult['success']) {
            return [
                'success'    => false,
                'error_code' => 'delivery_failed',
                'error'      => $mailResult['error'] ?? 'Could not deliver verification email to ' . $email . '. Please check server SMTP configuration.'
            ];
        }

        $effectiveOtp = !empty($mailResult['actual_otp']) ? (string)$mailResult['actual_otp'] : $otp;
        $otpHash = password_hash($effectiveOtp, PASSWORD_DEFAULT);
        $expiresAt = date('Y-m-d H:i:s', time() + 300);

        // Store OTP challenge with authenticated user ID in temp_payload
        $insChallenge = $pdo->prepare("INSERT INTO `crm_otp_challenges` (`email`, `otp_hash`, `purpose`, `expires_at`, `attempts`, `max_attempts`, `resend_count`, `last_sent_at`, `is_used`, `temp_payload`) VALUES (?, ?, 'login', ?, 0, 5, 0, NOW(), 0, ?)");
        $insChallenge->execute([$email, $otpHash, $expiresAt, json_encode([
            'user_id'   => $userId,
            'name'      => $finalName,
            'provider'  => 'google',
            'google_id' => $googleId
        ])]);

        return [
            'success'   => true,
            'step'      => 'otp_required',
            'email'     => $email,
            'name'      => $finalName,
            'user_id'   => $userId,
            'user_role' => $userRole,
            'provider'  => 'google',
            'expires_in'=> 300
        ];
    }

    /**
     * Testing mock helper
     */
    public static function setMockHttpHandler(?callable $handler): void {
        self::$mockHttpHandler = $handler;
    }
}
