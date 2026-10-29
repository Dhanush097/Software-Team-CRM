<?php
/**
 * Software CRM - Mobile Fingerprint & Face Authentication (WebAuthn / Passkeys) Controller
 * Secure, W3C/FIDO2 standard-compliant biometric verification.
 */

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

require_once __DIR__ . '/session_guard.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/webauthn_helper.php';

$pdo = Database::getConnection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

// Auto-migration & Schema Check
class BiometricMigration {
    public static function ensure(PDO $pdo): void {
        static $done = false;
        if ($done) return;

        $pdo->exec("CREATE TABLE IF NOT EXISTS `crm_biometric_credentials` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT NOT NULL,
          `credential_id` VARCHAR(255) NOT NULL UNIQUE,
          `public_key` TEXT NOT NULL,
          `counter` INT UNSIGNED NOT NULL DEFAULT 0,
          `device_name` VARCHAR(150) NOT NULL,
          `aaguid` VARCHAR(64) DEFAULT NULL,
          `attestation_type` VARCHAR(64) DEFAULT 'none',
          `status` ENUM('active', 'revoked') NOT NULL DEFAULT 'active',
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `last_used_at` DATETIME DEFAULT NULL,
          INDEX `idx_user_bio` (`user_id`, `status`),
          INDEX `idx_cred_id` (`credential_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `crm_biometric_challenges` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT DEFAULT NULL,
          `challenge` VARCHAR(255) NOT NULL,
          `purpose` ENUM('registration', 'login') NOT NULL,
          `origin` VARCHAR(255) NOT NULL,
          `rp_id` VARCHAR(255) NOT NULL,
          `expires_at` DATETIME NOT NULL,
          `is_used` TINYINT(1) NOT NULL DEFAULT 0,
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_chal_purp` (`challenge`, `purpose`, `is_used`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `crm_security_logs` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT DEFAULT NULL,
          `event_type` VARCHAR(64) NOT NULL,
          `ip_address` VARCHAR(64) DEFAULT NULL,
          `user_agent` TEXT DEFAULT NULL,
          `details` TEXT DEFAULT NULL,
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_sec_user` (`user_id`, `event_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $done = true;
    }
}
BiometricMigration::ensure($pdo);

function logSecurityEvent(PDO $pdo, ?int $userId, string $type, string $details = ''): void {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $stmt = $pdo->prepare("INSERT INTO `crm_security_logs` (`user_id`, `event_type`, `ip_address`, `user_agent`, `details`, `created_at`) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$userId, $type, $ip, $ua, $details]);
    } catch (Exception $e) {
        // Silently continue if log table busy
    }
}

function detectDeviceName(): string {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (stripos($ua, 'iPhone') !== false) {
        return 'Apple iPhone (Face ID / Touch ID)';
    }
    if (stripos($ua, 'iPad') !== false) {
        return 'Apple iPad (Face ID / Touch ID)';
    }
    if (stripos($ua, 'Android') !== false) {
        if (preg_match('/;\s*([^;)]+)\s+Build\//i', $ua, $matches)) {
            return trim($matches[1]) . ' (Biometrics)';
        }
        return 'Android Smartphone (Fingerprint)';
    }
    if (stripos($ua, 'Macintosh') !== false) {
        return 'Apple Mac (Touch ID)';
    }
    if (stripos($ua, 'Windows') !== false) {
        return 'Windows PC (Windows Hello / Fingerprint)';
    }
    return 'Smartphone (Biometrics)';
}

$rawInput = file_get_contents('php://input');
$body = json_decode($rawInput, true) ?: [];
$params = array_merge($_GET, $_POST, $body);

$action = trim($params['action'] ?? '');

switch ($action) {

    // =========================================================================
    // 1. CHECK SERVER WEBAUTHN / BIOMETRIC CONFIGURATION
    // =========================================================================
    case 'check_support':
        echo json_encode([
            'success' => true,
            'rp_id'   => WebAuthnHelper::getRelyingPartyId(),
            'rp_name' => 'Software CRM',
            'origin'  => WebAuthnHelper::getExpectedOrigin()
        ]);
        exit;


    // =========================================================================
    // 2. GENERATE REGISTRATION CHALLENGE (For Enrolling Device in Settings)
    // =========================================================================
    case 'register_challenge':
        $user = SessionGuard::requireAuth(true);

        $challenge = WebAuthnHelper::generateChallenge(32);
        $rpId = WebAuthnHelper::getRelyingPartyId();
        $origin = WebAuthnHelper::getExpectedOrigin();

        // Expire previous unused registration challenges for this user
        $pdo->prepare("UPDATE `crm_biometric_challenges` SET `is_used` = 1 WHERE `user_id` = ? AND `purpose` = 'registration'")->execute([$user['id']]);

        // Insert new challenge (expires in 5 minutes)
        $ins = $pdo->prepare("
            INSERT INTO `crm_biometric_challenges`
            (`user_id`, `challenge`, `purpose`, `origin`, `rp_id`, `expires_at`, `is_used`, `created_at`)
            VALUES
            (?, ?, 'registration', ?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE), 0, NOW())
        ");
        $ins->execute([$user['id'], $challenge, $origin, $rpId]);

        $userIdHex = bin2hex((string)$user['id']);

        echo json_encode([
            'success'   => true,
            'challenge' => $challenge,
            'rp' => [
                'name' => 'Software CRM',
                'id'   => $rpId
            ],
            'user' => [
                'id'          => $userIdHex,
                'name'        => $user['email'],
                'displayName' => $user['name']
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],   // ES256 (standard mobile biometrics)
                ['type' => 'public-key', 'alg' => -257]  // RS256 (Windows Hello fallback)
            ],
            'authenticatorSelection' => [
                'authenticatorAttachment' => 'platform', // Enforce smartphone hardware biometrics (Touch ID, Face ID, Android Fingerprint)
                'userVerification'        => 'required', // Mandatory biometric / PIN verification
                'residentKey'             => 'preferred'
            ],
            'timeout'     => 60000,
            'attestation' => 'none'
        ]);
        exit;


    // =========================================================================
    // 3. COMPLETE REGISTRATION: STORE PUBLIC KEY CREDENTIAL
    // =========================================================================
    case 'register_credential':
        $user = SessionGuard::requireAuth(true);

        $clientDataJSON    = trim($params['clientDataJSON'] ?? '');
        $attestationObject = trim($params['attestationObject'] ?? '');
        $deviceName        = trim($params['device_name'] ?? '');

        if (empty($clientDataJSON) || empty($attestationObject)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing WebAuthn registration parameters.']);
            exit;
        }

        if (empty($deviceName)) {
            $deviceName = detectDeviceName();
        }

        // Fetch valid challenge
        $cStmt = $pdo->prepare("
            SELECT `id`, `challenge`, `origin`, `rp_id`
            FROM `crm_biometric_challenges`
            WHERE `user_id` = ? AND `purpose` = 'registration' AND `is_used` = 0 AND `expires_at` > NOW()
            ORDER BY `id` DESC LIMIT 1
        ");
        $cStmt->execute([$user['id']]);
        $activeChallenge = $cStmt->fetch();

        if (!$activeChallenge) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Registration challenge has expired or is invalid. Please start registration again.']);
            exit;
        }

        try {
            $clientDataRaw = WebAuthnHelper::base64url_decode($clientDataJSON);
            $clientData = WebAuthnHelper::validateClientData($clientDataRaw, 'webauthn.create', $activeChallenge['challenge']);

            $attestationRaw = WebAuthnHelper::base64url_decode($attestationObject);
            $attParsed = WebAuthnHelper::parseAttestationObject($attestationRaw);

            // Mark challenge as used
            $pdo->prepare("UPDATE `crm_biometric_challenges` SET `is_used` = 1 WHERE `id` = ?")->execute([$activeChallenge['id']]);

            $credId = $attParsed['credentialId'];

            // Check duplicate
            $dupStmt = $pdo->prepare("SELECT `id`, `user_id`, `status` FROM `crm_biometric_credentials` WHERE `credential_id` = ? LIMIT 1");
            $dupStmt->execute([$credId]);
            $existing = $dupStmt->fetch();

            if ($existing) {
                if ($existing['status'] === 'active' && (int)$existing['user_id'] === (int)$user['id']) {
                    echo json_encode([
                        'success' => true,
                        'message' => 'This biometric device is already registered for your account.',
                        'credential_id' => $credId,
                        'device_name' => $deviceName
                    ]);
                    exit;
                }
                // Reactivate if previously revoked
                $pdo->prepare("UPDATE `crm_biometric_credentials` SET `status` = 'active', `user_id` = ?, `device_name` = ?, `public_key` = ?, `last_used_at` = NOW() WHERE `id` = ?")->execute([
                    $user['id'], $deviceName, $attParsed['publicKeyPem'], $existing['id']
                ]);
            } else {
                // Insert new credential
                $insCred = $pdo->prepare("
                    INSERT INTO `crm_biometric_credentials`
                    (`user_id`, `credential_id`, `public_key`, `counter`, `device_name`, `aaguid`, `attestation_type`, `status`, `created_at`, `last_used_at`)
                    VALUES
                    (?, ?, ?, ?, ?, ?, ?, 'active', NOW(), NOW())
                ");
                $insCred->execute([
                    $user['id'],
                    $credId,
                    $attParsed['publicKeyPem'],
                    $attParsed['signCount'],
                    $deviceName,
                    $attParsed['aaguid'],
                    $attParsed['fmt']
                ]);
            }

            logSecurityEvent($pdo, (int)$user['id'], 'biometric_device_registered', "Device: $deviceName, CredID: " . substr($credId, 0, 16) . '...');

            echo json_encode([
                'success'       => true,
                'message'       => 'Biometric device registered successfully! You can now sign in using your fingerprint or Face ID.',
                'credential_id' => $credId,
                'device_name'   => $deviceName
            ]);
            exit;

        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }


    // =========================================================================
    // 4. LIST REGISTERED BIOMETRIC DEVICES FOR CURRENT EMPLOYEE
    // =========================================================================
    case 'list_devices':
        $user = SessionGuard::requireAuth(true);

        $stmt = $pdo->prepare("
            SELECT `id`, `device_name`, `credential_id`, `created_at`, `last_used_at`, `status`
            FROM `crm_biometric_credentials`
            WHERE `user_id` = ?
            ORDER BY `created_at` DESC
        ");
        $stmt->execute([$user['id']]);
        $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formatted = array_map(function($d) {
            $cid = $d['credential_id'];
            $preview = strlen($cid) > 16 ? substr($cid, 0, 8) . '...' . substr($cid, -6) : $cid;
            return [
                'id'            => (int)$d['id'],
                'device_name'   => $d['device_name'],
                'credential_id' => $cid,
                'cred_preview'  => $preview,
                'created_at'    => $d['created_at'],
                'last_used_at'  => $d['last_used_at'],
                'status'        => $d['status']
            ];
        }, $devices);

        echo json_encode([
            'success' => true,
            'devices' => $formatted,
            'count'   => count($formatted)
        ]);
        exit;


    // =========================================================================
    // 5. REMOVE / REVOKE A REGISTERED BIOMETRIC DEVICE
    // =========================================================================
    case 'remove_device':
        $user = SessionGuard::requireAuth(true);

        $deviceId = (int)($params['id'] ?? 0);
        $credId = trim($params['credential_id'] ?? '');

        if (!$deviceId && empty($credId)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Device ID or Credential ID is required.']);
            exit;
        }

        if ($deviceId) {
            $stmt = $pdo->prepare("SELECT `id`, `device_name` FROM `crm_biometric_credentials` WHERE `id` = ? AND `user_id` = ?");
            $stmt->execute([$deviceId, $user['id']]);
        } else {
            $stmt = $pdo->prepare("SELECT `id`, `device_name` FROM `crm_biometric_credentials` WHERE `credential_id` = ? AND `user_id` = ?");
            $stmt->execute([$credId, $user['id']]);
        }
        $target = $stmt->fetch();

        if (!$target) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Device not found or not owned by you.']);
            exit;
        }

        // Delete record from database
        $pdo->prepare("DELETE FROM `crm_biometric_credentials` WHERE `id` = ?")->execute([$target['id']]);

        logSecurityEvent($pdo, (int)$user['id'], 'biometric_device_removed', "Removed: {$target['device_name']}");

        echo json_encode([
            'success' => true,
            'message' => 'Device removed successfully. Biometric sign-in from this device is now disabled.'
        ]);
        exit;


    // =========================================================================
    // 6. DISABLE BIOMETRIC LOGIN COMPLETELY FOR ACCOUNT
    // =========================================================================
    case 'disable_biometrics':
        $user = SessionGuard::requireAuth(true);

        $pdo->prepare("DELETE FROM `crm_biometric_credentials` WHERE `user_id` = ?")->execute([$user['id']]);

        logSecurityEvent($pdo, (int)$user['id'], 'biometric_disabled_all', 'All registered credentials revoked');

        echo json_encode([
            'success' => true,
            'message' => 'Biometric authentication has been disabled for all your devices.'
        ]);
        exit;


    // =========================================================================
    // 7. GENERATE LOGIN ASSERTION CHALLENGE (Sign-in Page)
    // =========================================================================
    case 'login_challenge':
        $email = trim(strtolower($params['email'] ?? ''));

        if (empty($email)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Please enter your registered email address to sign in with fingerprint or Face ID.']);
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Please enter a valid email address.']);
            exit;
        }

        // Query user
        $uStmt = $pdo->prepare("SELECT `id`, `name`, `email`, `role`, `status` FROM `crm_users` WHERE `email` = ? LIMIT 1");
        $uStmt->execute([$email]);
        $user = $uStmt->fetch();

        if (!$user) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'No account found with this email. Please verify your email or register.']);
            exit;
        }

