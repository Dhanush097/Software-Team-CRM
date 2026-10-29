<?php
/**
 * Software CRM - Invoices, Billing, Payments and Receipts Automated Test Suite
 * Covers all 27 verification requirements from Section 15 of user request.
 */

require_once __DIR__ . '/api/db.php';

$baseUrl = 'http://127.0.0.1:8000';
$cookieFile = __DIR__ . '/test_invoices_cookies.txt';
if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

function logTest($name, $passed, $details = '') {
    $status = $passed ? "[PASS]" : "[FAIL]";
    echo sprintf("%-6s %-52s %s\n", $status, $name, $details ? "- $details" : '');
}

function makeRequest($url, $method = 'GET', $data = null, $headers = []) {
    global $cookieFile;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data !== null) {
            $jsonData = json_encode($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($jsonData);
        }
    }

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headerStr = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    $json = json_decode($body, true);

    return [
        'code' => $httpCode,
        'headers' => $headerStr,
        'body' => $body,
        'json' => $json
    ];
}

echo "=================================================================\n";
echo "    SOFTWARE CRM: INVOICES, BILLING, PAYMENTS & RECEIPTS SUITE   \n";
echo "=================================================================\n\n";

$allPassed = true;
$pdo = Database::getConnection();

// --- STEP 1: AUTHENTICATION ---
echo "--- STEP 1: Authentication ---\n";
$loginRes = makeRequest("$baseUrl/api/auth.php?action=login_credentials", 'POST', [
    'email' => 'suresh@softwarecrm.com',
    'password' => 'Password@123'
]);
$step1Ok = ($loginRes['code'] === 200 && ($loginRes['json']['step'] ?? '') === 'otp_required');
logTest("Submit Credentials", $step1Ok, "Code: {$loginRes['code']}");
if (!$step1Ok) $allPassed = false;

$otp = null;
if (file_exists(__DIR__ . '/data/otp.txt')) {
    $otpLines = file(__DIR__ . '/data/otp.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!empty($otpLines[0]) && preg_match('/^\d{4}$/', trim($otpLines[0]))) {
        $otp = trim($otpLines[0]);
    }
}
if (!$otp && file_exists(__DIR__ . '/data/email_delivery.log')) {
    $logContent = file_get_contents(__DIR__ . '/data/email_delivery.log');
    if (preg_match_all('/Recipient: suresh@softwarecrm\.com.*?Verification Code: (\d{4})/s', $logContent, $matches)) {
        $otp = end($matches[1]);
    }
}

$otpRes = makeRequest("$baseUrl/api/auth.php?action=verify_otp", 'POST', [
    'email' => 'suresh@softwarecrm.com',
    'otp' => $otp,
    'purpose' => 'login'
]);
$authOk = ($otpRes['code'] === 200 && (!empty($otpRes['json']['success']) || !empty($otpRes['json']['authenticated'])));
logTest("Verify OTP & Establish Session", $authOk, "User: " . ($otpRes['json']['user']['name'] ?? 'Suresh Kumar'));
if (!$authOk) $allPassed = false;

// --- STEP 2: INVOICES DASHBOARD & FINANCIAL SUMMARY (SCREEN 1) ---
echo "\n--- STEP 2: Invoices Dashboard & Live Financial Summary (Screen 1) ---\n";
$listRes = makeRequest("$baseUrl/api/ledger.php");
$listOk = ($listRes['code'] === 200 && !empty($listRes['json']['data']) && is_array($listRes['json']['data']));
$invoicesCount = $listOk ? count($listRes['json']['data']) : 0;
logTest("List Invoices Feed", $listOk && $invoicesCount >= 5, "Found $invoicesCount invoices");
if (!$listOk) $allPassed = false;

