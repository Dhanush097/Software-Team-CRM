<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();

echo "Creating crm_task_members table...\n";
$sql = "
CREATE TABLE IF NOT EXISTS `crm_task_members` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `task_id` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `employee_id` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
    `assigned_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_task_employee` (`task_id`, `employee_id`),
    INDEX `idx_task_id` (`task_id`),
    INDEX `idx_employee_id` (`employee_id`),
    CONSTRAINT `fk_task_members_task` FOREIGN KEY (`task_id`) REFERENCES `crm_tasks` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_task_members_employee` FOREIGN KEY (`employee_id`) REFERENCES `crm_team_staff` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

try {
    $pdo->exec($sql);
    echo "Table crm_task_members created successfully!\n";
} catch (Exception $e) {
    echo "Error creating table: " . $e->getMessage() . "\n";
    exit(1);
}

// Backfill existing tasks
echo "Backfilling existing tasks into crm_task_members...\n";
$tasks = $pdo->query("SELECT id, assigned_staff FROM crm_tasks")->fetchAll(PDO::FETCH_ASSOC);
$ins = $pdo->prepare("INSERT IGNORE INTO crm_task_members (task_id, employee_id) VALUES (?, ?)");

$staffList = $pdo->query("SELECT id, name FROM crm_team_staff")->fetchAll(PDO::FETCH_ASSOC);
$staffMap = [];
foreach ($staffList as $s) {
    $staffMap[strtolower(trim($s['name']))] = $s['id'];
    $staffMap[strtolower(trim($s['id']))] = $s['id'];
}

$backfilled = 0;
foreach ($tasks as $t) {
    $assigned = trim($t['assigned_staff'] ?? '');
    if (empty($assigned)) continue;

    // Check if it's comma separated or single
    $names = array_map('trim', explode(',', $assigned));
    foreach ($names as $name) {
        $lower = strtolower($name);
        if (isset($staffMap[$lower])) {
            $ins->execute([$t['id'], $staffMap[$lower]]);
            $backfilled++;
        }
    }
}
echo "Backfilled {$backfilled} assignments across " . count($tasks) . " existing tasks.\n";
