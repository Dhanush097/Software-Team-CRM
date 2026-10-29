<?php
/**
 * Software CRM - Security Audit Logger
 *
 * Securely records critical authentication & authorization events:
 * - Successful logins
 * - Failed OTP verification attempts
 * - Denied employee authorizations (unlisted/inactive)
 * - Inactive employee detections & session terminations
 * - Logouts
 * - Role / permission changes
 * - Google Sheets authorization failures
 *
 * Strict Security Guarantee:
 * Never logs OTP values, passwords, private keys, access tokens, or session IDs.
 */

class SecurityAuditLog {
    private static string $logFile = '';

    private static function getLogPath(): string {
        if (empty(self::$logFile)) {
            self::$logFile = dirname(__DIR__) . '/data/security_audit.log';
        }
        return self::$logFile;
    }

    /**
     * Set custom log path (used during tests if desired)
     */
    public static function setLogPath(string $path): void {
        self::$logFile = $path;
    }

    /**
     * Log a security event
     */
    public static function log(string $eventType, array $context = []): void {
        $logPath = self::getLogPath();
        $dir = dirname($logPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        // Sanitize context: Remove all sensitive authentication secrets
        $sanitized = [];
        $sensitiveKeys = [
            'otp', 'otp_code', 'password', 'password_hash', 'secret',
            'token', 'access_token', 'private_key', 'private_key_id',
            'csrf_token', 'session_id', 'credentials'
        ];

        foreach ($context as $k => $v) {
            $lower = strtolower((string)$k);
            if (in_array($lower, $sensitiveKeys, true)) {
                $sanitized[$k] = '[REDACTED]';
            } elseif (is_string($v) && strlen($v) > 500) {
                $sanitized[$k] = substr($v, 0, 500) . '...[truncated]';
            } else {
                $sanitized[$k] = $v;
            }
        }

        $timestamp = date('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $userEmail = $sanitized['email'] ?? ($sanitized['user_email'] ?? 'unauthenticated');

        $detailParts = [];
        foreach ($sanitized as $key => $val) {
            if ($key === 'email' || $key === 'user_email') continue;
            if (is_array($val) || is_object($val)) {
                $val = json_encode($val);
            }
            $detailParts[] = "$key=$val";
        }
        $detailStr = !empty($detailParts) ? ' | ' . implode(', ', $detailParts) : '';

        $line = sprintf(
            "[%s] [%s] [%s] User: %s%s\n",
            $timestamp,
            $ip,
            strtoupper(trim($eventType)),
            $userEmail,
            $detailStr
        );

        @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
    }
}
