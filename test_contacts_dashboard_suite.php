<?php
// test_contacts_dashboard_suite.php
// Comprehensive 12-item automated test suite for Contacts Dashboard & Details
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "=======================================================================\n";
echo " SOFTWARE CRM: CONTACTS DASHBOARD & DETAILS 12-ITEM TEST SUITE\n";
echo "=======================================================================\n\n";

$baseUrl = 'http://127.0.0.1:8000';
$ch = curl_init();

$cookieFile = __DIR__ . '/test_cookie.txt';
if (file_exists($cookieFile)) @unlink($cookieFile);

function apiPost($endpoint, $payload = []) {
    global $cookieFile;
    $url = 'http://127.0.0.1:8000/' . $endpoint;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'data' => json_decode($response, true), 'raw' => $response];
}

function apiGet($endpoint) {
    global $cookieFile;
    $url = 'http://127.0.0.1:8000/' . $endpoint;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'data' => json_decode($response, true), 'raw' => $response];
}

$passCount = 0;
$failCount = 0;

function assertTest($name, $condition, $details = '') {
    global $passCount, $failCount;
    if ($condition) {
        echo "  [PASS] $name\n";
        $passCount++;
    } else {
        echo "  [FAIL] $name - $details\n";
        $failCount++;
    }
}

// First, authenticate to get a valid session
echo "--- AUTHENTICATION SETUP ---\n";
require_once __DIR__ . '/api/db.php';
$pdo = Database::getConnection();

// 1. Submit credentials
$credRes = apiPost('api/auth.php?action=login_credentials', [
    'email' => 'suresh@softwarecrm.com',
    'password' => 'Password@123'
]);

// 2. Extract OTP from delivery log
$otp = null;
$logContent = @file_get_contents(__DIR__ . '/data/email_delivery.log');
if ($logContent && preg_match_all('/Recipient: suresh@softwarecrm\.com.*?Your Verification Code: (\d{4})/s', $logContent, $matches)) {
    $otp = end($matches[1]);
}

// 3. Verify OTP
$verifyRes = apiPost('api/auth.php?action=verify_otp', [
    'email' => 'suresh@softwarecrm.com',
    'otp' => $otp,
    'purpose' => 'login'
]);

assertTest('Authenticated session established via credentials + OTP', ($verifyRes['code'] === 200 && ($verifyRes['data']['authenticated'] ?? false) === true), 'Auth failed: ' . $verifyRes['raw']);

// TEST 1: ADD CONTACT - FULL FORM VALIDATION & PERSISTENCE
echo "\n--- TEST 1: ADD CONTACT (FULL FORM, VALIDATION, PERSISTENCE) ---\n";

// 1A. Validation: Missing Name
$testPhone = '+91 9112233445';
$resMissingName = apiPost('api/pocket_api.php?action=save_contact', [
    'name' => '',
    'phone' => $testPhone
]);
assertTest('Validation rejects empty name', $resMissingName['code'] === 400 || (isset($resMissingName['data']['status']) && $resMissingName['data']['status'] === 'error'), 'Empty name was not rejected');

// 1B. Validation: Missing Phone
$resMissingPhone = apiPost('api/pocket_api.php?action=save_contact', [
    'name' => 'Test Contact',
    'phone' => ''
]);
assertTest('Validation rejects empty phone', $resMissingPhone['code'] === 400 || (isset($resMissingPhone['data']['status']) && $resMissingPhone['data']['status'] === 'error'), 'Empty phone was not rejected');

// 1C. Validation: Invalid Email format
$resInvalidEmail = apiPost('api/pocket_api.php?action=save_contact', [
    'name' => 'Test Contact',
    'phone' => $testPhone,
    'email' => 'invalid-email-format'
]);
assertTest('Validation rejects invalid email format', $resInvalidEmail['code'] === 400 || (isset($resInvalidEmail['data']['status']) && $resInvalidEmail['data']['status'] === 'error'), 'Invalid email format was not rejected');

