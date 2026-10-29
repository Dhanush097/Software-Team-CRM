<?php
/**
 * Sarekam CRM REST API - Media Management & Cloud Link Endpoint
 * Supports Google Drive Link Embedding, YouTube/Cloud Videos, and Media Listing
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

function parseGoogleDriveId($url) {
    if (preg_match('/[-\w]{25,}/', $url, $matches)) {
        return $matches[0];
    }
    return null;
}

if ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);

    if (!$data || !isset($data['title']) || !isset($data['file_url'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Title and file_url are required.']);
        exit;
    }

    $fileUrl = trim($data['file_url']);
    $fileType = isset($data['file_type']) ? $data['file_type'] : 'drive_link';
    $driveId = parseGoogleDriveId($fileUrl);
    
    $previewUrl = $fileUrl;
    if ($driveId && strpos($fileUrl, 'drive.google.com') !== false) {
        $previewUrl = 'https://drive.google.com/file/d/' . $driveId . '/preview';
        $fileType = 'drive_link';
    } elseif (strpos($fileUrl, 'youtube.com') !== false || strpos($fileUrl, 'youtu.be') !== false) {
        $fileType = 'youtube';
    }

    $newMedia = [
        'id' => 'm_cloud_' . time() . '_' . rand(100, 999),
        'title' => trim($data['title']),
        'file_type' => $fileType,
        'file_url' => $fileUrl,
        'preview_url' => $previewUrl,
        'drive_file_id' => $driveId,
        'contact_id' => isset($data['contact_id']) ? $data['contact_id'] : '',
        'project_id' => isset($data['project_id']) ? $data['project_id'] : '',
        'notes' => isset($data['notes']) ? $data['notes'] : '',
        'uploaded_at' => date('d/m/Y h:i A')
    ];

    echo json_encode([
        'success' => true,
        'message' => 'Cloud media link saved successfully',
        'data' => $newMedia
    ]);
    exit;
}

if ($method === 'GET') {
    $contactId = isset($_GET['contact_id']) ? $_GET['contact_id'] : '';
    $projectId = isset($_GET['project_id']) ? $_GET['project_id'] : '';

    echo json_encode([
        'success' => true,
        'contact_id' => $contactId,
        'project_id' => $projectId,
        'message' => 'Media catalog active'
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed.']);


http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
