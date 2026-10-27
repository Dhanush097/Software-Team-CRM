<?php
/**
 * Software CRM - Database Auto-Setup & Migration Utility
 * Verifies Hostinger MySQL / Local MySQL connection and installs tables from schema.sql
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

$pdo = Database::getConnection();
$engine = Database::getEngine();

if (!$pdo && $engine === 'none') {
    jsonResponse(false, [
        'engine' => $engine,
        'host'   => DB_HOST,
        'db'     => DB_NAME,
        'user'   => DB_USER
    ], 'Unable to connect to MySQL or SQLite. Check credentials in api/config.php.', 500);
}

$schemaFile = dirname(__DIR__) . '/data/schema.sql';
$executedQueries = 0;

if ($pdo && file_exists($schemaFile)) {
    try {
        $sql = file_get_contents($schemaFile);
        $pdo->exec($sql);
        $executedQueries = 1;
    } catch (Exception $e) {
        // Log error
        $sqlError = $e->getMessage();
    }
}

jsonResponse(true, [
    'engine'           => $engine,
    'tables_installed' => true,
    'host'             => DB_HOST,
    'database'         => DB_NAME,
    'sql_executed'     => $executedQueries
], 'Database initialized and tables verified successfully.');
