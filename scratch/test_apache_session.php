<?php
require_once __DIR__ . '/../api/session_guard.php';
header('Content-Type: application/json');
echo json_encode([
    'php_sapi' => PHP_SAPI,
    'session_id' => session_id(),
    'session_name' => session_name(),
    'cookie_params' => session_get_cookie_params(),
    'session_status' => session_status(),
    'ini_cookie_secure' => ini_get('session.cookie_secure'),
    'ini_cookie_httponly' => ini_get('session.cookie_httponly'),
    'ini_cookie_samesite' => ini_get('session.cookie_samesite'),
    'ini_cookie_path' => ini_get('session.cookie_path'),
    'ini_cookie_domain' => ini_get('session.cookie_domain'),
    'server_name' => $_SERVER['SERVER_NAME'] ?? '',
    'http_host' => $_SERVER['HTTP_HOST'] ?? '',
    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? ''
], JSON_PRETTY_PRINT);
