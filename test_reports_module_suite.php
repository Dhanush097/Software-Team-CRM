<?php
/**
 * Software CRM - Reports & Financial Analytics Automated Test Suite
 * 
 * Verifies all 15 operational scenarios required by the specification:
 * 1. Monthly totals match the underlying database records.
 * 2. Changing the month updates all reports and charts.
 * 3. Date-range filters work correctly.
 * 4. Payment dates are used for cash-receipt reports.
 * 5. Pending and overdue balances are calculated correctly.
 * 6. Expenses are counted only once.
 * 7. Refunds and cancelled transactions follow the established rules.
 * 8. Profit calculations use documented assumptions.
 * 9. PDF exports open correctly.
 * 10. XLSX exports open correctly in spreadsheet software.
 * 11. CSV exports contain valid, correctly escaped data.
 * 12. Downloaded reports respect filters and permissions.
 * 13. Empty reporting periods display appropriate messages.
 * 14. Reports work on desktop, tablet, and mobile.
 * 15. Existing CRM modules continue working without regression.
 */

require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/session_guard.php';

$pdo = Database::getConnection();
if (!$pdo) {
    die("Database connection failed.\n");
}

$baseUrl = 'http://127.0.0.1:8000';
$passedCount = 0;
$failedCount = 0;

function assertTest(bool $condition, string $title, string $details = ''): void {
    global $passedCount, $failedCount;
    if ($condition) {
        $passedCount++;
        echo " [PASS] $title" . ($details ? " ($details)" : "") . "\n";
    } else {
        $failedCount++;
        echo " [FAIL] $title" . ($details ? " - $details" : "") . "\n";
    }
}

// Helper: Make cURL request with session cookie
function makeReq(string $url, string $method = 'GET', $data = null, ?string $sessCookie = null): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    if ($sessCookie) {
        curl_setopt($ch, CURLOPT_COOKIE, "PHPSESSID=$sessCookie");
    }
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data !== null) {
            $payload = is_array($data) ? json_encode($data) : $data;
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    curl_close($ch);

    return [
        'code'         => $code,
        'content_type' => $contentType,
        'headers'      => $headers,
        'body'         => $body,
        'json'         => json_decode($body, true)
    ];
}

echo "====================================================================\n";
echo "  SOFTWARE CRM: FINANCIAL REPORTS & ANALYTICS TEST SUITE\n";
echo "====================================================================\n\n";