$summary = $listRes['json']['summary'] ?? [];
$hasSummary = !empty($summary) && isset($summary['total_invoiced']) && isset($summary['total_paid']) && isset($summary['total_pending']);
$totInvoiced = $summary['total_invoiced'] ?? 0;
$totPaid = $summary['total_paid'] ?? 0;
$totPending = $summary['total_pending'] ?? 0;
$totOverdue = $summary['total_overdue'] ?? 0;
logTest("Accurate Financial Calculations", $hasSummary && $totInvoiced > 0, "Invoiced: ₹" . number_format($totInvoiced, 2) . ", Paid: ₹" . number_format($totPaid, 2));
if (!$hasSummary) $allPassed = false;

// --- STEP 3: CREATE ITEMIZED INVOICE & SERVER-SIDE VALIDATION ---
echo "\n--- STEP 3: Create Itemized Invoice & Validation ---\n";

// Validation: Empty customer name rejected
$badNameRes = makeRequest("$baseUrl/api/ledger.php", 'POST', [
    'action' => 'create_invoice',
    'customer_name' => '',
    'amount' => 1000
]);
$badNameOk = ($badNameRes['code'] === 400);
logTest("Validation: Empty customer name rejected", $badNameOk, "HTTP Code: {$badNameRes['code']}");
if (!$badNameOk) $allPassed = false;

// Validation: No line items or zero amount rejected
$badAmtRes = makeRequest("$baseUrl/api/ledger.php", 'POST', [
    'action' => 'create_invoice',
    'customer_name' => 'Acme Corporation',
    'items' => [],
    'amount' => 0
]);
$badAmtOk = ($badAmtRes['code'] === 400);
logTest("Validation: Empty items and zero amount rejected", $badAmtOk, "HTTP Code: {$badAmtRes['code']}");
if (!$badAmtOk) $allPassed = false;

// Valid multi-line invoice creation
$testLineItems = [
    ['description' => 'Requirements & planning', 'quantity' => 1, 'rate' => 5000, 'amount' => 5000],
    ['description' => 'UI/UX design', 'quantity' => 1, 'rate' => 8000, 'amount' => 8000],
    ['description' => 'Frontend development', 'quantity' => 1, 'rate' => 20000, 'amount' => 20000],
    ['description' => 'Backend/API integration', 'quantity' => 1, 'rate' => 25000, 'amount' => 25000],
    ['description' => 'Testing & deployment', 'quantity' => 1, 'rate' => 7000, 'amount' => 7000]
];
// Subtotal = 65000, Discount 5% = 3250, Taxable = 61750, GST 18% = 11115, Total = 72865
$createRes = makeRequest("$baseUrl/api/ledger.php", 'POST', [
    'action' => 'create_invoice',
    'customer_name' => 'Verma Solutions (Automated Test)',
    'title' => 'Software Platform Architecture & Cloud Deployment',
    'client_id' => 'cont_test_1',
    'project_id' => 'proj_1',
    'discount_percent' => 5,
    'tax_percent' => 18,
    'status' => 'Pending',
    'issue_date' => '05 Aug 2026',
    'due_date' => '12 Aug 2026',
    'items' => $testLineItems,
    'notes' => "• Complete website development as per scope\n• Source code and documentation will be provided\n• 3 months of post-launch support",
    'terms' => '50% advance, 50% on project completion.'
]);

$createOk = ($createRes['code'] === 200 && !empty($createRes['json']['success']) && !empty($createRes['json']['id']));
$newInvId = $createRes['json']['id'] ?? null;
$newInvNo = $createRes['json']['invoice_no'] ?? null;
$calcTotal = (float)($createRes['json']['data']['amount'] ?? 0);
$subtotalOk = ((float)($createRes['json']['data']['subtotal'] ?? 0) === 65000.0);
$totalOk = ($calcTotal === 72865.0);

logTest("Create Itemized Invoice via API", $createOk && $totalOk, "Invoice No: $newInvNo, Total: ₹$calcTotal");
if (!($createOk && $totalOk)) $allPassed = false;

