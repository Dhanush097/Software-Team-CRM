<?php
/**
 * Software Team Database Connection Manager
 * Supports Hostinger MySQL, Localhost MySQL, with automatic SQLite fallback.
 */

/**
 * SQLite-compatible PDO wrapper.
 * Transparently rewrites MySQL-specific SQL to SQLite equivalents:
 *   NOW()                    -> CURRENT_TIMESTAMP
 *   ENGINE=InnoDB            -> stripped
 *   COLLATE utf8mb4_...      -> stripped
 *   ENUM(...)                -> TEXT
 *   INT AUTO_INCREMENT       -> INTEGER
 *   ON UPDATE CURRENT_TIMESTAMP -> stripped
 */
class SQLitePDO extends PDO {
    private static function patch(string $sql): string {
        $sql = preg_replace('/\bNOW\(\)/i', 'CURRENT_TIMESTAMP', $sql);
        $sql = preg_replace('/\s+ENGINE\s*=\s*\w+[^;]*/i', '', $sql);
        $sql = preg_replace('/\s+COLLATE\s+\S+/i', '', $sql);
        $sql = preg_replace('/\s+DEFAULT\s+CHARSET\s*=\s*\w+/i', '', $sql);
        $sql = preg_replace('/\bENUM\s*\([^)]+\)/i', 'TEXT', $sql);
        $sql = preg_replace('/\bINT\s+AUTO_INCREMENT\b/i', 'INTEGER', $sql);
        $sql = preg_replace('/\s+ON\s+UPDATE\s+CURRENT_TIMESTAMP/i', '', $sql);
        $sql = preg_replace('/\s+ON\s+UPDATE\s+NOW\(\)/i', '', $sql);
        return $sql;
    }
    public function exec(string $statement): int|false {
        return parent::exec(self::patch($statement));
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

    public static function getConnection(): ?PDO {
        if (self::$instance !== null) {
            return self::$instance;
        }

        // Database connection candidate configurations
        $configs = [
            // 1. Environment variable config (Render / production)
            [
                'host' => getenv('DB_HOST') ?: null,
                'port' => getenv('DB_PORT') ?: '3306',
                'db'   => getenv('DB_NAME') ?: null,
                'user' => getenv('DB_USER') ?: null,
                'pass' => getenv('DB_PASS') !== false ? getenv('DB_PASS') : null
            ],
            // 2. Localhost XAMPP / MariaDB (pocket_crm_db)
            [
                'host' => '127.0.0.1',
                'port' => '3306',
                'db'   => 'pocket_crm_db',
                'user' => 'root',
                'pass' => ''
            ],
            // 3. Localhost with Hostinger DB name
            [
                'host' => '127.0.0.1',
                'port' => '3306',
                'db'   => 'u213657911_crm',
                'user' => 'root',
                'pass' => ''
            ]
        ];

        // Try MySQL configurations
        foreach ($configs as $cfg) {
            // Skip if host/db/user not set
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
                return self::$instance;
            } catch (Exception $e) {
                // If specific DB does not exist on localhost, attempt to auto-create it
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
                        return self::$instance;
                    } catch (Exception $e2) {}
                }
            }
        }

        // SQLite Fallback — used on Render (no MySQL available)
        try {
            $sqlite_file = dirname(__DIR__) . '/data/pocket_crm.sqlite';
            // Ensure data directory exists and is writable
            if (!is_dir(dirname($sqlite_file))) {
                mkdir(dirname($sqlite_file), 0775, true);
            }
            self::$instance = new SQLitePDO("sqlite:" . $sqlite_file, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            // Enable WAL mode for better concurrency
            self::$instance->exec('PRAGMA journal_mode=WAL');
            self::$instance->exec('PRAGMA foreign_keys=ON');
            self::$engine = 'sqlite';

            // Auto-initialize tables on first connection
            self::initSQLite(self::$instance);

            return self::$instance;
        } catch (Exception $e) {
            self::$engine = 'json_fallback';
            return null;
        }
    }

    /**
     * Auto-create all required tables when running on SQLite.
     */
    private static function initSQLite(PDO $pdo): void {
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
                `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
                `name`       TEXT NOT NULL,
                `email`      TEXT UNIQUE,
                `phone`      TEXT,
                `role`       TEXT DEFAULT 'member',
                `team_id`    INTEGER,
                `status`     TEXT DEFAULT 'active',
                `avatar_url` TEXT,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_contacts` (
                `id`                     TEXT PRIMARY KEY,
                `name`                   TEXT NOT NULL,
                `phone`                  TEXT NOT NULL,
                `phone2`                 TEXT,
                `email`                  TEXT,
                `company`                TEXT,
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
            "CREATE TABLE IF NOT EXISTS `crm_tasks` (
                `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
                `title`       TEXT NOT NULL,
                `description` TEXT,
                `status`      TEXT DEFAULT 'pending',
                `priority`    TEXT DEFAULT 'medium',
                `assigned_to` TEXT,
                `contact_id`  TEXT,
                `due_date`    DATE,
                `created_by`  TEXT,
                `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at`  DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_task_files` (
                `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
                `task_id`     INTEGER NOT NULL,
                `file_name`   TEXT,
                `file_url`    TEXT NOT NULL,
                `file_size`   INTEGER DEFAULT 0,
                `file_type`   TEXT,
                `uploaded_by` TEXT,
                `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_teams` (
                `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
                `name`        TEXT NOT NULL,
                `description` TEXT,
                `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_companies` (
                `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
                `name`       TEXT NOT NULL,
                `industry`   TEXT,
                `website`    TEXT,
                `phone`      TEXT,
                `email`      TEXT,
                `address`    TEXT,
                `notes`      TEXT,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_projects` (
                `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
                `name`        TEXT NOT NULL,
                `description` TEXT,
                `status`      TEXT DEFAULT 'active',
                `company_id`  INTEGER,
                `contact_id`  TEXT,
                `start_date`  DATE,
                `end_date`    DATE,
                `budget`      TEXT,
                `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at`  DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_project_files` (
                `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
                `project_id`  INTEGER NOT NULL,
                `file_name`   TEXT,
                `file_url`    TEXT NOT NULL,
                `file_size`   INTEGER DEFAULT 0,
                `file_type`   TEXT,
                `uploaded_by` TEXT,
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
            )",
            "CREATE TABLE IF NOT EXISTS `crm_notifications` (
                `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
                `user_id`    INTEGER,
                `title`      TEXT NOT NULL,
                `message`    TEXT,
                `type`       TEXT DEFAULT 'info',
                `is_read`    INTEGER DEFAULT 0,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_groups` (
                `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
                `name`        TEXT NOT NULL,
                `description` TEXT,
                `created_by`  INTEGER,
                `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_group_members` (
                `id`        INTEGER PRIMARY KEY AUTOINCREMENT,
                `group_id`  INTEGER NOT NULL,
                `user_id`   INTEGER NOT NULL,
                `joined_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_group_messages` (
                `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
                `group_id`   INTEGER NOT NULL,
                `user_id`    INTEGER NOT NULL,
                `message`    TEXT,
                `file_url`   TEXT,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
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
            "CREATE TABLE IF NOT EXISTS `crm_settings` (
                `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
                `key_name`   TEXT NOT NULL UNIQUE,
                `value`      TEXT,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_webauthn_credentials` (
                `id`            INTEGER PRIMARY KEY AUTOINCREMENT,
                `user_id`       INTEGER NOT NULL,
                `credential_id` TEXT NOT NULL UNIQUE,
                `public_key`    TEXT NOT NULL,
                `sign_count`    INTEGER DEFAULT 0,
                `device_name`   TEXT,
                `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_ledger` (
                `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
                `contact_id`  TEXT,
                `type`        TEXT,
                `amount`      REAL DEFAULT 0,
                `description` TEXT,
                `date`        DATE,
                `created_by`  TEXT,
                `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS `crm_reports` (
                `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
                `title`      TEXT,
                `type`       TEXT,
                `data`       TEXT,
                `created_by` TEXT,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
        ];

        foreach ($tables as $sql) {
            try { $pdo->exec($sql); } catch (Exception $e) { /* skip already-exists */ }
        }
    }

    public static function getEngine(): string {
        self::getConnection();
        return self::$engine;
    }
}
