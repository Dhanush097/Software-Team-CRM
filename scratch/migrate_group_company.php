<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();

echo "=== MIGRATING crm_groups TO MATCH crm_companies.id COLLATION ===\n";

// Set collation of company_id to utf8mb4_unicode_ci to match crm_companies.id
$pdo->exec("
    ALTER TABLE crm_groups 
    MODIFY COLUMN company_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL
");
echo "company_id modified to utf8mb4_unicode_ci.\n";

// Add foreign key constraint
try {
    $fkCheck = $pdo->query("
        SELECT CONSTRAINT_NAME 
        FROM information_schema.TABLE_CONSTRAINTS 
        WHERE TABLE_SCHEMA = DATABASE() 
          AND TABLE_NAME = 'crm_groups' 
          AND CONSTRAINT_NAME = 'fk_crm_groups_company'
    ")->fetchColumn();

    if (!$fkCheck) {
        echo "Adding foreign key constraint fk_crm_groups_company...\n";
        $pdo->exec("
            ALTER TABLE crm_groups 
            ADD CONSTRAINT fk_crm_groups_company 
            FOREIGN KEY (company_id) REFERENCES crm_companies(id) 
            ON DELETE SET NULL ON UPDATE CASCADE
        ");
        echo "Foreign key constraint added successfully.\n";
    } else {
        echo "Foreign key constraint already exists.\n";
    }
} catch (Exception $e) {
    echo "Foreign key error: " . $e->getMessage() . "\n";
}

// Associate existing groups with companies
$cmpRows = $pdo->query("SELECT id, name FROM crm_companies ORDER BY created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
if (!empty($cmpRows)) {
    $c1 = $cmpRows[0]['id'];
    $c2 = $cmpRows[1]['id'] ?? $c1;
    $c3 = $cmpRows[2]['id'] ?? $c1;

    $pdo->prepare("UPDATE crm_groups SET company_id = ? WHERE name = 'Development & Engineering' AND (company_id IS NULL OR company_id = '')")->execute([$c2]);
    $pdo->prepare("UPDATE crm_groups SET company_id = ? WHERE name = 'Design & Product Experience' AND (company_id IS NULL OR company_id = '')")->execute([$c3]);
    $pdo->prepare("UPDATE crm_groups SET company_id = ? WHERE name = 'abc company' AND (company_id IS NULL OR company_id = '')")->execute([$c1]);
}

$rows = $pdo->query("
    SELECT g.id, g.name, g.company_id, c.name AS company_name 
    FROM crm_groups g 
    LEFT JOIN crm_companies c ON c.id = g.company_id
")->fetchAll(PDO::FETCH_ASSOC);

echo "Groups with linked companies:\n";
print_r($rows);
