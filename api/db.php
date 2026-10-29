<?php
/**
 * Software Team Database Connection Manager
 * Supports Hostinger MySQL, Localhost MySQL, with automatic SQLite fallback.
 */

class Database {
    private static ?PDO $instance = null;
    private static string $engine = 'none';

    public static function getConnection(): ?PDO {
        if (self::$instance !== null) {
            return self::$instance;
        }

        // Database connection candidate configurations
        $configs = [
            // 1. Hostinger cPanel Production (From Hostinger hPanel)
            [
                'host' => getenv('DB_HOST') ?: 'localhost',
                'port' => getenv('DB_PORT') ?: '3306',
                'db'   => getenv('DB_NAME') ?: 'u213657911_crm',
                'user' => getenv('DB_USER') ?: 'u213657911_crm',
                'pass' => getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'Sarekam@1234'
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
            try {
                $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['db']};charset=utf8mb4";
                self::$instance = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_TIMEOUT => 2
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
                            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                            PDO::ATTR_EMULATE_PREPARES => false,
                        ]);
                        self::$engine = 'mysql';
                        return self::$instance;
                    } catch (Exception $e2) {}
                }
            }
        }

        // 4. Try SQLite Fallback if MySQL is offline
        try {
            $sqlite_file = dirname(__DIR__) . '/data/pocket_crm.sqlite';
            self::$instance = new PDO("sqlite:" . $sqlite_file, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$engine = 'sqlite';
            return self::$instance;
        } catch (Exception $e) {
            self::$engine = 'json_fallback';
            return null;
        }
    }

    public static function getEngine(): string {
        self::getConnection();
        return self::$engine;
    }
}
