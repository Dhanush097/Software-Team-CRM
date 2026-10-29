<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();

$sql = "CREATE TABLE IF NOT EXISTS `crm_group_chats` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `group_id` INT NOT NULL,
  `sender_id` VARCHAR(64) NOT NULL,
  `sender_name` VARCHAR(128) NOT NULL,
  `sender_role` VARCHAR(64) DEFAULT 'Member',
  `sender_color` VARCHAR(32) DEFAULT '#2563eb',
  `sender_avatar` VARCHAR(255) DEFAULT '',
  `message` TEXT NOT NULL,
  `reply_to_name` VARCHAR(128) DEFAULT NULL,
  `reply_to_text` TEXT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_gc_group` (`group_id`),
  INDEX `idx_gc_created` (`created_at`),
  CONSTRAINT `fk_gc_group` FOREIGN KEY (`group_id`)
    REFERENCES `crm_groups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";

$pdo->exec($sql);
echo "crm_group_chats created successfully.\n";
