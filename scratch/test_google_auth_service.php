<?php
require_once __DIR__ . '/../api/google_auth.php';

echo "Client ID: " . substr(GoogleAuthService::getClientId(), 0, 20) . "... (len: " . strlen(GoogleAuthService::getClientId()) . ")\n";
echo "Client Secret: [configured: " . (GoogleAuthService::getClientSecret() !== '' ? 'YES' : 'NO') . ", len: " . strlen(GoogleAuthService::getClientSecret()) . "]\n";
echo "Redirect URI: " . GoogleAuthService::getRedirectUri() . "\n";
echo "Is Configured: " . (GoogleAuthService::isConfigured() ? 'YES' : 'NO') . "\n";
