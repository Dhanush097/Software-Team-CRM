<?php
/**
 * SQLite Database Initializer for Render.com / Docker deployments
 * Auto-creates all tables and seeds baseline data for the CRM.
 * Called by Dockerfile entrypoint on startup.
 */

require_once __DIR__ . '/db.php';

$pdo = Database::getConnection();
$engine = Database::getEngine();

if (!$pdo) {
    echo "[ERROR] Database connection failed.\n";
    exit(1);
}

echo "Database initialized successfully (engine: $engine).\n";

// Verify tables exist
try {
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
    echo "Total tables in database: " . count($tables) . "\n";
    echo "Tables: " . implode(', ', $tables) . "\n";

    // Check default user
    $userCount = (int)$pdo->query("SELECT COUNT(*) FROM crm_users")->fetchColumn();
    echo "Active CRM users: $userCount\n";
} catch (Exception $e) {
    echo "[WARN] Could not list tables: " . $e->getMessage() . "\n";
}

exit(0);
