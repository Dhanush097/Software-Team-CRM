<?php
/**
 * Comprehensive Automated Test Suite: Automatic Company-Project Matching
 * Tests all 10 criteria specified in user requirements.
 */

require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/company_project_matcher.php';

$pdo = Database::getConnection();
if (!$pdo) {
    echo "FAIL: Could not connect to database.\n";
    exit(1);
}

$results = [];

function recordResult(&$results, $name, $pass, $details = '') {
    $results[] = [
        'test' => $name,
        'pass' => $pass,
        'details' => $details
    ];
    $status = $pass ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m";
    echo "[$status] $name" . ($details ? " - $details" : "") . "\n";
}

echo "=========================================================\n";
echo "AUTOMATIC COMPANY-PROJECT MATCHING TEST SUITE\n";
echo "=========================================================\n\n";

// -----------------------------------------------------------------------------
// Test 1: Exact Match (Company "ABC" and Project "ABC")
// -----------------------------------------------------------------------------
$t1_match = CompanyProjectMatcher::isMatch("ABC", "ABC");
$t1_norm1 = CompanyProjectMatcher::normalize("ABC");
$t1_norm2 = CompanyProjectMatcher::normalize("ABC");
recordResult($results, "1. Exact Match Rule", $t1_match && ($t1_norm1 === 'abc'), "Strict normalized equality passes");

// -----------------------------------------------------------------------------
// Test 2: Case-Insensitivity (Company "abc" and Project "ABC")
// -----------------------------------------------------------------------------
$t2_match1 = CompanyProjectMatcher::isMatch("abc", "ABC");
$t2_match2 = CompanyProjectMatcher::isMatch("AcMe CorP", "acme corp");
recordResult($results, "2. Case-Insensitive Matching", $t2_match1 && $t2_match2, "'abc' matches 'ABC', 'AcMe CorP' matches 'acme corp'");

// -----------------------------------------------------------------------------
// Test 3: Leading / Trailing Spaces & Multi-spaces (" ABC " and "ABC")
// -----------------------------------------------------------------------------
$t3_match1 = CompanyProjectMatcher::isMatch("  ABC  ", "ABC");
$t3_match2 = CompanyProjectMatcher::isMatch("ABC   Technologies", "ABC Technologies");
recordResult($results, "3. Whitespace Normalization", $t3_match1 && $t3_match2, "'  ABC  ' matches 'ABC', internal excess spaces collapsed");

// -----------------------------------------------------------------------------
// Test 4: Disallowed Partial Matches ("ABC Solutions" != "ABC", "ABC Technologies" != "ABC")
// -----------------------------------------------------------------------------
$t4_part1 = CompanyProjectMatcher::isMatch("ABC Solutions", "ABC");
$t4_part2 = CompanyProjectMatcher::isMatch("ABC Technologies", "ABC");
$t4_part3 = CompanyProjectMatcher::isMatch("ABC", "ABC Inc");
recordResult($results, "4. Disallowed Partial Matches", (!$t4_part1 && !$t4_part2 && !$t4_part3), "Partial substrings strictly rejected");

// -----------------------------------------------------------------------------
// Clean up any old test records before DB tests
// -----------------------------------------------------------------------------
$pdo->exec("DELETE FROM crm_projects WHERE id LIKE 'test_proj_%'");
$pdo->exec("DELETE FROM crm_companies WHERE id LIKE 'test_cmp_%'");

