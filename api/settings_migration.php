<?php
/**
 * Software CRM - Settings & Security Migration
 * Sets up tables for user settings, system settings, and security audits.
 */

require_once __DIR__ . '/db.php';

class SettingsMigration {
    public static function run(?PDO $pdo = null): bool {
        if ($pdo === null) {
            $pdo = Database::getConnection();
        }
        if (!$pdo) {
            return false;
        }

        try {
            // 1. User Settings Table
            $pdo->exec("CREATE TABLE IF NOT EXISTS `crm_user_settings` (
                `user_id` INT NOT NULL PRIMARY KEY,
                `theme` VARCHAR(32) NOT NULL DEFAULT 'light',
                `email_notifications` TINYINT(1) NOT NULL DEFAULT 1,
                `task_reminder_alerts` TINYINT(1) NOT NULL DEFAULT 1,
                `invoice_payment_alerts` TINYINT(1) NOT NULL DEFAULT 1,
                `weekly_financial_digest` TINYINT(1) NOT NULL DEFAULT 1,
                `compact_mode` TINYINT(1) NOT NULL DEFAULT 0,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_user_settings_uid` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

            // 2. System Settings Table (for last_sync_time, google_sheets status, etc.)
            $pdo->exec("CREATE TABLE IF NOT EXISTS `crm_system_settings` (
                `setting_key` VARCHAR(64) NOT NULL PRIMARY KEY,
                `setting_value` TEXT DEFAULT NULL,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

            // 3. Ensure crm_security_logs Table
            $pdo->exec("CREATE TABLE IF NOT EXISTS `crm_security_logs` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT DEFAULT NULL,
                `event_type` VARCHAR(64) NOT NULL,
                `ip_address` VARCHAR(64) DEFAULT NULL,
                `user_agent` TEXT DEFAULT NULL,
                `details` TEXT DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_sec_user` (`user_id`),
                INDEX `idx_sec_event` (`event_type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

            return true;
        } catch (Exception $e) {
            error_log("Settings migration error: " . $e->getMessage());
            return false;
        }
    }
}

// Auto-run if executed directly via CLI
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $ok = SettingsMigration::run();
    echo $ok ? "Settings migration succeeded.\n" : "Settings migration failed.\n";
}