        if ($user['status'] !== 'active') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Your account is currently inactive or suspended. Please contact the administrator.']);
            exit;
        }

        // Retrieve active credentials
        $cStmt = $pdo->prepare("SELECT `id`, `credential_id` FROM `crm_biometric_credentials` WHERE `user_id` = ? AND `status` = 'active'");
        $cStmt->execute([$user['id']]);
        $credentials = $cStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($credentials)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'no_credentials' => true,
                'error' => 'No fingerprint or Face ID credential has been registered for this account yet. Please sign in with your password and enable Biometric Login in Settings.'
            ]);
            exit;
        }

        $challenge = WebAuthnHelper::generateChallenge(32);
        $rpId = WebAuthnHelper::getRelyingPartyId();
        $origin = WebAuthnHelper::getExpectedOrigin();

        // Expire previous unused login challenges for this user
        $pdo->prepare("UPDATE `crm_biometric_challenges` SET `is_used` = 1 WHERE `user_id` = ? AND `purpose` = 'login'")->execute([$user['id']]);

        // Insert login challenge
        $ins = $pdo->prepare("
            INSERT INTO `crm_biometric_challenges`
            (`user_id`, `challenge`, `purpose`, `origin`, `rp_id`, `expires_at`, `is_used`, `created_at`)
            VALUES
            (?, ?, 'login', ?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE), 0, NOW())
        ");
        $ins->execute([$user['id'], $challenge, $origin, $rpId]);

        $allowList = array_map(function($c) {
            return [
                'type' => 'public-key',
                'id'   => $c['credential_id']
            ];
        }, $credentials);

        echo json_encode([
            'success'          => true,
            'challenge'        => $challenge,
            'rpId'             => $rpId,
            'allowCredentials' => $allowList,
            'userVerification' => 'required',
            'timeout'          => 60000
        ]);
        exit;


    // =========================================================================
    // 8. VERIFY LOGIN ASSERTION & CREATE AUTHENTICATED SESSION
    // =========================================================================
    case 'verify_assertion':
        $credentialId      = trim($params['credential_id'] ?? '');
        $clientDataJSON    = trim($params['clientDataJSON'] ?? '');
        $authenticatorData = trim($params['authenticatorData'] ?? '');
        $signature         = trim($params['signature'] ?? '');

        if (empty($credentialId) || empty($clientDataJSON) || empty($authenticatorData) || empty($signature)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing biometric assertion verification parameters.']);
            exit;
        }

        // Fetch credential
        $stmt = $pdo->prepare("
            SELECT c.`id`, c.`user_id`, c.`public_key`, c.`counter`, c.`device_name`, c.`status`,
                   u.`id` AS u_id, u.`name`, u.`email`, u.`role`, u.`status` AS u_status, u.`avatar_url`
            FROM `crm_biometric_credentials` c
            JOIN `crm_users` u ON u.`id` = c.`user_id`
            WHERE c.`credential_id` = ? LIMIT 1
        ");
        $stmt->execute([$credentialId]);
        $record = $stmt->fetch();

        if (!$record) {
            logSecurityEvent($pdo, null, 'biometric_login_failed', "Unknown credential: " . substr($credentialId, 0, 16));
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Biometric credential not recognized or has been removed.']);
            exit;
        }

        if ($record['status'] !== 'active') {
            logSecurityEvent($pdo, (int)$record['user_id'], 'biometric_login_failed', 'Credential revoked');
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'This biometric credential has been revoked. Please sign in with your password.']);
            exit;
        }

        if ($record['u_status'] !== 'active') {
            logSecurityEvent($pdo, (int)$record['user_id'], 'biometric_login_failed', 'Account inactive: ' . $record['u_status']);
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Your employee account is currently inactive or suspended. Please contact administrator.']);
            exit;
        }

        // Fetch active login challenge
        $cStmt = $pdo->prepare("
            SELECT `id`, `challenge`
            FROM `crm_biometric_challenges`
            WHERE `user_id` = ? AND `purpose` = 'login' AND `is_used` = 0 AND `expires_at` > NOW()
            ORDER BY `id` DESC LIMIT 1
        ");
        $cStmt->execute([$record['user_id']]);
        $activeChallenge = $cStmt->fetch();

        if (!$activeChallenge) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Biometric authentication challenge expired or replayed. Please try again.']);
            exit;
        }

        // Single-use challenge: Mark as used immediately to prevent replay attacks
        $pdo->prepare("UPDATE `crm_biometric_challenges` SET `is_used` = 1 WHERE `id` = ?")->execute([$activeChallenge['id']]);

        try {
            $clientDataRaw = WebAuthnHelper::base64url_decode($clientDataJSON);
            $clientData = WebAuthnHelper::validateClientData($clientDataRaw, 'webauthn.get', $activeChallenge['challenge']);

            $authDataRaw = WebAuthnHelper::base64url_decode($authenticatorData);
            $sigRaw = WebAuthnHelper::base64url_decode($signature);

            $verification = WebAuthnHelper::verifyAssertion(
                $authDataRaw,
                $clientDataRaw,
                $sigRaw,
                $record['public_key'],
                (int)$record['counter']
            );

            // Update sign counter & last_used_at on credential
            $pdo->prepare("
                UPDATE `crm_biometric_credentials`
                SET `counter` = ?, `last_used_at` = NOW()
                WHERE `id` = ?
            ")->execute([$verification['signCount'], $record['id']]);

            // Update user last login
            $pdo->prepare("UPDATE `crm_users` SET `last_login_at` = NOW() WHERE `id` = ?")->execute([$record['user_id']]);

            // Regenerate session ID and establish authenticated session
            session_regenerate_id(true);
            $_SESSION['auth_user'] = [
                'id'         => (int)$record['user_id'],
                'name'       => $record['name'],
                'email'      => $record['email'],
                'role'       => $record['role'],
                'avatar_url' => $record['avatar_url'] ?? null
            ];

            // Reset rate limiter
            $_SESSION['auth_rate_limit'] = ['attempts' => 0, 'first_attempt' => time()];

            logSecurityEvent($pdo, (int)$record['user_id'], 'biometric_login_success', "Device: {$record['device_name']}");

            echo json_encode([
                'success'       => true,
                'authenticated' => true,
                'message'       => 'Biometric verification successful! Welcome back.',
                'user'          => $_SESSION['auth_user'],
                'csrf_token'    => SessionGuard::getCsrfToken()
            ]);
            exit;

        } catch (Exception $e) {
            logSecurityEvent($pdo, (int)$record['user_id'], 'biometric_login_failed', $e->getMessage());
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action requested.']);
        exit;
}
