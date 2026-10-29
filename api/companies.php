<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, DELETE, PUT');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/session_guard.php';
SessionGuard::requireAuth(true);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/company_project_matcher.php';
$pdo = Database::getConnection();

function sendJson($data, $code = 200) {
    if ($code >= 200 && $code < 300) {
        if (!isset($data['success'])) {
            $data['success'] = true;
        }
    } else {
        if (!isset($data['success'])) {
            $data['success'] = false;
        }
    }
    http_response_code($code);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$pdo) {
    sendJson(['status' => 'error', 'message' => 'Database connection failed'], 500);
}

// Auto-migration & Schema verification
class CompanyMigration {
    public static function ensure(PDO $pdo): void {
        static $checked = false;
        if ($checked) return;

        $sql = "CREATE TABLE IF NOT EXISTS `crm_companies` (
          `id` varchar(64) NOT NULL PRIMARY KEY,
          `name` varchar(255) NOT NULL,
          `industry` varchar(128) DEFAULT 'Technology & Services',
          `website` varchar(255) DEFAULT '',
          `email` varchar(255) DEFAULT '',
          `phone` varchar(64) DEFAULT '',
          `address` text DEFAULT NULL,
          `description` text DEFAULT NULL,
          `logo_url` text DEFAULT NULL,
          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY `idx_comp_name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $pdo->exec($sql);

        // Ensure company_id in crm_contacts
        $cols = $pdo->query("SHOW COLUMNS FROM `crm_contacts` LIKE 'company_id'")->fetch();
        if (!$cols) {
            $pdo->exec("ALTER TABLE `crm_contacts` ADD COLUMN `company_id` varchar(64) DEFAULT NULL AFTER `company`");
            $pdo->exec("ALTER TABLE `crm_contacts` ADD INDEX `idx_contacts_company_id` (`company_id`)");
        }

        // Ensure company_id in crm_projects
        $pcols = $pdo->query("SHOW COLUMNS FROM `crm_projects` LIKE 'company_id'")->fetch();
        if (!$pcols) {
            $pdo->exec("ALTER TABLE `crm_projects` ADD COLUMN `company_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `client_name`");
            $pdo->exec("ALTER TABLE `crm_projects` ADD INDEX `idx_projects_company_id` (`company_id`)");
            try {
                $pdo->exec("ALTER TABLE `crm_projects` ADD CONSTRAINT `fk_projects_company` FOREIGN KEY (`company_id`) REFERENCES `crm_companies` (`id`) ON DELETE SET NULL ON UPDATE CASCADE");
            } catch (Exception $e) {}
        }

        $checked = true;
    }
}
CompanyMigration::ensure($pdo);

$method = $_SERVER['REQUEST_METHOD'];

