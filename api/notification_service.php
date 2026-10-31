<?php
/**
 * Software CRM - Notification Service
 * Shared backend service to create, deduplicate, and synchronize CRM notifications.
 */

require_once __DIR__ . '/db.php';

class NotificationService {

    public static function ensureTable(PDO $pdo): void {
        $sql = "CREATE TABLE IF NOT EXISTS `crm_notifications` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT NULL,
          `category` ENUM('invoices', 'clients', 'tasks', 'system') NOT NULL DEFAULT 'system',
          `event_type` VARCHAR(64) NOT NULL,
          `title` VARCHAR(255) NOT NULL,
          `message` TEXT NOT NULL,
          `link_type` VARCHAR(64) NULL,
          `link_id` VARCHAR(128) NULL,
          `action_label` VARCHAR(64) NULL,
          `is_read` TINYINT(1) NOT NULL DEFAULT 0,
          `read_at` DATETIME NULL,
          `metadata` LONGTEXT NULL,
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_user_read` (`user_id`, `is_read`),
          INDEX `idx_category` (`category`),
          INDEX `idx_created` (`created_at`),
          INDEX `idx_event_link` (`event_type`, `link_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $pdo->exec($sql);

        $cols = [
            'category'     => "TEXT DEFAULT 'system'",
            'event_type'   => "TEXT DEFAULT 'info'",
            'link_type'    => "TEXT DEFAULT 'modal'",
            'link_id'      => "TEXT DEFAULT ''",
            'action_label' => "TEXT DEFAULT 'View'",
            'read_at'      => "DATETIME",
            'metadata'     => "TEXT"
        ];
        foreach ($cols as $c => $def) {
            try { $pdo->exec("ALTER TABLE `crm_notifications` ADD COLUMN `$c` $def"); } catch (Exception $e) {}
        }
    }

