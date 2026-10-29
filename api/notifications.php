<?php
/**
 * Software CRM - Notification Controller API
 * Handles:
 *  - GET notifications (with category filters, pagination, unread counts)
 *  - POST mark_read (individual)
 *  - POST mark_all_read (bulk)
 *  - Strict user-level authorization & privacy protection
 */

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

require_once __DIR__ . '/session_guard.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notification_service.php';

// Ensure database connection
$pdo = Database::getConnection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection unavailable']);
    exit;
}

// Authentication Check: User must have an active session
$currentUser = SessionGuard::getUser();
if (!$currentUser || empty($currentUser['id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized. Please sign in.']);
    exit;
}

$currentUserId = (int)$currentUser['id'];
$currentUserRole = strtolower($currentUser['role'] ?? 'user');
$isAdmin = in_array($currentUserRole, ['admin', 'founder']) || ($currentUserId === 1);

// Parse input
$rawBody = file_get_contents('php://input');
$jsonBody = !empty($rawBody) ? json_decode($rawBody, true) : [];
$params = array_merge($_GET, $_POST, is_array($jsonBody) ? $jsonBody : []);

$action = trim($params['action'] ?? '');
if (empty($action) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = 'get_notifications';
}

NotificationService::ensureTable($pdo);

// Relative time formatting helper
function formatTimeAgo(int $timestamp): string {
    $diff = time() - $timestamp;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 172800) return 'Yesterday';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', $timestamp);
}

