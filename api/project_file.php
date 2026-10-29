<?php
/**
 * Software CRM - Secure Project Attachment Viewer & Download Gateway
 * Endpoint: api/project_file.php?id=pf_...[&download=1]
 */

require_once __DIR__ . '/session_guard.php';
SessionGuard::requireAuth(true);
require_once __DIR__ . '/db.php';
$pdo = Database::getConnection();

$fileId = trim($_GET['id'] ?? '');
if (empty($fileId) || !preg_match('/^[a-zA-Z0-9_\-]+$/', $fileId)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Invalid file identifier']);
    exit;
}

if (!$pdo) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM crm_project_files WHERE id = ?");
    $stmt->execute([$fileId]);
    $fileRecord = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$fileRecord) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Attachment not found or access denied']);
        exit;
    }

    // Secure path resolution & Path Traversal Prevention
    $baseUploadDir = realpath(dirname(__DIR__) . '/uploads/project_files');
    $relativeStored = $fileRecord['file_path'];
    $fullPath = realpath(dirname(__DIR__) . '/' . $relativeStored);

    if (!$fullPath || !file_exists($fullPath) || strpos($fullPath, $baseUploadDir) !== 0) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Attachment file missing from storage disk']);
        exit;
    }

    $ext = strtolower(pathinfo($fileRecord['original_name'], PATHINFO_EXTENSION));
    $mimeType = $fileRecord['mime_type'];
    if (empty($mimeType) || $mimeType === 'application/octet-stream') {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = $finfo ? finfo_file($finfo, $fullPath) : 'application/octet-stream';
    }

    $viewableExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'pdf', 'txt', 'csv', 'json'];
    $isDownload = isset($_GET['download']) && ($_GET['download'] === '1' || $_GET['download'] === 'true');
    $canPreview = in_array($ext, $viewableExtensions) && !$isDownload;

    $disposition = $canPreview ? 'inline' : 'attachment';
    $safeFilename = str_replace(['"', "\r", "\n"], '', $fileRecord['original_name']);

    if (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: ' . $disposition . '; filename="' . $safeFilename . '"');
    header('Content-Length: ' . filesize($fullPath));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=86400');

    readfile($fullPath);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Internal server error serving attachment']);
    exit;
}
