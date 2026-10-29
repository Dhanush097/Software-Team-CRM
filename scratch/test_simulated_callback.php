<?php
/**
 * Test Simulated Full Callback Flow with Mock Handler
 */

require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/session_guard.php';
require_once __DIR__ . '/../api/google_auth.php';

$pdo = Database::getConnection();

// Mock Google endpoints
GoogleAuthService::setMockHttpHandler(function($url, $postData) {
    if (strpos($url, 'tokeninfo') !== false) {
        return [
            'code' => 200,
            'body' => json_encode([
                'sub' => '102135245561651215558',
                'aud' => GoogleAuthService::getClientId(),
                'iss' => 'https://accounts.google.com',
                'exp' => time() + 3600,
                'email' => 'vangaridhanush09@gmail.com',
                'email_verified' => 'true',
                'name' => 'Vangari Dhanush datta',
                'picture' => 'https://lh3.googleusercontent.com/a/test'
            ])
        ];
    }
    if (strpos($url, 'oauth2.googleapis.com/token') !== false) {
        return [
            'code' => 200,
            'body' => json_encode([
                'id_token' => 'mock_valid_id_token_123',
                'access_token' => 'mock_access_token_123',
                'expires_in' => 3600
            ])
        ];
    }
    return ['code' => 404, 'body' => '{}'];
});

// 1. Build Auth URL
$init = GoogleAuthService::buildAuthUrl();
$state = $_SESSION['google_oauth_state'];

echo "Initiated auth. Generated state: " . substr($state, 0, 8) . "...\n";

// 2. Simulate Callback
// verifyState
$stateValid = GoogleAuthService::verifyState($state);
echo "State verification: " . ($stateValid ? "PASS" : "FAIL") . "\n";

// exchangeCode
$exchange = GoogleAuthService::exchangeCode('mock_code_abc');
echo "Exchange success: " . ($exchange['success'] ? "PASS" : "FAIL") . "\n";

// verifyIdToken
$verified = GoogleAuthService::verifyIdToken($exchange['id_token']);
echo "ID Token verified: " . ($verified['success'] ? "PASS" : "FAIL") . "\n";
if (!$verified['success']) {
    echo "ID Token Error: " . ($verified['error'] ?? 'Unknown') . "\n";
    exit(1);
}

// authenticateGoogleUser
$authResult = GoogleAuthService::authenticateGoogleUser($pdo, $verified['user']);
echo "Authenticate User: " . ($authResult['success'] ? "PASS" : "FAIL") . "\n";
echo "Resolved User ID: " . ($authResult['user']['id'] ?? 'None') . "\n";
echo "Resolved Role: " . ($authResult['user']['role'] ?? 'None') . "\n";

// Establish session
$_SESSION['auth_user'] = [
    'id'          => (int)$authResult['user']['id'],
    'name'        => $authResult['user']['name'],
    'email'       => $authResult['user']['email'],
    'role'        => $authResult['user']['role'],
    'employee_id' => $authResult['user']['employee_id'] ?? null,
    'avatar_url'  => $authResult['user']['avatar_url'] ?? null
];

echo "Session is authenticated: " . (SessionGuard::isAuthenticated() ? "YES" : "NO") . "\n";
echo "Dashboard target: ../index.php?view=dashboard\n";
