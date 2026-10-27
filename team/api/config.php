<?php
/**
 * Software CRM Mobile - Production Configuration
 * Database settings, headers, and response utilities
 */

// Production Hostinger DB configuration
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'u213657911_crm');
define('DB_USER', getenv('DB_USER') ?: 'u213657911_crm');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'Sarekam@1234');

// Google Apps Script Web App Endpoint for OTP Authentication (from Software_Team_App)
define('APPS_SCRIPT_OTP_URL', getenv('APPS_SCRIPT_OTP_URL') ?: 'https://script.google.com/macros/s/AKfycbz8uzRc2g23mmTbed6dPCuir-x1fVHLQ8lRhvIyfFukEMtLsV5DjRkY1cUtqQbo2G0p/exec');

// Universal standard response helper
function jsonResponse($success, $data = null, $message = '', $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

    $payload = [
        'success'   => $success,
        'message'   => $message,
        'data'      => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    if (!$success) {
        $payload['error'] = $message;
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Handle CORS preflight options
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    http_response_code(200);
    exit;
}
