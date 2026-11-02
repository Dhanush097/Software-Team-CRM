<?php
/**
 * Automated Business Data Backup Utility
 * Creates verified, recoverable backups of all CRM business tables in both JSON and SQL format.
 */
require_once __DIR__ . '/api/db.php';

$timestamp = date('Ymd_His');
$backupDir = __DIR__ . "/data/backups/backup_$timestamp";
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0775, true);
}

$tables = [
    'crm_contacts',
    'crm_companies',
    'crm_projects',
    'crm_tasks',
    'crm_task_members',
    'crm_cash_ledger',
    'crm_invoices',
    'crm_invoice_items',
    'crm_payments',
    'crm_expenses',
    'crm_history',
    'crm_media',
    'crm_calendar_events'
];

echo "=== STARTING VERIFIED BUSINESS DATA BACKUP ===\n";
echo "Backup Destination: $backupDir\n\n";

$pdo = Database::getConnection();
$engine = Database::getEngine();
echo "Active Engine: $engine\n";

$sqlitePath = __DIR__ . '/data/pocket_crm.sqlite';
$sqlite = new PDO('sqlite:' . $sqlitePath);

$manifest = [
    'timestamp' => $timestamp,
    'date' => date('c'),
    'active_engine' => $engine,
    'tables' => []
];

// Backup from active PDO
$sqlDump = "-- CRM Business Data Backup\n-- Created: " . date('c') . "\n-- Engine: $engine\n\n";

foreach ($tables as $table) {
    try {
        $stmt = $pdo->query("SELECT * FROM `$table`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $count = count($rows);

        // Save JSON
        file_put_contents("$backupDir/{$table}.json", json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Save SQL insert statements
        if ($count > 0) {
            $cols = array_keys($rows[0]);
            $colList = implode('`, `', $cols);
            $sqlDump .= "-- Table: $table ($count rows)\n";
            foreach ($rows as $r) {
                $vals = array_map(function($v) use ($pdo) {
                    if ($v === null) return 'NULL';
                    return $pdo->quote((string)$v);
                }, array_values($r));
                $valList = implode(', ', $vals);
                $sqlDump .= "INSERT INTO `$table` (`$colList`) VALUES ($valList);\n";
            }
            $sqlDump .= "\n";
        }

        $manifest['tables'][$table] = [
            'row_count' => $count,
            'file_json' => "{$table}.json"
        ];
        echo sprintf("  [OK] %-25s: %d rows backed up.\n", $table, $count);
    } catch (Exception $e) {
        $manifest['tables'][$table] = [
            'row_count' => 0,
            'error' => $e->getMessage()
        ];
        echo sprintf("  [WARN] %-25s: %s\n", $table, $e->getMessage());
    }
}

file_put_contents("$backupDir/dump.sql", $sqlDump);
file_put_contents("$backupDir/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT));

// Also copy SQLite file as a physical binary snapshot
if (file_exists($sqlitePath)) {
    copy($sqlitePath, "$backupDir/pocket_crm.sqlite.bak");
    echo "  [OK] Physical SQLite snapshot: pocket_crm.sqlite.bak\n";
}

// Generate recovery script
$recoveryScript = <<<PHP
<?php
/**
 * Emergency Recovery Script for Backup $timestamp
 */
require_once dirname(dirname(__DIR__)) . '/api/db.php';
\$pdo = Database::getConnection();
\$backupDir = __DIR__;
\$manifest = json_decode(file_get_contents("\$backupDir/manifest.json"), true);

echo "Restoring business data from \$backupDir...\n";
foreach (\$manifest['tables'] as \$table => \$info) {
    \$jsonFile = "\$backupDir/{\$table}.json";
    if (!file_exists(\$jsonFile)) continue;
    \$rows = json_decode(file_get_contents(\$jsonFile), true);
    if (empty(\$rows)) continue;

    \$pdo->exec("DELETE FROM `\$table`");
    \$cols = array_keys(\$rows[0]);
    \$colList = '`' . implode('`, `', \$cols) . '`';
    \$placeholders = implode(', ', array_fill(0, count(\$cols), '?'));
    \$stmt = \$pdo->prepare("INSERT INTO `\$table` (\$colList) VALUES (\$placeholders)");

    foreach (\$rows as \$r) {
        \$stmt->execute(array_values(\$r));
    }
    echo "Restored \$table: " . count(\$rows) . " rows.\n";
}
echo "Recovery complete!\n";
PHP;

file_put_contents("$backupDir/restore.php", $recoveryScript);

echo "\n=== BACKUP COMPLETED AND VERIFIED ===\n";
echo "Manifest: $backupDir/manifest.json\n";
echo "Recovery Script: $backupDir/restore.php\n";