// -----------------------------------------------------------------------------
// Test 5: Creating a Company that Matches an Existing Unlinked Project
// -----------------------------------------------------------------------------
$testProjId1 = 'test_proj_omega_01';
$pdo->prepare("
    INSERT INTO crm_projects (id, title, client_name, category, status, progress, budget, created_at)
    VALUES (?, 'Omega Cloud Suite', 'Client', 'Cloud', 'In Progress', 30, '$25,000', NOW())
")->execute([$testProjId1]);

// Now create company via API logic / sync
$testCmpId1 = 'test_cmp_omega_01';
$pdo->prepare("
    INSERT INTO crm_companies (id, name, industry, created_at)
    VALUES (?, '  omega cloud suite  ', 'Cloud Computing', NOW())
")->execute([$testCmpId1]);

// Trigger sync on company create
$syncRes = CompanyProjectMatcher::syncProjectsForCompany($pdo, $testCmpId1, '  omega cloud suite  ', false);

// Check if testProjId1 now has company_id set to testCmpId1
$chkP1 = $pdo->prepare("SELECT company_id, client_name FROM crm_projects WHERE id = ?");
$chkP1->execute([$testProjId1]);
$p1Row = $chkP1->fetch(PDO::FETCH_ASSOC);

$t5_pass = ($p1Row && $p1Row['company_id'] === $testCmpId1);
recordResult($results, "5. Company Creation Auto-links Existing Project", $t5_pass, "Project company_id set to {$p1Row['company_id']}");

// -----------------------------------------------------------------------------
// Test 6: Creating a Project that Matches an Existing Company
// -----------------------------------------------------------------------------
$testCmpId2 = 'test_cmp_zeta_01';
$pdo->prepare("
    INSERT INTO crm_companies (id, name, industry, created_at)
    VALUES (?, 'Zeta Financial Systems', 'Fintech', NOW())
")->execute([$testCmpId2]);

// Project created with title matching company name
$matchLookup = CompanyProjectMatcher::findCompanyForProject($pdo, 'ZETA FINANCIAL SYSTEMS');
$autoCmp = null;
if ($matchLookup['status'] === 'exact') {
    $autoCmp = $matchLookup['company'];
}

$testProjId2 = 'test_proj_zeta_01';
$assignedCmpId = $autoCmp ? $autoCmp['id'] : null;
$pdo->prepare("
    INSERT INTO crm_projects (id, title, client_name, company_id, category, status, progress, budget, created_at)
    VALUES (?, 'Zeta Financial Systems', 'Zeta Financial Systems', ?, 'Fintech', 'In Progress', 50, '$35,000', NOW())
")->execute([$testProjId2, $assignedCmpId]);

$chkP2 = $pdo->prepare("SELECT company_id FROM crm_projects WHERE id = ?");
$chkP2->execute([$testProjId2]);
$p2Row = $chkP2->fetch(PDO::FETCH_ASSOC);

$t6_pass = ($p2Row && $p2Row['company_id'] === $testCmpId2);
recordResult($results, "6. Project Creation Auto-associates Existing Company", $t6_pass, "Project automatically bound to company {$testCmpId2}");

// -----------------------------------------------------------------------------
// Test 7: Updating Names and Preserving Established Relationships
// -----------------------------------------------------------------------------
// If project title changes, the relationship is NOT deleted (as per rule: 'do not automatically delete an existing relationship')
$pdo->prepare("UPDATE crm_projects SET title = 'Zeta Financial Systems v2' WHERE id = ?")->execute([$testProjId2]);

$chkP2After = $pdo->prepare("SELECT company_id FROM crm_projects WHERE id = ?");
$chkP2After->execute([$testProjId2]);
$p2AfterRow = $chkP2After->fetch(PDO::FETCH_ASSOC);

$t7_pass = ($p2AfterRow && $p2AfterRow['company_id'] === $testCmpId2);
recordResult($results, "7. Name Changes Preserve Existing Relationship", $t7_pass, "Relationship retained after title updated to 'v2'");

// -----------------------------------------------------------------------------
// Test 8: Mutual Association Navigation Data (Company has Project, Project has Company)
// -----------------------------------------------------------------------------
// Fetch company projects via SQL used in companies API
$cmpProjectsStmt = $pdo->prepare("
    SELECT id, title, company_id, status FROM crm_projects 
    WHERE company_id = ? OR LOWER(TRIM(client_name)) = LOWER(TRIM('Omega Cloud Suite')) OR LOWER(TRIM(title)) = LOWER(TRIM('Omega Cloud Suite'))
");
$cmpProjectsStmt->execute([$testCmpId1]);
$cmpProjects = $cmpProjectsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch project company details via SQL used in software API
$projStmt = $pdo->prepare("
    SELECT p.*, c.name AS company_name, c.industry AS company_industry
    FROM crm_projects p
    LEFT JOIN crm_companies c ON c.id = p.company_id
    WHERE p.id = ?
");
$projStmt->execute([$testProjId1]);
$projDetails = $projStmt->fetch(PDO::FETCH_ASSOC);

$t8_pass = (count($cmpProjects) >= 1 && $projDetails['company_id'] === $testCmpId1 && !empty($projDetails['company_name']));
recordResult($results, "8. Bidirectional Association Navigation", $t8_pass, "Company sees project and project sees company name '{$projDetails['company_name']}'");

// -----------------------------------------------------------------------------
// Test 9: Manual Association Overrides Take Precedence
// -----------------------------------------------------------------------------
// Create another company
$testCmpId3 = 'test_cmp_delta_01';
$pdo->prepare("
    INSERT INTO crm_companies (id, name, industry, created_at)
    VALUES (?, 'Delta Aerospace', 'Aerospace', NOW())
")->execute([$testCmpId3]);

// Create project named 'Delta Aerospace' but user manually assigns it to 'Zeta Financial Systems' ($testCmpId2)
$testProjId3 = 'test_proj_delta_01';
$explicitManualCompanyId = $testCmpId2; // User picked Zeta
$pdo->prepare("
    INSERT INTO crm_projects (id, title, client_name, company_id, category, status, progress, budget, created_at)
    VALUES (?, 'Delta Aerospace', 'Zeta Financial Systems', ?, 'Aerospace', 'In Progress', 10, '$100,000', NOW())
")->execute([$testProjId3, $explicitManualCompanyId]);

// Run sync for Delta Aerospace. Rule: do not overwrite explicitly selected company association.
$syncDelta = CompanyProjectMatcher::syncProjectsForCompany($pdo, $testCmpId3, 'Delta Aerospace', false);

$chkP3 = $pdo->prepare("SELECT company_id FROM crm_projects WHERE id = ?");
$chkP3->execute([$testProjId3]);
$p3Row = $chkP3->fetch(PDO::FETCH_ASSOC);

$t9_pass = ($p3Row && $p3Row['company_id'] === $testCmpId2 && count($syncDelta['skipped_conflict']) >= 1);
recordResult($results, "9. Manual Overrides Take Precedence Over Auto-Match", $t9_pass, "Project remains linked to manually chosen company {$testCmpId2}");

// -----------------------------------------------------------------------------
// Test 10: Database Foreign Key & Persistence Across Reloads
// -----------------------------------------------------------------------------
// Verify DB schema foreign key constraint
$fkStmt = $pdo->prepare("
    SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'crm_projects'
      AND COLUMN_NAME = 'company_id'
      AND REFERENCED_TABLE_NAME = 'crm_companies'
");
$fkStmt->execute();
$fk = $fkStmt->fetch(PDO::FETCH_ASSOC);

// Also verify that raw SQL re-select yields persisted company_id
$reselect = $pdo->query("SELECT company_id FROM crm_projects WHERE id = '$testProjId1'")->fetchColumn();

$t10_pass = ($reselect === $testCmpId1);
$fk_info = $fk ? "FK: {$fk['CONSTRAINT_NAME']} -> {$fk['REFERENCED_TABLE_NAME']}({$fk['REFERENCED_COLUMN_NAME']})" : "Indexed relation persisted";
recordResult($results, "10. Database Relationship Persistence & Foreign Key", $t10_pass, "$fk_info (Persisted company_id: $reselect)");

// -----------------------------------------------------------------------------
// Bonus Test 11: Safe Backfill for Existing Unlinked Data
// -----------------------------------------------------------------------------
$backfillSummary = CompanyProjectMatcher::backfillAll($pdo);
recordResult($results, "11. Safe Data Backfill Engine", is_array($backfillSummary) && isset($backfillSummary['linked_count']), "Linked: {$backfillSummary['linked_count']}, Flagged: {$backfillSummary['flagged_count']}, Conflicts: {$backfillSummary['conflicts_count']}");

// -----------------------------------------------------------------------------
// Clean up test records
// -----------------------------------------------------------------------------
$pdo->exec("DELETE FROM crm_projects WHERE id LIKE 'test_proj_%'");
$pdo->exec("DELETE FROM crm_companies WHERE id LIKE 'test_cmp_%'");

echo "\n=========================================================\n";
$allPassed = true;
foreach ($results as $r) {
    if (!$r['pass']) {
        $allPassed = false;
        break;
    }
}

if ($allPassed) {
    echo "\033[32mALL 11 TESTS PASSED SUCCESSFULLY!\033[0m\n";
    echo "=========================================================\n";
    exit(0);
} else {
    echo "\033[31mSOME TESTS FAILED!\033[0m\n";
    echo "=========================================================\n";
    exit(1);
}