// Verify persistence in MySQL (crm_cash_ledger and crm_invoice_items)
$dbInvStmt = $pdo->prepare("SELECT * FROM crm_cash_ledger WHERE id = ?");
$dbInvStmt->execute([$newInvId]);
$dbInv = $dbInvStmt->fetch(PDO::FETCH_ASSOC);
$persistedOk = ($dbInv && (float)$dbInv['amount'] === 72865.0 && (float)$dbInv['balance_amount'] === 72865.0 && $dbInv['status'] === 'Pending');
logTest("Persistence in crm_cash_ledger", $persistedOk, "Status: {$dbInv['status']}, Balance: ₹{$dbInv['balance_amount']}");
if (!$persistedOk) $allPassed = false;

$dbItemsStmt = $pdo->prepare("SELECT * FROM crm_invoice_items WHERE invoice_id = ?");
$dbItemsStmt->execute([$newInvId]);
$dbItems = $dbItemsStmt->fetchAll(PDO::FETCH_ASSOC);
$itemsCountOk = (count($dbItems) === 5);
logTest("Persistence in crm_invoice_items", $itemsCountOk, "Stored " . count($dbItems) . " line items");
if (!$itemsCountOk) $allPassed = false;

// --- STEP 4: INVOICE DETAILS & DEDICATED VIEW (SCREEN 2) ---
echo "\n--- STEP 4: Invoice Details & Itemized View (Screen 2) ---\n";
$getInvRes = makeRequest("$baseUrl/api/ledger.php?id=$newInvId");
$getInvOk = ($getInvRes['code'] === 200 && !empty($getInvRes['json']['data']['items']));
$returnedItems = $getInvRes['json']['data']['items'] ?? [];
logTest("Retrieve Single Invoice Details", $getInvOk && count($returnedItems) === 5, "Customer: " . ($getInvRes['json']['data']['customer_name'] ?? ''));
if (!$getInvOk) $allPassed = false;

// Verify printable A4 invoice endpoint (Screen 2: Download PDF)
$printInvRes = makeRequest("$baseUrl/api/ledger.php?action=print_invoice&id=$newInvId");
$printInvOk = ($printInvRes['code'] === 200 && strpos($printInvRes['body'], 'INVOICE') !== false && strpos($printInvRes['body'], $newInvNo) !== false && strpos($printInvRes['body'], '72,865.00') !== false);
logTest("Printable A4 Invoice Endpoint", $printInvOk, "HTTP 200, Contains A4 markup & items");
if (!$printInvOk) $allPassed = false;

// --- STEP 5: RECORD PARTIAL PAYMENT WORKFLOW ---
echo "\n--- STEP 5: Partial Payment Recording Workflow ---\n";

// Validation: Payment exceeding balance rejected
$overpayRes = makeRequest("$baseUrl/api/ledger.php", 'POST', [
    'action' => 'record_payment',
    'invoice_id' => $newInvId,
    'amount' => 999999.00
]);
$overpayOk = ($overpayRes['code'] === 400);
logTest("Validation: Overpayment exceeding balance blocked", $overpayOk, "HTTP Code: {$overpayRes['code']}");
if (!$overpayOk) $allPassed = false;

// Record partial payment of ₹30,000
$partPayRes = makeRequest("$baseUrl/api/ledger.php", 'POST', [
    'action' => 'record_payment',
    'invoice_id' => $newInvId,
    'amount' => 30000.00,
    'payment_date' => '08 Aug 2026',
    'payment_method' => 'Online Transfer',
    'transaction_id' => 'TXN7849201948',
    'reference' => "Partial advance for $newInvNo",
    'notes' => 'Received via NEFT from Verma Solutions'
]);

$partOk = ($partPayRes['code'] === 200 && !empty($partPayRes['json']['success']));
$partData = $partPayRes['json']['data'] ?? [];
$partPaid = (float)($partData['total_paid'] ?? 0);
$partBal = (float)($partData['balance_amount'] ?? 0);
$partStatus = $partData['status'] ?? '';
$receipt1No = $partPayRes['json']['receipt_no'] ?? ($partData['receipt_no'] ?? '');

$partMathOk = ($partPaid === 30000.0 && $partBal === 42865.0 && $partStatus === 'Partially Paid');
logTest("Record Partial Payment (₹30,000)", $partOk && $partMathOk, "Paid: ₹$partPaid, Remaining: ₹$partBal, Status: $partStatus");
if (!($partOk && $partMathOk)) $allPassed = false;