    public static function create(
        PDO $pdo,
        $categoryOrData,
        ?string $eventType = null,
        ?string $title = null,
        ?string $message = null,
        ?string $linkType = null,
        ?string $linkId = null,
        ?string $actionLabel = null,
        ?int $userId = null,
        array $metadata = [],
        ?string $createdAt = null
    ): ?int {
        self::ensureTable($pdo);

        if (is_array($categoryOrData)) {
            $data        = $categoryOrData;
            $category    = (string)($data['category'] ?? 'system');
            $eventType   = (string)($data['event_type'] ?? 'alert');
            $title       = (string)($data['title'] ?? 'Notification');
            $message     = (string)($data['message'] ?? '');
            $linkType    = isset($data['link_type']) ? (string)$data['link_type'] : null;
            $linkId      = isset($data['link_id']) ? (string)$data['link_id'] : null;
            $actionLabel = isset($data['action_label']) ? (string)$data['action_label'] : null;
            $userId      = isset($data['user_id']) ? (int)$data['user_id'] : null;
            $metadata    = isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : [];
            $createdAt   = isset($data['created_at']) ? (string)$data['created_at'] : null;
        } else {
            $category = (string)$categoryOrData;
        }

        // Deduplication check: do not create identical notifications for the same event and target record
        if (!empty($eventType) && !empty($linkId)) {
            $check = $pdo->prepare("SELECT `id` FROM `crm_notifications` WHERE `event_type` = ? AND `link_id` = ? AND (`user_id` = ? OR (`user_id` IS NULL AND ? IS NULL)) LIMIT 1");
            $check->execute([$eventType, $linkId, $userId, $userId]);
            $exists = $check->fetch();
            $check->closeCursor();
            if ($exists) {
                return null;
            }
        }

        $metaJson = !empty($metadata) ? json_encode($metadata) : null;
        if ($createdAt) {
            $stmt = $pdo->prepare("INSERT INTO `crm_notifications` (`user_id`, `category`, `event_type`, `title`, `message`, `link_type`, `link_id`, `action_label`, `is_read`, `metadata`, `created_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)");
            $stmt->execute([$userId, $category, $eventType, $title, $message, $linkType, $linkId, $actionLabel, $metaJson, $createdAt]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO `crm_notifications` (`user_id`, `category`, `event_type`, `title`, `message`, `link_type`, `link_id`, `action_label`, `is_read`, `metadata`, `created_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, NOW())");
            $stmt->execute([$userId, $category, $eventType, $title, $message, $linkType, $linkId, $actionLabel, $metaJson]);
        }
        $lastId = (int)$pdo->lastInsertId();
        $stmt->closeCursor();

        return $lastId;
    }

    /**
     * Synchronize notifications from real database records (invoices, tasks, contacts, security)
     * Ensures all existing CRM data is accurately reflected without mock data.
     */
    public static function syncRealCrmEvents(PDO $pdo, ?int $userId = null): array {
        self::ensureTable($pdo);

        // 1. INVOICE EVENTS (from crm_cash_ledger)
        $invStmt = $pdo->query("SELECT `id`, `invoice_no`, `customer_name`, `amount`, `paid_amount`, `balance_amount`, `status`, `due_date`, `transaction_date` FROM `crm_cash_ledger` ORDER BY `transaction_date` DESC LIMIT 50");
        $now = time();
        $invoices = $invStmt ? $invStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        if ($invStmt) $invStmt->closeCursor();

        foreach ($invoices as $inv) {
            $invId = $inv['id'];
            $invNo = $inv['invoice_no'] ?: $invId;
            $customer = $inv['customer_name'] ?: 'Valued Client';
            $amt = number_format((float)$inv['amount'], 2);
            $bal = number_format((float)$inv['balance_amount'], 2);
            $txDate = $inv['transaction_date'] ?: date('Y-m-d H:i:s');

            // Event A: New invoice created
            self::create(
                $pdo,
                'invoices',
                'invoice_created',
                "New Invoice: #{$invNo}",
                "Invoice of ₹{$amt} created for {$customer}. Status: {$inv['status']}",
                'invoice',
                $invId,
                'View Invoice',
                $userId,
                ['amount' => $inv['amount'], 'status' => $inv['status']],
                $txDate
            );

            // Event B: Invoice Paid
            if (strcasecmp($inv['status'], 'Paid') === 0 || (float)$inv['balance_amount'] <= 0.00) {
                self::create(
                    $pdo,
                    'invoices',
                    'invoice_paid',
                    "Invoice Paid: #{$invNo}",
                    "Full payment of ₹{$amt} successfully received for {$customer}.",
                    'invoice',
                    $invId,
                    'View Receipt',
                    $userId,
                    ['amount' => $inv['amount'], 'status' => 'Paid'],
                    $txDate
                );
            }

            // Event C: Invoice Overdue or Approaching Due Date
            if (strcasecmp($inv['status'], 'Paid') !== 0 && (float)$inv['balance_amount'] > 0.00 && !empty($inv['due_date'])) {
                $dueTs = strtotime($inv['due_date']);
                if ($dueTs !== false) {
                    if ($dueTs < $now) {
                        self::create(
                            $pdo,
                            'invoices',
                            'invoice_overdue',
                            "Invoice Overdue: #{$invNo}",
                            "Invoice for {$customer} with balance ₹{$bal} was due on {$inv['due_date']}.",
                            'invoice',
                            $invId,
                            'Collect Payment',
                            $userId,
                            ['balance' => $inv['balance_amount'], 'status' => 'Overdue'],
                            $txDate
                        );
                    } elseif ($dueTs - $now <= (86400 * 3)) { // within 3 days
                        self::create(
                            $pdo,
                            'invoices',
                            'invoice_due',
                            "Invoice Approaching Due: #{$invNo}",
                            "Payment of ₹{$bal} for {$customer} is due soon on {$inv['due_date']}.",
                            'invoice',
                            $invId,
                            'Review Invoice',
                            $userId,
                            ['balance' => $inv['balance_amount'], 'status' => 'Pending'],
                            $txDate
                        );
                    }
                }
            }
        }

        // 2. TASK EVENTS (from crm_tasks)
        $taskStmt = $pdo->query("SELECT `id`, `title`, `project_id`, `project_name`, `assigned_staff`, `completed`, `status`, `due_date`, `created_at` FROM `crm_tasks` ORDER BY `created_at` DESC LIMIT 50");
        $tasks = $taskStmt ? $taskStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        if ($taskStmt) $taskStmt->closeCursor();

        foreach ($tasks as $t) {
            $tId = $t['id'];
            $tTitle = $t['title'] ?: 'Untitled Task';
            $pName = $t['project_name'] ?: 'Main Project';
            $staff = $t['assigned_staff'] ?: 'Team Member';
            $tCreated = $t['created_at'] ?: date('Y-m-d H:i:s');

            // Event A: New task assigned / created
            self::create(
                $pdo,
                'tasks',
                'task_created',
                "Task Assigned: {$tTitle}",
                "Task in \"{$pName}\" assigned to {$staff}.",
                'task',
                $tId,
                'View Task',
                $userId,
                ['project_id' => $t['project_id']],
                $tCreated
            );

            // Event B: Task completed
            if (!empty($t['completed']) || strcasecmp($t['status'] ?? '', 'Completed') === 0) {
                self::create(
                    $pdo,
                    'tasks',
                    'task_completed',
                    "Task Completed: {$tTitle}",
                    "Task in \"{$pName}\" was marked as completed.",
                    'task',
                    $tId,
                    'View Task',
                    $userId,
                    ['project_id' => $t['project_id']],
                    $tCreated
                );
            }

            // Event C: Task Overdue
            if (empty($t['completed']) && !empty($t['due_date'])) {
                $dueTs = strtotime($t['due_date']);
                if ($dueTs !== false && $dueTs < $now) {
                    self::create(
                        $pdo,
                        'tasks',
                        'task_overdue',
                        "Task Overdue: {$tTitle}",
                        "Deadline ({$t['due_date']}) has passed for task in \"{$pName}\".",
                        'task',
                        $tId,
                        'Update Task',
                        $userId,
                        ['project_id' => $t['project_id']],
                        $tCreated
                    );
                }
            }
        }

        // 3. CLIENT / CONTACT EVENTS (from crm_contacts)
        $cntStmt = $pdo->query("SELECT `id`, `name`, `company`, `email`, `created_at`, `updated_at` FROM `crm_contacts` ORDER BY `created_at` DESC LIMIT 30");
        $contacts = $cntStmt ? $cntStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        if ($cntStmt) $cntStmt->closeCursor();

        foreach ($contacts as $c) {
            $cId = $c['id'];
            $cName = $c['name'] ?: 'Contact';
            $cCompany = $c['company'] ? " ({$c['company']})" : '';
            $cCreated = $c['created_at'] ?: date('Y-m-d H:i:s');

            // Event A: Client added
            self::create(
                $pdo,
                'clients',
                'client_created',
                "New Client: {$cName}",
                "{$cName}{$cCompany} was added to contacts directory.",
                'contact',
                $cId,
                'View Contact',
                $userId,
                ['email' => $c['email']],
                $cCreated
            );

            // Event B: Client updated
            if (!empty($c['updated_at']) && $c['updated_at'] !== $c['created_at']) {
                self::create(
                    $pdo,
                    'clients',
                    'client_updated',
                    "Contact Updated: {$cName}",
                    "Profile and contact details for {$cName} were updated.",
                    'contact',
                    $cId,
                    'View Profile',
                    $userId,
                    ['email' => $c['email']],
                    $c['updated_at']
                );
            }
        }

        // 4. SYSTEM NOTIFICATIONS
        self::create(
            $pdo,
            'system',
            'security_login',
            "Security Sign-In Verified",
            "Authenticated session active with email OTP verification protection.",
            'system',
            'auth_security',
            'Security Status',
            $userId,
            ['type' => 'security']
        );

        self::create(
            $pdo,
            'system',
            'system_alert',
            "CRM Data Synchronized",
            "Invoices, tasks, projects, and contacts are synchronized with local database.",
            'system',
            'db_sync',
            'System Health',
            $userId,
            ['type' => 'health']
        );

        return [
            'success' => true,
            'count' => (int)$pdo->query("SELECT COUNT(*) FROM crm_notifications")->fetchColumn()
        ];
    }
}
