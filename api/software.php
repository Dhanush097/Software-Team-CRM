<?php
/**
 * Software CRM - Projects Management REST API
 * Supports CRUD, Duplication, Archiving, File Attachments, and Activity Timeline.
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, DELETE, PUT');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/session_guard.php';
SessionGuard::requireAuth(true);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/company_project_matcher.php';
$pdo = Database::getConnection();

function sendResponse($data, $code = 200) {
    if ($code >= 200 && $code < 300) {
        if (!isset($data['success'])) {
            $data['success'] = true;
        }
    } else {
        if (!isset($data['success'])) {
            $data['success'] = false;
        }
    }
    http_response_code($code);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// Helper to log project activity
function logProjectActivity($pdo, $projectId, $title, $description, $userName = null, $type = 'update') {
    if (!$pdo || empty($projectId)) return;
    try {
        if (!$userName) {
            $user = SessionGuard::getUser();
            $userName = $user['name'] ?? 'Team Member';
        }
        $actId = 'act_' . time() . '_' . rand(100, 999);
        $stmt = $pdo->prepare("
            INSERT INTO crm_project_activity 
            (id, project_id, title, description, user_name, activity_type, created_at)
            VALUES 
            (:id, :project_id, :title, :description, :user_name, :activity_type, NOW())
        ");
        $stmt->execute([
            ':id' => $actId,
            ':project_id' => $projectId,
            ':title' => $title,
            ':description' => $description,
            ':user_name' => $userName,
            ':activity_type' => $type
        ]);
    } catch (Exception $e) {
        // Silently log or ignore activity insertion failure
    }
}

// =========================================================================
// GET: LIST PROJECTS, SINGLE PROJECT, FILES, VIEW FILE, OR ACTIVITY
// =========================================================================
if ($method === 'GET') {
    if (!$pdo) {
        sendResponse(['error' => 'Database connection failed'], 500);
    }

    $action = $_GET['action'] ?? '';

    // 1. VIEW / STREAM PROJECT ATTACHMENT FILE (SECURE ENDPOINT)
    if ($action === 'view_file') {
        $fileId = trim($_GET['id'] ?? '');
        if (empty($fileId) || !preg_match('/^[a-zA-Z0-9_\-]+$/', $fileId)) {
            sendResponse(['error' => 'Invalid file identifier'], 400);
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM crm_project_files WHERE id = ?");
            $stmt->execute([$fileId]);
            $fileRecord = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$fileRecord) {
                sendResponse(['error' => 'File not found or access denied'], 404);
            }

            // Secure file path resolution and directory traversal protection
            $baseUploadDir = realpath(dirname(__DIR__) . '/uploads/project_files');
            $relativeStored = $fileRecord['file_path'];
            $fullPath = realpath(dirname(__DIR__) . '/' . $relativeStored);

            if (!$fullPath || !file_exists($fullPath) || strpos($fullPath, $baseUploadDir) !== 0) {
                sendResponse(['error' => 'File missing from storage disk'], 404);
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
            sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    // 2. GET FILES FOR PROJECT
    if ($action === 'get_files') {
        $projectId = trim($_GET['project_id'] ?? '');
        if (empty($projectId)) {
            sendResponse(['error' => 'project_id required'], 400);
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM crm_project_files WHERE project_id = ? ORDER BY created_at DESC");
            $stmt->execute([$projectId]);
            $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $formatted = [];
            foreach ($files as $f) {
                $formatted[] = [
                    'id' => $f['id'],
                    'project_id' => $f['project_id'],
                    'name' => $f['original_name'],
                    'original_name' => $f['original_name'],
                    'size' => $f['file_size'],
                    'size_bytes' => (int)$f['file_size_bytes'],
                    'date' => date('d M Y', strtotime($f['created_at'])),
                    'type' => $f['file_type'],
                    'mime_type' => $f['mime_type'],
                    'url' => 'api/software.php?action=view_file&id=' . urlencode($f['id']),
                    'view_url' => 'api/software.php?action=view_file&id=' . urlencode($f['id']),
                    'download_url' => 'api/software.php?action=view_file&id=' . urlencode($f['id']) . '&download=1',
                    'uploaded_by' => $f['uploaded_by']
                ];
            }

            sendResponse([
                'status' => 'success',
                'files' => $formatted,
                'data' => $formatted
            ]);
        } catch (Exception $e) {
            sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    // 3. GET ACTIVITY TIMELINE FOR PROJECT
    if ($action === 'get_activity') {
        $projectId = trim($_GET['project_id'] ?? '');
        if (empty($projectId)) {
            sendResponse(['error' => 'project_id required'], 400);
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM crm_project_activity WHERE project_id = ? ORDER BY created_at DESC");
            $stmt->execute([$projectId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $formatted = [];
            foreach ($rows as $r) {
                $timeAgo = 'Recently';
                $ts = strtotime($r['created_at']);
                $diff = time() - $ts;
                if ($diff < 60) $timeAgo = 'Just now';
                elseif ($diff < 3600) $timeAgo = floor($diff / 60) . ' mins ago';
                elseif ($diff < 86400) $timeAgo = floor($diff / 3600) . ' hours ago';
                elseif ($diff < 172800) $timeAgo = 'Yesterday';
                else $timeAgo = date('d M Y', $ts);

                $formatted[] = [
                    'id' => $r['id'],
                    'project_id' => $r['project_id'],
                    'title' => $r['title'],
                    'description' => $r['description'],
                    'user' => $r['user_name'] ?: 'Team Member',
                    'time' => $timeAgo,
                    'created_at' => $r['created_at'],
                    'activity_type' => $r['activity_type']
                ];
            }

            sendResponse([
                'status' => 'success',
                'activity' => $formatted,
                'data' => $formatted
            ]);
        } catch (Exception $e) {
            sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    // 4. GET SINGLE PROJECT
    if (!empty($_GET['id'])) {
        try {
            $stmt = $pdo->prepare("
                SELECT p.*, c.name AS company_name, c.industry AS company_industry, c.logo_url AS company_logo
                FROM crm_projects p
                LEFT JOIN crm_companies c ON c.id = p.company_id
                WHERE p.id = ?
            ");
            $stmt->execute([trim($_GET['id'])]);
            $project = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$project) {
                sendResponse(['error' => 'Project not found'], 404);
            }
            if (empty($project['company_id']) && !empty($project['title'])) {
                $compMatch = CompanyProjectMatcher::findCompanyForProject($pdo, $project['title']);
                if ($compMatch['status'] === 'exact' && !empty($compMatch['company'])) {
                    $comp = $compMatch['company'];
                    $pdo->prepare("UPDATE crm_projects SET company_id = ? WHERE id = ?")->execute([$comp['id'], $project['id']]);
                    $project['company_id'] = $comp['id'];
                    $project['company_name'] = $comp['name'];
                    $project['company_industry'] = $comp['industry'];
                }
            }
            sendResponse(['status' => 'success', 'data' => $project]);
        } catch (Exception $e) {
            sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    // 5. DEFAULT: LIST ALL PROJECTS
    try {
        $stmt = $pdo->query("
            SELECT p.*, c.name AS company_name, c.industry AS company_industry, c.logo_url AS company_logo
            FROM crm_projects p
            LEFT JOIN crm_companies c ON c.id = p.company_id
            ORDER BY p.created_at DESC
        ");
        $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($projects as &$p) {
            if (empty($p['company_id']) && !empty($p['title'])) {
                $compMatch = CompanyProjectMatcher::findCompanyForProject($pdo, $p['title']);
                if ($compMatch['status'] === 'exact' && !empty($compMatch['company'])) {
                    $comp = $compMatch['company'];
                    $pdo->prepare("UPDATE crm_projects SET company_id = ? WHERE id = ?")->execute([$comp['id'], $p['id']]);
                    $p['company_id'] = $comp['id'];
                    $p['company_name'] = $comp['name'];
                    $p['company_industry'] = $comp['industry'];
                }
            }
        }
        unset($p);
        sendResponse(['status' => 'success', 'data' => $projects]);
    } catch (Exception $e) {
        sendResponse(['error' => $e->getMessage()], 500);
    }
}

// =========================================================================
// POST: CREATE, EDIT, DUPLICATE, ARCHIVE, DELETE, FILE UPLOAD, ACTIVITY
// =========================================================================
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: $_POST;
    $action = $input['action'] ?? ($_GET['action'] ?? '');

    if (!$pdo) {
        sendResponse(['error' => 'Database connection failed'], 500);
    }

    // ---------------------------------------------------------------------
    // A. UPLOAD PROJECT FILE ATTACHMENT
    // ---------------------------------------------------------------------
    if ($action === 'upload_file' || !empty($_FILES['file'])) {
        $projectId = trim($_POST['project_id'] ?? ($input['project_id'] ?? ''));
        if (empty($projectId)) {
            sendResponse(['error' => 'project_id is required'], 400);
        }

        // Verify project exists
        $stmt = $pdo->prepare("SELECT id, title FROM crm_projects WHERE id = ?");
        $stmt->execute([$projectId]);
        $project = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$project) {
            sendResponse(['error' => 'Project not found'], 404);
        }

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errCode = isset($_FILES['file']) ? $_FILES['file']['error'] : 'NO_FILE';
            sendResponse(['error' => 'File upload error: ' . $errCode], 400);
        }

        $file = $_FILES['file'];
        $maxBytes = 50 * 1024 * 1024; // 50MB
        if ($file['size'] > $maxBytes) {
            sendResponse(['error' => 'File exceeds 50MB limit'], 400);
        }

        $origName = basename($file['name']);
        if (empty(trim($origName))) {
            sendResponse(['error' => 'Invalid file name'], 400);
        }

        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $blockedExts = ['php', 'phtml', 'php3', 'php4', 'php5', 'phps', 'exe', 'bat', 'cmd', 'sh', 'vbs', 'js', 'jar'];
        if (in_array($ext, $blockedExts)) {
            sendResponse(['error' => 'Executable file types cannot be uploaded for security.'], 400);
        }

        $targetDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'project_files';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $cleanBase = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($origName, PATHINFO_FILENAME));
        $cleanBase = substr($cleanBase, 0, 40);
        $uniqueStorageName = $cleanBase . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . ($ext ? ('.' . $ext) : '');
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $uniqueStorageName;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            sendResponse(['error' => 'Failed to write uploaded file to storage disk'], 500);
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = $finfo ? finfo_file($finfo, $targetPath) : '';
        if (empty($mimeType)) {
            $extMap = [
                'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
                'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'pdf' => 'application/pdf',
                'txt' => 'text/plain', 'csv' => 'text/csv', 'json' => 'application/json',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'zip' => 'application/zip'
            ];
            $mimeType = $extMap[$ext] ?? 'application/octet-stream';
        }

        $fileType = 'document';
        if (strpos($mimeType, 'image/') === 0 || in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'])) {
            $fileType = 'image';
        } elseif ($ext === 'pdf' || $mimeType === 'application/pdf') {
            $fileType = 'pdf';
        } elseif ($ext === 'txt' || $mimeType === 'text/plain') {
            $fileType = 'text';
        }

        $bytes = (int)$file['size'];
        $formattedSize = ($bytes > 1048576) ? round($bytes / 1048576, 1) . ' MB' : round($bytes / 1024, 1) . ' KB';
        $fileId = 'pf_' . time() . '_' . rand(100, 999);
        $relativePath = 'uploads/project_files/' . $uniqueStorageName;

        $curUser = SessionGuard::getUser();
        $uploadedBy = $curUser['name'] ?? 'Team Member';

        $ins = $pdo->prepare("
            INSERT INTO crm_project_files 
            (id, project_id, file_name, original_name, file_path, file_type, mime_type, file_size, file_size_bytes, uploaded_by, created_at)
            VALUES 
            (:id, :project_id, :file_name, :original_name, :file_path, :file_type, :mime_type, :file_size, :file_size_bytes, :uploaded_by, NOW())
        ");
        $ins->execute([
            ':id' => $fileId,
            ':project_id' => $projectId,
            ':file_name' => $uniqueStorageName,
            ':original_name' => $origName,
            ':file_path' => $relativePath,
            ':file_type' => $fileType,
            ':mime_type' => $mimeType,
            ':file_size' => $formattedSize,
            ':file_size_bytes' => $bytes,
            ':uploaded_by' => $uploadedBy
        ]);

        $filePayload = [
            'id' => $fileId,
            'project_id' => $projectId,
            'name' => $origName,
            'original_name' => $origName,
            'size' => $formattedSize,
            'size_bytes' => $bytes,
            'date' => date('d M Y'),
            'type' => $fileType,
            'mime_type' => $mimeType,
            'url' => 'api/software.php?action=view_file&id=' . urlencode($fileId),
            'view_url' => 'api/software.php?action=view_file&id=' . urlencode($fileId),
            'download_url' => 'api/software.php?action=view_file&id=' . urlencode($fileId) . '&download=1',
            'uploaded_by' => $uploadedBy
        ];

        // Log to activity timeline
        logProjectActivity($pdo, $projectId, 'Files uploaded', "Added $origName by $uploadedBy", $uploadedBy, 'file');

        sendResponse([
            'status' => 'success',
            'message' => 'File uploaded successfully',
            'file' => $filePayload,
            'data' => $filePayload
        ]);
    }

    // ---------------------------------------------------------------------
    // B. DELETE PROJECT FILE
    // ---------------------------------------------------------------------
    if ($action === 'delete_file') {
        $fileId = trim($input['id'] ?? ($_GET['id'] ?? ''));
        if (empty($fileId)) sendResponse(['error' => 'File ID required'], 400);

        try {
            $stmt = $pdo->prepare("SELECT * FROM crm_project_files WHERE id = ?");
            $stmt->execute([$fileId]);
            $fileRecord = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($fileRecord) {
                $baseUploadDir = realpath(dirname(__DIR__) . '/uploads/project_files');
                $fullPath = realpath(dirname(__DIR__) . '/' . $fileRecord['file_path']);
                if ($fullPath && file_exists($fullPath) && strpos($fullPath, $baseUploadDir) === 0) {
                    @unlink($fullPath);
                }
                $del = $pdo->prepare("DELETE FROM crm_project_files WHERE id = ?");
                $del->execute([$fileId]);
            }
            sendResponse(['status' => 'success', 'message' => 'File attachment removed']);
        } catch (Exception $e) {
            sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    // ---------------------------------------------------------------------
    // C. ADD ACTIVITY NOTE
    // ---------------------------------------------------------------------
    if ($action === 'add_activity' || $action === 'add_note') {
        $projectId = trim($input['project_id'] ?? '');
        $note = trim($input['description'] ?? ($input['note'] ?? ''));
        $title = trim($input['title'] ?? 'Note added');

        if (empty($projectId)) sendResponse(['error' => 'Project ID required'], 400);
        if (empty($note) && empty($title)) sendResponse(['error' => 'Note content cannot be empty'], 400);

        $curUser = SessionGuard::getUser();
        $author = $curUser['name'] ?? 'Team Member';
        $type = $input['activity_type'] ?? 'note';

        $actId = 'act_' . time() . '_' . rand(100, 999);
        try {
            $stmt = $pdo->prepare("
                INSERT INTO crm_project_activity 
                (id, project_id, title, description, user_name, activity_type, created_at)
                VALUES 
                (:id, :project_id, :title, :description, :user_name, :activity_type, NOW())
            ");
            $stmt->execute([
                ':id' => $actId,
                ':project_id' => $projectId,
                ':title' => $title,
                ':description' => $note,
                ':user_name' => $author,
                ':activity_type' => $type
            ]);

            sendResponse([
                'status' => 'success',
                'message' => 'Activity note added',
                'data' => [
                    'id' => $actId,
                    'project_id' => $projectId,
                    'title' => $title,
                    'description' => $note,
                    'user' => $author,
                    'time' => 'Just now',
                    'activity_type' => $type
                ]
            ]);
        } catch (Exception $e) {
            sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    // ---------------------------------------------------------------------
    // D. DELETE ACTIVITY NOTE
    // ---------------------------------------------------------------------
    if ($action === 'delete_activity') {
        $actId = trim($input['id'] ?? ($_GET['id'] ?? ''));
        if (empty($actId)) sendResponse(['error' => 'Activity ID required'], 400);

        try {
            $stmt = $pdo->prepare("DELETE FROM crm_project_activity WHERE id = ?");
            $stmt->execute([$actId]);
            sendResponse(['status' => 'success', 'message' => 'Activity note deleted']);
        } catch (Exception $e) {
            sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    // ---------------------------------------------------------------------
    // E. EDIT ACTIVITY NOTE
    // ---------------------------------------------------------------------
    if ($action === 'edit_activity') {
        $actId = trim($input['id'] ?? '');
        $title = trim($input['title'] ?? '');
        $description = trim($input['description'] ?? '');

        if (empty($actId)) sendResponse(['error' => 'Activity ID required'], 400);
        if (empty($title) && empty($description)) sendResponse(['error' => 'Content cannot be empty'], 400);

        try {
            $stmt = $pdo->prepare("UPDATE crm_project_activity SET title = :title, description = :description WHERE id = :id");
            $stmt->execute([':title' => $title, ':description' => $description, ':id' => $actId]);
            sendResponse(['status' => 'success', 'message' => 'Activity note updated']);
        } catch (Exception $e) {
            sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    // ---------------------------------------------------------------------
    // F. DUPLICATE PROJECT
    // ---------------------------------------------------------------------
    if ($action === 'duplicate') {
        $id = trim($input['id'] ?? '');
        if (empty($id)) sendResponse(['error' => 'Project ID required to duplicate'], 400);

        try {
            $stmt = $pdo->prepare("SELECT * FROM crm_projects WHERE id = ?");
            $stmt->execute([$id]);
            $orig = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$orig) {
                sendResponse(['error' => 'Source project not found'], 404);
            }

            $newId = 'proj_' . time() . '_' . rand(100, 999);
            $newTitle = 'Copy of ' . $orig['title'];

            $ins = $pdo->prepare("
                INSERT INTO crm_projects 
                (id, title, client_name, company_id, description, category, status, progress, budget, project_manager, start_date, deadline, team_members, icon, color, archived, created_at)
                VALUES 
                (:id, :title, :client_name, :company_id, :description, :category, 'In Progress', 0, :budget, :project_manager, :start_date, :deadline, :team_members, :icon, :color, 0, NOW())
            ");
            $ins->execute([
                ':id' => $newId,
                ':title' => $newTitle,
                ':client_name' => $orig['client_name'] ?? 'Client',
                ':company_id' => $orig['company_id'] ?? null,
                ':description' => $orig['description'] ?? '',
                ':category' => $orig['category'] ?? 'Web & App',
                ':budget' => $orig['budget'] ?? '$10,000',
                ':project_manager' => $orig['project_manager'] ?? 'Project Manager',
                ':start_date' => $orig['start_date'] ?: date('Y-m-d'),
                ':deadline' => $orig['deadline'] ?: date('Y-m-d', strtotime('+3 months')),
                ':team_members' => $orig['team_members'] ?? '',
                ':icon' => $orig['icon'] ?? 'layout',
                ':color' => $orig['color'] ?? 'blue'
            ]);

            // Copy tasks associated with original project safely
            $taskStmt = $pdo->prepare("SELECT * FROM crm_tasks WHERE project_id = ?");
            $taskStmt->execute([$id]);
            $origTasks = $taskStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($origTasks as $ot) {
                $newTaskKey = 'tsk_' . time() . '_' . rand(100, 999);
                $insTask = $pdo->prepare("
                    INSERT INTO crm_tasks 
                    (id, title, description, client_name, project_name, project_id, assigned_staff, due_time, due_date, priority, category, domain, icon, status, completed, created_at)
                    VALUES 
                    (:id, :title, :description, :client_name, :project_name, :project_id, :assigned_staff, :due_time, :due_date, :priority, :category, :domain, :icon, 'In Progress', 0, NOW())
                ");
                $insTask->execute([
                    ':id' => $newTaskKey,
                    ':title' => $ot['title'],
                    ':description' => $ot['description'] ?? '',
                    ':client_name' => $orig['client_name'] ?? '',
                    ':project_name' => $newTitle,
                    ':project_id' => $newId,
                    ':assigned_staff' => $ot['assigned_staff'] ?? '',
                    ':due_time' => $ot['due_time'] ?? 'Today',
                    ':due_date' => $ot['due_date'] ?? '',
                    ':priority' => $ot['priority'] ?? 'Medium',
                    ':category' => $ot['category'] ?? 'General',
                    ':domain' => 'software.local',
                    ':icon' => $ot['icon'] ?? 'layout'
                ]);
            }

            $curUser = SessionGuard::getUser();
            $author = $curUser['name'] ?? 'Admin';
            logProjectActivity($pdo, $newId, 'Project created', "Project duplicated from {$orig['title']} by $author", $author, 'milestone');

            $fetchNew = $pdo->prepare("SELECT * FROM crm_projects WHERE id = ?");
            $fetchNew->execute([$newId]);
            $newProject = $fetchNew->fetch(PDO::FETCH_ASSOC);

            sendResponse([
                'status' => 'success',
                'message' => 'Project duplicated successfully',
                'id' => $newId,
                'project' => $newProject,
                'data' => $newProject
            ]);
        } catch (Exception $e) {
            sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    // ---------------------------------------------------------------------
    // G. ARCHIVE / UNARCHIVE PROJECT
    // ---------------------------------------------------------------------
    if ($action === 'archive' || $action === 'toggle_archive') {
        $id = trim($input['id'] ?? '');
        if (empty($id)) sendResponse(['error' => 'Project ID required'], 400);

        try {
            $stmt = $pdo->prepare("SELECT id, title, archived FROM crm_projects WHERE id = ?");
            $stmt->execute([$id]);
            $proj = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$proj) sendResponse(['error' => 'Project not found'], 404);

            $newArchived = empty($proj['archived']) ? 1 : 0;
            $upd = $pdo->prepare("UPDATE crm_projects SET archived = ? WHERE id = ?");
            $upd->execute([$newArchived, $id]);

            $curUser = SessionGuard::getUser();
            $author = $curUser['name'] ?? 'Admin';
            $msg = $newArchived ? 'Project archived' : 'Project restored from archive';
            logProjectActivity($pdo, $id, $msg, "$msg by $author", $author, 'status');

            sendResponse([
                'status' => 'success',
                'message' => $msg,
                'archived' => $newArchived,
                'id' => $id
            ]);
        } catch (Exception $e) {
            sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    // ---------------------------------------------------------------------
    // H. DELETE PROJECT
    // ---------------------------------------------------------------------
    if ($action === 'delete') {
        $id = trim($input['id'] ?? '');
        if (empty($id)) sendResponse(['error' => 'Project ID required'], 400);

        try {
            $stmt = $pdo->prepare("SELECT id, title FROM crm_projects WHERE id = ?");
            $stmt->execute([$id]);
            $proj = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$proj) sendResponse(['error' => 'Project not found'], 404);

            // Safely disassociate or clean up project records
            $pdo->prepare("UPDATE crm_tasks SET project_id = NULL WHERE project_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM crm_project_activity WHERE project_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM crm_project_files WHERE project_id = ?")->execute([$id]);

            $del = $pdo->prepare("DELETE FROM crm_projects WHERE id = ?");
            $del->execute([$id]);

            sendResponse(['status' => 'success', 'message' => 'Project deleted successfully']);
        } catch (Exception $e) {
            sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    // ---------------------------------------------------------------------
    // I. CREATE OR UPDATE PROJECT (EDIT PROJECT FORM)
    // ---------------------------------------------------------------------
    $title = trim($input['title'] ?? '');
    if (empty($title)) {
        sendResponse(['error' => 'Project Name is required'], 400);
    }

    $id = trim($input['id'] ?? '');
    $isExisting = false;
    $existingRecord = null;

    if (!empty($id)) {
        $check = $pdo->prepare("SELECT * FROM crm_projects WHERE id = ?");
        $check->execute([$id]);
        $existingRecord = $check->fetch(PDO::FETCH_ASSOC);
        if ($existingRecord) {
            $isExisting = true;
        }
    }

    if (!$isExisting && empty($id)) {
        $id = 'proj_' . time() . '_' . rand(100, 999);
    }

    $clientName = trim($input['client_name'] ?? '');
    if (empty($clientName)) {
        $clientName = $existingRecord ? ($existingRecord['client_name'] ?? 'Client') : 'Client';
    }

    $description = trim($input['description'] ?? ($existingRecord['description'] ?? ''));
    $category = trim($input['category'] ?? ($existingRecord['category'] ?? 'Web & App'));
    $status = trim($input['status'] ?? ($existingRecord['status'] ?? 'In Progress'));
    $progress = isset($input['progress']) ? max(0, min(100, (int)$input['progress'])) : ($existingRecord['progress'] ?? 50);
    $budget = trim($input['budget'] ?? ($existingRecord['budget'] ?? '$10,000'));
    $projectManager = trim($input['project_manager'] ?? ($existingRecord['project_manager'] ?? 'Project Manager'));
    $startDate = !empty($input['start_date']) ? $input['start_date'] : ($existingRecord['start_date'] ?? date('Y-m-d'));
    $deadline = !empty($input['deadline']) ? $input['deadline'] : (!empty($input['due_date']) ? $input['due_date'] : ($existingRecord['deadline'] ?? date('Y-m-d', strtotime('+3 months'))));
    $teamMembers = $input['team_members'] ?? ($existingRecord['team_members'] ?? '');
    $icon = $input['icon'] ?? ($existingRecord['icon'] ?? 'layout');
    $color = $input['color'] ?? ($existingRecord['color'] ?? 'blue');

    // Automatic Company-Project Association
    $companyId = isset($input['company_id']) && $input['company_id'] !== '' ? trim($input['company_id']) : null;
    $autoMatchedCompany = null;

    if (!$isExisting) {
        // WHEN A PROJECT IS CREATED:
        // 1. Check whether a company with the same normalized name exists.
        // 2. If an unambiguous match exists, automatically select and associate that company.
        if (empty($companyId)) {
            $compMatch = CompanyProjectMatcher::findCompanyForProject($pdo, $title);
            if ($compMatch['status'] === 'exact' && !empty($compMatch['company'])) {
                $companyId = $compMatch['company']['id'];
                $autoMatchedCompany = $compMatch['company'];
                if (empty($clientName) || $clientName === 'Client') {
                    $clientName = $compMatch['company']['name'];
                }
            }
        } elseif (!empty($companyId)) {
            $cChk = $pdo->prepare("SELECT id, name FROM crm_companies WHERE id = ?");
            $cChk->execute([$companyId]);
            $cRow = $cChk->fetch(PDO::FETCH_ASSOC);
            if ($cRow && (empty($clientName) || $clientName === 'Client')) {
                $clientName = $cRow['name'];
            }
        }
    } else {
        // WHEN A PROJECT IS EDITED:
        if (array_key_exists('company_id', $input)) {
            $companyId = !empty($input['company_id']) ? trim($input['company_id']) : null;
        } else {
            // Preserve existing relationship
            $companyId = $existingRecord['company_id'] ?? null;
        }

        // If unlinked, check if title matches a company
        if (empty($companyId)) {
            $compMatch = CompanyProjectMatcher::findCompanyForProject($pdo, $title);
            if ($compMatch['status'] === 'exact' && !empty($compMatch['company'])) {
                $companyId = $compMatch['company']['id'];
                $autoMatchedCompany = $compMatch['company'];
                if (empty($clientName) || $clientName === 'Client' || $clientName === ($existingRecord['client_name'] ?? '')) {
                    $clientName = $compMatch['company']['name'];
                }
            }
        }
        // If names no longer match, we preserve existing explicit relationship as required
    }

    try {
        if ($isExisting) {
            $upd = $pdo->prepare("
                UPDATE crm_projects SET
                    title = :title,
                    client_name = :client_name,
                    company_id = :company_id,
                    description = :description,
                    category = :category,
                    status = :status,
                    progress = :progress,
                    budget = :budget,
                    project_manager = :project_manager,
                    start_date = :start_date,
                    deadline = :deadline,
                    team_members = :team_members,
                    icon = :icon,
                    color = :color
                WHERE id = :id
            ");
            $upd->execute([
                ':id' => $id,
                ':title' => $title,
                ':client_name' => $clientName,
                ':company_id' => $companyId,
                ':description' => $description,
                ':category' => $category,
                ':status' => $status,
                ':progress' => $progress,
                ':budget' => $budget,
                ':project_manager' => $projectManager,
                ':start_date' => $startDate,
                ':deadline' => $deadline,
                ':team_members' => $teamMembers,
                ':icon' => $icon,
                ':color' => $color
            ]);

            $curUser = SessionGuard::getUser();
            $author = $curUser['name'] ?? 'Admin';
            logProjectActivity($pdo, $id, 'Project updated', "Project progress updated to $progress% by $author", $author, 'update');

            $fetchUpdated = $pdo->prepare("
                SELECT p.*, c.name AS company_name, c.industry AS company_industry, c.logo_url AS company_logo
                FROM crm_projects p
                LEFT JOIN crm_companies c ON c.id = p.company_id
                WHERE p.id = ?
            ");
            $fetchUpdated->execute([$id]);
            $updatedProject = $fetchUpdated->fetch(PDO::FETCH_ASSOC);

            sendResponse([
                'status' => 'success',
                'message' => 'Project updated successfully',
                'id' => $id,
                'project' => $updatedProject,
                'data' => $updatedProject,
                'auto_matched_company' => $autoMatchedCompany
            ]);
        } else {
            $ins = $pdo->prepare("
                INSERT INTO crm_projects 
                (id, title, client_name, company_id, description, category, status, progress, budget, project_manager, start_date, deadline, team_members, icon, color, archived, created_at)
                VALUES 
                (:id, :title, :client_name, :company_id, :description, :category, :status, :progress, :budget, :project_manager, :start_date, :deadline, :team_members, :icon, :color, 0, NOW())
            ");
            $ins->execute([
                ':id' => $id,
                ':title' => $title,
                ':client_name' => $clientName,
                ':company_id' => $companyId,
                ':description' => $description,
                ':category' => $category,
                ':status' => $status,
                ':progress' => $progress,
                ':budget' => $budget,
                ':project_manager' => $projectManager,
                ':start_date' => $startDate,
                ':deadline' => $deadline,
                ':team_members' => $teamMembers,
                ':icon' => $icon,
                ':color' => $color
            ]);

            $curUser = SessionGuard::getUser();
            $author = $curUser['name'] ?? 'Admin';
            logProjectActivity($pdo, $id, 'Project kick-off', "Project initialized with budget allocation by $author", $author, 'milestone');

            $fetchNew = $pdo->prepare("
                SELECT p.*, c.name AS company_name, c.industry AS company_industry, c.logo_url AS company_logo
                FROM crm_projects p
                LEFT JOIN crm_companies c ON c.id = p.company_id
                WHERE p.id = ?
            ");
            $fetchNew->execute([$id]);
            $newProject = $fetchNew->fetch(PDO::FETCH_ASSOC);

            sendResponse([
                'status' => 'success',
                'message' => 'Project created successfully',
                'id' => $id,
                'project' => $newProject,
                'data' => $newProject,
                'auto_matched_company' => $autoMatchedCompany
            ]);
        }
    } catch (Exception $e) {
        sendResponse(['error' => $e->getMessage()], 500);
    }
}

// =========================================================================
// DELETE HTTP METHOD FALLBACK
// =========================================================================
if ($method === 'DELETE') {
    $id = trim($_GET['id'] ?? '');
    if (empty($id)) sendResponse(['error' => 'Project ID required'], 400);
    try {
        $pdo->prepare("UPDATE crm_tasks SET project_id = NULL WHERE project_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM crm_project_activity WHERE project_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM crm_project_files WHERE project_id = ?")->execute([$id]);

        $stmt = $pdo->prepare("DELETE FROM crm_projects WHERE id = ?");
        $stmt->execute([$id]);
        sendResponse(['status' => 'success', 'message' => 'Project deleted successfully']);
    } catch (Exception $e) {
        sendResponse(['error' => $e->getMessage()], 500);
    }
}
