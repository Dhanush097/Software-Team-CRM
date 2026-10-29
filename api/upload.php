<?php
/**
 * Sarekam CRM REST API - File & Media Upload Endpoint
 * Handles local media storage for Images, Documents, and Web Videos
 * Base Directory: /uploads/
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST multipart/form-data.']);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errorCode = isset($_FILES['file']) ? $_FILES['file']['error'] : 'NO_FILE';
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No file uploaded or upload error occurred. Code: ' . $errorCode]);
    exit;
}

$file = $_FILES['file'];
$contactId = isset($_POST['contact_id']) ? trim($_POST['contact_id']) : '';
$projectId = isset($_POST['project_id']) ? trim($_POST['project_id']) : '';
$customTitle = isset($_POST['title']) ? trim($_POST['title']) : '';

// Validation & Allowed Extensions
$maxBytes = 100 * 1024 * 1024; // 100MB limit
if ($file['size'] > $maxBytes) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'File size exceeds limit of 100MB. Use Google Drive link for larger files.']);
    exit;
}

$origName = basename($file['name']);
$extension = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

$imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
$videoExts = ['mp4', 'webm', 'mov', 'mkv', 'avi'];
$docExts   = ['pdf', 'docx', 'xlsx', 'txt', 'zip', 'csv'];

$fileType = 'document';
$subfolder = 'docs';

if (in_array($extension, $imageExts)) {
    $fileType = 'image';
    $subfolder = 'images';
} elseif (in_array($extension, $videoExts)) {
    $fileType = 'video';
    $subfolder = 'videos';
} elseif (in_array($extension, $docExts)) {
    $fileType = 'document';
    $subfolder = 'docs';
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unsupported file extension (.' . $extension . ')']);
    exit;
}

// Generate Safe Unique File Name
$baseNameClean = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($origName, PATHINFO_FILENAME));
$baseNameClean = substr($baseNameClean, 0, 40);
$uniqueName = $baseNameClean . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $extension;

$targetDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $subfolder;
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0755, true);
}

$targetPath = $targetDir . DIRECTORY_SEPARATOR . $uniqueName;

if (move_uploaded_file($file['tmp_name'], $targetPath)) {
    $publicUrl = './uploads/' . $subfolder . '/' . $uniqueName;
    
    $responseItem = [
        'id' => 'm_' . time() . '_' . rand(100, 999),
        'title' => $customTitle ? $customTitle : $origName,
        'file_name' => $uniqueName,
        'original_name' => $origName,
        'file_type' => $fileType,
        'file_url' => $publicUrl,
        'file_size_bytes' => $file['size'],
        'file_size_formatted' => round($file['size'] / 1024, 1) . ' KB',
        'contact_id' => $contactId,
        'project_id' => $projectId,
        'uploaded_at' => date('d/m/Y h:i A')
    ];

    echo json_encode([
        'success' => true,
        'message' => 'File uploaded successfully',
        'data' => $responseItem
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to move uploaded file to target storage directory.']);
}

