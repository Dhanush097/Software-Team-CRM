<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();

echo "Creating Group Chat and WebRTC calling tables...\n";

// 1. Group Messages
$pdo->exec("
CREATE TABLE IF NOT EXISTS `crm_group_chats` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `group_id` INT NOT NULL,
  `sender_id` VARCHAR(64) NOT NULL,
  `sender_name` VARCHAR(128) NOT NULL,
  `sender_role` VARCHAR(64) DEFAULT 'Member',
  `sender_color` VARCHAR(32) DEFAULT '#2563eb',
  `sender_avatar` VARCHAR(255) DEFAULT '',
  `message_type` VARCHAR(32) DEFAULT 'text',
  `message` TEXT NOT NULL,
  `attachment_type` VARCHAR(32) DEFAULT NULL,
  `attachment_url` TEXT DEFAULT NULL,
  `attachment_name` VARCHAR(255) DEFAULT NULL,
  `reply_to_id` INT DEFAULT NULL,
  `reply_to_name` VARCHAR(128) DEFAULT NULL,
  `reply_to_text` TEXT DEFAULT NULL,
  `deletion_state` ENUM('none', 'unsent', 'deleted_everyone') DEFAULT 'none',
  `edited_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_gc_group` (`group_id`),
  INDEX `idx_gc_sender` (`sender_id`),
  INDEX `idx_gc_created` (`created_at`),
  CONSTRAINT `fk_gc_group` FOREIGN KEY (`group_id`)
    REFERENCES `crm_groups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
");

// 2. Per-User Message Deletions ("Delete for Me")
$pdo->exec("
CREATE TABLE IF NOT EXISTS `crm_group_message_deletions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `message_id` INT NOT NULL,
  `user_id` VARCHAR(64) NOT NULL,
  `deleted_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_msg_user_del` (`message_id`, `user_id`),
  INDEX `idx_gmd_msg` (`message_id`),
  INDEX `idx_gmd_user` (`user_id`),
  CONSTRAINT `fk_gmd_msg` FOREIGN KEY (`message_id`)
    REFERENCES `crm_group_chats` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
");

// 3. Message Delivery & Read Receipts (per message & recipient)
$pdo->exec("
CREATE TABLE IF NOT EXISTS `crm_group_message_receipts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `message_id` INT NOT NULL,
  `group_id` INT NOT NULL,
  `user_id` VARCHAR(64) NOT NULL,
  `user_name` VARCHAR(128) DEFAULT '',
  `delivered_at` DATETIME DEFAULT NULL,
  `read_at` DATETIME DEFAULT NULL,
  UNIQUE KEY `uq_msg_user_receipt` (`message_id`, `user_id`),
  INDEX `idx_gmr_msg` (`message_id`),
  INDEX `idx_gmr_user` (`user_id`),
  INDEX `idx_gmr_group` (`group_id`),
  CONSTRAINT `fk_gmr_msg` FOREIGN KEY (`message_id`)
    REFERENCES `crm_group_chats` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
");

// 4. Group Call Sessions
$pdo->exec("
CREATE TABLE IF NOT EXISTS `crm_group_calls` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `group_id` INT NOT NULL,
  `initiator_id` VARCHAR(64) NOT NULL,
  `initiator_name` VARCHAR(128) NOT NULL,
  `call_type` ENUM('audio', 'video') NOT NULL DEFAULT 'audio',
  `call_status` ENUM('calling', 'ringing', 'connecting', 'connected', 'reconnecting', 'ended', 'failed') NOT NULL DEFAULT 'calling',
  `is_presenting` TINYINT(1) DEFAULT 0,
  `presenter_id` VARCHAR(64) DEFAULT NULL,
  `presenter_name` VARCHAR(128) DEFAULT NULL,
  `start_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `end_time` DATETIME DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_call_group` (`group_id`),
  INDEX `idx_call_status` (`call_status`),
  CONSTRAINT `fk_call_group` FOREIGN KEY (`group_id`)
    REFERENCES `crm_groups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
");

// 5. Group Call Participants
$pdo->exec("
CREATE TABLE IF NOT EXISTS `crm_group_call_participants` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `call_id` INT NOT NULL,
  `user_id` VARCHAR(64) NOT NULL,
  `user_name` VARCHAR(128) NOT NULL,
  `user_role` VARCHAR(64) DEFAULT 'Member',
  `user_avatar` VARCHAR(255) DEFAULT '',
  `status` ENUM('invited', 'ringing', 'joined', 'left', 'declined') DEFAULT 'invited',
  `is_muted` TINYINT(1) DEFAULT 0,
  `is_camera_off` TINYINT(1) DEFAULT 0,
  `is_screen_sharing` TINYINT(1) DEFAULT 0,
  `joined_at` DATETIME DEFAULT NULL,
  `left_at` DATETIME DEFAULT NULL,
  `last_seen` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_call_user` (`call_id`, `user_id`),
  INDEX `idx_cp_call` (`call_id`),
  INDEX `idx_cp_user` (`user_id`),
  CONSTRAINT `fk_cp_call` FOREIGN KEY (`call_id`)
    REFERENCES `crm_group_calls` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
");

// 6. WebRTC Call Signals
$pdo->exec("
CREATE TABLE IF NOT EXISTS `crm_group_call_signals` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `call_id` INT NOT NULL,
  `sender_id` VARCHAR(64) NOT NULL,
  `receiver_id` VARCHAR(64) DEFAULT NULL,
  `signal_type` VARCHAR(32) NOT NULL,
  `signal_data` MEDIUMTEXT NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_cs_call_rec` (`call_id`, `receiver_id`),
  INDEX `idx_cs_created` (`created_at`),
  CONSTRAINT `fk_cs_call` FOREIGN KEY (`call_id`)
    REFERENCES `crm_group_calls` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
");

echo "All 6 tables created successfully with foreign keys and indexes!\n";
