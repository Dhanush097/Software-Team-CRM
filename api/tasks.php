<?php
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
$pdo = Database::getConnection();

// Ensure crm_task_members table exists
if ($pdo) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `crm_task_members` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `task_id` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
                `employee_id` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
                `assigned_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_task_employee` (`task_id`, `employee_id`),
                INDEX `idx_task_id` (`task_id`),
                INDEX `idx_employee_id` (`employee_id`),
                CONSTRAINT `fk_task_members_task` FOREIGN KEY (`task_id`) REFERENCES `crm_tasks` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk_task_members_employee` FOREIGN KEY (`employee_id`) REFERENCES `crm_team_staff` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    } catch (Exception $e) {
        // Table already exists or foreign key handled
    }
}

function getTaskAssignees(PDO $pdo, string $taskId): array {
    $stmt = $pdo->prepare("
        SELECT 
            s.id,
            s.name,
            s.email,
            s.role,
            s.department,
            s.initials,
            s.color_class,
            s.avatar_url,
            s.status,
            tm.assigned_at
        FROM crm_task_members tm
        JOIN crm_team_staff s ON s.id = tm.employee_id
        WHERE tm.task_id = ?
        ORDER BY s.name ASC
    ");
    $stmt->execute([$taskId]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($members as &$m) {
        if (empty($m['initials'])) {
            $m['initials'] = strtoupper(substr($m['name'], 0, 2));
        }
        if (empty($m['color_class'])) {
            $m['color_class'] = 'bg-blue-600';
        }
    }
    return $members;
}

function sendJson($data, $code = 200) {
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

// GET ALL TASKS OR TASK FILES
if ($method === 'GET') {
    $action = $_GET['action'] ?? '';

    // 1. VIEW / SERVE TASK ATTACHMENT FILE (SECURE ENDPOINT)
    if ($action === 'view_file') {
        $fileId = trim($_GET['id'] ?? '');
        if (empty($fileId) || !preg_match('/^[a-zA-Z0-9_\-]+$/', $fileId)) {
            sendJson(['status' => 'error', 'message' => 'Invalid file identifier'], 400);
        }

        if (!$pdo) {
            sendJson(['status' => 'error', 'message' => 'Database unavailable'], 500);
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM crm_task_files WHERE id = ?");
            $stmt->execute([$fileId]);
            $fileRecord = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$fileRecord) {
                sendJson(['status' => 'error', 'message' => 'File not found or access denied'], 404);
            }

            // Verify task association
            $taskStmt = $pdo->prepare("SELECT id, title FROM crm_tasks WHERE id = ?");
            $taskStmt->execute([$fileRecord['task_id']]);
            $task = $taskStmt->fetch(PDO::FETCH_ASSOC);
            if (!$task) {
                sendJson(['status' => 'error', 'message' => 'Associated task not found'], 404);
            }

            // Secure path resolution & Directory Traversal Protection
            $baseUploadDir = realpath(dirname(__DIR__) . '/uploads/task_files');
            $relativeStored = $fileRecord['file_path'];
            $fullPath = realpath(dirname(__DIR__) . '/' . $relativeStored);

            if (!$fullPath || !file_exists($fullPath) || strpos($fullPath, $baseUploadDir) !== 0) {
                sendJson(['status' => 'error', 'message' => 'File missing from storage'], 404);
            }

            // Content Type & Disposition
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
            sendJson(['status' => 'error', 'message' => 'Server error serving file: ' . $e->getMessage()], 500);
        }
    }

    // 2. GET TASK ATTACHMENTS FOR A SPECIFIC TASK
    if ($action === 'get_files') {
        $taskId = trim($_GET['task_id'] ?? '');
        if (empty($taskId)) {
            sendJson(['status' => 'error', 'message' => 'Task ID required'], 400);
        }

        if (!$pdo) {
            sendJson(['status' => 'error', 'message' => 'Database connection failed'], 500);
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM crm_task_files WHERE task_id = ? ORDER BY created_at DESC");
            $stmt->execute([$taskId]);
            $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $formatted = [];
            foreach ($files as $f) {
                $formatted[] = [
                    'id' => $f['id'],
                    'task_id' => $f['task_id'],
                    'name' => $f['original_name'],
                    'original_name' => $f['original_name'],
                    'size' => $f['file_size'],
                    'size_bytes' => (int)$f['file_size_bytes'],
                    'date' => date('d M Y, h:i A', strtotime($f['created_at'])),
                    'type' => $f['file_type'],
                    'mime_type' => $f['mime_type'],
                    'url' => 'api/tasks.php?action=view_file&id=' . urlencode($f['id']),
                    'view_url' => 'api/tasks.php?action=view_file&id=' . urlencode($f['id']),
                    'download_url' => 'api/tasks.php?action=view_file&id=' . urlencode($f['id']) . '&download=1',
                    'uploaded_by' => $f['uploaded_by']
                ];
            }

            sendJson(['status' => 'success', 'success' => true, 'files' => $formatted, 'data' => $formatted]);
        } catch (Exception $e) {
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // 3. GET SINGLE TASK (BY ID)
    if ($action === 'get' || (!empty($_GET['id']) && $action !== 'view_file' && $action !== 'get_files')) {
        $taskId = trim($_GET['id']);
        if (!$pdo) {
            sendJson(['status' => 'error', 'message' => 'Database connection failed'], 500);
        }
        try {
            $stmt = $pdo->prepare("SELECT * FROM crm_tasks WHERE id = ?");
            $stmt->execute([$taskId]);
            $task = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$task) {
                sendJson(['status' => 'error', 'message' => 'Task not found'], 404);
            }
            $task['completed'] = (int)$task['completed'];
            if (empty($task['project_name']) && !empty($task['client_name'])) {
                $task['project_name'] = $task['client_name'];
            }
            $assignees = getTaskAssignees($pdo, $taskId);
            $task['assigned_members'] = $assignees;
            $task['assigned_member_ids'] = array_column($assignees, 'id');
            if (!empty($assignees)) {
                $task['assigned_staff'] = implode(', ', array_column($assignees, 'name'));
            }
            sendJson(['status' => 'success', 'data' => $task]);
        } catch (Exception $e) {
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    if ($pdo) {
        try {
            $stmt = $pdo->query("SELECT * FROM crm_tasks ORDER BY completed ASC, created_at DESC");
            $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Batch fetch all task assignees from crm_task_members joined with crm_team_staff
            $tmStmt = $pdo->query("
                SELECT 
                    tm.task_id,
                    s.id,
                    s.name,
                    s.email,
                    s.role,
                    s.department,
                    s.initials,
                    s.color_class,
                    s.avatar_url,
                    s.status,
                    tm.assigned_at
                FROM crm_task_members tm
                JOIN crm_team_staff s ON s.id = tm.employee_id
                ORDER BY s.name ASC
            ");
            $allMembers = $tmStmt ? $tmStmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $membersByTask = [];
            foreach ($allMembers as $m) {
                $tId = $m['task_id'];
                if (!isset($membersByTask[$tId])) {
                    $membersByTask[$tId] = [];
                }
                if (empty($m['initials'])) {
                    $m['initials'] = strtoupper(substr($m['name'], 0, 2));
                }
                if (empty($m['color_class'])) {
                    $m['color_class'] = 'bg-blue-600';
                }
                $membersByTask[$tId][] = $m;
            }

            foreach ($tasks as &$t) {
                $t['completed'] = (int)$t['completed'];
                if (empty($t['project_name']) && !empty($t['client_name'])) {
                    $t['project_name'] = $t['client_name'];
                }
                $assignedList = $membersByTask[$t['id']] ?? [];
                $t['assigned_members'] = $assignedList;
                $t['assigned_member_ids'] = array_column($assignedList, 'id');
                if (!empty($assignedList)) {
                    $t['assigned_staff'] = implode(', ', array_column($assignedList, 'name'));
                }
            }
            unset($t);

            sendJson(['status' => 'success', 'data' => $tasks]);
        } catch (Exception $e) {
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    } else {
        sendJson(['status' => 'error', 'message' => 'Database connection failed'], 500);
    }
}

// DELETE HTTP METHOD
if ($method === 'DELETE') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: $_GET;
    $action = $input['action'] ?? ($_GET['action'] ?? '');

    // Deleting task attachment file via DELETE method
    if ($action === 'delete_file') {
        $fileId = trim($input['id'] ?? ($input['file_id'] ?? ($_GET['id'] ?? ($_GET['file_id'] ?? ''))));
        if (empty($fileId)) sendJson(['status' => 'error', 'message' => 'File ID required'], 400);

        try {
            $stmt = $pdo->prepare("SELECT * FROM crm_task_files WHERE id = ?");
            $stmt->execute([$fileId]);
            $fileRecord = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($fileRecord) {
                $baseUploadDir = realpath(dirname(__DIR__) . '/uploads/task_files');
                $fullPath = realpath(dirname(__DIR__) . '/' . $fileRecord['file_path']);
                if ($fullPath && file_exists($fullPath) && $baseUploadDir && strpos($fullPath, $baseUploadDir) === 0) {
                    @unlink($fullPath);
                }
                $del = $pdo->prepare("DELETE FROM crm_task_files WHERE id = ?");
                $del->execute([$fileId]);
                sendJson([
                    'status' => 'success',
                    'success' => true,
                    'message' => 'Attachment deleted successfully',
                    'file_id' => $fileId,
                    'task_id' => $fileRecord['task_id']
                ]);
            } else {
                sendJson(['status' => 'error', 'message' => 'Attachment not found'], 404);
            }
        } catch (Exception $e) {
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    $id = $input['id'] ?? ($_GET['id'] ?? '');
    if (empty($id)) sendJson(['status' => 'error', 'message' => 'ID required'], 400);

    try {
        $stmt = $pdo->prepare("DELETE FROM crm_tasks WHERE id = ?");
        $stmt->execute([$id]);
        sendJson(['status' => 'success', 'message' => 'Task deleted']);
    } catch (Exception $e) {
        sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}

// POST - CREATE, TOGGLE, UPDATE, BULK, OR DELETE
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: $_POST;
    $action = $input['action'] ?? ($_GET['action'] ?? 'create');

    if (!$pdo) {
        sendJson(['status' => 'error', 'message' => 'Database not connected'], 500);
    }

    // 0A. UPLOAD TASK ATTACHMENT FILE
    if ($action === 'upload_file' || !empty($_FILES['file'])) {
        $taskId = trim($_POST['task_id'] ?? ($input['task_id'] ?? ''));
        if (empty($taskId)) {
            sendJson(['status' => 'error', 'message' => 'task_id is required'], 400);
        }

        // Verify task exists
        $stmt = $pdo->prepare("SELECT id, title, project_name FROM crm_tasks WHERE id = ?");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$task) {
            sendJson(['status' => 'error', 'message' => 'Task not found'], 404);
        }

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errCode = isset($_FILES['file']) ? $_FILES['file']['error'] : 'NO_FILE';
            sendJson(['status' => 'error', 'message' => 'File upload error occurred: ' . $errCode], 400);
        }

        $file = $_FILES['file'];
        $maxBytes = 50 * 1024 * 1024; // 50MB
        if ($file['size'] > $maxBytes) {
            sendJson(['status' => 'error', 'message' => 'File exceeds maximum limit of 50MB'], 400);
        }

        $origName = basename($file['name']);
        if (empty(trim($origName))) {
            sendJson(['status' => 'error', 'message' => 'Invalid file name'], 400);
        }

        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        // Block dangerous script extensions
        $blockedExts = ['php', 'phtml', 'php3', 'php4', 'php5', 'phps', 'exe', 'bat', 'cmd', 'sh', 'vbs', 'js', 'jar'];
        if (in_array($ext, $blockedExts)) {
            sendJson(['status' => 'error', 'message' => 'Executable file types cannot be uploaded for security.'], 400);
        }

        // Target directory
        $targetDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'task_files';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        // Generate safe unique storage name
        $cleanBase = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($origName, PATHINFO_FILENAME));
        $cleanBase = substr($cleanBase, 0, 40);
        $uniqueStorageName = $cleanBase . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . ($ext ? ('.' . $ext) : '');
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $uniqueStorageName;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            sendJson(['status' => 'error', 'message' => 'Failed to write uploaded file to storage.'], 500);
        }

        // Determine MIME & Type
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
        $fileId = 'tf_' . time() . '_' . rand(100, 999);
        $relativePath = 'uploads/task_files/' . $uniqueStorageName;

        $curUser = SessionGuard::getUser();
        $uploadedBy = $curUser['name'] ?? 'Staff';

        $ins = $pdo->prepare("
            INSERT INTO crm_task_files 
            (id, task_id, file_name, original_name, file_path, file_type, mime_type, file_size, file_size_bytes, uploaded_by, created_at)
            VALUES 
            (:id, :task_id, :file_name, :original_name, :file_path, :file_type, :mime_type, :file_size, :file_size_bytes, :uploaded_by, NOW())
        ");
        $ins->execute([
            ':id' => $fileId,
            ':task_id' => $taskId,
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
                'task_id' => $taskId,
                'name' => $origName,
                'original_name' => $origName,
                'size' => $formattedSize,
                'size_bytes' => $bytes,
                'date' => 'Just now',
                'type' => $fileType,
                'mime_type' => $mimeType,
                'url' => 'api/tasks.php?action=view_file&id=' . urlencode($fileId),
                'view_url' => 'api/tasks.php?action=view_file&id=' . urlencode($fileId),
                'download_url' => 'api/tasks.php?action=view_file&id=' . urlencode($fileId) . '&download=1',
                'uploaded_by' => $uploadedBy
        ];

        sendJson([
            'status' => 'success',
            'success' => true,
            'message' => 'File uploaded successfully',
            'file' => $filePayload,
            'data' => $filePayload
        ]);
    }

    // 0B. DELETE TASK ATTACHMENT FILE
    if ($action === 'delete_file') {
        $fileId = trim($input['id'] ?? ($input['file_id'] ?? ($_POST['id'] ?? ($_POST['file_id'] ?? ($_GET['id'] ?? ($_GET['file_id'] ?? ''))))));
        if (empty($fileId)) sendJson(['status' => 'error', 'message' => 'File ID required'], 400);

        try {
            $stmt = $pdo->prepare("SELECT * FROM crm_task_files WHERE id = ?");
            $stmt->execute([$fileId]);
            $fileRecord = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($fileRecord) {
                $baseUploadDir = realpath(dirname(__DIR__) . '/uploads/task_files');
                $fullPath = realpath(dirname(__DIR__) . '/' . $fileRecord['file_path']);
                if ($fullPath && file_exists($fullPath) && $baseUploadDir && strpos($fullPath, $baseUploadDir) === 0) {
                    @unlink($fullPath);
                }
                $del = $pdo->prepare("DELETE FROM crm_task_files WHERE id = ?");
                $del->execute([$fileId]);
                sendJson([
                    'status' => 'success',
                    'success' => true,
                    'message' => 'Attachment deleted successfully',
                    'file_id' => $fileId,
                    'task_id' => $fileRecord['task_id']
                ]);
            } else {
                sendJson(['status' => 'error', 'message' => 'Attachment not found'], 404);
            }
        } catch (Exception $e) {
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // 1. TOGGLE COMPLETION
    if ($action === 'toggle') {
        $id = $input['id'] ?? '';
        if (empty($id)) sendJson(['status' => 'error', 'message' => 'ID required'], 400);

        try {
            $stmt = $pdo->prepare("SELECT completed FROM crm_tasks WHERE id = ?");
            $stmt->execute([$id]);
            $task = $stmt->fetch();
            if (!$task) sendJson(['status' => 'error', 'message' => 'Task not found'], 404);

            $newCompleted = $task['completed'] ? 0 : 1;
            $newStatus = $newCompleted ? 'Completed' : 'In Progress';

            $upd = $pdo->prepare("UPDATE crm_tasks SET completed = ?, status = ? WHERE id = ?");
            $upd->execute([$newCompleted, $newStatus, $id]);

            // If marked completed, trigger notification
            if ($newCompleted && file_exists(__DIR__ . '/notification_service.php')) {
                try {
                    require_once __DIR__ . '/notification_service.php';
                    $stT = $pdo->prepare("SELECT title FROM crm_tasks WHERE id = ?");
                    $stT->execute([$id]);
                    $taskTitle = $stT->fetchColumn() ?: 'Task';
                    NotificationService::create($pdo, [
                        'category' => 'tasks',
                        'event_type' => 'task_completed',
                        'title' => 'Task Completed: ' . $taskTitle,
                        'message' => 'Task "' . $taskTitle . '" has been marked as completed.',
                        'link_type' => 'task',
                        'link_id' => (string)$id,
                        'action_label' => 'View Task',
                        'metadata' => ['task_id' => $id, 'title' => $taskTitle]
                    ]);
                } catch (Exception $e) {
                    error_log('Notification error on task toggle: ' . $e->getMessage());
                }
            }

            sendJson([
                'status' => 'success',
                'success' => true,
                'message' => $newCompleted ? 'Task marked as completed' : 'Task reopened',
                'id' => $id,
                'completed' => $newCompleted,
                'task_status' => $newStatus,
                'data' => [
                    'id' => $id,
                    'completed' => $newCompleted,
                    'status' => $newStatus
                ]
            ]);
        } catch (Exception $e) {
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // 2. SET EXPLICIT COMPLETION
    if ($action === 'set_completed') {
        $id = $input['id'] ?? '';
        if (empty($id)) sendJson(['status' => 'error', 'message' => 'ID required'], 400);
        $completedVal = !empty($input['completed']) ? 1 : 0;
        $newStatus = $completedVal ? 'Completed' : 'In Progress';

        try {
            $upd = $pdo->prepare("UPDATE crm_tasks SET completed = ?, status = ? WHERE id = ?");
            $upd->execute([$completedVal, $newStatus, $id]);

            if ($completedVal && file_exists(__DIR__ . '/notification_service.php')) {
                try {
                    require_once __DIR__ . '/notification_service.php';
                    $stT = $pdo->prepare("SELECT title FROM crm_tasks WHERE id = ?");
                    $stT->execute([$id]);
                    $taskTitle = $stT->fetchColumn() ?: 'Task';
                    NotificationService::create($pdo, [
                        'category' => 'tasks',
                        'event_type' => 'task_completed',
                        'title' => 'Task Completed: ' . $taskTitle,
                        'message' => 'Task "' . $taskTitle . '" has been marked as completed.',
                        'link_type' => 'task',
                        'link_id' => (string)$id,
                        'action_label' => 'View Task',
                        'metadata' => ['task_id' => $id, 'title' => $taskTitle]
                    ]);
                } catch (Exception $e) {
                    error_log('Notification error on task set_completed: ' . $e->getMessage());
                }
            }

            sendJson([
                'status' => 'success',
                'id' => $id,
                'completed' => $completedVal,
                'task_status' => $newStatus
            ]);
        } catch (Exception $e) {
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // 3. UPDATE TASK
    if ($action === 'update') {
        $id = $input['id'] ?? '';
        if (empty($id)) sendJson(['status' => 'error', 'message' => 'Task ID required'], 400);
        if (empty($input['title'])) sendJson(['status' => 'error', 'message' => 'Task Title is required'], 400);

        $status = trim($input['status'] ?? 'In Progress');
        $completed = (strtolower($status) === 'completed' || !empty($input['completed'])) ? 1 : 0;
        if ($completed) $status = 'Completed';

        // Extract assigned member IDs if provided
        $hasMemberIds = false;
        $assignedMemberIds = [];
        if (array_key_exists('assigned_member_ids', $input)) {
            $hasMemberIds = true;
            $assignedMemberIds = is_array($input['assigned_member_ids']) ? $input['assigned_member_ids'] : json_decode($input['assigned_member_ids'], true);
        } elseif (array_key_exists('member_ids', $input)) {
            $hasMemberIds = true;
            $assignedMemberIds = is_array($input['member_ids']) ? $input['member_ids'] : json_decode($input['member_ids'], true);
        }
        if ($hasMemberIds) {
            $assignedMemberIds = array_values(array_unique(array_filter(array_map('trim', (array)$assignedMemberIds))));
        }

        try {
            $pdo->beginTransaction();

            // Validate provided member IDs
            $validStaff = [];
            $assignedStaffStr = trim($input['assigned_staff'] ?? ($input['assignedStaff'] ?? ''));
            if ($hasMemberIds) {
                if (!empty($assignedMemberIds)) {
                    $inQ = implode(',', array_fill(0, count($assignedMemberIds), '?'));
                    $chkStmt = $pdo->prepare("SELECT id, name FROM crm_team_staff WHERE id IN ($inQ)");
                    $chkStmt->execute($assignedMemberIds);
                    $validStaff = $chkStmt->fetchAll(PDO::FETCH_ASSOC);
                    if (count($validStaff) !== count($assignedMemberIds)) {
                        $pdo->rollBack();
                        sendJson(['status' => 'error', 'message' => 'One or more selected team members are invalid or do not exist in the CRM database'], 400);
                    }
                    $assignedStaffStr = implode(', ', array_column($validStaff, 'name'));
                } else {
                    $assignedStaffStr = '';
                }
            }

            $stmt = $pdo->prepare("
                UPDATE crm_tasks SET
                    title = :title,
                    description = :description,
                    project_id = :project_id,
                    project_name = :project_name,
                    client_name = :client_name,
                    assigned_staff = :assigned_staff,
                    priority = :priority,
                    due_time = :due_time,
                    due_date = :due_date,
                    status = :status,
                    completed = :completed
                WHERE id = :id
            ");

            $projectName = trim($input['project_name'] ?? ($input['project'] ?? ''));
            $clientName = trim($input['client_name'] ?? ($input['client'] ?? $projectName));

            $stmt->execute([
                ':id' => $id,
                ':title' => trim($input['title']),
                ':description' => trim($input['description'] ?? ''),
                ':project_id' => trim($input['project_id'] ?? ''),
                ':project_name' => $projectName,
                ':client_name' => $clientName,
                ':assigned_staff' => $assignedStaffStr,
                ':priority' => ucfirst(strtolower(trim($input['priority'] ?? 'Medium'))),
                ':due_time' => trim($input['due_time'] ?? 'Today'),
                ':due_date' => trim($input['due_date'] ?? ''),
                ':status' => $status,
                ':completed' => $completed
            ]);

            // Sync crm_task_members if member IDs were provided
            if ($hasMemberIds) {
                $del = $pdo->prepare("DELETE FROM crm_task_members WHERE task_id = ?");
                $del->execute([$id]);

                if (!empty($assignedMemberIds)) {
                    $mIns = $pdo->prepare("INSERT IGNORE INTO crm_task_members (task_id, employee_id) VALUES (?, ?)");
                    foreach ($assignedMemberIds as $mId) {
                        $mIns->execute([$id, $mId]);
                    }
                }
            }

            $pdo->commit();

            $assignees = getTaskAssignees($pdo, $id);
            sendJson([
                'status' => 'success',
                'message' => 'Task updated successfully',
                'id' => $id,
                'data' => [
                    'id' => $id,
                    'title' => trim($input['title']),
                    'assigned_staff' => $assignedStaffStr,
                    'assigned_members' => $assignees,
                    'assigned_member_ids' => array_column($assignees, 'id')
                ]
            ]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // 4. DELETE TASK
    if ($action === 'delete') {
        $id = $input['id'] ?? '';
        if (empty($id)) sendJson(['status' => 'error', 'message' => 'ID required'], 400);

        try {
            $stmt = $pdo->prepare("DELETE FROM crm_tasks WHERE id = ?");
            $stmt->execute([$id]);
            sendJson(['status' => 'success', 'message' => 'Task deleted']);
        } catch (Exception $e) {
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // 5. DUPLICATE TASK
    if ($action === 'duplicate') {
        $id = $input['id'] ?? '';
        if (empty($id)) sendJson(['status' => 'error', 'message' => 'ID required'], 400);

        try {
            $stmt = $pdo->prepare("SELECT * FROM crm_tasks WHERE id = ?");
            $stmt->execute([$id]);
            $orig = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$orig) sendJson(['status' => 'error', 'message' => 'Task not found'], 404);

            $newId = 'tsk_' . time() . '_' . rand(100, 999);
            $newTitle = 'Copy of ' . $orig['title'];

            $pdo->beginTransaction();

            $ins = $pdo->prepare("
                INSERT INTO crm_tasks 
                (id, title, description, client_name, project_name, project_id, assigned_staff, due_time, due_date, priority, category, domain, icon, status, completed)
                VALUES 
                (:id, :title, :description, :client_name, :project_name, :project_id, :assigned_staff, :due_time, :due_date, :priority, :category, :domain, :icon, :status, 0)
            ");
            $ins->execute([
                ':id' => $newId,
                ':title' => $newTitle,
                ':description' => $orig['description'] ?? '',
                ':client_name' => $orig['client_name'] ?? '',
                ':project_name' => $orig['project_name'] ?? '',
                ':project_id' => $orig['project_id'] ?? '',
                ':assigned_staff' => $orig['assigned_staff'] ?? 'Rahul Sharma',
                ':due_time' => $orig['due_time'] ?? 'Today',
                ':due_date' => $orig['due_date'] ?? '',
                ':priority' => $orig['priority'] ?? 'Medium',
                ':category' => $orig['category'] ?? 'Design',
                ':domain' => $orig['domain'] ?? 'software.local',
                ':icon' => $orig['icon'] ?? 'layout',
                ':status' => 'In Progress'
            ]);

            $copyStmt = $pdo->prepare("INSERT INTO crm_task_members (task_id, employee_id) SELECT ?, employee_id FROM crm_task_members WHERE task_id = ?");
            $copyStmt->execute([$newId, $id]);

            $pdo->commit();

            sendJson(['status' => 'success', 'message' => 'Task duplicated', 'id' => $newId]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // 6. BULK ACTIONS
    if ($action === 'bulk_complete') {
        $ids = $input['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) sendJson(['status' => 'error', 'message' => 'IDs array required'], 400);

        try {
            $inClause = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("UPDATE crm_tasks SET completed = 1, status = 'Completed' WHERE id IN ($inClause)");
            $stmt->execute(array_values($ids));
            sendJson(['status' => 'success', 'message' => count($ids) . ' tasks marked as completed', 'count' => count($ids)]);
        } catch (Exception $e) {
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    if ($action === 'bulk_delete') {
        $ids = $input['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) sendJson(['status' => 'error', 'message' => 'IDs array required'], 400);

        try {
            $inClause = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("DELETE FROM crm_tasks WHERE id IN ($inClause)");
            $stmt->execute(array_values($ids));
            sendJson(['status' => 'success', 'message' => count($ids) . ' tasks deleted', 'count' => count($ids)]);
        } catch (Exception $e) {
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    if ($action === 'bulk_assign') {
        $ids = $input['ids'] ?? [];
        $staff = trim($input['assigned_staff'] ?? '');
        if (!is_array($ids) || empty($ids) || empty($staff)) sendJson(['status' => 'error', 'message' => 'IDs and staff required'], 400);

        try {
            $inClause = implode(',', array_fill(0, count($ids), '?'));
            $params = array_merge([$staff], array_values($ids));
            $stmt = $pdo->prepare("UPDATE crm_tasks SET assigned_staff = ? WHERE id IN ($inClause)");
            $stmt->execute($params);
            sendJson(['status' => 'success', 'message' => count($ids) . ' tasks assigned to ' . $staff]);
        } catch (Exception $e) {
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    if ($action === 'bulk_move') {
        $ids = $input['ids'] ?? [];
        $projectId = trim($input['project_id'] ?? '');
        $projectName = trim($input['project_name'] ?? '');
        if (!is_array($ids) || empty($ids)) sendJson(['status' => 'error', 'message' => 'IDs required'], 400);

        try {
            $inClause = implode(',', array_fill(0, count($ids), '?'));
            $params = array_merge([$projectId, $projectName, $projectName], array_values($ids));
            $stmt = $pdo->prepare("UPDATE crm_tasks SET project_id = ?, project_name = ?, client_name = ? WHERE id IN ($inClause)");
            $stmt->execute($params);
            sendJson(['status' => 'success', 'message' => count($ids) . ' tasks moved to ' . $projectName]);
        } catch (Exception $e) {
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // 7. DEFAULT: CREATE TASK
    if (empty($input['title']) || !trim($input['title'])) {
        sendJson(['status' => 'error', 'success' => false, 'message' => 'Task Title is required'], 400);
    }

    // Extract assigned member IDs if provided
    $assignedMemberIds = $input['assigned_member_ids'] ?? ($input['member_ids'] ?? []);
    if (!is_array($assignedMemberIds) && !empty($assignedMemberIds)) {
        $decoded = json_decode($assignedMemberIds, true);
        $assignedMemberIds = is_array($decoded) ? $decoded : [];
    }
    $assignedMemberIds = array_values(array_unique(array_filter(array_map('trim', (array)$assignedMemberIds))));

    // Validate provided member IDs
    $validStaff = [];
    $assignedStaffStr = trim($input['assigned_staff'] ?? ($input['assignedStaff'] ?? ''));
    if (!empty($assignedMemberIds)) {
        $inQ = implode(',', array_fill(0, count($assignedMemberIds), '?'));
        $chkStmt = $pdo->prepare("SELECT id, name, email FROM crm_team_staff WHERE id IN ($inQ)");
        $chkStmt->execute($assignedMemberIds);
        $validStaff = $chkStmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($validStaff) !== count($assignedMemberIds)) {
            sendJson(['status' => 'error', 'message' => 'One or more selected team members are invalid or do not exist in the CRM database'], 400);
        }
        $assignedStaffStr = implode(', ', array_column($validStaff, 'name'));
    }

    try {
        $pdo->beginTransaction();

        $id = 'tsk_' . time() . '_' . rand(100, 999);
        $projectName = trim($input['project_name'] ?? ($input['project'] ?? ''));
        $clientName = trim($input['client_name'] ?? ($input['client'] ?? ($projectName ?: 'ABC Tech Solutions')));
        $status = trim($input['status'] ?? 'To Do');
        if (empty($status)) $status = 'To Do';
        $completed = (strtolower($status) === 'completed' || !empty($input['completed'])) ? 1 : 0;
        if ($completed) $status = 'Completed';

        $dueDate = trim($input['due_date'] ?? date('Y-m-d'));
        $dueTime = trim($input['due_time'] ?? ($input['dueTime'] ?? ''));
        if (empty($dueTime) && !empty($dueDate)) {
            $timestamp = strtotime($dueDate);
            if ($timestamp) {
                $dueTime = date('d M', $timestamp);
            } else {
                $dueTime = 'Today';
            }
        } elseif (empty($dueTime)) {
            $dueTime = 'Today';
        }

        $stmt = $pdo->prepare("
            INSERT INTO crm_tasks 
            (id, title, description, client_name, project_name, project_id, phone, location, assigned_staff, due_time, due_date, priority, category, domain, icon, status, completed)
            VALUES 
            (:id, :title, :description, :client_name, :project_name, :project_id, :phone, :location, :assigned_staff, :due_time, :due_date, :priority, :category, :domain, :icon, :status, :completed)
        ");

        $stmt->execute([
            ':id' => $id,
            ':title' => trim($input['title']),
            ':description' => trim($input['description'] ?? ''),
            ':client_name' => $clientName,
            ':project_name' => $projectName,
            ':project_id' => trim($input['project_id'] ?? ''),
            ':phone' => trim($input['phone'] ?? ''),
            ':location' => trim($input['location'] ?? ''),
            ':assigned_staff' => $assignedStaffStr,
            ':due_time' => $dueTime,
            ':due_date' => $dueDate,
            ':priority' => ucfirst(strtolower(trim($input['priority'] ?? 'Medium'))),
            ':category' => trim($input['category'] ?? 'Design'),
            ':domain' => 'software.local',
            ':icon' => trim($input['icon'] ?? 'layout'),
            ':status' => $status,
            ':completed' => $completed
        ]);

        // Insert into crm_task_members
        if (!empty($assignedMemberIds)) {
            $mIns = $pdo->prepare("INSERT IGNORE INTO crm_task_members (task_id, employee_id) VALUES (?, ?)");
            foreach ($assignedMemberIds as $mId) {
                $mIns->execute([$id, $mId]);
            }
        }

        $pdo->commit();

        // Trigger notification for task assigned/created
        try {
            if (file_exists(__DIR__ . '/notification_service.php')) {
                require_once __DIR__ . '/notification_service.php';
                NotificationService::create($pdo, [
                    'category' => 'tasks',
                    'event_type' => 'task_assigned',
                    'title' => 'Task Created: ' . trim($input['title']),
                    'message' => 'New task created' . (!empty($assignedStaffStr) ? ' and assigned to ' . $assignedStaffStr : '') . '. Priority: ' . ucfirst(strtolower(trim($input['priority'] ?? 'Medium'))) . ', Due: ' . $dueTime,
                    'link_type' => 'task',
                    'link_id' => (string)$id,
                    'action_label' => 'View Task',
                    'metadata' => [
                        'task_id' => $id,
                        'title' => trim($input['title']),
                        'assigned_staff' => $assignedStaffStr,
                        'assigned_member_ids' => $assignedMemberIds,
                        'priority' => ucfirst(strtolower(trim($input['priority'] ?? 'Medium'))),
                        'due_date' => $dueDate
                    ]
                ]);
            }
        } catch (Exception $e) {
            error_log('Notification error on task create: ' . $e->getMessage());
        }

        $assignees = getTaskAssignees($pdo, $id);

        sendJson([
            'status' => 'success',
            'success' => true,
            'id' => $id,
            'message' => 'Task created successfully',
            'data' => [
                'id' => $id,
                'title' => trim($input['title']),
                'project_id' => trim($input['project_id'] ?? ''),
                'client_name' => $clientName,
                'assigned_staff' => $assignedStaffStr,
                'assigned_members' => $assignees,
                'assigned_member_ids' => array_column($assignees, 'id'),
                'due_date' => $dueDate,
                'due_time' => $dueTime,
                'priority' => ucfirst(strtolower(trim($input['priority'] ?? 'Medium'))),
                'status' => $status,
                'completed' => $completed
            ]
        ], 201);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sendJson(['status' => 'error', 'success' => false, 'message' => $e->getMessage()], 500);
    }
}
