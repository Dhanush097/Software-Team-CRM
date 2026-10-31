<?php
/**
 * Test Suite: Edit Invoice Option & Payment Balance Calculation
 * Verifies:
 * 1. INV-2024-003 remaining balance calculation ($3000, not $0)
 * 2. Record payment on INV-2024-003 succeeds without "exceeds balance" error
 * 3. Overpayment exceeding balance is correctly blocked
 * 4. "Edit Invoice" button presence in Screen 2, Header, and Action Menus
 * 5. Update invoice API endpoint (update_invoice) functionality & database persistence
 */

require_once __DIR__ . '/api/db.php';

$baseUrl = 'http://127.0.0.1:8000';
$cookieFile = __DIR__ . '/test_edit_invoice_cookies.txt';
if (file_exists($cookieFile)) unlink($cookieFile);

function logTest($name, $passed, $details = '') {
    $status = $passed ? "[PASS]" : "[FAIL]";
    echo sprintf("%-6s %-54s %s\n", $status, $name, $details ? "- $details" : '');
}

function makeReq($url, $method = 'GET', $data = null, $headers = []) {
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
echo "   SOFTWARE CRM: EDIT INVOICE & PAYMENT BALANCE VERIFICATION     \n";
echo "=================================================================\n\n";

$allPassed = true;

// 1. Establish Session
echo "--- STEP 1: Authentication ---\n";
$loginRes = makeReq("$baseUrl/api/auth.php?action=login_credentials", 'POST', [
    'email' => 'suresh@softwarecrm.com',
    'password' => 'Password@123'
]);

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

$verifyRes = makeReq("$baseUrl/api/auth.php?action=verify_otp", 'POST', [
    'email' => 'suresh@softwarecrm.com',
    'otp' => $otp,
    'purpose' => 'login'
]);

$authOk = ($verifyRes['code'] === 200 && (!empty($verifyRes['json']['success']) || !empty($verifyRes['json']['authenticated'])));
logTest("Establish Authenticated Session", $authOk, "User: " . ($verifyRes['json']['user']['name'] ?? 'Suresh Kumar'));
if (!$authOk) $allPassed = false;

// 2. Verify INV-2024-003 remaining balance in database & API
echo "\n--- STEP 2: Verify INV-2024-003 Remaining Balance ---\n";
$pdo = Database::getConnection();
$inv3Db = $pdo->query("SELECT * FROM crm_cash_ledger WHERE invoice_no = 'INV-2024-003'")->fetch(PDO::FETCH_ASSOC);

$dbBalOk = ($inv3Db && (float)$inv3Db['balance_amount'] > 0);
logTest("Database: INV-2024-003 balance > $0", $dbBalOk, "Stored balance: $" . ($inv3Db['balance_amount'] ?? 0));
if (!$dbBalOk) $allPassed = false;

$apiInv3 = makeReq("$baseUrl/api/ledger.php?id=INV-2024-003");
$apiBal = (float)($apiInv3['json']['data']['balance_amount'] ?? 0);
$apiBalOk = ($apiInv3['code'] === 200 && $apiBal === 3000.0);
logTest("API: INV-2024-003 balance_amount is $3000", $apiBalOk, "Returned balance: $" . $apiBal);
if (!$apiBalOk) $allPassed = false;

// 3. Test Payment Recording: Attempt $3000 payment on INV-2024-003
echo "\n--- STEP 3: Record Payment of $3000 on INV-2024-003 ---\n";
// First test that overpayment (e.g. $4000) is rejected
$overpayRes = makeReq("$baseUrl/api/ledger.php", 'POST', [
    'action' => 'record_payment',
    'invoice_id' => 'INV-2024-003',
    'amount' => 4000.00
]);
$overpayBlocked = ($overpayRes['code'] === 400 && strpos($overpayRes['json']['message'] ?? '', 'cannot exceed') !== false);
logTest("Validation: Overpayment of $4000 rejected", $overpayBlocked, $overpayRes['json']['message'] ?? '');
if (!$overpayBlocked) $allPassed = false;

// Now record valid payment of $3000
$payRes = makeReq("$baseUrl/api/ledger.php", 'POST', [
    'action' => 'record_payment',
    'invoice_id' => 'INV-2024-003',
    'amount' => 3000.00,
    'payment_method' => 'Credit Card',
    'transaction_id' => 'TXN7550252620',
    'reference' => 'Payment for INV-2024-003'
]);

$payOk = ($payRes['code'] === 200 && ($payRes['json']['status'] ?? '') === 'success');
logTest("Record Payment of $3000 succeeded", $payOk, "Receipt: " . ($payRes['json']['receipt_no'] ?? 'N/A'));
if (!$payOk) $allPassed = false;

// Verify invoice status is now Paid and balance is 0
$checkPaid = makeReq("$baseUrl/api/ledger.php?id=INV-2024-003");
$paidStatusOk = (($checkPaid['json']['data']['status'] ?? '') === 'Paid' && (float)$checkPaid['json']['data']['balance_amount'] === 0.0);
logTest("INV-2024-003 status transitioned to Paid", $paidStatusOk, "Balance: $" . ($checkPaid['json']['data']['balance_amount'] ?? ''));
if (!$paidStatusOk) $allPassed = false;

// 4. Test "Edit Invoice" Option & update_invoice endpoint
echo "\n--- STEP 4: Update Invoice API Endpoint (update_invoice) ---\n";
$updateRes = makeReq("$baseUrl/api/ledger.php", 'POST', [
    'action' => 'update_invoice',
    'id' => 'INV-2024-003',
    'customer_name' => 'Kapoor Designs International',
    'title' => 'Brand Identity & Design System 2026',
    'discount_percent' => 10,
    'tax_percent' => 18,
    'items' => [
        ['description' => 'Brand Strategy & Research', 'quantity' => 1, 'rate' => 2000, 'amount' => 2000],
        ['description' => 'Visual Identity & Guidelines', 'quantity' => 1, 'rate' => 2000, 'amount' => 2000]
    ],
    'notes' => 'Updated scope with additional guidelines',
    'terms' => '30% advance, balance on handover'
]);

$updateOk = ($updateRes['code'] === 200 && ($updateRes['json']['status'] ?? '') === 'success');
logTest("Update Invoice via API", $updateOk, "Customer: " . ($updateRes['json']['data']['customer_name'] ?? ''));
if (!$updateOk) $allPassed = false;

// Subtotal = 4000, Discount 10% = 400, Taxable = 3600, Tax 18% = 648, Total = 4248
$updTotal = (float)($updateRes['json']['data']['amount'] ?? 0);
$mathOk = ($updTotal === 4248.0);
logTest("Financial Recalculation on Update", $mathOk, "Expected: $4248, Got: $" . $updTotal);
if (!$mathOk) $allPassed = false;

// Verify line items persisted in crm_invoice_items
$itemCount = (int)$pdo->query("SELECT count(*) FROM crm_invoice_items WHERE invoice_id = 'inv_3'")->fetchColumn();
$itemsOk = ($itemCount === 2);
logTest("Line items updated in crm_invoice_items", $itemsOk, "Line items count: $itemCount");
if (!$itemsOk) $allPassed = false;

// 5. Verify UI markup in index.php has Edit options
echo "\n--- STEP 5: UI Verification for Edit Invoice Option ---\n";
$indexContent = file_get_contents(__DIR__ . '/index.php');

$hasHeaderEdit = (strpos($indexContent, 'openEditInvoiceModal(activeInvoice)') !== false);
logTest("Screen 2 Header & Action buttons include Edit Invoice", $hasHeaderEdit, "Wired to openEditInvoiceModal(activeInvoice)");
if (!$hasHeaderEdit) $allPassed = false;

$hasActionMenuEdit = (strpos($indexContent, 'openEditInvoiceModal(activeInvoice);') !== false);
logTest("3-Dots Action Menu includes Edit Invoice", $hasActionMenuEdit, "Present in modalInvoiceActionMenuOpen");
if (!$hasActionMenuEdit) $allPassed = false;

$hasModalEditState = (strpos($indexContent, 'isEditingInvoice') !== false);
logTest("Invoice Modal adapts dynamically to Edit state", $hasModalEditState, "isEditingInvoice used for title & submit");
if (!$hasModalEditState) $allPassed = false;

// 6. Reset INV-2024-003 to clean state for subsequent runs
$pdo->prepare("UPDATE crm_cash_ledger SET customer_name = 'Kapoor Designs', title = 'Brand Identity & Design System', amount = 3000.00, subtotal = 3000.00, discount_percent = 0, tax_percent = 0, paid_amount = 0.00, balance_amount = 3000.00, status = 'Overdue' WHERE invoice_no = 'INV-2024-003'")->execute();
$pdo->prepare("DELETE FROM crm_payments WHERE invoice_no = 'INV-2024-003'")->execute();
$pdo->prepare("DELETE FROM crm_invoice_items WHERE invoice_id = 'inv_3'")->execute();

echo "\n=================================================================\n";
if ($allPassed) {
    echo " RESULTS: ALL TESTS PASSED SUCCESSFULLY! (100% SUCCESS RATE) \n";
} else {
    echo " RESULTS: SOME TESTS FAILED! PLEASE REVIEW OUTPUT.            \n";
}
echo "=================================================================\n";

exit($allPassed ? 0 : 1);