switch ($action) {

    // =========================================================================
    // 1. GET NOTIFICATIONS (FILTERED BY CATEGORY, WITH COUNTS)
    // =========================================================================
    case 'get_notifications':
        $category = trim($params['category'] ?? 'all');
        $limit    = max(1, min(100, (int)($params['limit'] ?? 50)));

        // Sync real events if table is empty
        $countCheck = (int)$pdo->query("SELECT COUNT(*) FROM `crm_notifications`")->fetchColumn();
        if ($countCheck < 3) {
            NotificationService::syncRealCrmEvents($pdo, $currentUserId);
        }

        // Base user authorization query clause:
        // Users can access their own notifications OR global notifications (user_id IS NULL or 0)
        $authWhere = "(`user_id` = :uid OR `user_id` IS NULL OR `user_id` = 0)";
        $sqlParams = [':uid' => $currentUserId];

        $filterWhere = "";
        if ($category === 'unread') {
            $filterWhere = " AND `is_read` = 0";
        } elseif (in_array($category, ['invoices', 'clients', 'tasks', 'system'])) {
            $filterWhere = " AND `category` = :cat";
            $sqlParams[':cat'] = $category;
        }

        $query = "SELECT `id`, `user_id`, `category`, `event_type`, `title`, `message`, `link_type`, `link_id`, `action_label`, `is_read`, `read_at`, `metadata`, `created_at`
                  FROM `crm_notifications`
                  WHERE {$authWhere} {$filterWhere}
                  ORDER BY `created_at` DESC, `id` DESC
                  LIMIT {$limit}";

        $stmt = $pdo->prepare($query);
        $stmt->execute($sqlParams);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format dates and parse metadata
        foreach ($notifications as &$n) {
            $n['id'] = (int)$n['id'];
            $n['is_read'] = (bool)$n['is_read'];
            $n['metadata'] = !empty($n['metadata']) ? json_decode($n['metadata'], true) : null;
            $ts = strtotime($n['created_at']);
            $n['time_ago'] = formatTimeAgo($ts);
            $n['formatted_date'] = date('M j, Y • h:i A', $ts);
        }
        unset($n);

        // Compute category & unread breakdown for tab badges
        $countQuery = "SELECT 
            COUNT(*) as all_count,
            SUM(CASE WHEN `is_read` = 0 THEN 1 ELSE 0 END) as unread_count,
            SUM(CASE WHEN `category` = 'invoices' THEN 1 ELSE 0 END) as invoices_count,
            SUM(CASE WHEN `category` = 'clients' THEN 1 ELSE 0 END) as clients_count,
            SUM(CASE WHEN `category` = 'tasks' THEN 1 ELSE 0 END) as tasks_count,
            SUM(CASE WHEN `category` = 'system' THEN 1 ELSE 0 END) as system_count
        FROM `crm_notifications`
        WHERE {$authWhere}";

        $cStmt = $pdo->prepare($countQuery);
        $cStmt->execute([':uid' => $currentUserId]);
        $countsRow = $cStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $counts = [
            'all'      => (int)($countsRow['all_count'] ?? 0),
            'unread'   => (int)($countsRow['unread_count'] ?? 0),
            'invoices' => (int)($countsRow['invoices_count'] ?? 0),
            'clients'  => (int)($countsRow['clients_count'] ?? 0),
            'tasks'    => (int)($countsRow['tasks_count'] ?? 0),
            'system'   => (int)($countsRow['system_count'] ?? 0)
        ];

        echo json_encode([
            'status'        => 'success',
            'success'       => true,
            'data'          => $notifications,
            'notifications' => $notifications,
            'unread_count'  => $counts['unread'],
            'counts'        => $counts
        ]);
        break;


    // =========================================================================
    // 2. GET UNREAD COUNT ONLY (FOR POLLING & FAST BADGE REFRESH)
    // =========================================================================
    case 'get_unread_count':
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `crm_notifications` WHERE (`user_id` = ? OR `user_id` IS NULL OR `user_id` = 0) AND `is_read` = 0");
        $stmt->execute([$currentUserId]);
        $unread = (int)$stmt->fetchColumn();

        echo json_encode([
            'status'       => 'success',
            'success'      => true,
            'unread_count' => $unread
        ]);
        break;


    // =========================================================================
    // 3. MARK SINGLE NOTIFICATION AS READ
    // =========================================================================
    case 'mark_read':
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'success' => false, 'error' => 'Notification ID is required']);
            exit;
        }

        // Verify notification exists and user is authorized to update it
        $stmt = $pdo->prepare("SELECT `id`, `user_id` FROM `crm_notifications` WHERE `id` = ? LIMIT 1");
        $stmt->execute([$id]);
        $notif = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$notif) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'success' => false, 'error' => 'Notification not found']);
            exit;
        }

        // Authorization check: Users cannot access or modify another user's private notification
        $notifUserId = (int)($notif['user_id'] ?? 0);
        if ($notifUserId > 0 && $notifUserId !== $currentUserId && !$isAdmin) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'success' => false, 'error' => 'Access denied: You cannot modify another user\'s private notifications.']);
            exit;
        }

        // Mark as read
        $upd = $pdo->prepare("UPDATE `crm_notifications` SET `is_read` = 1, `read_at` = NOW() WHERE `id` = ?");
        $upd->execute([$id]);

        // Get fresh unread count
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM `crm_notifications` WHERE (`user_id` = ? OR `user_id` IS NULL OR `user_id` = 0) AND `is_read` = 0");
        $cntStmt->execute([$currentUserId]);
        $unread = (int)$cntStmt->fetchColumn();

        echo json_encode([
            'status'       => 'success',
            'success'      => true,
            'message'      => 'Notification marked as read',
            'id'           => $id,
            'unread_count' => $unread
        ]);
        break;


    // =========================================================================
    // 4. MARK ALL NOTIFICATIONS AS READ
    // =========================================================================
    case 'mark_all_read':
        $category = trim($params['category'] ?? '');

        if (!empty($category) && in_array($category, ['invoices', 'clients', 'tasks', 'system'])) {
            $stmt = $pdo->prepare("UPDATE `crm_notifications` SET `is_read` = 1, `read_at` = NOW() WHERE (`user_id` = ? OR `user_id` IS NULL OR `user_id` = 0) AND `category` = ? AND `is_read` = 0");
            $stmt->execute([$currentUserId, $category]);
        } else {
            $stmt = $pdo->prepare("UPDATE `crm_notifications` SET `is_read` = 1, `read_at` = NOW() WHERE (`user_id` = ? OR `user_id` IS NULL OR `user_id` = 0) AND `is_read` = 0");
            $stmt->execute([$currentUserId]);
        }

        echo json_encode([
            'status'       => 'success',
            'success'      => true,
            'message'      => 'All notifications marked as read',
            'unread_count' => 0
        ]);
        break;


    // =========================================================================
    // 5. MANUAL CRM EVENT SYNC (ADMIN OR USER TRIGGERED)
    // =========================================================================
    case 'sync_events':
        NotificationService::syncRealCrmEvents($pdo, $currentUserId);
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM `crm_notifications` WHERE (`user_id` = ? OR `user_id` IS NULL OR `user_id` = 0) AND `is_read` = 0");
        $cntStmt->execute([$currentUserId]);
        $unread = (int)$cntStmt->fetchColumn();

        echo json_encode([
            'status'       => 'success',
            'success'      => true,
            'message'      => 'CRM events synchronized',
            'unread_count' => $unread
        ]);
        break;


    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action specified']);
        break;
}
