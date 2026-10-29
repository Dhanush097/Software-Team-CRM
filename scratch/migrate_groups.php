<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `crm_groups` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(150) NOT NULL UNIQUE,
            `description` TEXT NULL,
            `leader_id` VARCHAR(64) NULL,
            `color` VARCHAR(50) DEFAULT '#2563eb',
            `icon` VARCHAR(50) DEFAULT 'users',
            `created_by` VARCHAR(64) NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_leader` (`leader_id`),
            CONSTRAINT `fk_group_leader` FOREIGN KEY (`leader_id`) REFERENCES `crm_team_staff` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `crm_group_members` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `group_id` INT NOT NULL,
            `employee_id` VARCHAR(64) NOT NULL,
            `role_in_group` VARCHAR(50) DEFAULT 'member',
            `added_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_group_member` (`group_id`, `employee_id`),
            INDEX `idx_group` (`group_id`),
            INDEX `idx_employee` (`employee_id`),
            CONSTRAINT `fk_group_members_group` FOREIGN KEY (`group_id`) REFERENCES `crm_groups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_group_members_employee` FOREIGN KEY (`employee_id`) REFERENCES `crm_team_staff` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");

    echo "Tables crm_groups and crm_group_members created successfully!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