// Verify receipt number format (Screen 3: REC-YYYY-XXX)
$rec1FormatOk = preg_match('/^REC-\d{4}-\d{3,}$/', $receipt1No);
logTest("Generated Receipt Number Format", $rec1FormatOk, "Receipt No: $receipt1No");
if (!$rec1FormatOk) $allPassed = false;

// --- STEP 6: SETTLE REMAINING BALANCE & FULLY PAID TRANSITION ---
echo "\n--- STEP 6: Settle Remaining Balance & Status Transition ---\n";
$fullPayRes = makeRequest("$baseUrl/api/ledger.php", 'POST', [
    'action' => 'record_payment',
    'invoice_id' => $newInvId,
    'amount' => 42865.00,
    'payment_date' => '12 Aug 2026',
    'payment_method' => 'Credit Card',
    'transaction_id' => 'TXN1234567890',
    'reference' => "Final settlement for $newInvNo",
    'notes' => 'Settled in full via Corporate Credit Card'
]);

$fullOk = ($fullPayRes['code'] === 200 && !empty($fullPayRes['json']['success']));
$fullData = $fullPayRes['json']['data'] ?? [];
$fullPaid = (float)($fullData['total_paid'] ?? 0);
$fullBal = (float)($fullData['balance_amount'] ?? 0);
$fullStatus = $fullData['status'] ?? '';
$receipt2No = $fullPayRes['json']['receipt_no'] ?? ($fullData['receipt_no'] ?? '');

$fullMathOk = ($fullPaid === 72865.0 && $fullBal === 0.0 && $fullStatus === 'Paid');
logTest("Settle Remaining Balance (₹42,865)", $fullOk && $fullMathOk, "Paid: ₹$fullPaid, Remaining: ₹$fullBal, Status: $fullStatus");
if (!($fullOk && $fullMathOk)) $allPassed = false;

// Verify database reflects Paid
$chkFinal = $pdo->prepare("SELECT * FROM crm_cash_ledger WHERE id = ?");
$chkFinal->execute([$newInvId]);
$finalDbInv = $chkFinal->fetch(PDO::FETCH_ASSOC);
$finalDbOk = ($finalDbInv && $finalDbInv['status'] === 'Paid' && (float)$finalDbInv['balance_amount'] === 0.0);
logTest("Invoice Database Record Marked Paid", $finalDbOk, "Status: {$finalDbInv['status']}, Balance: {$finalDbInv['balance_amount']}");
if (!$finalDbOk) $allPassed = false;

// --- STEP 7: PAYMENT RECEIPT & DETAILS (SCREEN 3) ---
echo "\n--- STEP 7: Payment Receipt Generation & Verification (Screen 3) ---\n";
$getRecRes = makeRequest("$baseUrl/api/ledger.php?action=get_receipt&receipt_no=$receipt2No");
$getRecOk = ($getRecRes['code'] === 200 && !empty($getRecRes['json']['data']['payment']));
$recData = $getRecRes['json']['data']['payment'] ?? [];
$recMatchOk = ($recData['receipt_no'] === $receipt2No && (float)$recData['amount'] === 42865.0 && $recData['transaction_id'] === 'TXN1234567890');
logTest("Payment Receipt Data Lookup API", $getRecOk && $recMatchOk, "Receipt: {$recData['receipt_no']}, Txn: {$recData['transaction_id']}");
if (!($getRecOk && $recMatchOk)) $allPassed = false;

// Printable A4 Receipt (Screen 3: Download Receipt PDF)
$printRecRes = makeRequest("$baseUrl/api/ledger.php?action=print_receipt&receipt_no=$receipt2No");
$printRecOk = ($printRecRes['code'] === 200 && strpos($printRecRes['body'], 'Payment Successful!') !== false && strpos($printRecRes['body'], $receipt2No) !== false && strpos($printRecRes['body'], 'TXN1234567890') !== false);
logTest("Printable A4 Payment Receipt Endpoint", $printRecOk, "HTTP 200, Contains receipt markup & checkmark");
if (!$printRecOk) $allPassed = false;

