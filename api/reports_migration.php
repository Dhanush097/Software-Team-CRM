<?php
/**
 * Software CRM - Reports & Expenses Database Migration
 */
require_once __DIR__ . '/db.php';
$pdo = Database::getConnection();

if (!$pdo) {
    die("Database connection failed.\n");
}

echo "=== MIGRATING EXPENSES & REPORTS SCHEMA ===\n";

$sql = "
CREATE TABLE IF NOT EXISTS `crm_expenses` (
  `id` VARCHAR(64) PRIMARY KEY,
  `expense_date` DATE NOT NULL,
  `category` VARCHAR(64) NOT NULL,
  `description` VARCHAR(255) NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `payment_method` VARCHAR(64) NOT NULL DEFAULT 'Bank Transfer',
  `reference` VARCHAR(128) NULL,
  `notes` TEXT NULL,
  `recorded_by` VARCHAR(128) NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'Paid',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_expense_date` (`expense_date`),
  INDEX `idx_category` (`category`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

$pdo->exec($sql);
echo "[OK] crm_expenses table created or verified.\n";

// Check if any expenses exist, if not, add realistic baseline software expenses
$count = (int)$pdo->query("SELECT COUNT(*) FROM `crm_expenses`")->fetchColumn();
if ($count === 0) {
    echo "Seeding initial baseline business operating expenses for October 2026...\n";
    $sampleExpenses = [
        [
            'id' => 'exp_1001',
            'expense_date' => '2026-10-02',
            'category' => 'Cloud & Infrastructure',
            'description' => 'AWS Cloud Infrastructure & EC2 / RDS Database Cluster',
            'amount' => 14500.00,
            'payment_method' => 'Company Card',
            'reference' => 'AWS-INV-993821',
            'notes' => 'Monthly hosting, RDS MySQL database, and S3 file storage.',
            'recorded_by' => 'Suresh Kumar',
            'status' => 'Paid'
        ],
        [
            'id' => 'exp_1002',
            'expense_date' => '2026-10-05',
            'category' => 'Software & Tools',
            'description' => 'GitHub Enterprise Team & CI/CD Actions Runners',
            'amount' => 6200.00,
            'payment_method' => 'Credit Card',
            'reference' => 'GH-SUB-202610',
            'notes' => '10 Developer seats + private code repositories.',
            'recorded_by' => 'Suresh Kumar',
            'status' => 'Paid'
        ],
        [
            'id' => 'exp_1003',
            'expense_date' => '2026-10-10',
            'category' => 'Office & Utilities',
            'description' => 'High-Speed Fiber Leased Line & Office Utilities',
            'amount' => 4500.00,
            'payment_method' => 'Bank Transfer',
            'reference' => 'ACT-FIBER-8821',
            'notes' => 'Primary static IP leased line for development server.',
            'recorded_by' => 'Admin',
            'status' => 'Paid'
        ],
        [
            'id' => 'exp_1004',
            'expense_date' => '2026-10-15',
            'category' => 'Salaries & Contractors',
            'description' => 'Frontend UI/UX Contract Design & Asset Kit',
            'amount' => 18000.00,
            'payment_method' => 'Bank Transfer',
            'reference' => 'NEFT-20261015-882',
            'notes' => 'Deliverables: Figma design system and mobile prototype assets.',
            'recorded_by' => 'Suresh Kumar',
            'status' => 'Paid'
        ],
        [
            'id' => 'exp_1005',
            'expense_date' => '2026-10-20',
            'category' => 'Marketing & Sales',
            'description' => 'Google Workspace & CRM Outbound Domain Licensing',
            'amount' => 3800.00,
            'payment_method' => 'UPI',
            'reference' => 'UPI-REF-59281736',
            'notes' => 'Google Workspace business email accounts for staff.',
            'recorded_by' => 'Dhanush',
            'status' => 'Paid'
        ]
    ];

    $insStmt = $pdo->prepare("
        INSERT INTO `crm_expenses` 
        (`id`, `expense_date`, `category`, `description`, `amount`, `payment_method`, `reference`, `notes`, `recorded_by`, `status`, `created_at`)
        VALUES (:id, :expense_date, :category, :description, :amount, :payment_method, :reference, :notes, :recorded_by, :status, NOW())
    ");

    foreach ($sampleExpenses as $e) {
        $insStmt->execute($e);
    }
    echo "[OK] Seeded 5 initial baseline expenses (Total: ₹47,000.00).\n";
} else {
    echo "[INFO] Existing crm_expenses found ($count rows). Preserved existing records.\n";
}

echo "=== MIGRATION COMPLETE ===\n";
