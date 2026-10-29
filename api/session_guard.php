<?php
/**
 * Software CRM - Central Session Guard & Server-Side Authorization
 * Protects every private PHP page and API endpoint.
 */

if (!ini_get('date.timezone')) {
    date_default_timezone_set('Asia/Kolkata');
} else {
    date_default_timezone_set('Asia/Kolkata');
}

if (session_status() === PHP_SESSION_NONE) {
    // Configure secure session cookie attributes
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    
    session_set_cookie_params([
        'lifetime' => 86400 * 7, // 7 days
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    
    session_start();
}

class SessionGuard {
    /**
     * Checks if current request is authenticated.
     * If $strictApi = true, automatically outputs 401 JSON and halts execution.
     */
    public static function requireAuth(bool $strictApi = true): ?array {
        if (!empty($_SESSION['auth_user']) && is_array($_SESSION['auth_user']) && !empty($_SESSION['auth_user']['id'])) {
            return $_SESSION['auth_user'];
        }

        if ($strictApi) {
            http_response_code(401);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'success' => false,
                'authenticated' => false,
                'error' => 'Unauthorized. Please sign in with email and OTP.',
                'code' => 'AUTH_REQUIRED'
            ]);
            exit;
        }

        return null;
    }

    /**
     * Returns true if user is currently logged in with active session
     */
    public static function isAuthenticated(): bool {
        return !empty($_SESSION['auth_user']) && is_array($_SESSION['auth_user']) && !empty($_SESSION['auth_user']['id']);
    }

    /**
     * Returns the current authenticated user data or null
     */
    public static function getUser(): ?array {
        return self::isAuthenticated() ? $_SESSION['auth_user'] : null;
    }

    public static function getCurrentUser(): ?array {
        return self::getUser();
    }

    /**
     * Generates or returns active CSRF token
     */
    public static function getCsrfToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Verifies CSRF token
     */
    public static function verifyCsrfToken(?string $token): bool {
        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }
}
