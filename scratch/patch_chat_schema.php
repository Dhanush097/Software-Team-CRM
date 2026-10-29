<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();

function addColIfNotExists($pdo, $table, $col, $def) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
            echo "Added $col to $table\n";
        }
    } catch (Exception $e) {
        echo "Error on $col: " . $e->getMessage() . "\n";
    }
}

addColIfNotExists($pdo, 'crm_group_chats', 'message_type', "VARCHAR(32) DEFAULT 'text' AFTER `sender_avatar`");
addColIfNotExists($pdo, 'crm_group_chats', 'attachment_type', "VARCHAR(32) DEFAULT NULL");
addColIfNotExists($pdo, 'crm_group_chats', 'attachment_url', "TEXT DEFAULT NULL");
addColIfNotExists($pdo, 'crm_group_chats', 'attachment_name', "VARCHAR(255) DEFAULT NULL");
addColIfNotExists($pdo, 'crm_group_chats', 'reply_to_id', "INT DEFAULT NULL");
addColIfNotExists($pdo, 'crm_group_chats', 'deletion_state', "ENUM('none', 'unsent', 'deleted_everyone') DEFAULT 'none'");
addColIfNotExists($pdo, 'crm_group_chats', 'edited_at', "DATETIME DEFAULT NULL");

addColIfNotExists($pdo, 'crm_group_message_receipts', 'user_name', "VARCHAR(128) DEFAULT '' AFTER `user_id`");

echo "Schema updated.\n";