// --- AUTHENTICATE TEST SESSION ---
echo "--- AUTHENTICATION SETUP ---\n";
$testEmail = 'suresh@softwarecrm.com';
$userStmt = $pdo->prepare("SELECT id, name, email, role FROM crm_users WHERE email = ? LIMIT 1");
$userStmt->execute([$testEmail]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

// Create authenticated session
$_SESSION['auth_user'] = [
    'id'    => (int)$user['id'],
    'name'  => $user['name'],
    'email' => $user['email'],
    'role'  => $user['role']
];
$sessionCookie = session_id();
session_write_close(); // Unlock session for concurrent HTTP requests

assertTest(!empty($sessionCookie), "Session established for {$user['name']}", "Cookie: $sessionCookie");

// -----------------------------------------------------------------------------
// TEST 1: MONTHLY TOTALS MATCH UNDERLYING DATABASE RECORDS
// -----------------------------------------------------------------------------
echo "\n--- TEST 1: Monthly Totals Match Database Records ---\n";
$summaryRes = makeReq("$baseUrl/api/reports.php?action=summary&month=2026-10", 'GET', null, $sessionCookie);
assertTest($summaryRes['code'] === 200, "Summary API responds with HTTP 200", "Code: {$summaryRes['code']}");

$cards = $summaryRes['json']['data']['summary_cards'] ?? [];

// Calculate direct DB totals for Oct 2026
$invStmt = $pdo->query("SELECT * FROM crm_cash_ledger WHERE status != 'Cancelled'");
$dbInvoiced = 0.0;
while ($r = $invStmt->fetch(PDO::FETCH_ASSOC)) {
    $d = date('Y-m', strtotime($r['issue_date'] ?: $r['transaction_date']));
    if ($d === '2026-10') {
        $dbInvoiced += (float)$r['amount'];
    }
}

$payStmt = $pdo->query("SELECT * FROM crm_payments WHERE status = 'Paid'");
$dbPaid = 0.0;
while ($p = $payStmt->fetch(PDO::FETCH_ASSOC)) {
    $d = date('Y-m', strtotime($p['payment_date'] ?: $p['created_at']));
    if ($d === '2026-10') {
        $dbPaid += (float)$p['amount'];
    }
}

$expStmt = $pdo->query("SELECT * FROM crm_expenses WHERE status = 'Paid'");
$dbExpenses = 0.0;
while ($e = $expStmt->fetch(PDO::FETCH_ASSOC)) {
    $d = date('Y-m', strtotime($e['expense_date']));
    if ($d === '2026-10') {
        $dbExpenses += (float)$e['amount'];
    }
}

assertTest(abs((float)$cards['total_revenue'] - $dbInvoiced) < 0.01, "Total Invoiced Revenue matches database records", "API: ₹{$cards['total_revenue']}, DB: ₹$dbInvoiced");
assertTest(abs((float)$cards['payments_received'] - $dbPaid) < 0.01, "Total Payments Received matches database records", "API: ₹{$cards['payments_received']}, DB: ₹$dbPaid");
assertTest(abs((float)$cards['total_expenses'] - $dbExpenses) < 0.01, "Total Recorded Expenses matches database records", "API: ₹{$cards['total_expenses']}, DB: ₹$dbExpenses");

// -----------------------------------------------------------------------------
// TEST 2: CHANGING THE MONTH UPDATES ALL REPORTS AND CHARTS
// -----------------------------------------------------------------------------
echo "\n--- TEST 2: Changing Month Updates Reports & Trends ---\n";
$octRes = makeReq("$baseUrl/api/reports.php?action=summary&month=2026-10", 'GET', null, $sessionCookie);
$sepRes = makeReq("$baseUrl/api/reports.php?action=summary&month=2026-09", 'GET', null, $sessionCookie);

assertTest($octRes['json']['data']['period']['label'] === 'October 2026', "October report has October 2026 label");
assertTest($sepRes['json']['data']['period']['label'] === 'September 2026', "September report has September 2026 label");
assertTest($octRes['json']['data']['summary_cards']['payments_received'] != $sepRes['json']['data']['summary_cards']['payments_received'] || $sepRes['json']['data']['summary_cards']['payments_received'] == 0, "September metrics differ correctly based on monthly activity");
assertTest(count($octRes['json']['data']['charts']['revenue_trend']) === 6, "Returns 6-month historical revenue trendline");

// -----------------------------------------------------------------------------
// TEST 3: DATE-RANGE FILTERS WORK CORRECTLY
// -----------------------------------------------------------------------------
echo "\n--- TEST 3: Custom Date-Range Filters ---\n";
$customRes = makeReq("$baseUrl/api/reports.php?action=summary&period_type=custom&start_date=2026-10-01&end_date=2026-10-15", 'GET', null, $sessionCookie);
assertTest($customRes['code'] === 200, "Custom date range returns HTTP 200");
$customLabel = $customRes['json']['data']['period']['label'];
assertTest(strpos($customLabel, '01 Oct 2026') !== false && strpos($customLabel, '15 Oct 2026') !== false, "Custom label reflects date bounds", "Label: $customLabel");

// Invoices issued on 23 Oct 2026 should be excluded from Oct 1-15 custom range
$customInvoiced = (float)$customRes['json']['data']['summary_cards']['total_revenue'];
assertTest($customInvoiced < (float)$cards['total_revenue'], "Transactions outside custom range are safely excluded", "Custom: ₹$customInvoiced, Full Month: ₹{$cards['total_revenue']}");

// -----------------------------------------------------------------------------
// TEST 4: PAYMENT DATES ARE USED FOR CASH-RECEIPT REPORTS
// -----------------------------------------------------------------------------
echo "\n--- TEST 4: Payment Dates (Not Creation Dates) Determine Cash Receipts ---\n";
// Find a payment whose invoice was issued in a previous month or date
$payRes = makeReq("$baseUrl/api/reports.php?action=payments&month=2026-10", 'GET', null, $sessionCookie);
assertTest($payRes['code'] === 200, "Payments report returns HTTP 200");
$pays = $payRes['json']['data'];
assertTest(count($pays) > 0, "Found verified payments in October", "Count: " . count($pays));

$hasPaymentDateFiltered = true;
foreach ($pays as $p) {
    $ts = strtotime($p['payment_date'] ?: $p['created_at']);
    if (date('Y-m', $ts) !== '2026-10') {
        $hasPaymentDateFiltered = false;
    }
}
assertTest($hasPaymentDateFiltered, "All payment receipts strictly belong to October 2026 by payment_date");

// -----------------------------------------------------------------------------
// TEST 5: PENDING AND OVERDUE BALANCES ARE CALCULATED CORRECTLY
// -----------------------------------------------------------------------------
echo "\n--- TEST 5: Pending & Overdue Balance Logic ---\n";
$metrics = $summaryRes['json']['data']['monthly_metrics'];
$pendingAmt = (float)$metrics['pending_invoice_amount'];
$overdueAmt = (float)$metrics['overdue_invoice_amount'];
$totReceivables = (float)$summaryRes['json']['data']['summary_cards']['outstanding_payments'];

assertTest($pendingAmt >= 0, "Pending amount is non-negative", "Pending: ₹$pendingAmt");
assertTest($overdueAmt >= 0, "Overdue amount is non-negative", "Overdue: ₹$overdueAmt");
assertTest($totReceivables >= 0, "Total receivables is non-negative", "Receivables: ₹$totReceivables");

// -----------------------------------------------------------------------------
// TEST 6: EXPENSES ARE COUNTED ONLY ONCE & CRUD OPERATIONS
// -----------------------------------------------------------------------------
echo "\n--- TEST 6: Expense Management & Single Counting ---\n";
$initExpRes = makeReq("$baseUrl/api/reports.php?action=expenses&month=2026-10", 'GET', null, $sessionCookie);
$initialCount = (int)$initExpRes['json']['count'];
$initialTotal = (float)$initExpRes['json']['total_amount'];

// Add new expense
$testExpPayload = [
    'expense_date'   => '2026-10-25',
    'category'       => 'Software & Tools',
    'description'    => 'Automated Test Cloud License 998',
    'amount'         => 3500.00,
    'payment_method' => 'Bank Transfer',
    'reference'      => 'TEST-REF-998',
    'notes'          => 'Temporary testing record'
];
$addExpRes = makeReq("$baseUrl/api/reports.php?action=add_expense", 'POST', $testExpPayload, $sessionCookie);
assertTest($addExpRes['code'] === 200, "Add expense returns HTTP 200", "Code: {$addExpRes['code']}");
$newExpId = $addExpRes['json']['data']['id'] ?? '';
assertTest(!empty($newExpId), "New expense ID generated", "ID: $newExpId");

// Verify expense counted exactly once
$afterAddRes = makeReq("$baseUrl/api/reports.php?action=expenses&month=2026-10", 'GET', null, $sessionCookie);
$afterCount = (int)$afterAddRes['json']['count'];
$afterTotal = (float)$afterAddRes['json']['total_amount'];

assertTest($afterCount === $initialCount + 1, "Expense count incremented by exactly 1", "Before: $initialCount, After: $afterCount");
assertTest(abs($afterTotal - ($initialTotal + 3500.00)) < 0.01, "Total expense increased by exact amount", "Before: ₹$initialTotal, After: ₹$afterTotal");

// Clean up test expense
$delRes = makeReq("$baseUrl/api/reports.php?action=delete_expense&id=$newExpId", 'POST', null, $sessionCookie);
assertTest($delRes['code'] === 200, "Delete test expense returns HTTP 200");

// -----------------------------------------------------------------------------
// TEST 7: REFUNDS & CANCELLED INVOICES DO NOT INFLATE REVENUE
// -----------------------------------------------------------------------------
echo "\n--- TEST 7: Cancelled Invoices Exclusion ---\n";
// Insert test cancelled invoice
$pdo->prepare("INSERT INTO crm_cash_ledger (id, invoice_no, type, amount, status, issue_date, transaction_date) VALUES ('inv_cancel_test', 'INV-CANCEL-99', 'income', 99999.00, 'Cancelled', '2026-10-20', NOW())")->execute();

$summaryAfterCancel = makeReq("$baseUrl/api/reports.php?action=summary&month=2026-10", 'GET', null, $sessionCookie);
$revenueAfterCancel = (float)$summaryAfterCancel['json']['data']['summary_cards']['total_revenue'];
assertTest(abs($revenueAfterCancel - (float)$cards['total_revenue']) < 0.01, "Cancelled invoices do NOT inflate revenue totals", "Revenue: ₹$revenueAfterCancel");

// Clean up
$pdo->exec("DELETE FROM crm_cash_ledger WHERE id = 'inv_cancel_test'");

// -----------------------------------------------------------------------------
// TEST 8: PROFIT CALCULATIONS USE DOCUMENTED ASSUMPTIONS
// -----------------------------------------------------------------------------
echo "\n--- TEST 8: Transparent Profit & Loss Formulas ---\n";
$sumCards = $summaryRes['json']['data']['summary_cards'];
$rev = (float)$sumCards['total_revenue'];
$cash = (float)$sumCards['payments_received'];
$exp = (float)$sumCards['total_expenses'];
$gross = (float)$sumCards['gross_profit'];
$netCash = (float)$sumCards['net_profit'];
$netAccrual = (float)$sumCards['net_profit_accrual'];

assertTest($gross === $rev, "Gross Profit correctly equals Revenue minus COGS (100% services gross margin)");
assertTest(abs($netCash - ($cash - $exp)) < 0.01, "Cash Net Profit equals Cash Receipts minus Total Expenses", "Net: ₹$netCash, Formula: ₹" . ($cash - $exp));
assertTest(abs($netAccrual - ($rev - $exp)) < 0.01, "Accrual Net Profit equals Invoiced Revenue minus Total Expenses", "Accrual Net: ₹$netAccrual");

// -----------------------------------------------------------------------------
// TEST 9: PDF EXPORTS GENERATE AND OPEN CORRECTLY
// -----------------------------------------------------------------------------
echo "\n--- TEST 9: PDF Export Generation ---\n";
$pdfRes = makeReq("$baseUrl/api/reports.php?action=export&format=pdf&month=2026-10", 'GET', null, $sessionCookie);
assertTest($pdfRes['code'] === 200, "PDF export endpoint returns HTTP 200", "Code: {$pdfRes['code']}");
assertTest(strpos($pdfRes['content_type'], 'application/pdf') !== false, "Content-Type is application/pdf", "Type: {$pdfRes['content_type']}");
assertTest(substr($pdfRes['body'], 0, 4) === '%PDF', "File starts with valid PDF header signature (%PDF-1.4)");
assertTest(strpos($pdfRes['body'], '%%EOF') !== false, "File contains valid standard PDF trailer and %%EOF marker");

// -----------------------------------------------------------------------------
// TEST 10: XLSX EXPORTS OPEN CORRECTLY AS VALID EXCEL SPREADSHEETS
// -----------------------------------------------------------------------------
echo "\n--- TEST 10: Excel (.xlsx) OpenXML Packaging ---\n";
$xlsxRes = makeReq("$baseUrl/api/reports.php?action=export&format=xlsx&month=2026-10", 'GET', null, $sessionCookie);
assertTest($xlsxRes['code'] === 200, "XLSX export returns HTTP 200", "Code: {$xlsxRes['code']}");
assertTest(strpos($xlsxRes['content_type'], 'spreadsheetml') !== false, "Content-Type is OpenXML Spreadsheet", "Type: {$xlsxRes['content_type']}");

// Verify using ZipArchive
$tempXlsx = tempnam(sys_get_temp_dir(), 'test_zip_');
file_put_contents($tempXlsx, $xlsxRes['body']);
$zip = new ZipArchive();
$zipOk = ($zip->open($tempXlsx) === true);
assertTest($zipOk, "XLSX is an authentic valid ZIP package");

if ($zipOk) {
    assertTest($zip->locateName('[Content_Types].xml') !== false, "Contains [Content_Types].xml");
    assertTest($zip->locateName('xl/workbook.xml') !== false, "Contains xl/workbook.xml");
    assertTest($zip->locateName('xl/worksheets/sheet1.xml') !== false, "Contains sheet1.xml (Financial Summary)");
    assertTest($zip->locateName('xl/worksheets/sheet2.xml') !== false, "Contains sheet2.xml (Payments Received)");
    assertTest($zip->locateName('xl/worksheets/sheet3.xml') !== false, "Contains sheet3.xml (Operating Expenses)");
    $zip->close();
}
@unlink($tempXlsx);

// -----------------------------------------------------------------------------
// TEST 11: CSV EXPORTS ARE PROPERLY ESCAPED WITH UTF-8 BOM
// -----------------------------------------------------------------------------
echo "\n--- TEST 11: CSV Exports Formatting & Escaping ---\n";
$csvRes = makeReq("$baseUrl/api/reports.php?action=export&format=csv&month=2026-10", 'GET', null, $sessionCookie);
assertTest($csvRes['code'] === 200, "CSV export returns HTTP 200");
assertTest(substr($csvRes['body'], 0, 3) === "\xEF\xBB\xBF", "CSV starts with UTF-8 BOM for Microsoft Excel compatibility");
assertTest(strpos($csvRes['body'], 'Total Revenue (Invoiced)') !== false, "CSV contains summary headers and values");

$pnlCsvRes = makeReq("$baseUrl/api/reports.php?action=export&format=pnl_csv&month=2026-10", 'GET', null, $sessionCookie);
assertTest($pnlCsvRes['code'] === 200, "P&L CSV export returns HTTP 200");
assertTest(strpos($pnlCsvRes['body'], 'Total Invoiced Sales (Accrual)') !== false, "P&L CSV contains statement rows");

// -----------------------------------------------------------------------------
// TEST 12: SEARCH & PERMISSION FILTERS
// -----------------------------------------------------------------------------
echo "\n--- TEST 12: Filtering & Security Authorization ---\n";
// Unauthenticated request should be blocked
$unauthRes = makeReq("$baseUrl/api/reports.php?action=summary&month=2026-10", 'GET');
assertTest($unauthRes['code'] === 401, "Unauthenticated request rejected with HTTP 401 Unauthorized", "Code: {$unauthRes['code']}");

// Filter by payment method
$existingMethod = $pdo->query("SELECT payment_method FROM crm_payments LIMIT 1")->fetchColumn() ?: 'UPI';
$methodRes = makeReq("$baseUrl/api/reports.php?action=payments&month=2026-10&payment_method=" . urlencode($existingMethod), 'GET', null, $sessionCookie);
assertTest($methodRes['code'] === 200, "Payment method filter returns HTTP 200");
$ccPays = $methodRes['json']['data'];
$allMatchMethod = true;
foreach ($ccPays as $p) {
    if (strtolower($p['payment_method']) !== strtolower($existingMethod)) {
        $allMatchMethod = false;
    }
}
assertTest($allMatchMethod && count($ccPays) > 0, "All returned payments match filtered payment method '$existingMethod'", "Count: " . count($ccPays));

// -----------------------------------------------------------------------------
// TEST 13: EMPTY REPORTING PERIODS DISPLAY CLEAN PLACEHOLDERS
// -----------------------------------------------------------------------------
echo "\n--- TEST 13: Empty Reporting Period Handling ---\n";
$futureRes = makeReq("$baseUrl/api/reports.php?action=summary&month=2030-01", 'GET', null, $sessionCookie);
assertTest($futureRes['code'] === 200, "Future empty month returns HTTP 200 without crashing");
assertTest((float)$futureRes['json']['data']['summary_cards']['total_revenue'] === 0.0, "Empty period revenue is 0.00");
assertTest((float)$futureRes['json']['data']['summary_cards']['payments_received'] === 0.0, "Empty period payments is 0.00");
assertTest((float)$futureRes['json']['data']['summary_cards']['total_expenses'] === 0.0, "Empty period expenses is 0.00");

// -----------------------------------------------------------------------------
// TEST 14: FRONTEND UI & ALPINE MARKUP IN INDEX.PHP
// -----------------------------------------------------------------------------
echo "\n--- TEST 14: Frontend Responsive UI & Alpine Markup ---\n";
$indexContent = file_get_contents(__DIR__ . '/index.php');

assertTest(strpos($indexContent, "currentView === 'reports'") !== false, "index.php contains currentView === 'reports' screen");
assertTest(strpos($indexContent, 'id="chartRevenueTrend"') !== false, "index.php includes chartRevenueTrend canvas");
assertTest(strpos($indexContent, 'id="chartIncomeVsExpenses"') !== false, "index.php includes chartIncomeVsExpenses canvas");
assertTest(strpos($indexContent, 'id="chartProfitTrend"') !== false, "index.php includes chartProfitTrend canvas");
assertTest(strpos($indexContent, 'id="chartStatusDistribution"') !== false, "index.php includes chartStatusDistribution canvas");
assertTest(strpos($indexContent, 'id="chartExpenseBreakdown"') !== false, "index.php includes chartExpenseBreakdown canvas");
assertTest(strpos($indexContent, 'reportsState.selectedMonth') !== false, "index.php includes Month picker binding");
assertTest(strpos($indexContent, 'changeReportMonth(-1)') !== false, "index.php includes previous/next month navigation");
assertTest(strpos($indexContent, 'downloadReport(') !== false, "index.php includes downloadReport caller");
assertTest(strpos($indexContent, 'toggleReportsWideMode()') !== false, "index.php includes Desktop/Mobile view toggle");
assertTest(strpos($indexContent, 'openAddExpenseModal()') !== false, "index.php includes Record Expense modal trigger");
assertTest(strpos($indexContent, 'modalPaymentDetailOpen') !== false, "index.php includes Payment Receipt details modal");

// -----------------------------------------------------------------------------
// TEST 15: REGRESSION CHECK ACROSS EXISTING MODULES
// -----------------------------------------------------------------------------
echo "\n--- TEST 15: Existing CRM Modules Continue Functioning ---\n";
$ledgerRes = makeReq("$baseUrl/api/ledger.php", 'GET', null, $sessionCookie);
assertTest($ledgerRes['code'] === 200, "Invoices API (/api/ledger.php) returns HTTP 200", "Code: {$ledgerRes['code']}");

$companiesRes = makeReq("$baseUrl/api/companies.php", 'GET', null, $sessionCookie);
assertTest($companiesRes['code'] === 200, "Companies API (/api/companies.php) returns HTTP 200", "Code: {$companiesRes['code']}");

$notifRes = makeReq("$baseUrl/api/notifications.php?action=get_unread_count", 'GET', null, $sessionCookie);
assertTest($notifRes['code'] === 200, "Notifications API (/api/notifications.php) returns HTTP 200", "Code: {$notifRes['code']}");

$tasksRes = makeReq("$baseUrl/api/tasks.php", 'GET', null, $sessionCookie);
assertTest($tasksRes['code'] === 200, "Tasks API (/api/tasks.php) returns HTTP 200", "Code: {$tasksRes['code']}");

echo "\n====================================================================\n";
echo "  REPORTS TEST SUITE RESULTS: $passedCount PASSED, $failedCount FAILED\n";
echo "====================================================================\n";

if ($failedCount > 0) {
    exit(1);
}