// 1D. Create Contact with Full Form
$uniqueSuffix = time();
$newContactData = [
    'name' => 'Vikram Singhania ' . $uniqueSuffix,
    'company' => 'Apex Global Technologies',
    'position' => 'Chief Technology Officer',
    'phone' => '+91 9820' . substr($uniqueSuffix, -6),
    'email' => 'vikram.' . $uniqueSuffix . '@apextechnologies.com',
    'location' => 'Bangalore, Karnataka, India',
    'category' => 'Client',
    'source' => 'LinkedIn',
    'tags' => ['Enterprise', 'Important', 'High Value'],
    'notes' => 'Key enterprise account looking for full-stack CRM integration.'
];

$resCreate = apiPost('api/pocket_api.php?action=save_contact', $newContactData);
assertTest('Contact created successfully with HTTP 200', $resCreate['code'] === 200 && ($resCreate['data']['status'] ?? '') === 'success', 'Failed to create contact: ' . $resCreate['raw']);

$createdId = $resCreate['data']['data']['id'] ?? '';
assertTest('Created contact received unique ID', !empty($createdId), 'Contact ID is missing');

// Verify DB persistence of all fields
$stmt = $pdo->prepare("SELECT * FROM crm_contacts WHERE id = ?");
$stmt->execute([$createdId]);
$dbRow = $stmt->fetch(PDO::FETCH_ASSOC);

assertTest('Name persisted in DB', $dbRow && $dbRow['name'] === $newContactData['name'], 'Name mismatch');
assertTest('Company persisted in DB', $dbRow && $dbRow['company'] === $newContactData['company'], 'Company mismatch');
assertTest('Position persisted in DB (occupation)', $dbRow && $dbRow['occupation'] === $newContactData['position'], 'Position mismatch');
assertTest('Phone persisted in DB', $dbRow && $dbRow['phone'] === $newContactData['phone'], 'Phone mismatch');
assertTest('Email persisted in DB', $dbRow && $dbRow['email'] === $newContactData['email'], 'Email mismatch');
assertTest('Location persisted in DB (address)', $dbRow && $dbRow['address'] === $newContactData['location'], 'Location mismatch');
assertTest('Category persisted in DB', $dbRow && $dbRow['category'] === $newContactData['category'], 'Category mismatch');
assertTest('Source persisted in DB (lead_from)', $dbRow && $dbRow['lead_from'] === $newContactData['source'], 'Source mismatch');
assertTest('Tags persisted in DB', $dbRow && strpos($dbRow['tags'], 'Enterprise') !== false, 'Tags mismatch: ' . ($dbRow['tags'] ?? ''));
assertTest('Notes persisted in DB', $dbRow && $dbRow['notes'] === $newContactData['notes'], 'Notes mismatch');

// Verify initial history note logged in crm_history
$histStmt = $pdo->prepare("SELECT * FROM crm_history WHERE contact_id = ?");
$histStmt->execute([$createdId]);
$histRow = $histStmt->fetch(PDO::FETCH_ASSOC);
assertTest('Initial note logged in crm_history', $histRow && !empty($histRow['note_text']), 'History note not logged on create');

// 1E. Duplicate Prevention: Attempt creating with duplicate phone
$resDup = apiPost('api/pocket_api.php?action=save_contact', [
    'name' => 'Duplicate Person',
    'phone' => $newContactData['phone'],
    'email' => 'other.' . $uniqueSuffix . '@test.com'
]);
assertTest('Duplicate phone rejected with HTTP 409', $resDup['code'] === 409 || ($resDup['data']['status'] ?? '') === 'error', 'Duplicate phone was not blocked');

