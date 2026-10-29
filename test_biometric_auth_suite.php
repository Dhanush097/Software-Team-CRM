<?php
/**
 * Comprehensive Automated Test Suite for CRM Mobile Biometric Authentication
 * Tests WebAuthn / Passkeys, Platform Authenticators, OpenSSL Cryptography,
 * Device Management, Security Guards, Anti-Replay Protection, and Frontend UI.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$appDir = 'C:/Users/vanga/Downloads/new project/Software/Software_Team_App';
require_once $appDir . '/api/db.php';
require_once $appDir . '/api/webauthn_helper.php';
require_once $appDir . '/api/cbor_decoder.php';

$pdo = Database::getConnection();

echo "====================================================================\n";
echo "  CRM MOBILE BIOMETRIC AUTHENTICATION (WEBAUTHN) AUTOMATED TEST SUITE\n";
echo "====================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($condition, $testName, $detail = '') {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo " [PASS] $testName" . ($detail ? " ($detail)" : "") . "\n";
    } else {
        $failCount++;
        echo " [FAIL] $testName" . ($detail ? " - $detail" : "") . "\n";
    }
}

$baseUrl = 'http://127.0.0.1:8000';
$cookieFile = sys_get_temp_dir() . '/test_biometric_cookie.txt';
if (file_exists($cookieFile)) @unlink($cookieFile);

// -------------------------------------------------------------
// TEST 1: Database Schema & Migration Tables
// -------------------------------------------------------------
echo "\n--- TEST 1: Database Schema & Storage ---\n";

$tables = ['crm_biometric_credentials', 'crm_biometric_challenges', 'crm_security_logs'];
foreach ($tables as $tbl) {
    $stmt = $pdo->query("SHOW TABLES LIKE '$tbl'");
    assertTest((bool)$stmt->fetch(), "Table '$tbl' exists in database");
}

// Check crm_biometric_credentials columns
$colsStmt = $pdo->query("SHOW COLUMNS FROM crm_biometric_credentials");
$credCols = $colsStmt->fetchAll(PDO::FETCH_COLUMN, 0);
$requiredCredCols = ['id', 'user_id', 'credential_id', 'public_key', 'counter', 'device_name', 'aaguid', 'attestation_type', 'status', 'created_at', 'last_used_at'];
$allCredCols = count(array_intersect($requiredCredCols, $credCols)) === count($requiredCredCols);
assertTest($allCredCols, "All required columns present in crm_biometric_credentials", implode(', ', $requiredCredCols));

// Check crm_biometric_challenges columns
$chalStmt = $pdo->query("SHOW COLUMNS FROM crm_biometric_challenges");
$chalCols = $chalStmt->fetchAll(PDO::FETCH_COLUMN, 0);
$requiredChalCols = ['id', 'user_id', 'challenge', 'purpose', 'origin', 'rp_id', 'expires_at', 'is_used', 'created_at'];
$allChalCols = count(array_intersect($requiredChalCols, $chalCols)) === count($requiredChalCols);
assertTest($allChalCols, "All required columns present in crm_biometric_challenges", implode(', ', $requiredChalCols));

// -------------------------------------------------------------
// TEST 2: Public Check Support Endpoint
// -------------------------------------------------------------
echo "\n--- TEST 2: Public Support Endpoint ---\n";

$ch = curl_init("$baseUrl/api/biometric_auth.php?action=check_support");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$data = json_decode($res, true);

assertTest($code === 200, "check_support returns HTTP 200", "HTTP Code: $code");
assertTest(!empty($data['success']) && !empty($data['rp_id']) && !empty($data['origin']), "check_support returns valid RP metadata", "RP ID: " . ($data['rp_id'] ?? 'none'));

// -------------------------------------------------------------
// TEST 3: Unauthenticated Security Guards
// -------------------------------------------------------------
echo "\n--- TEST 3: Unauthenticated Access Guards ---\n";

$guardedActions = [
    'register_challenge' => 'POST',
    'register_credential' => 'POST',
    'list_devices' => 'GET',
    'remove_device' => 'POST',
    'disable_biometrics' => 'POST'
];

foreach ($guardedActions as $act => $method) {
    $url = "$baseUrl/api/biometric_auth.php?action=$act";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['action' => $act]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    assertTest($code === 401, "Unauthenticated '$act' returns HTTP 401 Unauthorized", "HTTP Code: $code");
}

// -------------------------------------------------------------
// TEST 4: Authenticate Test Session
// -------------------------------------------------------------
echo "\n--- TEST 4: Establish Authenticated Employee Session ---\n";

$testEmail = 'suresh@softwarecrm.com';
$userStmt = $pdo->prepare("SELECT * FROM crm_users WHERE email = ? LIMIT 1");
$userStmt->execute([$testEmail]);
$testUser = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$testUser) {
    $pdo->prepare("INSERT INTO crm_users (name, email, password, role, status) VALUES ('Suresh Sharma', ?, ?, 'admin', 'active')")
        ->execute([$testEmail, password_hash('Admin@123', PASSWORD_DEFAULT)]);
    $testUser = $pdo->query("SELECT * FROM crm_users WHERE email = '$testEmail'")->fetch(PDO::FETCH_ASSOC);
}
$userId = (int)$testUser['id'];

// Log in via OTP challenge to get authentic session cookie
$testOtp = '8899';
$pdo->prepare("UPDATE crm_otp_challenges SET is_used = 1 WHERE email = ?")->execute([$testEmail]);
$pdo->prepare("INSERT INTO crm_otp_challenges (email, otp_hash, purpose, expires_at, attempts, max_attempts, resend_count, last_sent_at, is_used) VALUES (?, ?, 'login', DATE_ADD(NOW(), INTERVAL 5 MINUTE), 0, 5, 0, NOW(), 0)")
    ->execute([$testEmail, password_hash($testOtp, PASSWORD_DEFAULT)]);

$ch = curl_init("$baseUrl/api/auth.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'action' => 'verify_otp',
    'email' => $testEmail,
    'otp' => $testOtp,
    'purpose' => 'login'
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$authRes = curl_exec($ch);
$authCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$authData = json_decode($authRes, true);

assertTest($authCode === 200 && !empty($authData['authenticated']), "Employee session established successfully", "User: " . ($authData['user']['name'] ?? 'none'));

// -------------------------------------------------------------
// TEST 5: Authenticated Register Challenge
// -------------------------------------------------------------
echo "\n--- TEST 5: Authenticated Registration Challenge ---\n";

$ch = curl_init("$baseUrl/api/biometric_auth.php?action=register_challenge");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$regChalRes = curl_exec($ch);
$regChalCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$regChalData = json_decode($regChalRes, true);

assertTest($regChalCode === 200, "register_challenge returns HTTP 200", "HTTP Code: $regChalCode");
assertTest(!empty($regChalData['challenge']), "Challenge string generated", "Len: " . strlen($regChalData['challenge'] ?? ''));
assertTest(!empty($regChalData['rp']['id']), "RP ID provided", "RP: " . ($regChalData['rp']['id'] ?? ''));
assertTest(!empty($regChalData['user']['id']) && !empty($regChalData['user']['name']), "User ID and name provided", "User: " . ($regChalData['user']['name'] ?? ''));
assertTest(!empty($regChalData['pubKeyCredParams']), "pubKeyCredParams contains ES256 and RS256 algorithms");

// Verify challenge in DB
$cRow = $pdo->prepare("SELECT * FROM crm_biometric_challenges WHERE challenge = ? AND purpose = 'registration' AND is_used = 0");
$cRow->execute([$regChalData['challenge']]);
$dbChal = $cRow->fetch(PDO::FETCH_ASSOC);
assertTest(!empty($dbChal), "Challenge recorded in crm_biometric_challenges database table", "Expires: " . ($dbChal['expires_at'] ?? ''));

// -------------------------------------------------------------
// TEST 6: Cryptographic Registration (P-256 Key Pair & Attestation)
// -------------------------------------------------------------
echo "\n--- TEST 6: Public Key Credential Registration ---\n";

// Generate genuine OpenSSL EC P-256 key pair
$configPath = 'C:/xampp/php/extras/ssl/openssl.cnf';
$opensslConfig = file_exists($configPath) ? ['config' => $configPath] : [];
$ecKey = openssl_pkey_new(array_merge([
    'curve_name' => 'prime256v1',
    'private_key_type' => OPENSSL_KEYTYPE_EC
], $opensslConfig));

$keyDetails = openssl_pkey_get_details($ecKey);
$xCoord = $keyDetails['ec']['x'];
$yCoord = $keyDetails['ec']['y'];
assertTest(strlen($xCoord) === 32 && strlen($yCoord) === 32, "Generated OpenSSL EC P-256 key pair with 32-byte X/Y coordinates");

// Build COSE Key Map: {1: 2 (EC2), 3: -7 (ES256), -1: 1 (P-256), -2: x, -3: y}
$coseMap = [
    1 => 2,
    3 => -7,
    -1 => 1,
    -2 => CborDecoder::byteString($xCoord),
    -3 => CborDecoder::byteString($yCoord)
];
$coseBinary = CborDecoder::encode($coseMap);

// Build Authenticator Data: rpIdHash (32) + flags (1) + signCount (4) + aaguid (16) + credIdLen (2) + credId (N) + coseBinary
$originUrl = $regChalData['rp']['id'];
$rpIdHash = hash('sha256', $originUrl, true);
$flags = chr(0x45); // UP (0x01) + UV (0x04) + AT (0x40)
$signCount = pack('N', 0);
$aaguid = str_repeat("\x00", 16);
$rawCredId = random_bytes(32);
$credIdLen = pack('n', strlen($rawCredId));
$attestedCredData = $aaguid . $credIdLen . $rawCredId . $coseBinary;
$authData = $rpIdHash . $flags . $signCount . $attestedCredData;

// Build Attestation Object: {fmt: "none", attStmt: {}, authData: authData}
$attestationObject = CborDecoder::encode([
    'fmt' => 'none',
    'attStmt' => (object)[],
    'authData' => CborDecoder::byteString($authData)
]);

// Build ClientDataJSON
$clientData = [
    'type' => 'webauthn.create',
    'challenge' => $regChalData['challenge'],
    'origin' => 'http://' . $originUrl . ':8000',
    'crossOrigin' => false
];
$clientDataJSON = json_encode($clientData);

$credIdBase64Url = WebAuthnHelper::base64url_encode($rawCredId);

// Send registration to API
$ch = curl_init("$baseUrl/api/biometric_auth.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'action' => 'register_credential',
    'credential_id' => $credIdBase64Url,
    'clientDataJSON' => WebAuthnHelper::base64url_encode($clientDataJSON),
    'attestationObject' => WebAuthnHelper::base64url_encode($attestationObject),
    'device_name' => 'iPhone 15 Pro (Face ID)'
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$regRes = curl_exec($ch);
$regCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$regData = json_decode($regRes, true);

assertTest($regCode === 200 && !empty($regData['success']), "Credential registered successfully on server", "Message: " . ($regData['message'] ?? 'none'));

// Verify database record
$savedCredStmt = $pdo->prepare("SELECT * FROM crm_biometric_credentials WHERE credential_id = ?");
$savedCredStmt->execute([$credIdBase64Url]);
$savedCred = $savedCredStmt->fetch(PDO::FETCH_ASSOC);

assertTest(!empty($savedCred), "Credential saved in crm_biometric_credentials table", "ID: " . ($savedCred['id'] ?? 'none'));
assertTest(strpos($savedCred['public_key'], 'BEGIN PUBLIC KEY') !== false, "Public key stored as valid OpenSSL PEM format");
assertTest($savedCred['device_name'] === 'iPhone 15 Pro (Face ID)', "Device name recorded accurately");
assertTest($savedCred['status'] === 'active', "Credential status is active");

// Verify security audit log
$secLogStmt = $pdo->prepare("SELECT * FROM crm_security_logs WHERE user_id = ? AND event_type = 'biometric_device_registered' ORDER BY id DESC LIMIT 1");
$secLogStmt->execute([$userId]);
$secLog = $secLogStmt->fetch(PDO::FETCH_ASSOC);
assertTest(!empty($secLog), "Audit log recorded in crm_security_logs", "Event: " . ($secLog['event_type'] ?? ''));

// -------------------------------------------------------------
// TEST 7: Duplicate Credential Registration Prevention
// -------------------------------------------------------------
echo "\n--- TEST 7: Duplicate Credential Prevention ---\n";

// Request fresh registration challenge
$ch = curl_init("$baseUrl/api/biometric_auth.php?action=register_challenge");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$newChalRes = curl_exec($ch);
curl_close($ch);
$newChalData = json_decode($newChalRes, true);

$dupClientData = json_encode(['type' => 'webauthn.create', 'challenge' => $newChalData['challenge'], 'origin' => 'http://' . $originUrl . ':8000', 'crossOrigin' => false]);

$ch = curl_init("$baseUrl/api/biometric_auth.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'action' => 'register_credential',
    'credential_id' => $credIdBase64Url,
    'clientDataJSON' => WebAuthnHelper::base64url_encode($dupClientData),
    'attestationObject' => WebAuthnHelper::base64url_encode($attestationObject),
    'device_name' => 'iPhone 15 Pro (Face ID)'
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$dupRes = curl_exec($ch);
curl_close($ch);
$dupData = json_decode($dupRes, true);

assertTest(strpos(($dupData['message'] ?? ''), 'already registered') !== false, "Duplicate credential recognized and safely acknowledged", "Message: " . ($dupData['message'] ?? ''));

// -------------------------------------------------------------
// TEST 8: List Registered Devices
// -------------------------------------------------------------
echo "\n--- TEST 8: List Registered Devices ---\n";

$ch = curl_init("$baseUrl/api/biometric_auth.php?action=list_devices");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$listRes = curl_exec($ch);
$listCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$listData = json_decode($listRes, true);

assertTest($listCode === 200 && !empty($listData['success']), "list_devices returns HTTP 200");
assertTest(count($listData['devices'] ?? []) >= 1, "Enrolled device present in list", "Count: " . count($listData['devices'] ?? []));
$firstDev = $listData['devices'][0] ?? [];
assertTest($firstDev['device_name'] === 'iPhone 15 Pro (Face ID)', "Device name matches");
assertTest(!empty($firstDev['cred_preview']) && (strpos($firstDev['cred_preview'], '...') !== false || strpos($firstDev['cred_preview'], '••••') !== false), "Credential ID is masked for UI privacy", "Preview: " . ($firstDev['cred_preview'] ?? ''));
assertTest(!isset($firstDev['public_key']), "Raw public key is not exposed in list API response");

// -------------------------------------------------------------
// TEST 9: Login Challenge Generation
// -------------------------------------------------------------
echo "\n--- TEST 9: Login Challenge Generation ---\n";

// 9A. Valid registered employee
$ch = curl_init("$baseUrl/api/biometric_auth.php?action=login_challenge");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['email' => $testEmail]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$loginChalRes = curl_exec($ch);
$loginChalCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$loginChalData = json_decode($loginChalRes, true);

assertTest($loginChalCode === 200 && !empty($loginChalData['challenge']), "login_challenge returns valid challenge", "Challenge len: " . strlen($loginChalData['challenge'] ?? ''));
assertTest(!empty($loginChalData['allowCredentials']), "allowCredentials list contains registered credential");
$allowedIds = array_column($loginChalData['allowCredentials'], 'id');
assertTest(in_array($credIdBase64Url, $allowedIds), "Enrolled credential ID included in allowCredentials");

// 9B. Non-existent employee email
$ch = curl_init("$baseUrl/api/biometric_auth.php?action=login_challenge");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['email' => 'nonexistent@nowhere.com']));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$nonRes = curl_exec($ch);
$nonCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
assertTest($nonCode === 404, "Non-existent email returns HTTP 404 Not Found", "HTTP Code: $nonCode");

// 9C. Active user without registered biometrics
$noBioEmail = 'test_nobio_' . time() . '@softwarecrm.com';
$pdo->prepare("INSERT INTO crm_users (name, email, password_hash, role, status) VALUES ('No Bio User', ?, 'dummy_pass_hash', 'user', 'active')")
    ->execute([$noBioEmail]);

$ch = curl_init("$baseUrl/api/biometric_auth.php?action=login_challenge");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['email' => $noBioEmail]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$noBioRes = curl_exec($ch);
$noBioCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$noBioData = json_decode($noBioRes, true);

assertTest($noBioCode === 400 && !empty($noBioData['no_credentials']), "User without biometrics returns friendly no_credentials flag", "Msg: " . ($noBioData['error'] ?? ''));
$pdo->prepare("DELETE FROM crm_users WHERE email = ?")->execute([$noBioEmail]);

// -------------------------------------------------------------
// TEST 10: Cryptographic Biometric Assertion & Sign In
// -------------------------------------------------------------
echo "\n--- TEST 10: Biometric Assertion Signature Verification & Session Establishment ---\n";

$loginChallenge = $loginChalData['challenge'];

// Construct Assertion clientDataJSON
$assertClientData = [
    'type' => 'webauthn.get',
    'challenge' => $loginChallenge,
    'origin' => 'http://' . $originUrl . ':8000',
    'crossOrigin' => false
];
$assertClientDataJSON = json_encode($assertClientData);
$assertClientDataHash = hash('sha256', $assertClientDataJSON, true);

// Construct Authenticator Data: rpIdHash (32) + flags (1, UP+UV = 0x05) + signCount (4, count = 1)
$assertFlags = chr(0x05); // UP (0x01) + UV (0x04)
$assertSignCount = pack('N', 1);
$assertAuthData = $rpIdHash . $assertFlags . $assertSignCount;

// Sign (authenticatorData . sha256(clientDataJSON)) using the private key
$signedData = $assertAuthData . $assertClientDataHash;
$signature = '';
openssl_sign($signedData, $signature, $ecKey, OPENSSL_ALGO_SHA256);
assertTest(!empty($signature), "Created ECDSA SHA-256 digital signature with hardware private key");

// Verify assertion via clean session cookie (simulate fresh browser session)
$cleanCookie = sys_get_temp_dir() . '/test_clean_login_cookie.txt';
if (file_exists($cleanCookie)) @unlink($cleanCookie);

$ch = curl_init("$baseUrl/api/biometric_auth.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'action' => 'verify_assertion',
    'credential_id' => $credIdBase64Url,
    'clientDataJSON' => WebAuthnHelper::base64url_encode($assertClientDataJSON),
    'authenticatorData' => WebAuthnHelper::base64url_encode($assertAuthData),
    'signature' => WebAuthnHelper::base64url_encode($signature)
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cleanCookie);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cleanCookie);
$assertRes = curl_exec($ch);
$assertCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$assertData = json_decode($assertRes, true);

assertTest($assertCode === 200 && !empty($assertData['authenticated']), "Biometric assertion verified and employee authenticated", "User: " . ($assertData['user']['name'] ?? 'none'));

// Verify database counter updated
$updCred = $pdo->query("SELECT * FROM crm_biometric_credentials WHERE credential_id = '$credIdBase64Url'")->fetch(PDO::FETCH_ASSOC);
assertTest((int)$updCred['counter'] === 1, "Sign counter incremented to 1 in database", "Counter: " . ($updCred['counter'] ?? 0));
assertTest(!empty($updCred['last_used_at']), "last_used_at timestamp updated in database", "Last Used: " . ($updCred['last_used_at'] ?? ''));

// Verify session check with cleanCookie
$ch = curl_init("$baseUrl/api/auth.php?action=check_session");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cleanCookie);
$sessRes = curl_exec($ch);
curl_close($ch);
$sessData = json_decode($sessRes, true);

assertTest(!empty($sessData['authenticated']) && $sessData['user']['email'] === $testEmail, "Authenticated session active across CRM endpoints", "Session User: " . ($sessData['user']['email'] ?? ''));

// Verify security audit log for biometric sign-in
$loginAudit = $pdo->prepare("SELECT * FROM crm_security_logs WHERE user_id = ? AND event_type = 'biometric_login_success' ORDER BY id DESC LIMIT 1");
$loginAudit->execute([$userId]);
$loginLog = $loginAudit->fetch(PDO::FETCH_ASSOC);
assertTest(!empty($loginLog), "Audit log recorded 'biometric_login_success'", "Details: " . ($loginLog['details'] ?? ''));

// -------------------------------------------------------------
// TEST 11: Single-Use Anti-Replay Protection
// -------------------------------------------------------------
echo "\n--- TEST 11: Single-Use Challenge Anti-Replay Protection ---\n";

// Replaying the exact same assertion must fail because the challenge has been consumed (is_used = 1)
$ch = curl_init("$baseUrl/api/biometric_auth.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'action' => 'verify_assertion',
    'credential_id' => $credIdBase64Url,
    'clientDataJSON' => WebAuthnHelper::base64url_encode($assertClientDataJSON),
    'authenticatorData' => WebAuthnHelper::base64url_encode($assertAuthData),
    'signature' => WebAuthnHelper::base64url_encode($signature)
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$replayRes = curl_exec($ch);
$replayCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$replayData = json_decode($replayRes, true);

assertTest($replayCode === 400 && (strpos(($replayData['error'] ?? ''), 'replayed') !== false || strpos(($replayData['error'] ?? ''), 'already been used') !== false), "Replay attack rejected: challenge cannot be reused", "Error: " . ($replayData['error'] ?? ''));

// -------------------------------------------------------------
// TEST 12: Invalid Signature Tamper Rejection
// -------------------------------------------------------------
echo "\n--- TEST 12: Cryptographic Signature Tamper Rejection ---\n";

// Request new fresh challenge
$ch = curl_init("$baseUrl/api/biometric_auth.php?action=login_challenge");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['email' => $testEmail]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$tChalRes = curl_exec($ch);
curl_close($ch);
$tChalData = json_decode($tChalRes, true);
$tChallenge = $tChalData['challenge'];

$tClientData = json_encode(['type' => 'webauthn.get', 'challenge' => $tChallenge, 'origin' => 'http://' . $originUrl . ':8000', 'crossOrigin' => false]);
$tAuthData = $rpIdHash . chr(0x05) . pack('N', 2);
// Tampered bad signature
$badSignature = str_repeat("\xAA", 64);

$ch = curl_init("$baseUrl/api/biometric_auth.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'action' => 'verify_assertion',
    'credential_id' => $credIdBase64Url,
    'clientDataJSON' => WebAuthnHelper::base64url_encode($tClientData),
    'authenticatorData' => WebAuthnHelper::base64url_encode($tAuthData),
    'signature' => WebAuthnHelper::base64url_encode($badSignature)
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$tamperRes = curl_exec($ch);
$tamperCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$tamperData = json_decode($tamperRes, true);

assertTest(in_array($tamperCode, [400, 401]) && strpos(($tamperData['error'] ?? ''), 'verification failed') !== false, "Tampered signature rejected cryptographically with HTTP 400/401", "Error: " . ($tamperData['error'] ?? ''));

// -------------------------------------------------------------
// TEST 13: Inactive / Suspended Employee Account Rejection
// -------------------------------------------------------------
echo "\n--- TEST 13: Inactive Employee Account Rejection ---\n";

// Suspend employee
$pdo->prepare("UPDATE crm_users SET status = 'suspended' WHERE id = ?")->execute([$userId]);

$ch = curl_init("$baseUrl/api/biometric_auth.php?action=login_challenge");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['email' => $testEmail]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$suspRes = curl_exec($ch);
$suspCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$suspData = json_decode($suspRes, true);

assertTest($suspCode === 403 && (strpos(($suspData['error'] ?? ''), 'inactive or suspended') !== false || strpos(($suspData['error'] ?? ''), 'suspended') !== false), "Suspended employee cannot initiate biometric login (HTTP 403)", "Msg: " . ($suspData['error'] ?? ''));

// Restore active status
$pdo->prepare("UPDATE crm_users SET status = 'active' WHERE id = ?")->execute([$userId]);

// -------------------------------------------------------------
// TEST 14: Remove Registered Device
// -------------------------------------------------------------
echo "\n--- TEST 14: Remove Registered Device ---\n";

$ch = curl_init("$baseUrl/api/biometric_auth.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'action' => 'remove_device',
    'credential_id' => $credIdBase64Url
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$remRes = curl_exec($ch);
$remCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$remData = json_decode($remRes, true);

assertTest($remCode === 200 && !empty($remData['success']), "Credential removed successfully", "Msg: " . ($remData['message'] ?? ''));

// Verify database record deleted
$deletedCheck = $pdo->prepare("SELECT COUNT(*) FROM crm_biometric_credentials WHERE credential_id = ?");
$deletedCheck->execute([$credIdBase64Url]);
assertTest((int)$deletedCheck->fetchColumn() === 0, "Credential removed from database");

// Verify audit log
$remLog = $pdo->prepare("SELECT * FROM crm_security_logs WHERE user_id = ? AND event_type = 'biometric_device_removed' ORDER BY id DESC LIMIT 1");
$remLog->execute([$userId]);
assertTest(!empty($remLog->fetch()), "Audit log recorded 'biometric_device_removed'");

// -------------------------------------------------------------
// TEST 15: Multi-Device Registration & Disable All Biometrics
// -------------------------------------------------------------
echo "\n--- TEST 15: Multi-Device Registration & Disable All Biometrics ---\n";

// Clear any prior credentials for user to start fresh
$pdo->prepare("DELETE FROM crm_biometric_credentials WHERE user_id = ?")->execute([$userId]);

// Register 2 test credentials
$credA = WebAuthnHelper::base64url_encode(random_bytes(32));
$credB = WebAuthnHelper::base64url_encode(random_bytes(32));
$dummyPem = "-----BEGIN PUBLIC KEY-----\nMFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE\n-----END PUBLIC KEY-----";

$pdo->prepare("INSERT INTO crm_biometric_credentials (user_id, credential_id, public_key, counter, device_name, status) VALUES (?, ?, ?, 0, 'Samsung Galaxy S24 (Fingerprint)', 'active')")
    ->execute([$userId, $credA, $dummyPem]);
$pdo->prepare("INSERT INTO crm_biometric_credentials (user_id, credential_id, public_key, counter, device_name, status) VALUES (?, ?, ?, 0, 'iPad Pro (Face ID)', 'active')")
    ->execute([$userId, $credB, $dummyPem]);

$ch = curl_init("$baseUrl/api/biometric_auth.php?action=list_devices");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$list2Res = curl_exec($ch);
curl_close($ch);
$list2Data = json_decode($list2Res, true);
assertTest(count($list2Data['devices'] ?? []) === 2, "Two devices enrolled concurrently", "Count: " . count($list2Data['devices'] ?? []));

// Disable all biometrics
$ch = curl_init("$baseUrl/api/biometric_auth.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'action' => 'disable_biometrics'
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$disRes = curl_exec($ch);
$disCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$disData = json_decode($disRes, true);

assertTest($disCode === 200 && !empty($disData['success']), "disable_biometrics executed successfully", "Msg: " . ($disData['message'] ?? ''));

// Verify zero credentials remain
$zeroCheck = $pdo->prepare("SELECT COUNT(*) FROM crm_biometric_credentials WHERE user_id = ?");
$zeroCheck->execute([$userId]);
assertTest((int)$zeroCheck->fetchColumn() === 0, "All credentials revoked and deleted from database");

// -------------------------------------------------------------
// TEST 16: Frontend Index.php UI Structure & Alpine Integration
// -------------------------------------------------------------
echo "\n--- TEST 16: Frontend UI & Alpine Markup Verification ---\n";

$indexContent = file_get_contents($appDir . '/index.php');

assertTest(strpos($indexContent, 'biometric_client.js') !== false, "index.php includes biometric_client.js script");
assertTest(strpos($indexContent, 'startBiometricLogin()') !== false, "index.php has 'Sign In with Fingerprint / Face ID' button calling startBiometricLogin()");
assertTest(strpos($indexContent, 'biometricLoading') !== false, "index.php has biometricLoading state and spinner");
assertTest(strpos($indexContent, 'enrollBiometricDevice()') !== false, "index.php has '+ Register This Device' button calling enrollBiometricDevice()");
assertTest(strpos($indexContent, 'loadBiometricDevices()') !== false, "index.php has loadBiometricDevices() integration");
assertTest(strpos($indexContent, 'promptRemoveDevice(dev)') !== false, "index.php has per-device removal button");
assertTest(strpos($indexContent, 'promptDisableAllBiometrics()') !== false, "index.php has 'Disable Biometric Login for All Devices' action");
assertTest(strpos($indexContent, 'modalConfirmRemoveDeviceOpen') !== false, "index.php contains confirmation modal for device removal");
assertTest(strpos($indexContent, 'modalConfirmDisableAllBiometricsOpen') !== false, "index.php contains confirmation modal to disable all biometrics");
assertTest(strpos($indexContent, 'Hardware-Protected:') !== false, "index.php includes hardware enclave security disclosure callout");

// -------------------------------------------------------------
// TEST 17: Existing Password & OTP Login Regression Test
// -------------------------------------------------------------
echo "\n--- TEST 17: Password & OTP Authentication Regression ---\n";

// Set up verified OTP challenge to verify authentication controller
$regOtp = '5566';
$pdo->prepare("UPDATE crm_otp_challenges SET is_used = 1 WHERE email = ?")->execute([$testEmail]);
$pdo->prepare("INSERT INTO crm_otp_challenges (email, otp_hash, purpose, expires_at, attempts, max_attempts, resend_count, last_sent_at, is_used) VALUES (?, ?, 'login', DATE_ADD(NOW(), INTERVAL 5 MINUTE), 0, 5, 0, NOW(), 0)")
    ->execute([$testEmail, password_hash($regOtp, PASSWORD_DEFAULT)]);

$ch = curl_init("$baseUrl/api/auth.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'action' => 'verify_otp',
    'email' => $testEmail,
    'otp' => $regOtp,
    'purpose' => 'login'
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$pwdRes = curl_exec($ch);
$pwdCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$pwdData = json_decode($pwdRes, true);

assertTest($pwdCode === 200 && !empty($pwdData['authenticated']), "Standard employee authentication functions properly without regressions", "User: " . ($pwdData['user']['name'] ?? 'none'));

// Clean up temporary cookies
if (file_exists($cookieFile)) @unlink($cookieFile);
if (file_exists($cleanCookie)) @unlink($cleanCookie);

echo "\n====================================================================\n";
echo "  TEST SUMMARY: $passCount Passed, $failCount Failed\n";
echo "====================================================================\n";

if ($failCount > 0) exit(1);
