<?php
require_once __DIR__ . '/../api/db.php';
$db = Database::getConnection();

echo "=== MIGRATING crm_projects: company_id ===\n";

// 1. Check if company_id column exists
$cols = $db->query("SHOW COLUMNS FROM `crm_projects` LIKE 'company_id'")->fetchAll();
if (empty($cols)) {
    echo "Adding company_id column to crm_projects...\n";
    $db->exec("ALTER TABLE `crm_projects` ADD COLUMN `company_id` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER `client_name`");
    echo "company_id column added.\n";
} else {
    echo "company_id column already exists.\n";
}

// 2. Check index
$indexes = $db->query("SHOW INDEX FROM `crm_projects` WHERE Key_name = 'idx_projects_company_id'")->fetchAll();
if (empty($indexes)) {
    echo "Adding index idx_projects_company_id...\n";
    $db->exec("ALTER TABLE `crm_projects` ADD INDEX `idx_projects_company_id` (`company_id`)");
    echo "Index added.\n";
} else {
    echo "Index already exists.\n";
}

// 3. Check foreign key
$fks = $db->query("
    SELECT CONSTRAINT_NAME 
    FROM information_schema.TABLE_CONSTRAINTS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'crm_projects' 
      AND CONSTRAINT_NAME = 'fk_projects_company'
")->fetchAll();

if (empty($fks)) {
    echo "Adding foreign key fk_projects_company...\n";
    try {
        $db->exec("ALTER TABLE `crm_projects` ADD CONSTRAINT `fk_projects_company` FOREIGN KEY (`company_id`) REFERENCES `crm_companies` (`id`) ON DELETE SET NULL ON UPDATE CASCADE");
        echo "Foreign key added.\n";
    } catch (Exception $e) {
        echo "FK warning: " . $e->getMessage() . "\n";
    }
} else {
    echo "Foreign key already exists.\n";
}

// 4. Safe Backfill for existing records
echo "\n=== RUNNING SAFE BACKFILL FOR EXISTING COMPANIES & PROJECTS ===\n";

function normalizeName(?string $str): string {
    if ($str === null) return '';
    $str = trim(preg_replace('/\s+/', ' ', $str));
    return mb_strtolower($str, 'UTF-8');
}

$companies = $db->query("SELECT id, name FROM crm_companies")->fetchAll(PDO::FETCH_ASSOC);
$projects = $db->query("SELECT id, title, company_id, client_name FROM crm_projects")->fetchAll(PDO::FETCH_ASSOC);

$compByNorm = [];
foreach ($companies as $c) {
    $norm = normalizeName($c['name']);
    if (!isset($compByNorm[$norm])) $compByNorm[$norm] = [];
    $compByNorm[$norm][] = $c;
}

$projByNorm = [];
foreach ($projects as $p) {
    $norm = normalizeName($p['title']);
    if (!isset($projByNorm[$norm])) $projByNorm[$norm] = [];
    $projByNorm[$norm][] = $p;
}

$linkedCount = 0;
$flaggedCount = 0;

foreach ($compByNorm as $norm => $cList) {
    if (!isset($projByNorm[$norm])) continue;
    $pList = $projByNorm[$norm];

    // Check ambiguity: exactly 1 company and 1 project
    if (count($cList) === 1 && count($pList) === 1) {
        $comp = $cList[0];
        $proj = $pList[0];

        // Check if project has a conflicting company_id
        if (!empty($proj['company_id']) && $proj['company_id'] !== $comp['id']) {
            echo " [CONFLICT] Project '{$proj['title']}' ({$proj['id']}) already explicitly linked to company_id '{$proj['company_id']}'. Preserving explicit relationship.\n";
            continue;
        }

        if (empty($proj['company_id'])) {
            $upd = $db->prepare("UPDATE crm_projects SET company_id = ? WHERE id = ?");
            $upd->execute([$comp['id'], $proj['id']]);
            echo " [LINKED] Project '{$proj['title']}' ({$proj['id']}) <---> Company '{$comp['name']}' ({$comp['id']})\n";
            $linkedCount++;
        }
    } else {
        echo " [MANUAL REVIEW] Ambiguous match for normalized name '$norm': " . count($cList) . " companies, " . count($pList) . " projects. Flagged for review.\n";
        $flaggedCount++;
    }
}

echo "Backfill finished. Linked: $linkedCount | Flagged for review: $flaggedCount\n";