// -----------------------------------------------------------------------------
// GET REQUESTS: LIST ALL OR SINGLE COMPANY BY ID
// -----------------------------------------------------------------------------
if ($method === 'GET') {
    $id = isset($_GET['id']) ? trim($_GET['id']) : null;
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';

    try {
        if ($id) {
            // Retrieve single company
            $stmt = $pdo->prepare("SELECT * FROM crm_companies WHERE id = :id OR LOWER(name) = LOWER(:name) LIMIT 1");
            $stmt->execute([':id' => $id, ':name' => $id]);
            $company = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$company) {
                sendJson(['status' => 'error', 'message' => 'Company not found'], 404);
            }

            // Retrieve associated contacts
            $cStmt = $pdo->prepare("
                SELECT id, name, company, occupation, email, phone, phone2, address, website_link, category, created_at
                FROM crm_contacts
                WHERE company_id = :cid OR (company IS NOT NULL AND LOWER(TRIM(company)) = LOWER(TRIM(:cname)))
                ORDER BY name ASC
            ");
            $cStmt->execute([':cid' => $company['id'], ':cname' => $company['name']]);
            $contacts = $cStmt->fetchAll(PDO::FETCH_ASSOC);

            // Retrieve associated projects
            $pStmt = $pdo->prepare("
                SELECT id, title, client_name, company_id, status, budget, deadline, category, progress, color, icon
                FROM crm_projects
                WHERE company_id = :cid OR LOWER(TRIM(client_name)) = LOWER(TRIM(:cname)) OR LOWER(TRIM(title)) = LOWER(TRIM(:cname2))
                ORDER BY created_at DESC
            ");
            $pStmt->execute([':cid' => $company['id'], ':cname' => $company['name'], ':cname2' => $company['name']]);
            $projects = $pStmt->fetchAll(PDO::FETCH_ASSOC);

            // Retrieve associated invoices
            $iStmt = $pdo->prepare("
                SELECT id, invoice_no, customer_name, amount, paid_amount, balance_amount, status, due_date, transaction_date
                FROM crm_cash_ledger
                WHERE LOWER(TRIM(customer_name)) = LOWER(TRIM(:cname))
                ORDER BY transaction_date DESC
            ");
            $iStmt->execute([':cname' => $company['name']]);
            $invoices = $iStmt->fetchAll(PDO::FETCH_ASSOC);

            $company['contacts'] = $contacts;
            $company['contacts_count'] = count($contacts);
            $company['projects'] = $projects;
            $company['projects_count'] = count($projects);
            $company['invoices'] = $invoices;
            $company['invoices_count'] = count($invoices);

            sendJson(['status' => 'success', 'data' => $company]);
        } else {
            // List all companies with dynamic counts
            $sql = "
                SELECT 
                    c.id, 
                    c.name, 
                    c.industry, 
                    c.website, 
                    c.email, 
                    c.phone, 
                    c.address, 
                    c.description, 
                    c.logo_url, 
                    c.created_at, 
                    c.updated_at,
                    COUNT(DISTINCT cnt.id) AS contacts_count,
                    COUNT(DISTINCT prj.id) AS projects_count,
                    COUNT(DISTINCT ldg.id) AS invoices_count
                FROM crm_companies c
                LEFT JOIN crm_contacts cnt ON (cnt.company_id = c.id OR (cnt.company IS NOT NULL AND LOWER(TRIM(cnt.company)) = LOWER(TRIM(c.name))))
                LEFT JOIN crm_projects prj ON (prj.company_id = c.id OR LOWER(TRIM(prj.client_name)) = LOWER(TRIM(c.name)) OR LOWER(TRIM(prj.title)) = LOWER(TRIM(c.name)))
                LEFT JOIN crm_cash_ledger ldg ON (LOWER(TRIM(ldg.customer_name)) = LOWER(TRIM(c.name)))
            ";

            $params = [];
            if (!empty($q)) {
                $sql .= " WHERE (c.name LIKE :q1 OR c.industry LIKE :q2 OR c.address LIKE :q3)";
                $params[':q1'] = "%$q%";
                $params[':q2'] = "%$q%";
                $params[':q3'] = "%$q%";
            }

            $sql .= " GROUP BY c.id ORDER BY c.name ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

            sendJson(['status' => 'success', 'data' => $companies, 'total' => count($companies)]);
        }
    } catch (Exception $e) {
        sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}

// -----------------------------------------------------------------------------
// DELETE REQUEST
// -----------------------------------------------------------------------------
if ($method === 'DELETE') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: $_GET;
    $id = trim($input['id'] ?? ($_GET['id'] ?? ''));

    if (empty($id)) {
        sendJson(['status' => 'error', 'message' => 'Company ID is required'], 400);
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM crm_companies WHERE id = ?");
        $stmt->execute([$id]);
        $comp = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$comp) {
            sendJson(['status' => 'error', 'message' => 'Company not found'], 404);
        }

        // Preserve contacts by unlinking company_id
        $pdo->prepare("UPDATE crm_contacts SET company_id = NULL WHERE company_id = ?")->execute([$id]);

        // Delete company
        $del = $pdo->prepare("DELETE FROM crm_companies WHERE id = ?");
        $del->execute([$id]);

        sendJson(['status' => 'success', 'message' => 'Company deleted successfully', 'id' => $id]);
    } catch (Exception $e) {
        sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}

// -----------------------------------------------------------------------------
// POST REQUESTS: CREATE, UPDATE, DELETE
// -----------------------------------------------------------------------------
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: $_POST;
    $action = trim($input['action'] ?? ($_GET['action'] ?? 'create'));

    // 1. DELETE ACTION VIA POST
    if ($action === 'delete') {
        $id = trim($input['id'] ?? ($_GET['id'] ?? ''));
        if (empty($id)) {
            sendJson(['status' => 'error', 'message' => 'Company ID is required'], 400);
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM crm_companies WHERE id = ?");
            $stmt->execute([$id]);
            $comp = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$comp) {
                sendJson(['status' => 'error', 'message' => 'Company not found'], 404);
            }

            // Unlink associated contacts so they are preserved
            $pdo->prepare("UPDATE crm_contacts SET company_id = NULL WHERE company_id = ?")->execute([$id]);

            $del = $pdo->prepare("DELETE FROM crm_companies WHERE id = ?");
            $del->execute([$id]);

            sendJson(['status' => 'success', 'message' => 'Company deleted successfully', 'id' => $id]);
        } catch (Exception $e) {
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // 2. CREATE COMPANY
    if ($action === 'create') {
        $name = trim($input['name'] ?? '');
        if (empty($name)) {
            sendJson(['status' => 'error', 'message' => 'Company name is required'], 400);
        }

        try {
            // Check if company already exists
            $chk = $pdo->prepare("SELECT id FROM crm_companies WHERE LOWER(name) = LOWER(?)");
            $chk->execute([$name]);
            if ($chk->fetch()) {
                sendJson(['status' => 'error', 'message' => 'A company with this name already exists'], 409);
            }

            $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($name));
            $slug = trim($slug, '_');
            if (empty($slug)) $slug = 'company';
            $id = 'cmp_' . $slug . '_' . substr(md5(uniqid()), 0, 4);

            $industry    = trim($input['industry'] ?? 'Technology & Services');
            $website     = trim($input['website'] ?? '');
            $email       = trim($input['email'] ?? '');
            $phone       = trim($input['phone'] ?? '');
            $address     = trim($input['address'] ?? '');
            $description = trim($input['description'] ?? '');
            $logoUrl     = trim($input['logo_url'] ?? '');

            $stmt = $pdo->prepare("
                INSERT INTO crm_companies 
                (id, name, industry, website, email, phone, address, description, logo_url, created_at)
                VALUES 
                (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$id, $name, $industry, $website, $email, $phone, $address, $description, $logoUrl]);

            // Link existing matching contacts
            $pdo->prepare("UPDATE crm_contacts SET company_id = ? WHERE LOWER(TRIM(company)) = LOWER(TRIM(?))")->execute([$id, $name]);

            // Automatically associate matching projects
            $syncResult = CompanyProjectMatcher::syncProjectsForCompany($pdo, $id, $name, false);
            $matchedProjects = $syncResult['linked'] ?? [];

            $createdData = [
                'id' => $id,
                'name' => $name,
                'industry' => $industry,
                'website' => $website,
                'email' => $email,
                'phone' => $phone,
                'address' => $address,
                'description' => $description,
                'logo_url' => $logoUrl,
                'contacts_count' => 0,
                'projects_count' => count($matchedProjects),
                'invoices_count' => 0,
                'matched_projects' => $matchedProjects,
                'created_at' => date('Y-m-d H:i:s')
            ];

            sendJson(['status' => 'success', 'message' => 'Company created successfully', 'id' => $id, 'data' => $createdData], 201);
        } catch (Exception $e) {
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // BACKFILL ASSOCIATIONS
    if ($action === 'backfill_associations') {
        try {
            $summary = CompanyProjectMatcher::backfillAll($pdo);
            sendJson(['status' => 'success', 'message' => 'Company-project backfill completed', 'data' => $summary]);
        } catch (Exception $e) {
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // 3. UPDATE COMPANY
    if ($action === 'update') {
        $id = trim($input['id'] ?? '');
        $name = trim($input['name'] ?? '');

        if (empty($id)) {
            sendJson(['status' => 'error', 'message' => 'Company ID is required'], 400);
        }
        if (empty($name)) {
            sendJson(['status' => 'error', 'message' => 'Company name is required'], 400);
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM crm_companies WHERE id = ?");
            $stmt->execute([$id]);
            $orig = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$orig) {
                sendJson(['status' => 'error', 'message' => 'Company not found'], 404);
            }

            // Check name uniqueness if changed
            if (strcasecmp($orig['name'], $name) !== 0) {
                $chk = $pdo->prepare("SELECT id FROM crm_companies WHERE LOWER(name) = LOWER(?) AND id != ?");
                $chk->execute([$name, $id]);
                if ($chk->fetch()) {
                    sendJson(['status' => 'error', 'message' => 'Another company already exists with this name'], 409);
                }
            }

            $industry    = trim($input['industry'] ?? ($orig['industry'] ?? 'Technology & Services'));
            $website     = trim($input['website'] ?? ($orig['website'] ?? ''));
            $email       = trim($input['email'] ?? ($orig['email'] ?? ''));
            $phone       = trim($input['phone'] ?? ($orig['phone'] ?? ''));
            $address     = trim($input['address'] ?? ($orig['address'] ?? ''));
            $description = trim($input['description'] ?? ($orig['description'] ?? ''));
            $logoUrl     = trim($input['logo_url'] ?? ($orig['logo_url'] ?? ''));

            $upd = $pdo->prepare("
                UPDATE crm_companies 
                SET name = ?, industry = ?, website = ?, email = ?, phone = ?, address = ?, description = ?, logo_url = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $upd->execute([$name, $industry, $website, $email, $phone, $address, $description, $logoUrl, $id]);

            // If name changed, update linked contacts company name & sync projects
            if (strcasecmp($orig['name'], $name) !== 0) {
                $pdo->prepare("UPDATE crm_contacts SET company = ? WHERE company_id = ? OR LOWER(TRIM(company)) = LOWER(TRIM(?))")->execute([$name, $id, $orig['name']]);
                
                // Re-evaluate matching projects without breaking existing associations
                CompanyProjectMatcher::syncProjectsForCompany($pdo, $id, $name, true);

                // Update client_name for already linked projects if they matched the old company name
                $pdo->prepare("UPDATE crm_projects SET client_name = ? WHERE company_id = ? AND LOWER(TRIM(client_name)) = LOWER(TRIM(?))")->execute([$name, $id, $orig['name']]);
            }

            $updatedData = [
                'id' => $id,
                'name' => $name,
                'industry' => $industry,
                'website' => $website,
                'email' => $email,
                'phone' => $phone,
                'address' => $address,
                'description' => $description,
                'logo_url' => $logoUrl
            ];

            sendJson(['status' => 'success', 'message' => 'Company updated successfully', 'data' => $updatedData]);
        } catch (Exception $e) {
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    sendJson(['status' => 'error', 'message' => 'Unknown action'], 400);
}

sendJson(['status' => 'error', 'message' => 'Method not allowed'], 405);
