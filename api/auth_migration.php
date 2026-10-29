<?php
/**
 * Software CRM - Database Migration for Authentication & OTP
 * Creates `crm_users` and `crm_otp_challenges` tables.
 * Safe & reversible. Never deletes existing CRM data.
 */

require_once __DIR__ . '/db.php';

$pdo = Database::getConnection();
if (!$pdo) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit(1);
}

try {
    // 1. Create crm_users table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `crm_users` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(150) NOT NULL,
        `email` VARCHAR(191) NOT NULL UNIQUE,
        `password_hash` VARCHAR(255) NOT NULL,
        `role` VARCHAR(50) NOT NULL DEFAULT 'Superadmin',
        `status` ENUM('active', 'pending_verification', 'suspended') NOT NULL DEFAULT 'active',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `last_login_at` DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // 2. Create crm_otp_challenges table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `crm_otp_challenges` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `email` VARCHAR(191) NOT NULL,
        `otp_hash` VARCHAR(255) NOT NULL,
        `purpose` ENUM('login', 'registration', 'password_reset') NOT NULL,
        `temp_payload` LONGTEXT NULL,
        `attempts` INT NOT NULL DEFAULT 0,
        `max_attempts` INT NOT NULL DEFAULT 5,
        `resend_count` INT NOT NULL DEFAULT 0,
        `last_sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `expires_at` DATETIME NOT NULL,
        `is_used` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_email_purpose` (`email`, `purpose`),
        INDEX `idx_expires` (`expires_at`, `is_used`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // 3. Seed default admin user if none exists
    $stmt = $pdo->prepare("SELECT id FROM `crm_users` WHERE `email` = ?");
    $stmt->execute(['suresh@softwarecrm.com']);
    $user = $stmt->fetch();

    if (!$user) {
        // Default password for Suresh Kumar (Admin@12345)
        $defaultPassword = 'Password@123';
        $hashed = password_hash($defaultPassword, PASSWORD_DEFAULT);
        $insertStmt = $pdo->prepare("INSERT INTO `crm_users` (`name`, `email`, `password_hash`, `role`, `status`) VALUES (?, ?, ?, ?, ?)");
        $insertStmt->execute(['Suresh Kumar', 'suresh@softwarecrm.com', $hashed, 'Superadmin', 'active']);
    }

    // Also run for pocket_crm_db if connected to u213657911_crm, ensuring cross-database resilience
    try {
        $altPdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=pocket_crm_db;charset=utf8mb4", "root", "", [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        $altPdo->exec("CREATE TABLE IF NOT EXISTS `crm_users` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(150) NOT NULL,
            `email` VARCHAR(191) NOT NULL UNIQUE,
            `password_hash` VARCHAR(255) NOT NULL,
            `role` VARCHAR(50) NOT NULL DEFAULT 'Superadmin',
            `status` ENUM('active', 'pending_verification', 'suspended') NOT NULL DEFAULT 'active',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `last_login_at` DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $altPdo->exec("CREATE TABLE IF NOT EXISTS `crm_otp_challenges` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `email` VARCHAR(191) NOT NULL,
            `otp_hash` VARCHAR(255) NOT NULL,
            `purpose` ENUM('login', 'registration', 'password_reset') NOT NULL,
            `temp_payload` LONGTEXT NULL,
            `attempts` INT NOT NULL DEFAULT 0,
            `max_attempts` INT NOT NULL DEFAULT 5,
            `resend_count` INT NOT NULL DEFAULT 0,
            `last_sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `expires_at` DATETIME NOT NULL,
            `is_used` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_email_purpose` (`email`, `purpose`),
            INDEX `idx_expires` (`expires_at`, `is_used`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $stmt2 = $altPdo->prepare("SELECT id FROM `crm_users` WHERE `email` = ?");
        $stmt2->execute(['suresh@softwarecrm.com']);
        if (!$stmt2->fetch()) {
            $hashed = password_hash('Password@123', PASSWORD_DEFAULT);
            $altPdo->prepare("INSERT INTO `crm_users` (`name`, `email`, `password_hash`, `role`, `status`) VALUES (?, ?, ?, ?, ?)")
                   ->execute(['Suresh Kumar', 'suresh@softwarecrm.com', $hashed, 'Superadmin', 'active']);
        }
    } catch (Exception $altEx) {
        // pocket_crm_db optional
    }

    echo json_encode(['success' => true, 'message' => 'Auth migration completed successfully.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