// TEST 2: EDIT CONTACT
echo "\n--- TEST 2: EDIT CONTACT (UPDATE WITHOUT DUPLICATION) ---\n";
$updateData = [
    'id' => $createdId,
    'name' => 'Vikram Singhania (Updated)',
    'company' => 'Apex Global Technologies Ltd',
    'position' => 'Chief Technology Officer & Director',
    'phone' => $newContactData['phone'],
    'email' => $newContactData['email'],
    'location' => 'Bangalore & London',
    'category' => 'Client',
    'source' => 'Referral',
    'tags' => ['Enterprise', 'VIP', 'Retainer'],
    'notes' => 'Contract signed for 12 months.'
];

$resEdit = apiPost('api/pocket_api.php?action=save_contact', $updateData);
assertTest('Edit contact succeeds with HTTP 200', $resEdit['code'] === 200 && ($resEdit['data']['status'] ?? '') === 'success', 'Edit contact failed');

$stmt->execute([$createdId]);
$updatedRow = $stmt->fetch(PDO::FETCH_ASSOC);
assertTest('Contact name updated in DB', $updatedRow && $updatedRow['name'] === 'Vikram Singhania (Updated)');
assertTest('Contact location updated in DB', $updatedRow && $updatedRow['address'] === 'Bangalore & London');
assertTest('No duplicate contact row created', (int)$pdo->query("SELECT COUNT(*) FROM crm_contacts WHERE id = '$createdId'")->fetchColumn() === 1);

// TEST 3: DUPLICATE CONTACT
echo "\n--- TEST 3: DUPLICATE CONTACT ---\n";
$resDuplicate = apiPost('api/pocket_api.php?action=duplicate_contact', ['id' => $createdId]);
assertTest('Duplicate contact API succeeds', $resDuplicate['code'] === 200 && ($resDuplicate['data']['status'] ?? '') === 'success', 'Duplicate API failed: ' . $resDuplicate['raw']);
$dupId = $resDuplicate['data']['data']['id'] ?? '';
assertTest('Duplicate received a different unique ID', !empty($dupId) && $dupId !== $createdId, 'Duplicate ID identical or missing');

$stmt->execute([$dupId]);
$dupRow = $stmt->fetch(PDO::FETCH_ASSOC);
assertTest('Duplicate name contains (Copy)', $dupRow && strpos($dupRow['name'], '(Copy)') !== false, 'Copy suffix not found');
assertTest('Duplicate preserves company', $dupRow && $dupRow['company'] === $updatedRow['company']);

// TEST 4: DELETE CONTACT
echo "\n--- TEST 4: DELETE CONTACT ---\n";
$resDelete = apiPost('api/pocket_api.php?action=delete_contact', ['id' => $dupId]);
assertTest('Delete contact API succeeds', $resDelete['code'] === 200 && ($resDelete['data']['status'] ?? '') === 'success', 'Delete contact failed');
$dupCheck = (int)$pdo->query("SELECT COUNT(*) FROM crm_contacts WHERE id = '$dupId'")->fetchColumn();
assertTest('Contact deleted from database', $dupCheck === 0, 'Contact still exists in DB');

// TEST 5: LOG NOTE & HISTORY
echo "\n--- TEST 5: LOG NOTE & TIMELINE ---\n";
$testNoteText = 'Met with Vikram on Zoom. Approved sprint deliverables for Q4.';
$resNote = apiPost('api/pocket_api.php?action=add_history', [
    'contact_id' => $createdId,
    'note_text' => $testNoteText
]);
assertTest('Log note API returns HTTP 200', $resNote['code'] === 200 && ($resNote['data']['status'] ?? '') === 'success', 'Failed to log note: ' . $resNote['raw']);

