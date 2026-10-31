<?php
/**
 * Software Team Database Connection Manager
 * Supports Hostinger MySQL, Localhost MySQL, with automatic SQLite fallback.
 */

date_default_timezone_set('UTC');

/**
 * SQLite-compatible PDO wrapper.
 * Transparently rewrites MySQL-specific SQL to SQLite equivalents:
 *   NOW()                    -> CURRENT_TIMESTAMP
 *   ENGINE=InnoDB            -> stripped
 *   COLLATE utf8mb4_...      -> stripped
 *   ENUM(...)                -> TEXT
 *   INT AUTO_INCREMENT       -> INTEGER
 *   ON UPDATE CURRENT_TIMESTAMP -> stripped
 *   SHOW TABLES / COLUMNS    -> pragma & sqlite_master
 *   DATE_ADD / DATE_SUB      -> datetime('now', '+/-x units')
 */
class SQLitePDO extends PDO {
    public static function patch(string $sql): string {
        // Strip MySQL engine, collate, and charset options
        $sql = preg_replace('/\s+ENGINE\s*=\s*\w+[^;]*/i', '', $sql);
        $sql = preg_replace('/\s+COLLATE\s+\S+/i', '', $sql);
        $sql = preg_replace('/\s+DEFAULT\s+CHARSET\s*=\s*\w+/i', '', $sql);
        $sql = preg_replace('/\s+CHARACTER\s+SET\s+\w+/i', '', $sql);
        $sql = preg_replace('/\bENUM\s*\([^)]+\)/i', 'TEXT', $sql);
        $sql = preg_replace('/\bINT\s+AUTO_INCREMENT\b/i', 'INTEGER', $sql);
        $sql = preg_replace('/\s+ON\s+UPDATE\s+CURRENT_TIMESTAMP/i', '', $sql);
        $sql = preg_replace('/\s+ON\s+UPDATE\s+NOW\(\)/i', '', $sql);

        // Date & Time intervals
        $sql = preg_replace('/\bDATE_ADD\s*\(\s*(?:NOW\(\)|CURRENT_TIMESTAMP)\s*,\s*INTERVAL\s+(\d+)\s+([A-Za-z]+)\s*\)/i', "datetime('now', '+$1 $2')", $sql);
        $sql = preg_replace('/\bDATE_SUB\s*\(\s*(?:NOW\(\)|CURRENT_TIMESTAMP)\s*,\s*INTERVAL\s+(\d+)\s+([A-Za-z]+)\s*\)/i', "datetime('now', '-$1 $2')", $sql);
        $sql = preg_replace('/(?:NOW\(\)|CURRENT_TIMESTAMP)\s*\+\s*INTERVAL\s+(\d+)\s+([A-Za-z]+)/i', "datetime('now', '+$1 $2')", $sql);
        $sql = preg_replace('/(?:NOW\(\)|CURRENT_TIMESTAMP)\s*-\s*INTERVAL\s+(\d+)\s+([A-Za-z]+)/i', "datetime('now', '-$1 $2')", $sql);
        $sql = preg_replace('/\bNOW\(\)/i', 'CURRENT_TIMESTAMP', $sql);

        // INSERT IGNORE -> INSERT OR IGNORE
        $sql = preg_replace('/\bINSERT\s+IGNORE\s+INTO\b/i', 'INSERT OR IGNORE INTO', $sql);

        // ON DUPLICATE KEY UPDATE -> ON CONFLICT
        if (preg_match('/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i', $sql)) {
            $conflictCol = 'id';
            if (preg_match('/INSERT\s+INTO\s+[`\'"]?crm_user_settings[`\'"]?/i', $sql)) {
                $conflictCol = 'user_id';
            } elseif (preg_match('/INSERT\s+INTO\s+[`\'"]?([a-zA-Z0-9_]+)[`\'"]?\s*\(([^)]+)\)/i', $sql, $tblM)) {
                $colsList = array_map('trim', explode(',', str_replace('`', '', $tblM[2])));
                if (in_array('user_id', $colsList, true) && !in_array('id', $colsList, true)) {
                    $conflictCol = 'user_id';
                }
            }
            $sql = preg_replace('/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i', "ON CONFLICT ($conflictCol) DO UPDATE SET", $sql);
            $sql = preg_replace('/\bVALUES\s*\(\s*[`\'"]?([a-zA-Z0-9_]+)[`\'"]?\s*\)/i', 'excluded.$1', $sql);
        }

        // SHOW TABLES [LIKE '...']
        if (preg_match('/^\s*SHOW\s+TABLES(?:\s+LIKE\s+(.+))?\s*$/i', trim($sql), $m)) {
            if (!empty($m[1])) {
                return "SELECT name AS Tables_in_crm, name FROM sqlite_master WHERE type='table' AND name LIKE " . $m[1];
            }
            return "SELECT name AS Tables_in_crm, name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'";
        }

        // SHOW COLUMNS FROM `table` [LIKE 'col'] / DESCRIBE `table`
        if (preg_match('/^\s*SHOW\s+COLUMNS\s+FROM\s+[`\'"]?([a-zA-Z0-9_]+)[`\'"]?(?:\s+LIKE\s+(.+))?\s*$/i', trim($sql), $m) ||
            preg_match('/^\s*DESCRIBE\s+[`\'"]?([a-zA-Z0-9_]+)[`\'"]?\s*$/i', trim($sql), $m)) {
            $tbl = $m[1];
            $like = !empty($m[2]) ? " WHERE name LIKE " . $m[2] : "";
            return "SELECT name AS Field, type AS Type, (CASE WHEN \"notnull\"=1 THEN 'NO' ELSE 'YES' END) AS `Null`, (CASE WHEN pk=1 THEN 'PRI' ELSE '' END) AS `Key`, dflt_value AS `Default`, '' AS Extra FROM pragma_table_info('$tbl')$like";
        }

        // ALTER TABLE conversions
        if (preg_match('/^\s*ALTER\s+TABLE\s+[`\'"]?([a-zA-Z0-9_]+)[`\'"]?\s+ADD\s+INDEX\s+[`\'"]?([a-zA-Z0-9_]+)[`\'"]?\s*\((.+)\)/i', trim($sql), $m)) {
            return "CREATE INDEX IF NOT EXISTS `{$m[2]}` ON `{$m[1]}` ({$m[3]})";
        }
        if (preg_match('/^\s*ALTER\s+TABLE\s+[`\'"]?([a-zA-Z0-9_]+)[`\'"]?\s+ADD\s+CONSTRAINT/i', trim($sql))) {
            return "SELECT 1";
        }
        if (preg_match('/^\s*ALTER\s+TABLE/i', $sql)) {
            $sql = preg_replace('/,\s*ADD\s+INDEX\s+[`\'"]?([a-zA-Z0-9_]+)[`\'"]?\s*\([^)]+\)/i', '', $sql);
            $sql = preg_replace('/,\s*ADD\s+KEY\s+[`\'"]?([a-zA-Z0-9_]+)[`\'"]?\s*\([^)]+\)/i', '', $sql);
            $sql = preg_replace('/,\s*ADD\s+CONSTRAINT\s+[^;]+/i', '', $sql);
            $sql = preg_replace('/\s+AFTER\s+[`\'"]?\w+[`\'"]?/i', '', $sql);
        }

        // CREATE TABLE inline INDEX / KEY stripping
        if (preg_match('/^\s*CREATE\s+TABLE/i', $sql)) {
            $sql = preg_replace('/\bUNIQUE\s+(?:KEY|INDEX)\s+[`\'"]?[a-zA-Z0-9_]+[`\'"]?\s*\(([^)]+)\)/i', 'UNIQUE ($1)', $sql);
            $sql = preg_replace('/,\s*(?:INDEX|KEY)\s+[`\'"]?[a-zA-Z0-9_]+[`\'"]?\s*\([^)]+\)/i', '', $sql);
        }

        // Strip LIMIT from UPDATE and DELETE statements (unsupported in SQLite)
        $sql = preg_replace('/^(UPDATE\s+.+?)\s+LIMIT\s+\d+\s*$/is', '$1', $sql);
        $sql = preg_replace('/^(DELETE\s+.+?)\s+LIMIT\s+\d+\s*$/is', '$1', $sql);

        return $sql;
    }