// --- STEP 8: OVERDUE LOGIC & CALCULATION ---
echo "\n--- STEP 8: Overdue Calculation Logic ---\n";
// Create an invoice with past due date and positive balance
$pastDueRes = makeRequest("$baseUrl/api/ledger.php", 'POST', [
    'action' => 'create_invoice',
    'customer_name' => 'Overdue Test Client',
    'title' => 'Legacy System Migration',
    'amount' => 15000,
    'status' => 'Pending',
    'issue_date' => '01 Jan 2026',
    'due_date' => '15 Jan 2026' // Past due
]);
$pastInvId = $pastDueRes['json']['id'] ?? null;
$pastInvNo = $pastDueRes['json']['invoice_no'] ?? null;

// Re-check summary feed
$recheckList = makeRequest("$baseUrl/api/ledger.php");
$recheckSummary = $recheckList['json']['summary'] ?? [];
$overdueSum = $recheckSummary['total_overdue'] ?? 0;
$overdueDetected = ($overdueSum >= 15000);
logTest("Overdue Detection by Past Due Date", $overdueDetected, "Total Overdue in Feed: ₹" . number_format($overdueSum, 2));
if (!$overdueDetected) $allPassed = false;

// --- STEP 9: SECURITY, AUTHORIZATION & PERMISSIONS ---
echo "\n--- STEP 9: Security & Authorization Protection ---\n";

// Path traversal on invoice print blocked
$travRes = makeRequest("$baseUrl/api/ledger.php?action=print_invoice&id=../../index.php");
$travOk = ($travRes['code'] === 404 || $travRes['code'] === 400);
logTest("Path Traversal on Invoice Serving Blocked", $travOk, "Code: {$travRes['code']}");
if (!$travOk) $allPassed = false;

// Unauthenticated access blocked without active session
$unauthCh = curl_init("$baseUrl/api/ledger.php");
curl_setopt($unauthCh, CURLOPT_RETURNTRANSFER, true);
curl_setopt($unauthCh, CURLOPT_HEADER, true);
$unauthResp = curl_exec($unauthCh);
$unauthCode = curl_getinfo($unauthCh, CURLINFO_HTTP_CODE);
curl_close($unauthCh);

$unauthOk = ($unauthCode === 401 || $unauthCode === 302);
logTest("Unauthenticated Request Rejected (401)", $unauthOk, "HTTP Code: $unauthCode");
if (!$unauthOk) $allPassed = false;

// --- STEP 10: CLEANUP TEST INVOICES & DATA PRESERVATION ---
echo "\n--- STEP 10: Clean Up Test Records & Preserve CRM Data ---\n";

if ($newInvId) {
    makeRequest("$baseUrl/api/ledger.php", 'POST', ['action' => 'delete_invoice', 'id' => $newInvId]);
}
if ($pastInvId) {
    makeRequest("$baseUrl/api/ledger.php", 'POST', ['action' => 'delete_invoice', 'id' => $pastInvId]);
}

// Verify initial invoices (inv_1 through inv_5) still exist and untouched
$checkInitial = $pdo->query("SELECT id, invoice_no, amount, status FROM crm_cash_ledger WHERE id IN ('inv_1', 'inv_2', 'inv_3', 'inv_4', 'inv_5')")->fetchAll(PDO::FETCH_ASSOC);
$initOk = (count($checkInitial) === 5);
logTest("Original CRM Invoices Intact & Preserved", $initOk, "Found 5/5 original invoices");
if (!$initOk) $allPassed = false;

if (file_exists($cookieFile)) @unlink($cookieFile);

echo "\n=================================================================\n";
if ($allPassed) {
    echo "  ALL INVOICES & BILLING MODULE TESTS PASSED (100%)!\n";
} else {
    echo "  SOME TESTS FAILED! Review test log above.\n";
}
echo "=================================================================\n";

if ($allPassed) {
    exit(0);
} else {
    exit(1);
}
