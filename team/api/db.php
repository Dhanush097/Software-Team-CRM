<?php
/**
 * Software CRM - Database Manager
 * Multi-Engine PDO Connection with Automatic Local Fallback & JSON Sync
 */

require_once __DIR__ . '/config.php';

class Database {
    private static ?PDO $pdo = null;
    private static string $engine = 'none';

    public static function getConnection(): ?PDO {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        // Connection candidates
        $connectionCandidates = [
            // 1. Hostinger cPanel / Live MySQL
            [
                'dsn'  => 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                'user' => DB_USER,
                'pass' => DB_PASS,
                'type' => 'mysql-hostinger'
            ],
            // 2. Local XAMPP/MariaDB pocket_crm_db
            [
                'dsn'  => 'mysql:host=127.0.0.1;port=3306;dbname=pocket_crm_db;charset=utf8mb4',
                'user' => 'root',
                'pass' => '',
                'type' => 'mysql-local'
            ],
            // 3. Local SQLite file fallback
            [
                'dsn'  => 'sqlite:' . dirname(__DIR__) . '/data/crm.sqlite',
                'user' => null,
                'pass' => null,
                'type' => 'sqlite'
            ]
        ];

        foreach ($connectionCandidates as $cand) {
            try {
                if ($cand['type'] === 'sqlite') {
                    $sqlitePath = dirname(__DIR__) . '/data/crm.sqlite';
                    if (!file_exists($sqlitePath)) {
                        @touch($sqlitePath);
                    }
                    $conn = new PDO($cand['dsn'], null, null, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    ]);
                    self::$pdo = $conn;
                    self::$engine = 'sqlite';
                    self::ensureTables();
                    return self::$pdo;
                }

                $conn = new PDO($cand['dsn'], $cand['user'], $cand['pass'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT => 2,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                self::$pdo = $conn;
                self::$engine = $cand['type'];
                return self::$pdo;
            } catch (Exception $e) {
                // Continue to next candidate
            }
        }

        self::$engine = 'json-fallback';
        return null;
    }

    public static function getEngine(): string {
        if (self::$pdo === null) {
            self::getConnection();
        }
        return self::$engine;
    }

    // Auto-create minimal SQLite tables if running in SQLite mode
    private static function ensureTables(): void {
        if (!self::$pdo || self::$engine !== 'sqlite') return;

        self::$pdo->exec("
            CREATE TABLE IF NOT EXISTS crm_sessions (
                token TEXT PRIMARY KEY,
                email TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS crm_tasks (
                id TEXT PRIMARY KEY,
                title TEXT NOT NULL,
                project TEXT DEFAULT '',
                priority TEXT DEFAULT 'Medium',
                due_str TEXT DEFAULT 'Today',
                completed INTEGER DEFAULT 0,
                category TEXT DEFAULT 'my',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");
    }

    // Load JSON seed data as fallback
    public static function getSeedData(): array {
        $filePath = dirname(__DIR__) . '/data/seed_data.json';
        if (file_exists($filePath)) {
            $content = file_get_contents($filePath);
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }
}