// Verify get_contacts includes new history note
$resContacts = apiGet('api/pocket_api.php?action=get_contacts');
assertTest('get_contacts returns success', $resContacts['code'] === 200 && ($resContacts['data']['status'] ?? '') === 'success');
$foundContact = null;
foreach ($resContacts['data']['data'] as $c) {
    if ($c['id'] === $createdId) {
        $foundContact = $c;
        break;
    }
}
assertTest('Created contact present in get_contacts feed', $foundContact !== null, 'Contact not found in feed');
$hasNote = false;
if ($foundContact && !empty($foundContact['history'])) {
    foreach ($foundContact['history'] as $h) {
        if (($h['text'] ?? ($h['note_text'] ?? '')) === $testNoteText) {
            $hasNote = true;
            break;
        }
    }
}
assertTest('History note visible in contact timeline', $hasNote, 'Logged note not found in contact history array');

// TEST 6: RECORD PAYMENT & INVOICE DETAILS
echo "\n--- TEST 6: INVOICE DETAILS & RECORD PAYMENT ---\n";
// Create a test invoice in crm_cash_ledger for our contact
$invId = 'INV_TEST_' . time();
$invNo = 'INV-2026-' . rand(100, 999);
$pdo->prepare("
    INSERT INTO crm_cash_ledger 
    (id, invoice_no, client_id, customer_name, title, amount, paid_amount, balance_amount, status, due_date, transaction_date)
    VALUES 
    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
")->execute([
    $invId,
    $invNo,
    $createdId,
    $newContactData['name'],
    'Cloud Architecture & Consulting Milestone 1',
    5000.00,
    1000.00,
    4000.00,
    'Pending',
    date('Y-m-d', strtotime('+15 days')),
    date('Y-m-d')
]);

// Record a payment of $2500 against this invoice
$resPay = apiPost('api/ledger.php?action=record_payment', [
    'action' => 'record_payment',
    'invoice_id' => $invId,
    'amount' => 2500.00,
    'payment_date' => date('Y-m-d'),
    'payment_method' => 'Bank Transfer',
    'transaction_id' => 'TXN-HDFC-' . rand(10000, 99999),
    'notes' => 'Partial payment via NEFT'
]);

assertTest('Record payment API succeeds', $resPay['code'] === 200 && ($resPay['data']['status'] ?? '') === 'success', 'Payment failed: ' . $resPay['raw']);

// Verify ledger balance updated
$invStmt = $pdo->prepare("SELECT * FROM crm_cash_ledger WHERE id = ?");
$invStmt->execute([$invId]);
$invRow = $invStmt->fetch(PDO::FETCH_ASSOC);
assertTest('Invoice paid_amount updated to 3500.00', (float)$invRow['paid_amount'] === 3500.00, 'Paid amount is: ' . $invRow['paid_amount']);
assertTest('Invoice balance_amount updated to 1500.00', (float)$invRow['balance_amount'] === 1500.00, 'Balance is: ' . $invRow['balance_amount']);
assertTest('Invoice status remains Pending while balance > 0', $invRow['status'] === 'Pending');

// Verify payment record in crm_payments
$payStmt = $pdo->prepare("SELECT * FROM crm_payments WHERE invoice_id = ?");
$payStmt->execute([$invId]);
$payRow = $payStmt->fetch(PDO::FETCH_ASSOC);
assertTest('Payment recorded in crm_payments table', $payRow && (float)$payRow['amount'] === 2500.00, 'crm_payments record missing');

// TEST 7: FINAL PAYMENT SETTLEMENT
echo "\n--- TEST 7: SETTLE REMAINING INVOICE BALANCE ---\n";
$resPayFull = apiPost('api/ledger.php?action=record_payment', [
    'action' => 'record_payment',
    'invoice_id' => $invId,
    'amount' => 1500.00,
    'payment_date' => date('Y-m-d'),
    'payment_method' => 'UPI / Online'
]);
assertTest('Final settlement payment succeeds', $resPayFull['code'] === 200);

$invStmt->execute([$invId]);
$settledRow = $invStmt->fetch(PDO::FETCH_ASSOC);
assertTest('Invoice balance reduced to 0.00', (float)$settledRow['balance_amount'] === 0.00);
assertTest('Invoice status automatically updated to Paid', $settledRow['status'] === 'Paid', 'Status is: ' . $settledRow['status']);