    public function exec(string $statement): int|false {
        try {
            return parent::exec(self::patch($statement));
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'duplicate column name')) {
                return 0;
            }
            throw $e;
        }
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(self::patch($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $query = self::patch($query);
        if ($fetchMode !== null) {
            return parent::query($query, $fetchMode, ...$fetchModeArgs);
        }
        return parent::query($query);
    }
}

class Database {
    private static ?PDO $instance = null;
    private static string $engine = 'none';
    private static bool $schemaEnsured = false;

    public static function resetConnection(): void {
        self::$instance = null;
    }

    public static function getConnection(): ?PDO {
        if (self::$instance !== null) {
            return self::$instance;
        }

        // Database connection candidate configurations
        $dbHost = getenv('DB_HOST') ?: null;
        $configs = [];

        if ($dbHost !== null) {
            // Environment variable explicitly configured MySQL host (Render / production)
            $configs = [
                [
                    'host' => $dbHost,
                    'port' => getenv('DB_PORT') ?: '3306',
                    'db'   => getenv('DB_NAME') ?: 'pocket_crm_db',
                    'user' => getenv('DB_USER') ?: 'root',
                    'pass' => getenv('DB_PASS') !== false ? getenv('DB_PASS') : ''
                ]
            ];
        } else {
            // Fast socket probe (50ms) to check if local MySQL is listening before attempting PDO
            $probe = @fsockopen('127.0.0.1', 3306, $errno, $errstr, 0.05);
            if ($probe) {
                fclose($probe);
                $configs = [
                    [
                        'host' => '127.0.0.1',
                        'port' => '3306',
                        'db'   => 'pocket_crm_db',
                        'user' => 'root',
                        'pass' => ''
                    ],
                    [
                        'host' => '127.0.0.1',
                        'port' => '3306',
                        'db'   => 'u213657911_crm',
                        'user' => 'root',
                        'pass' => ''
                    ]
                ];
            }
        }

        // Try MySQL configurations
        foreach ($configs as $cfg) {
            if (empty($cfg['host']) || empty($cfg['db']) || empty($cfg['user'])) {
                continue;
            }
            try {
                $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['db']};charset=utf8mb4";
                self::$instance = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_TIMEOUT            => 2
                ]);
                self::$engine = 'mysql';
                self::ensureSchema(self::$instance, self::$engine);
                return self::$instance;
            } catch (Exception $e) {
                if ($cfg['user'] === 'root') {
                    try {
                        $root_pdo = new PDO("mysql:host={$cfg['host']};port={$cfg['port']}", $cfg['user'], $cfg['pass'], [
                            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_TIMEOUT => 2
                        ]);
                        $root_pdo->exec("CREATE DATABASE IF NOT EXISTS `{$cfg['db']}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                        $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['db']};charset=utf8mb4";
                        self::$instance = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                            PDO::ATTR_EMULATE_PREPARES   => false,
                        ]);
                        self::$engine = 'mysql';
                        self::ensureSchema(self::$instance, self::$engine);
                        return self::$instance;
                    } catch (Exception $e2) {}
                }
            }
        }

        // SQLite Fallback — used on Render and localhost when MySQL unavailable
        try {
            $sqlite_file = dirname(__DIR__) . '/data/pocket_crm.sqlite';
            if (!is_dir(dirname($sqlite_file))) {
                mkdir(dirname($sqlite_file), 0775, true);
            }
            self::$instance = new SQLitePDO("sqlite:" . $sqlite_file, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$instance->exec('PRAGMA journal_mode=WAL');
            self::$instance->exec('PRAGMA foreign_keys=ON');
            self::$instance->exec('PRAGMA busy_timeout=5000');
            self::$engine = 'sqlite';

            self::ensureSchema(self::$instance, self::$engine);
            return self::$instance;
        } catch (Exception $e) {
            self::$engine = 'json_fallback';
            return null;
        }
    }

    /**
     * Auto-create all required tables and seed baseline data.
     */
    public static function ensureSchema(PDO $pdo, string $engine): void {
        if (self::$schemaEnsured) return;
        self::$schemaEnsured = true;

        $tables = [
            "CREATE TABLE IF NOT EXISTS `crm_users` (
                `id`            INTEGER PRIMARY KEY AUTOINCREMENT,
                `name`          TEXT NOT NULL,
                `email`         TEXT NOT NULL UNIQUE,
                `password_hash` TEXT NOT NULL DEFAULT '',
                `employee_id`   TEXT,
                `role`          TEXT NOT NULL DEFAULT 'Superadmin',
                `status`        TEXT NOT NULL DEFAULT 'active',
                `auth_provider` TEXT DEFAULT 'email',
                `google_id`     TEXT,
                `avatar_url`    TEXT,
                `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
                `last_login_at` DATETIME
            )",
            "CREATE TABLE IF NOT EXISTS `crm_otp_challenges` (
                `id`           INTEGER PRIMARY KEY AUTOINCREMENT,
                `email`        TEXT NOT NULL,
                `otp_hash`     TEXT NOT NULL,
                `purpose`      TEXT NOT NULL,
                `temp_payload` TEXT,
                `attempts`     INTEGER NOT NULL DEFAULT 0,
                `max_attempts` INTEGER NOT NULL DEFAULT 5,
                `resend_count` INTEGER NOT NULL DEFAULT 0,
                `last_sent_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `expires_at`   DATETIME NOT NULL,
                `is_used`      INTEGER NOT NULL DEFAULT 0,
                `created_at`   DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_team_staff` (
                `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
                `name`        TEXT NOT NULL,
                `email`       TEXT UNIQUE,
                `phone`       TEXT,
                `role`        TEXT DEFAULT 'member',
                `team_id`     INTEGER,
                `department`  TEXT DEFAULT 'Engineering',
                `company`     TEXT DEFAULT 'ABC Tech Solutions',
                `location`    TEXT DEFAULT 'Bangalore, India',
                `website`     TEXT DEFAULT 'www.abctech.com',
                `bio`         TEXT DEFAULT '',
                `skills`      TEXT DEFAULT '',
                `initials`    TEXT,
                `color_class` TEXT DEFAULT 'bg-blue-600',
                `status`      TEXT DEFAULT 'active',
                `avatar_url`  TEXT,
                `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_contacts` (
                `id`                     TEXT PRIMARY KEY,
                `name`                   TEXT NOT NULL,
                `phone`                  TEXT DEFAULT '',
                `phone2`                 TEXT,
                `email`                  TEXT,
                `company`                TEXT,
                `company_id`             TEXT,
                `occupation`             TEXT,
                `category`               TEXT,
                `address`                TEXT,
                `lead_from`              TEXT,
                `notes`                  TEXT,
                `whatsapp_notes`         TEXT,
                `drive_link`             TEXT,
                `website_link`           TEXT,
                `creds_server`           TEXT,
                `creds_social`           TEXT,
                `budget`                 TEXT,
                `amount_status`          TEXT,
                `amount_percentage_paid` TEXT,
                `specifications`         TEXT,
                `is_starred`             INTEGER DEFAULT 0,
                `is_blocked`             INTEGER DEFAULT 0,
                `last_contact`           TEXT,
                `tags`                   TEXT,
                `created_at`             DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at`             DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_history` (
                `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
                `contact_id` TEXT NOT NULL,
                `staff`      TEXT,
                `time_str`   TEXT,
                `duration`   TEXT,
                `type`       TEXT,
                `note_text`  TEXT NOT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_media` (
                `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
                `contact_id` TEXT NOT NULL,
                `folder`     TEXT,
                `file_name`  TEXT,
                `file_url`   TEXT NOT NULL,
                `file_size`  INTEGER DEFAULT 0,
                `file_type`  TEXT,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_vault` (
                `id`              INTEGER PRIMARY KEY AUTOINCREMENT,
                `contact_id`      TEXT NOT NULL,
                `title`           TEXT,
                `credential_type` TEXT,
                `username`        TEXT,
                `password_val`    TEXT,
                `notes`           TEXT,
                `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_companies` (
                `id`          TEXT PRIMARY KEY,
                `name`        TEXT NOT NULL UNIQUE,
                `industry`    TEXT DEFAULT 'Technology & Services',
                `website`     TEXT DEFAULT '',
                `email`       TEXT DEFAULT '',
                `phone`       TEXT DEFAULT '',
                `address`     TEXT DEFAULT NULL,
                `description` TEXT DEFAULT NULL,
                `logo_url`    TEXT DEFAULT NULL,
                `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at`  DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_projects` (
                `id`           TEXT PRIMARY KEY,
                `title`        TEXT NOT NULL,
                `client_name`  TEXT NOT NULL,
                `company_id`   TEXT DEFAULT NULL,
                `category`     TEXT DEFAULT 'Web & App',
                `status`       TEXT DEFAULT 'In Progress',
                `progress`     INTEGER DEFAULT 50,
                `budget`       TEXT DEFAULT '$10,000',
                `project_manager` TEXT DEFAULT 'Project Manager',
                `start_date`   DATE DEFAULT NULL,
                `deadline`     TEXT DEFAULT NULL,
                `team_members` TEXT DEFAULT NULL,
                `icon`         TEXT DEFAULT 'layout',
                `color`        TEXT DEFAULT 'blue',
                `description`  TEXT DEFAULT NULL,
                `archived`     INTEGER DEFAULT 0,
                `created_at`   DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at`   DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_project_files` (
                `id`              TEXT PRIMARY KEY,
                `project_id`      TEXT NOT NULL,
                `file_name`       TEXT,
                `original_name`   TEXT,
                `file_path`       TEXT,
                `file_url`        TEXT DEFAULT '',
                `file_size`       TEXT,
                `file_size_bytes` INTEGER DEFAULT 0,
                `file_type`       TEXT,
                `mime_type`       TEXT,
                `uploaded_by`     TEXT,
                `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_project_activity` (
                `id`            TEXT PRIMARY KEY,
                `project_id`    TEXT NOT NULL,
                `title`         TEXT,
                `description`   TEXT,
                `user_name`     TEXT,
                `activity_type` TEXT DEFAULT 'update',
                `action`        TEXT DEFAULT '',
                `details`       TEXT DEFAULT '',
                `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_project_chats` (
                `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
                `project_id`  TEXT NOT NULL,
                `sender_id`   TEXT NOT NULL,
                `sender_name` TEXT NOT NULL,
                `message`     TEXT NOT NULL,
                `file_url`    TEXT,
                `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_tasks` (
                `id`             TEXT PRIMARY KEY,
                `title`          TEXT NOT NULL,
                `description`    TEXT,
                `client_name`    TEXT DEFAULT 'Customer',
                `project_id`     TEXT DEFAULT 'proj_1',
                `project_name`   TEXT DEFAULT 'E-commerce Website',
                `phone`          TEXT DEFAULT '',
                `location`       TEXT DEFAULT '',
                `assigned_staff` TEXT DEFAULT 'Akash Dev',
                `due_time`       TEXT DEFAULT 'Today',
                `due_date`       DATE,
                `priority`       TEXT DEFAULT 'Medium',
                `status`         TEXT DEFAULT 'pending',
                `category`       TEXT DEFAULT 'general',
                `domain`         TEXT DEFAULT 'software.local',
                `icon`           TEXT DEFAULT 'layout',
                `completed`      INTEGER DEFAULT 0,
                `created_by`     TEXT,
                `created_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at`     DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_task_members` (
                `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
                `task_id`     TEXT NOT NULL,
                `employee_id` TEXT NOT NULL,
                `assigned_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (`task_id`, `employee_id`)
            )",
            "CREATE TABLE IF NOT EXISTS `crm_task_files` (
                `id`              TEXT PRIMARY KEY,
                `task_id`         TEXT NOT NULL,
                `file_name`       TEXT,
                `original_name`   TEXT,
                `file_path`       TEXT,
                `file_url`        TEXT NOT NULL,
                `file_type`       TEXT,
                `mime_type`       TEXT,
                `file_size`       TEXT,
                `file_size_bytes` INTEGER DEFAULT 0,
                `uploaded_by`     TEXT,
                `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_cash_ledger` (
                `id`               TEXT PRIMARY KEY,
                `invoice_no`       TEXT,
                `client_id`        TEXT,
                `project_id`       TEXT,
                `type`             TEXT DEFAULT 'income',
                `amount`           REAL NOT NULL DEFAULT 0,
                `subtotal`         REAL DEFAULT 0,
                `discount_percent` REAL DEFAULT 0,
                `discount_amount`  REAL DEFAULT 0,
                `tax_percent`      REAL DEFAULT 0,
                `tax_amount`       REAL DEFAULT 0,
                `paid_amount`      REAL DEFAULT 0,
                `balance_amount`   REAL DEFAULT 0,
                `title`            TEXT DEFAULT '',
                `category`         TEXT DEFAULT 'General',
                `payment_mode`     TEXT DEFAULT 'Online Transfer',
                `handled_by`       TEXT DEFAULT 'Admin',
                `customer_name`    TEXT,
                `customer_phone`   TEXT,
                `status`           TEXT DEFAULT 'Paid',
                `issue_date`       TEXT,
                `due_date`         TEXT,
                `domain`           TEXT DEFAULT 'software.local',
                `notes`            TEXT,
                `terms`            TEXT,
                `items_json`       TEXT,
                `transaction_date` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `created_at`       DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_invoice_items` (
                `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
                `invoice_id`  TEXT NOT NULL,
                `description` TEXT NOT NULL,
                `quantity`    REAL DEFAULT 1,
                `rate`        REAL DEFAULT 0,
                `amount`      REAL DEFAULT 0,
                `sort_order`  INTEGER DEFAULT 0,
                `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_payments` (
                `id`             TEXT PRIMARY KEY,
                `receipt_no`     TEXT,
                `invoice_id`     TEXT NOT NULL,
                `invoice_no`     TEXT,
                `customer_name`  TEXT,
                `amount`         REAL NOT NULL DEFAULT 0,
                `payment_date`   TEXT,
                `payment_method` TEXT DEFAULT 'Online Transfer',
                `payment_mode`   TEXT DEFAULT 'Bank Transfer',
                `transaction_id` TEXT,
                `reference`      TEXT,
                `notes`          TEXT,
                `recorded_by`    TEXT,
                `received_by`    TEXT,
                `status`         TEXT DEFAULT 'Paid',
                `created_at`     DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_expenses` (
                `id`             TEXT PRIMARY KEY,
                `expense_date`   DATE NOT NULL,
                `category`       TEXT NOT NULL,
                `description`    TEXT NOT NULL,
                `amount`         REAL NOT NULL DEFAULT 0,
                `payment_method` TEXT NOT NULL DEFAULT 'Bank Transfer',
                `reference`      TEXT,
                `notes`          TEXT,
                `recorded_by`    TEXT,
                `status`         TEXT NOT NULL DEFAULT 'Paid',
                `created_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at`     DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_calendar_events` (
                `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
                `title`       TEXT NOT NULL,
                `event_date`  DATE NOT NULL,
                `event_time`  TEXT DEFAULT '10:00 AM',
                `category`    TEXT DEFAULT 'Meeting',
                `color`       TEXT DEFAULT '#2563eb',
                `description` TEXT,
                `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_notifications` (
                `id`           INTEGER PRIMARY KEY AUTOINCREMENT,
                `user_id`      INTEGER DEFAULT 0,
                `category`     TEXT DEFAULT 'system',
                `event_type`   TEXT DEFAULT 'info',
                `title`        TEXT NOT NULL,
                `message`      TEXT,
                `link_type`    TEXT DEFAULT 'modal',
                `link_id`      TEXT DEFAULT '',
                `action_label` TEXT DEFAULT 'View',
                `is_read`      INTEGER DEFAULT 0,
                `read_at`      DATETIME,
                `metadata`     TEXT,
                `created_at`   DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_groups` (
                `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
                `name`        TEXT NOT NULL UNIQUE,
                `company_id`  TEXT,
                `description` TEXT,
                `leader_id`   TEXT,
                `color`       TEXT DEFAULT '#2563eb',
                `icon`        TEXT DEFAULT 'users',
                `created_by`  TEXT,
                `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at`  DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_group_members` (
                `id`            INTEGER PRIMARY KEY AUTOINCREMENT,
                `group_id`      INTEGER NOT NULL,
                `employee_id`   TEXT NOT NULL,
                `role_in_group` TEXT DEFAULT 'member',
                `added_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (`group_id`, `employee_id`)
            )",
            "CREATE TABLE IF NOT EXISTS `crm_group_chats` (
                `id`              INTEGER PRIMARY KEY AUTOINCREMENT,
                `group_id`        INTEGER NOT NULL,
                `sender_id`       TEXT NOT NULL,
                `sender_name`     TEXT NOT NULL,
                `sender_role`     TEXT DEFAULT 'Member',
                `sender_color`    TEXT DEFAULT '#2563eb',
                `sender_avatar`   TEXT DEFAULT '',
                `message_type`    TEXT DEFAULT 'text',
                `message`         TEXT NOT NULL,
                `attachment_type` TEXT,
                `attachment_url`  TEXT,
                `attachment_name` TEXT,
                `reply_to_id`     INTEGER,
                `reply_to_name`   TEXT,
                `reply_to_text`   TEXT,
                `deletion_state`  TEXT DEFAULT 'none',
                `edited_at`       DATETIME,
                `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_group_message_deletions` (
                `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
                `message_id` INTEGER NOT NULL,
                `user_id`    TEXT NOT NULL,
                `deleted_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (`message_id`, `user_id`)
            )",
            "CREATE TABLE IF NOT EXISTS `crm_group_message_receipts` (
                `id`           INTEGER PRIMARY KEY AUTOINCREMENT,
                `message_id`   INTEGER NOT NULL,
                `group_id`     INTEGER NOT NULL,
                `user_id`      TEXT NOT NULL,
                `user_name`    TEXT DEFAULT '',
                `delivered_at` DATETIME,
                `read_at`      DATETIME,
                UNIQUE (`message_id`, `user_id`)
            )",
            "CREATE TABLE IF NOT EXISTS `crm_group_calls` (
                `id`             INTEGER PRIMARY KEY AUTOINCREMENT,
                `group_id`       INTEGER NOT NULL,
                `initiator_id`   TEXT NOT NULL,
                `initiator_name` TEXT NOT NULL,
                `call_type`      TEXT DEFAULT 'audio',
                `call_status`    TEXT DEFAULT 'calling',
                `is_presenting`  INTEGER DEFAULT 0,
                `presenter_id`   TEXT,
                `presenter_name` TEXT,
                `start_time`     DATETIME DEFAULT CURRENT_TIMESTAMP,
                `end_time`       DATETIME,
                `created_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at`     DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_group_call_participants` (
                `id`                INTEGER PRIMARY KEY AUTOINCREMENT,
                `call_id`           INTEGER NOT NULL,
                `user_id`           TEXT NOT NULL,
                `user_name`         TEXT NOT NULL,
                `user_role`         TEXT DEFAULT 'Member',
                `user_avatar`       TEXT DEFAULT '',
                `status`            TEXT DEFAULT 'invited',
                `is_muted`          INTEGER DEFAULT 0,
                `is_camera_off`     INTEGER DEFAULT 0,
                `is_screen_sharing` INTEGER DEFAULT 0,
                `joined_at`         DATETIME,
                `left_at`           DATETIME,
                `last_seen`         DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (`call_id`, `user_id`)
            )",
            "CREATE TABLE IF NOT EXISTS `crm_group_call_signals` (
                `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
                `call_id`     INTEGER NOT NULL,
                `sender_id`   TEXT NOT NULL,
                `receiver_id` TEXT,
                `signal_type` TEXT NOT NULL,
                `signal_data` TEXT NOT NULL,
                `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_biometric_credentials` (
                `id`               INTEGER PRIMARY KEY AUTOINCREMENT,
                `user_id`          INTEGER NOT NULL,
                `credential_id`    TEXT NOT NULL UNIQUE,
                `public_key`       TEXT NOT NULL,
                `counter`          INTEGER NOT NULL DEFAULT 0,
                `device_name`      TEXT NOT NULL,
                `aaguid`           TEXT DEFAULT NULL,
                `attestation_type` TEXT DEFAULT 'none',
                `status`           TEXT NOT NULL DEFAULT 'active',
                `created_at`       DATETIME DEFAULT CURRENT_TIMESTAMP,
                `last_used_at`     DATETIME DEFAULT NULL
            )",
            "CREATE TABLE IF NOT EXISTS `crm_biometric_challenges` (
                `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
                `user_id`    INTEGER DEFAULT NULL,
                `challenge`  TEXT NOT NULL UNIQUE,
                `purpose`    TEXT NOT NULL,
                `origin`     TEXT NOT NULL,
                `rp_id`      TEXT NOT NULL,
                `expires_at` DATETIME NOT NULL,
                `is_used`    INTEGER NOT NULL DEFAULT 0,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_security_logs` (
                `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
                `user_id`    INTEGER,
                `event_type` TEXT,
                `ip_address` TEXT,
                `user_agent` TEXT,
                `details`    TEXT,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_user_settings` (
                `user_id`                 INTEGER PRIMARY KEY,
                `theme`                   TEXT DEFAULT 'light',
                `email_notifications`     INTEGER DEFAULT 1,
                `task_reminder_alerts`    INTEGER DEFAULT 1,
                `invoice_payment_alerts`  INTEGER DEFAULT 1,
                `weekly_financial_digest` INTEGER DEFAULT 1,
                `compact_mode`            INTEGER DEFAULT 0,
                `updated_at`              DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_system_settings` (
                `id`            INTEGER PRIMARY KEY AUTOINCREMENT,
                `setting_key`   TEXT NOT NULL UNIQUE,
                `setting_value` TEXT,
                `updated_at`    DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_teams` (
                `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
                `name`        TEXT NOT NULL,
                `description` TEXT,
                `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_calendar` (
                `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
                `title`       TEXT NOT NULL,
                `description` TEXT,
                `start_time`  DATETIME,
                `end_time`    DATETIME,
                `user_id`     INTEGER,
                `contact_id`  TEXT,
                `type`        TEXT DEFAULT 'event',
                `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_invoices` (
                `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
                `invoice_no` TEXT UNIQUE,
                `contact_id` TEXT,
                `company_id` INTEGER,
                `amount`     REAL DEFAULT 0,
                `status`     TEXT DEFAULT 'draft',
                `issue_date` DATE,
                `due_date`   DATE,
                `notes`      TEXT,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            )"
        ];

        foreach ($tables as $sql) {
            try { $pdo->exec($sql); } catch (Exception $e) {}
        }

        // Schema integrity checks: ensure essential columns exist on upgraded tables
        self::ensureColumns($pdo);

        // Pre-seed default baseline data if tables are empty
        self::seedBaselineData($pdo);
    }

    private static function ensureColumns(PDO $pdo): void {
        $alterChecks = [
            'crm_users' => [
                'employee_id' => 'TEXT',
                'auth_provider' => "TEXT DEFAULT 'email'",
                'google_id' => 'TEXT',
                'avatar_url' => 'TEXT',
                'last_login_at' => 'DATETIME'
            ],
            'crm_team_staff' => [
                'department' => "TEXT DEFAULT 'Engineering'",
                'initials' => 'TEXT',
                'color_class' => "TEXT DEFAULT 'bg-blue-600'"
            ],
            'crm_contacts' => [
                'company_id' => 'TEXT',
                'tags' => 'TEXT'
            ],
            'crm_projects' => [
                'title' => "TEXT DEFAULT ''",
                'client_name' => "TEXT DEFAULT ''",
                'company_id' => 'TEXT',
                'category' => "TEXT DEFAULT 'Web & App'",
                'progress' => 'INTEGER DEFAULT 50',
                'deadline' => 'TEXT',
                'team_members' => 'TEXT',
                'icon' => "TEXT DEFAULT 'layout'",
                'color' => "TEXT DEFAULT 'blue'",
                'description' => 'TEXT',
                'project_manager' => "TEXT DEFAULT 'Project Manager'",
                'budget' => "TEXT DEFAULT '$10,000'",
                'archived' => 'INTEGER DEFAULT 0'
            ],
            'crm_project_files' => [
                'original_name' => 'TEXT',
                'file_path' => 'TEXT',
                'file_url' => "TEXT DEFAULT ''",
                'file_type' => 'TEXT',
                'mime_type' => 'TEXT',
                'file_size' => 'TEXT',
                'file_size_bytes' => 'INTEGER DEFAULT 0',
                'uploaded_by' => 'TEXT'
            ],
            'crm_project_activity' => [
                'title' => 'TEXT',
                'description' => 'TEXT',
                'user_name' => 'TEXT',
                'activity_type' => "TEXT DEFAULT 'update'",
                'action' => "TEXT DEFAULT ''",
                'details' => "TEXT DEFAULT ''"
            ],
            'crm_tasks' => [
                'client_name' => "TEXT DEFAULT 'Customer'",
                'project_id' => "TEXT DEFAULT 'proj_1'",
                'project_name' => "TEXT DEFAULT 'E-commerce Website'",
                'phone' => "TEXT DEFAULT ''",
                'location' => "TEXT DEFAULT ''",
                'assigned_staff' => "TEXT DEFAULT 'Akash Dev'",
                'due_time' => "TEXT DEFAULT 'Today'",
                'due_date' => 'DATE',
                'status' => "TEXT DEFAULT 'pending'",
                'completed' => 'INTEGER DEFAULT 0',
                'priority' => "TEXT DEFAULT 'Medium'",
                'category' => "TEXT DEFAULT 'general'",
                'domain' => "TEXT DEFAULT 'software.local'",
                'icon' => "TEXT DEFAULT 'layout'"
            ],
            'crm_companies' => [
                'logo_url' => 'TEXT',
                'updated_at' => 'DATETIME'
            ],
            'crm_task_files' => [
                'original_name' => 'TEXT',
                'file_path' => 'TEXT',
                'mime_type' => 'TEXT',
                'file_size_bytes' => 'INTEGER DEFAULT 0'
            ],
            'crm_groups' => [
                'company_id' => 'TEXT',
                'leader_id' => 'TEXT',
                'color' => "TEXT DEFAULT '#2563eb'",
                'icon' => "TEXT DEFAULT 'users'",
                'updated_at' => 'DATETIME'
            ],
            'crm_group_members' => [
                'employee_id' => "TEXT DEFAULT ''",
                'role_in_group' => "TEXT DEFAULT 'member'",
                'added_at' => 'DATETIME'
            ],
            'crm_cash_ledger' => [
                'client_id' => 'TEXT',
                'project_id' => 'TEXT',
                'issue_date' => 'TEXT',
                'subtotal' => 'REAL DEFAULT 0',
                'discount_percent' => 'REAL DEFAULT 0',
                'discount_amount' => 'REAL DEFAULT 0',
                'tax_percent' => 'REAL DEFAULT 0',
                'tax_amount' => 'REAL DEFAULT 0',
                'paid_amount' => 'REAL DEFAULT 0',
                'balance_amount' => 'REAL DEFAULT 0',
                'notes' => 'TEXT',
                'terms' => 'TEXT',
                'items_json' => 'TEXT'
            ],
            'crm_notifications' => [
                'category'     => "TEXT DEFAULT 'system'",
                'event_type'   => "TEXT DEFAULT 'info'",
                'link_type'    => "TEXT DEFAULT 'modal'",
                'link_id'      => "TEXT DEFAULT ''",
                'action_label' => "TEXT DEFAULT 'View'",
                'read_at'      => "DATETIME",
                'metadata'     => "TEXT"
            ],
            'crm_payments' => [
                'invoice_no'     => 'TEXT',
                'customer_name'  => 'TEXT',
                'payment_method' => "TEXT DEFAULT 'Online Transfer'",
                'transaction_id' => 'TEXT',
                'recorded_by'    => 'TEXT',
                'status'         => "TEXT DEFAULT 'Paid'"
            ],
            'crm_biometric_credentials' => [
                'counter' => 'INTEGER NOT NULL DEFAULT 0',
                'attestation_type' => "TEXT DEFAULT 'none'",
                'status' => "TEXT NOT NULL DEFAULT 'active'",
                'last_used_at' => 'DATETIME'
            ],
            'crm_biometric_challenges' => [
                'purpose' => "TEXT DEFAULT 'registration'",
                'origin' => "TEXT DEFAULT ''",
                'rp_id' => "TEXT DEFAULT ''",
                'is_used' => 'INTEGER NOT NULL DEFAULT 0'
            ],
            'crm_user_settings' => [
                'theme'                   => "TEXT DEFAULT 'light'",
                'email_notifications'     => "INTEGER DEFAULT 1",
                'task_reminder_alerts'    => "INTEGER DEFAULT 1",
                'invoice_payment_alerts'  => "INTEGER DEFAULT 1",
                'weekly_financial_digest' => "INTEGER DEFAULT 1",
                'compact_mode'            => "INTEGER DEFAULT 0",
                'updated_at'              => "DATETIME"
            ],
            'crm_team_staff' => [
                'company'     => "TEXT DEFAULT 'ABC Tech Solutions'",
                'location'    => "TEXT DEFAULT 'Bangalore, India'",
                'website'     => "TEXT DEFAULT 'www.abctech.com'",
                'bio'         => "TEXT DEFAULT ''",
                'skills'      => "TEXT DEFAULT ''"
            ]
        ];

        foreach ($alterChecks as $table => $cols) {
            foreach ($cols as $colName => $colDef) {
                try {
                    $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$colName` $colDef");
                } catch (Exception $e) {}
            }
        }

        // Upgrade empty tables from legacy INTEGER id to TEXT id if needed
        $textPkTables = [
            'crm_tasks' => "CREATE TABLE `crm_tasks` (
                `id`             TEXT PRIMARY KEY,
                `title`          TEXT NOT NULL,
                `description`    TEXT,
                `client_name`    TEXT DEFAULT 'Customer',
                `project_id`     TEXT DEFAULT 'proj_1',
                `project_name`   TEXT DEFAULT 'E-commerce Website',
                `phone`          TEXT DEFAULT '',
                `location`       TEXT DEFAULT '',
                `assigned_staff` TEXT DEFAULT 'Akash Dev',
                `due_time`       TEXT DEFAULT 'Today',
                `due_date`       DATE,
                `priority`       TEXT DEFAULT 'Medium',
                `status`         TEXT DEFAULT 'pending',
                `category`       TEXT DEFAULT 'general',
                `domain`         TEXT DEFAULT 'software.local',
                `icon`           TEXT DEFAULT 'layout',
                `completed`      INTEGER DEFAULT 0,
                `created_by`     TEXT,
                `created_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at`     DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            'crm_projects' => "CREATE TABLE `crm_projects` (
                `id`           TEXT PRIMARY KEY,
                `title`        TEXT NOT NULL,
                `client_name`  TEXT NOT NULL,
                `company_id`   TEXT DEFAULT NULL,
                `category`     TEXT DEFAULT 'Web & App',
                `status`       TEXT DEFAULT 'In Progress',
                `progress`     INTEGER DEFAULT 50,
                `budget`       TEXT DEFAULT '$10,000',
                `project_manager` TEXT DEFAULT 'Project Manager',
                `start_date`   DATE DEFAULT NULL,
                `deadline`     TEXT DEFAULT NULL,
                `team_members` TEXT DEFAULT NULL,
                `icon`         TEXT DEFAULT 'layout',
                `color`        TEXT DEFAULT 'blue',
                `description`  TEXT DEFAULT NULL,
                `archived`     INTEGER DEFAULT 0,
                `created_at`   DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at`   DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            'crm_project_files' => "CREATE TABLE `crm_project_files` (
                `id`              TEXT PRIMARY KEY,
                `project_id`      TEXT NOT NULL,
                `file_name`       TEXT,
                `original_name`   TEXT,
                `file_path`       TEXT,
                `file_url`        TEXT DEFAULT '',
                `file_size`       TEXT,
                `file_size_bytes` INTEGER DEFAULT 0,
                `file_type`       TEXT,
                `mime_type`       TEXT,
                `uploaded_by`     TEXT,
                `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            'crm_project_activity' => "CREATE TABLE `crm_project_activity` (
                `id`            TEXT PRIMARY KEY,
                `project_id`    TEXT NOT NULL,
                `title`         TEXT,
                `description`   TEXT,
                `user_name`     TEXT,
                `activity_type` TEXT DEFAULT 'update',
                `action`        TEXT DEFAULT '',
                `details`       TEXT DEFAULT '',
                `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            'crm_companies' => "CREATE TABLE `crm_companies` (
                `id`          TEXT PRIMARY KEY,
                `name`        TEXT NOT NULL UNIQUE,
                `industry`    TEXT DEFAULT 'Technology & Services',
                `website`     TEXT DEFAULT '',
                `email`       TEXT DEFAULT '',
                `phone`       TEXT DEFAULT '',
                `address`     TEXT DEFAULT NULL,
                `description` TEXT DEFAULT NULL,
                `logo_url`    TEXT DEFAULT NULL,
                `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at`  DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            'crm_task_files' => "CREATE TABLE `crm_task_files` (
                `id`              TEXT PRIMARY KEY,
                `task_id`         TEXT NOT NULL,
                `file_name`       TEXT,
                `original_name`   TEXT,
                `file_path`       TEXT,
                `file_url`        TEXT NOT NULL,
                `file_type`       TEXT,
                `mime_type`       TEXT,
                `file_size`       TEXT,
                `file_size_bytes` INTEGER DEFAULT 0,
                `uploaded_by`     TEXT,
                `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            'crm_payments' => "CREATE TABLE `crm_payments` (
                `id`             TEXT PRIMARY KEY,
                `receipt_no`     TEXT,
                `invoice_id`     TEXT NOT NULL,
                `invoice_no`     TEXT,
                `customer_name`  TEXT,
                `amount`         REAL NOT NULL DEFAULT 0,
                `payment_date`   TEXT,
                `payment_method` TEXT DEFAULT 'Online Transfer',
                `payment_mode`   TEXT DEFAULT 'Bank Transfer',
                `transaction_id` TEXT,
                `reference`      TEXT,
                `notes`          TEXT,
                `recorded_by`    TEXT,
                `received_by`    TEXT,
                `status`         TEXT DEFAULT 'Paid',
                `created_at`     DATETIME DEFAULT CURRENT_TIMESTAMP
            )"
        ];

        foreach ($textPkTables as $tName => $createSql) {
            try {
                $info = $pdo->query("SELECT type FROM pragma_table_info('$tName') WHERE name = 'id'")->fetch();
                if ($info && strtoupper($info['type']) === 'INTEGER') {
                    $cnt = (int)$pdo->query("SELECT count(*) FROM `$tName`")->fetchColumn();
                    if ($cnt === 0) {
                        $pdo->exec("DROP TABLE `$tName`");
                        $pdo->exec($createSql);
                    }
                }
            } catch (Exception $e) {}
        }
    }

    private static function seedBaselineData(PDO $pdo): void {
        // 1. Seed Core Administrative Users
        try {
            $defaultPasswordHash = password_hash('Password@123', PASSWORD_DEFAULT);

            // Suresh Kumar (Superadmin)
            $stmt = $pdo->prepare("SELECT id FROM crm_users WHERE LOWER(email) = 'suresh@softwarecrm.com' LIMIT 1");
            $stmt->execute();
            if (!$stmt->fetch()) {
                $ins = $pdo->prepare("INSERT INTO crm_users (name, email, password_hash, employee_id, role, status, auth_provider, created_at, updated_at) VALUES ('Suresh Kumar', 'suresh@softwarecrm.com', ?, 'EMP-001', 'Superadmin', 'active', 'email', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
                $ins->execute([$defaultPasswordHash]);
            }

            // Dhanush (Superadmin)
            $stmtD = $pdo->prepare("SELECT id FROM crm_users WHERE LOWER(email) = 'vangaridhanush09@gmail.com' LIMIT 1");
            $stmtD->execute();
            if (!$stmtD->fetch()) {
                $insD = $pdo->prepare("INSERT INTO crm_users (name, email, password_hash, employee_id, role, status, auth_provider, created_at, updated_at) VALUES ('Dhanush', 'vangaridhanush09@gmail.com', ?, 'EMP-000', 'Superadmin', 'active', 'email', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
                $insD->execute([$defaultPasswordHash]);
            }
        } catch (Exception $e) {}

        // 2. Seed Team Staff
        try {
            $staffCount = (int)$pdo->query("SELECT COUNT(*) FROM crm_team_staff")->fetchColumn();
            if ($staffCount === 0) {
                $staff = [
                    ['Suresh Kumar', 'suresh@softwarecrm.com', '+91 98765 00001', 'Superadmin', 'Management', 'SK', 'bg-blue-600'],
                    ['Akash Dev', 'akash@softwarecrm.com', '+91 98765 00002', 'member', 'Engineering', 'AD', 'bg-indigo-600'],
                    ['Priya Verma', 'priya@softwarecrm.com', '+91 98765 00003', 'member', 'Design', 'PV', 'bg-purple-600'],
                    ['Emma Wilson', 'emma@softwarecrm.com', '+91 98765 00004', 'member', 'Design', 'EW', 'bg-pink-600'],
                    ['Liam C.', 'liam@softwarecrm.com', '+91 98765 00005', 'member', 'Engineering', 'LC', 'bg-green-600'],
                    ['Sophia B.', 'sophia@softwarecrm.com', '+91 98765 00006', 'member', 'Engineering', 'SB', 'bg-teal-600'],
                    ['Vikram S.', 'vikram@softwarecrm.com', '+91 98765 00007', 'member', 'Cloud & DevOps', 'VS', 'bg-yellow-600'],
                    ['Karan M.', 'karan@softwarecrm.com', '+91 98765 00008', 'member', 'IoT & Hardware', 'KM', 'bg-red-600']
                ];
                $insStaff = $pdo->prepare("INSERT INTO crm_team_staff (name, email, phone, role, department, initials, color_class, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', CURRENT_TIMESTAMP)");
                foreach ($staff as $s) {
                    $insStaff->execute($s);
                }
            }
        } catch (Exception $e) {}

        // 3. Seed Companies
        try {
            $companies = [
                ['cmp_abc', 'ABC', 'Technology & Services', 'https://www.abc.com', 'contact@abc.com', '+91 98765 43210', 'Mumbai, India', 'Key enterprise software client'],
                ['cmp_apex_global_technologies', 'Apex Global Technologies', 'Cloud & IoT', 'https://apexglobal.in', 'ops@apexglobal.in', '+91 98765 43215', 'Pune, India', 'Global enterprise engineering client'],
                ['cmp_test_corp', 'Test Corp', 'Testing & Automation', 'https://testcorp.com', 'info@testcorp.com', '+91 98765 43219', 'Hyderabad, India', 'Automation testing client'],
                ['cmp_abc_tech_solutions', 'ABC Tech Solutions', 'Technology & Services', 'https://www.abctech.com', 'contact@abctech.com', '+91 98765 43210', 'Mumbai, India', 'Key enterprise software client'],
                ['cmp_verma_solutions', 'Verma Solutions', 'Fintech & Mobile', 'https://verma-solutions.com', 'info@verma.com', '+91 98765 43211', 'Delhi, India', 'Fintech application design & dev'],
                ['cmp_test_automation_corp', 'Test Automation Corp', 'QA & Automation', 'https://testautomation.corp', 'qa@testautomation.corp', '+91 98765 43218', 'Bengaluru, India', 'Enterprise QA automation'],
                ['cmp_3', 'Patel & Co.', 'ERP & Industrial', 'https://patelco.in', 'hello@patelco.in', '+91 98765 43212', 'Ahmedabad, India', 'ERP and accounting automation contract'],
                ['cmp_4', 'Kapoor Designs', 'Creative & Media', 'https://kapoordesigns.com', 'design@kapoor.com', '+91 98765 43213', 'Bengaluru, India', 'Brand identity and design system revamps'],
                ['cmp_5', 'Sheopur Univ', 'Education & Public', 'https://sheopur.edu.in', 'it@sheopur.edu.in', '+91 98765 43214', 'Madhya Pradesh, India', 'Cloud campus ERP and student portal'],
                ['cmp_6', 'Apex Industrial Works', 'Manufacturing & IoT', 'https://apexworks.in', 'ops@apexworks.in', '+91 98765 43215', 'Pune, India', 'Factory SCADA telemetry & sensor dashboards']
            ];
            $insComp = $pdo->prepare("INSERT OR IGNORE INTO crm_companies (id, name, industry, website, email, phone, address, description, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
            foreach ($companies as $c) {
                $insComp->execute($c);
            }
        } catch (Exception $e) {}

        // 4. Seed Contacts
        try {
            $cntCount = (int)$pdo->query("SELECT COUNT(*) FROM crm_contacts")->fetchColumn();
            if ($cntCount === 0) {
                $contacts = [
                    ["CNT_REF_01", "Rahul Sharma", "+91 98765 43210", "", "rahul@example.com", "ABC Tech Solutions", "cmp_abc_tech_solutions", "Founder", "Client", "Mumbai, India", "Website", "Key client for web development and digital marketing projects. Interested in long-term partnership.", "https://www.abctech.com", "$15,000", "Paid", "100", "Important, Long Term", 1],
                    ["CNT_REF_02", "Priya Verma", "+91 98765 43211", "", "priya@example.com", "Verma Solutions", "cmp_verma_solutions", "Director", "Lead", "Delhi, India", "Referral", "Mobile application design inquiry for fintech system.", "https://verma-solutions.com", "$22,000", "Pending", "40", "Mobile, Fintech", 1],
                    ["CNT_REF_03", "Amit Patel", "+91 98765 43212", "", "amit@example.com", "Patel & Co.", "cmp_3", "Managing Director", "Client", "Ahmedabad, India", "LinkedIn", "ERP and accounting automation contract.", "https://patelco.in", "$18,500", "Partially Paid", "50", "ERP, Retainer", 0],
                    ["CNT_REF_04", "Sneha Kapoor", "+91 98765 43213", "", "sneha@example.com", "Kapoor Designs", "cmp_4", "Creative Head", "Prospect", "Bengaluru, India", "Campaign", "Brand identity and design system revamps.", "https://kapoordesigns.com", "$6,500", "Pending", "0", "Design, Branding", 0],
                    ["CNT_REF_05", "Vikram Singh", "+91 98765 43214", "", "vikram@example.com", "Singh Enterprises", "", "Owner", "Client", "Jaipur, India", "Direct", "Supply chain tracking and GPS logistics portal.", "https://singhenterprises.in", "$12,000", "Paid", "100", "Logistics, GPS", 1],
                    ["CNT_REF_06", "Neha Gupta", "+91 98765 43215", "", "neha@example.com", "Gupta Media", "", "Editor-in-Chief", "Lead", "Pune, India", "Instagram", "Publishing CMS and creator subscription platform.", "https://guptamedia.com", "$9,000", "Pending", "20", "CMS, Media", 0],
                    ["CNT_REF_07", "Karan Mehta", "+91 98765 43216", "", "karan@example.com", "Mehta Industries", "cmp_6", "Operations VP", "Client", "Surat, India", "Trade Fair", "Factory SCADA dashboard and sensor telemetry system.", "https://mehtaind.com", "$28,000", "Paid", "80", "Industry, SCADA", 1],
                    ["CNT_REF_08", "Anjali Rao", "+91 98765 43217", "", "anjali@example.com", "Rao Creations", "", "Principal Architect", "Prospect", "Hyderabad, India", "Google Ads", "Architecture 3D rendering and VR walkthrough gallery.", "https://raocreations.in", "$14,000", "Pending", "0", "3D, VR", 0]
                ];
                $insCnt = $pdo->prepare("INSERT OR IGNORE INTO crm_contacts (id, name, phone, phone2, email, company, company_id, occupation, category, address, lead_from, notes, website_link, budget, amount_status, amount_percentage_paid, specifications, is_starred, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
                foreach ($contacts as $c) {
                    $insCnt->execute($c);
                }
            }
        } catch (Exception $e) {}

        // 5. Seed Projects
        try {
            $projCount = (int)$pdo->query("SELECT COUNT(*) FROM crm_projects")->fetchColumn();
            if ($projCount === 0) {
                $projects = [
                    ["proj_1", "E-commerce Website", "ABC Tech Solutions", "cmp_1", "Web & App", "In Progress", 70, "$15,000", "2024-06-01", "10 Aug 2024", "Rahul S., Akash D., Priya V.", "layout", "purple"],
                    ["proj_2", "Mobile App Development", "Verma Solutions", "cmp_2", "Mobile App", "In Progress", 40, "$22,000", "2024-07-15", "25 Sep 2024", "Priya V., Liam C., Sophia B.", "smartphone", "indigo"],
                    ["proj_3", "Brand Identity Design", "Kapoor Designs", "cmp_4", "Design", "Completed", 100, "$6,500", "2024-05-10", "10 Aug 2024", "Sneha K., Emma W.", "feather", "emerald"],
                    ["proj_4", "CRM System", "Patel & Co.", "cmp_3", "SaaS Enterprise", "On Hold", 25, "$18,500", "2024-07-01", "15 Nov 2024", "Amit P., Akash D.", "database", "amber"],
                    ["proj_5", "Cloud ERP Integration", "Sheopur Univ", "cmp_5", "Cloud & DevOps", "In Progress", 85, "$30,000", "2024-04-12", "05 Sep 2024", "Rohit S., Vikram S.", "cloud", "blue"],
                    ["proj_6", "Factory IoT SCADA", "Apex Industrial Works", "cmp_6", "IoT Telemetry", "In Progress", 60, "$25,000", "2024-05-20", "30 Oct 2024", "Karan M., Akash D.", "cpu", "cyan"]
                ];
                $insProj = $pdo->prepare("INSERT OR IGNORE INTO crm_projects (id, title, client_name, company_id, category, status, progress, budget, start_date, deadline, team_members, icon, color, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
                foreach ($projects as $pr) {
                    $insProj->execute($pr);
                }
            }
        } catch (Exception $e) {}

        // 6. Seed Tasks (8 initial tasks for proj_1)
        try {
            $taskCount = (int)$pdo->query("SELECT COUNT(*) FROM crm_tasks")->fetchColumn();
            if ($taskCount === 0) {
                $tasks = [
                    ["tsk_1", "Design Homepage", "ABC Tech Solutions", "proj_1", "E-commerce Website", "", "", "Akash Dev", "Today", "High", "Design", "software.local", "layout", 0],
                    ["tsk_2", "Client Meeting - ABC Ltd", "ABC Tech Solutions", "proj_1", "E-commerce Website", "", "", "Suresh Kumar", "Tomorrow", "Medium", "Meeting", "software.local", "users", 0],
                    ["tsk_3", "Prepare Proposal", "Verma Solutions", "proj_1", "E-commerce Website", "", "", "Akash Dev", "14 Aug", "Low", "Sales", "software.local", "file-text", 0],
                    ["tsk_4", "Review UI Design", "Kapoor Designs", "proj_1", "E-commerce Website", "", "", "Emma Wilson", "16 Aug", "High", "Design", "software.local", "eye", 0],
                    ["tsk_5", "Update Project Documentation", "Patel & Co.", "proj_1", "E-commerce Website", "", "", "Liam C.", "18 Aug", "Medium", "Docs", "software.local", "book", 0],
                    ["tsk_6", "Database Optimization", "Sheopur Univ", "proj_1", "E-commerce Website", "", "", "Akash Dev", "20 Aug", "Low", "Database", "software.local", "database", 1],
                    ["tsk_7", "API Security Audit", "Apex Industrial Works", "proj_1", "E-commerce Website", "", "", "Akash Dev", "22 Aug", "High", "Security", "software.local", "shield", 1],
                    ["tsk_8", "Configure Production SSL", "ABC Tech Solutions", "proj_1", "E-commerce Website", "", "", "Akash Dev", "24 Aug", "Medium", "DevOps", "software.local", "lock", 1]
                ];
                $insTask = $pdo->prepare("INSERT OR IGNORE INTO crm_tasks (id, title, client_name, project_id, project_name, phone, location, assigned_staff, due_time, priority, category, domain, icon, completed, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
                foreach ($tasks as $tk) {
                    $insTask->execute($tk);
                }
            }
        } catch (Exception $e) {}

        // 7. Seed Invoices / Cash Ledger
        try {
            $ledgerCount = (int)$pdo->query("SELECT COUNT(*) FROM crm_cash_ledger")->fetchColumn();
            if ($ledgerCount === 0) {
                $invoices = [
                    ["inv_1", "INV-2024-001", "income", 2500.00, "Website Development Milestone 1", "Development", "Online Transfer", "Admin", "ABC Tech Solutions", "+91 98765 43210", "Paid", "10 Aug 2024"],
                    ["inv_2", "INV-2024-002", "income", 1200.00, "Mobile App UI Design Phase", "Design", "Bank Wire", "Admin", "Verma Solutions", "+91 98765 43211", "Pending", "12 Aug 2024"],
                    ["inv_3", "INV-2024-003", "income", 3000.00, "Brand Identity & Design System", "Branding", "Cheque", "Admin", "Kapoor Designs", "+91 98765 43213", "Overdue", "05 Aug 2024"],
                    ["inv_4", "INV-2024-004", "income", 800.00, "Architecture & Cloud Consultation", "Consulting", "UPI", "Admin", "Patel & Co.", "+91 98765 43212", "Paid", "28 Jul 2024"],
                    ["inv_5", "INV-2024-005", "income", 4950.00, "ERP Integration & Module Delivery", "Enterprise", "Online Transfer", "Admin", "Apex Industrial Works", "+91 98765 43216", "Paid", "15 Jul 2024"]
                ];
                $insInv = $pdo->prepare("INSERT OR IGNORE INTO crm_cash_ledger (id, invoice_no, type, amount, title, category, payment_mode, handled_by, customer_name, customer_phone, status, due_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
                foreach ($invoices as $iv) {
                    $insInv->execute($iv);
                }
            }
        } catch (Exception $e) {}

        // 8. Seed Expenses
        try {
            $expCount = (int)$pdo->query("SELECT COUNT(*) FROM crm_expenses")->fetchColumn();
            if ($expCount === 0) {
                $expenses = [
                    ['exp_1001', '2026-10-02', 'Cloud & Infrastructure', 'AWS Cloud Infrastructure & EC2 / RDS Database Cluster', 14500.00, 'Company Card', 'AWS-INV-993821', 'Monthly hosting and RDS MySQL', 'Suresh Kumar', 'Paid'],
                    ['exp_1002', '2026-10-05', 'Software & Tools', 'GitHub Enterprise Team & CI/CD Actions Runners', 6200.00, 'Credit Card', 'GH-SUB-202610', '10 Developer seats + private repos', 'Suresh Kumar', 'Paid'],
                    ['exp_1003', '2026-10-10', 'Office & Utilities', 'High-Speed Fiber Leased Line & Office Utilities', 4500.00, 'Bank Transfer', 'ACT-FIBER-8821', 'Primary static IP leased line', 'Admin', 'Paid'],
                    ['exp_1004', '2026-10-15', 'Salaries & Contractors', 'Frontend UI/UX Contract Design & Asset Kit', 18000.00, 'Bank Transfer', 'NEFT-20261015-882', 'Figma design system and assets', 'Suresh Kumar', 'Paid'],
                    ['exp_1005', '2026-10-20', 'Marketing & Sales', 'Google Workspace & CRM Outbound Domain Licensing', 3800.00, 'UPI', 'UPI-REF-59281736', 'Google Workspace business accounts', 'Dhanush', 'Paid']
                ];
                $insExp = $pdo->prepare("INSERT OR IGNORE INTO crm_expenses (id, expense_date, category, description, amount, payment_method, reference, notes, recorded_by, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
                foreach ($expenses as $ex) {
                    $insExp->execute($ex);
                }
            }
        } catch (Exception $e) {}

        // 9. Seed Payments
        try {
            $payCount = (int)$pdo->query("SELECT COUNT(*) FROM crm_payments")->fetchColumn();
            if ($payCount === 0) {
                $payments = [
                    ['pay_seed_1', 'REC-2026-001', 'inv_1', 'INV-2024-001', 'ABC Tech Solutions', 2500.00, '2026-10-15', 'Online Transfer', 'Bank Transfer', 'TXN-901', 'Full payment for invoice 1', 'Advance payment', 'Suresh Kumar', 'Suresh Kumar', 'Paid', '2026-10-15 10:00:00'],
                    ['pay_seed_2', 'REC-2026-002', 'inv_2', 'INV-2024-002', 'Verma Solutions', 1200.00, '2026-10-18', 'Online Transfer', 'Bank Transfer', 'TXN-902', 'Milestone 1 payment', 'Online transfer', 'Suresh Kumar', 'Suresh Kumar', 'Paid', '2026-10-18 14:30:00']
                ];
                $insPay = $pdo->prepare("INSERT OR IGNORE INTO crm_payments (id, receipt_no, invoice_id, invoice_no, customer_name, amount, payment_date, payment_method, payment_mode, transaction_id, reference, notes, recorded_by, received_by, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                foreach ($payments as $p) {
                    $insPay->execute($p);
                }
            }
        } catch (Exception $e) {}

        // 10. Seed Calendar Events
        try {
            $calCount = (int)$pdo->query("SELECT COUNT(*) FROM crm_calendar_events")->fetchColumn();
            if ($calCount === 0) {
                $events = [
                    ["Client Meeting", "2026-08-07", "10:00 AM", "Meeting", "#10b981", "Client meeting with ABC Tech Solutions team"],
                    ["Project Review", "2026-08-09", "02:30 PM", "Review", "#3b82f6", "Sprint 3 deliverables review"],
                    ["Design Deadline", "2026-08-14", "06:00 PM", "Deadline", "#ef4444", "Final Figma approval & handover"],
                    ["Team Meeting", "2026-08-21", "11:00 AM", "Meeting", "#6366f1", "Bi-weekly engineering sync"],
                    ["Invoice Due", "2026-08-26", "05:00 PM", "Billing", "#f59e0b", "Payment reminder for INV-2024-002"]
                ];
                $insCal = $pdo->prepare("INSERT INTO crm_calendar_events (title, event_date, event_time, category, color, description, created_at) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
                foreach ($events as $ev) {
                    $insCal->execute($ev);
                }
            }
        } catch (Exception $e) {}

        // 10. Seed Default Group
        try {
            $grpCount = (int)$pdo->query("SELECT COUNT(*) FROM crm_groups")->fetchColumn();
            if ($grpCount === 0) {
                $pdo->exec("INSERT OR IGNORE INTO crm_groups (name, description, leader_id, color, icon, created_by, created_at) VALUES ('Engineering & Product', 'Core software development and platform operations team', 'EMP-001', '#2563eb', 'code', 'EMP-001', CURRENT_TIMESTAMP)");
                $grpId = $pdo->lastInsertId();
                if ($grpId) {
                    $pdo->exec("INSERT OR IGNORE INTO crm_group_members (group_id, employee_id, role_in_group, added_at) VALUES ($grpId, 'EMP-001', 'leader', CURRENT_TIMESTAMP)");
                    $pdo->exec("INSERT OR IGNORE INTO crm_group_members (group_id, employee_id, role_in_group, added_at) VALUES ($grpId, 'EMP-002', 'member', CURRENT_TIMESTAMP)");
                }
            }
        } catch (Exception $e) {}
    }

    public static function getEngine(): string {
        self::getConnection();
        return self::$engine;
    }
}