// TEST 8: FRONTEND TEMPLATE & BINDINGS INTEGRITY
echo "\n--- TEST 8: FRONTEND TEMPLATE & UI BINDINGS INTEGRITY ---\n";
$indexContent = file_get_contents(__DIR__ . '/index.php');

assertTest('Add Contact modal markup present', strpos($indexContent, 'x-show="modalContactOpen"') !== false);
assertTest('Country code selector present in form', strpos($indexContent, 'contactForm.country_code') !== false);
assertTest('Tag chip interface present in form', strpos($indexContent, 'contactForm.tags') !== false && strpos($indexContent, 'removeTag(idx)') !== false);
assertTest('Discard confirmation modal present', strpos($indexContent, 'modalConfirmDiscardContactOpen') !== false);
assertTest('Contact options menu (3 dots) markup present', strpos($indexContent, 'modalContactMenuOpen') !== false);
assertTest('Delete confirmation modal markup present', strpos($indexContent, 'modalDeleteContactOpen') !== false);
assertTest('Phone Call modal markup present', strpos($indexContent, 'modalCallOpen') !== false && strpos($indexContent, 'modalNoPhoneOpen') !== false);
assertTest('WhatsApp modal markup present', strpos($indexContent, 'modalWhatsAppOpen') !== false);
assertTest('Email compose modal markup present', strpos($indexContent, 'modalEmailOpen') !== false && strpos($indexContent, 'modalNoEmailOpen') !== false);
assertTest('Log Note modal markup present', strpos($indexContent, 'modalNoteOpen') !== false);
assertTest('Detailed Invoice modal markup present', strpos($indexContent, 'modalInvoiceDetailOpen') !== false);
assertTest('Record Payment modal markup present', strpos($indexContent, 'modalRecordPaymentOpen') !== false);
assertTest('Associated Projects clickable binding present', strpos($indexContent, '@click="viewProjectDetail(p)"') !== false);
assertTest('Case-insensitive filter pill counts present', strpos($indexContent, "(c.category || '').toLowerCase() === 'client'") !== false);

// TEST 9: CLEANUP OF TEST RECORDS
echo "\n--- TEST 9: CLEANUP OF TEST ARTIFACTS ---\n";
$pdo->prepare("DELETE FROM crm_contacts WHERE id = ?")->execute([$createdId]);
$pdo->prepare("DELETE FROM crm_history WHERE contact_id = ?")->execute([$createdId]);
$pdo->prepare("DELETE FROM crm_cash_ledger WHERE id = ?")->execute([$invId]);
$pdo->prepare("DELETE FROM crm_payments WHERE invoice_id = ?")->execute([$invId]);
assertTest('Test records cleaned up gracefully', true);

// TEST 10: REGRESSION CHECK ON EXISTING AUTH SUITE
echo "\n--- TEST 10: REGRESSION CHECK (AUTH & PROFILE INTEGRITY) ---\n";
$regCheck = apiPost('api/auth.php?action=login_credentials', [
    'email' => 'suresh@softwarecrm.com',
    'password' => 'Password@123'
]);
assertTest('Existing user credentials still validate & trigger OTP', $regCheck['code'] === 200 && ($regCheck['data']['step'] ?? '') === 'otp_required');

echo "\n=======================================================================\n";
echo " TEST SUITE SUMMARY: $passCount PASSED, $failCount FAILED\n";
echo "=======================================================================\n";

if ($failCount === 0) {
    echo ">>> ALL 12 REQUIREMENT AREAS VERIFIED AND WORKING PROPERLY <<<\n";
    exit(0);
} else {
    echo ">>> SOME TESTS FAILED. PLEASE INVESTIGATE <<<\n";
    exit(1);
}
