<?php
/**
 * Software CRM - Groups & Team Structure API
 * 
 * Provides complete group management, team structure visualization,
 * member assignments, role management, and organizational hierarchy.
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, DELETE, PUT');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/session_guard.php';
$authUser = SessionGuard::requireAuth(true);

require_once __DIR__ . '/db.php';
$pdo = Database::getConnection();

if (!$pdo) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'status'  => 'error',
        'message' => 'Database connection failed'
    ]);
    exit;
}

// Ensure groups and group_members tables exist
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `crm_groups` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(150) NOT NULL UNIQUE,
            `description` TEXT NULL,
            `company_id` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
            `leader_id` VARCHAR(64) NULL,
            `color` VARCHAR(50) DEFAULT '#2563eb',
            `icon` VARCHAR(50) DEFAULT 'users',
            `created_by` VARCHAR(64) NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_crm_groups_company_id` (`company_id`),
            INDEX `idx_leader` (`leader_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

        CREATE TABLE IF NOT EXISTS `crm_group_members` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `group_id` INT NOT NULL,
            `employee_id` VARCHAR(64) NOT NULL,
            `role_in_group` VARCHAR(50) DEFAULT 'member',
            `added_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_group_member` (`group_id`, `employee_id`),
            INDEX `idx_group` (`group_id`),
            INDEX `idx_employee` (`employee_id`),
            CONSTRAINT `fk_group_members_group` FOREIGN KEY (`group_id`) REFERENCES `crm_groups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");

    // Migration check for company_id column in crm_groups
    $chkCol = $pdo->query("SHOW COLUMNS FROM `crm_groups` LIKE 'company_id'")->fetch();
    if (!$chkCol) {
        $pdo->exec("ALTER TABLE `crm_groups` ADD COLUMN `company_id` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER `description`");
        $pdo->exec("ALTER TABLE `crm_groups` ADD INDEX `idx_crm_groups_company_id` (`company_id`)");
    }
} catch (Exception $e) {
    // Continue if tables already exist
}

function sendJson($data, int $code = 200): void {
    if ($code >= 200 && $code < 300) {
        if (!isset($data['success'])) {
            $data['success'] = true;
        }
        if (!isset($data['status'])) {
            $data['status'] = 'success';
        }
    } else {
        if (!isset($data['success'])) {
            $data['success'] = false;
        }
        if (!isset($data['status'])) {
            $data['status'] = 'error';
        }
    }
    http_response_code($code);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Verify CSRF for mutating requests
function verifyRequestCsrf(array $input): void {
    $token = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? ''));
    if (!empty($_SESSION['csrf_token'])) {
        if (empty($token) || !SessionGuard::verifyCsrfToken($token)) {
            sendJson([
                'status'  => 'error',
                'message' => 'Invalid or expired CSRF token. Please refresh and try again.',
                'code'    => 'CSRF_INVALID'
            ], 403);
        }
    }
}

// Resolve effective staff profile from authenticated session
function getEffectiveStaffUser(PDO $pdo, array $authUser): array {
    $empId = $authUser['employee_id'] ?? '';
    $email = $authUser['email'] ?? '';
    $name  = $authUser['name'] ?? 'User';
    $role  = $authUser['role'] ?? 'Member';

    $staff = null;
    if (!empty($empId)) {
        $stmt = $pdo->prepare("SELECT id, name, role, email, initials, color_class, avatar_url FROM crm_team_staff WHERE id = ? LIMIT 1");
        $stmt->execute([$empId]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$staff && !empty($email)) {
        $stmt = $pdo->prepare("SELECT id, name, role, email, initials, color_class, avatar_url FROM crm_team_staff WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$staff && !empty($name)) {
        $stmt = $pdo->prepare("SELECT id, name, role, email, initials, color_class, avatar_url FROM crm_team_staff WHERE name = ? LIMIT 1");
        $stmt->execute([$name]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($staff) {
        return [
            'id'          => (string)$staff['id'],
            'name'        => (string)$staff['name'],
            'role'        => (string)$staff['role'],
            'email'       => (string)$staff['email'],
            'avatar'      => (string)($staff['avatar_url'] ?: ($staff['initials'] ?: '')),
            'color'       => (string)($staff['color_class'] ?: '#2563eb'),
            'is_admin'    => (strtolower($role) === 'admin' || strtolower($role) === 'superadmin')
        ];
    }

    return [
        'id'          => 'user_' . ($authUser['id'] ?? '1'),
        'name'        => (string)$name,
        'role'        => (string)$role,
        'email'       => (string)$email,
        'avatar'      => strtoupper(substr($name, 0, 2)),
        'color'       => '#2563eb',
        'is_admin'    => (strtolower($role) === 'admin' || strtolower($role) === 'superadmin')
    ];
}

// Verify if user is an active member of group, leader, or admin
function isUserAuthorizedForGroup(PDO $pdo, int $groupId, array $user): bool {
    if (!empty($user['is_admin'])) {
        return true;
    }
    $uid = $user['id'];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM crm_group_members WHERE group_id = ? AND employee_id = ?");
    $stmt->execute([$groupId, $uid]);
    if ((int)$stmt->fetchColumn() > 0) return true;

    $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM crm_groups WHERE id = ? AND leader_id = ?");
    $stmt2->execute([$groupId, $uid]);
    return ((int)$stmt2->fetchColumn() > 0);
}

// Get all member IDs of a group (including leader)
function getGroupMemberIds(PDO $pdo, int $groupId): array {
    $stmt = $pdo->prepare("SELECT employee_id FROM crm_group_members WHERE group_id = ?");
    $stmt->execute([$groupId]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $lstmt = $pdo->prepare("SELECT leader_id FROM crm_groups WHERE id = ?");
    $lstmt->execute([$groupId]);
    $leaderId = $lstmt->fetchColumn();
    if ($leaderId && !in_array($leaderId, $ids, true)) {
        $ids[] = $leaderId;
    }
    return $ids;
}

// Fetch helper for single group details + members + chart hierarchy
function fetchGroupFullDetails(PDO $pdo, int $groupId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM crm_groups WHERE id = ?");
    $stmt->execute([$groupId]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$group) {
        return null;
    }

    // Leader record
    $leader = null;
    if (!empty($group['leader_id'])) {
        $lstmt = $pdo->prepare("SELECT id, name, role, email, phone, department, initials, color_class, avatar_url, status FROM crm_team_staff WHERE id = ?");
        $lstmt->execute([$group['leader_id']]);
        $leader = $lstmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // Company record
    $company = null;
    if (!empty($group['company_id'])) {
        $cstmt = $pdo->prepare("SELECT id, name, industry, website, email, phone, address, logo_url FROM crm_companies WHERE id = ?");
        $cstmt->execute([$group['company_id']]);
        $company = $cstmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // Members list joined with crm_team_staff
    $mstmt = $pdo->prepare("
        SELECT 
            gm.id AS membership_id,
            gm.role_in_group,
            gm.added_at,
            s.id,
            s.name,
            s.role,
            s.email,
            s.phone,
            s.department,
            s.initials,
            s.color_class,
            s.avatar_url,
            s.status
        FROM crm_group_members gm
        JOIN crm_team_staff s ON s.id = gm.employee_id
        WHERE gm.group_id = ?
        ORDER BY 
            CASE WHEN s.id = ? THEN 0 ELSE 1 END,
            s.name ASC
    ");
    $mstmt->execute([$groupId, $group['leader_id'] ?? '']);
    $members = $mstmt->fetchAll(PDO::FETCH_ASSOC);

    // Annotate is_leader flag
    foreach ($members as &$m) {
        $m['is_leader'] = ($group['leader_id'] !== null && $m['id'] === $group['leader_id']);
    }
    unset($m);

    // Build visual tree structure for Group Chart visualization
    // Flat hierarchy: Group Leader at top, all other members underneath
    $children = [];
    foreach ($members as $m) {
        if ($group['leader_id'] !== null && $m['id'] === $group['leader_id']) {
            continue; // Leader is represented at the top node
        }
        $children[] = [
            'id'          => $m['id'],
            'name'        => $m['name'],
            'role'        => $m['role'],
            'email'       => $m['email'],
            'initials'    => $m['initials'],
            'color_class' => $m['color_class'],
            'avatar_url'  => $m['avatar_url'],
            'status'      => $m['status'],
            'role_in_group' => $m['role_in_group']
        ];
    }

    $tree = [
        'leader' => $leader ? [
            'id'          => $leader['id'],
            'name'        => $leader['name'],
            'role'        => $leader['role'],
            'email'       => $leader['email'],
            'initials'    => $leader['initials'],
            'color_class' => $leader['color_class'],
            'avatar_url'  => $leader['avatar_url'],
            'status'      => $leader['status'],
            'role_in_group' => 'leader'
        ] : null,
        'members' => $children,
        'total_members' => count($members)
    ];

    $group['leader'] = $leader;
    $group['company'] = $company;
    $group['company_name'] = $company ? $company['name'] : null;
    $group['members'] = $members;
    $group['member_count'] = count($members);
    $group['tree'] = $tree;

    return $group;
}

$method = $_SERVER['REQUEST_METHOD'];

// Handle GET requests: list, get, summary, available_employees
if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;

    // 1. SUMMARY DASHBOARD METRICS
    if ($action === 'summary') {
        try {
            // Total Groups
            $totalGroups = (int)$pdo->query("SELECT COUNT(*) FROM crm_groups")->fetchColumn();

            // Total Active Team Members
            $totalActiveMembers = (int)$pdo->query("SELECT COUNT(*) FROM crm_team_staff WHERE LOWER(status) = 'active'")->fetchColumn();

            // Members Without a Group (Active staff not in crm_group_members and not a leader in crm_groups)
            $unassignedStmt = $pdo->query("
                SELECT COUNT(*) FROM crm_team_staff
                WHERE LOWER(status) = 'active'
                  AND id NOT IN (SELECT employee_id FROM crm_group_members)
                  AND id NOT IN (SELECT leader_id FROM crm_groups WHERE leader_id IS NOT NULL)
            ");
            $unassignedMembers = (int)$unassignedStmt->fetchColumn();

            // Largest Group
            $largestStmt = $pdo->query("
                SELECT 
                    g.id, 
                    g.name, 
                    g.color, 
                    g.icon, 
                    COUNT(gm.employee_id) AS member_count, 
                    s.name AS leader_name
                FROM crm_groups g
                LEFT JOIN crm_group_members gm ON gm.group_id = g.id
                LEFT JOIN crm_team_staff s ON s.id = g.leader_id
                GROUP BY g.id
                ORDER BY member_count DESC, g.id ASC
                LIMIT 1
            ");
            $largestGroup = $largestStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            sendJson([
                'status' => 'success',
                'data'   => [
                    'total_groups'            => $totalGroups,
                    'total_active_members'    => $totalActiveMembers,
                    'members_without_group'   => $unassignedMembers,
                    'largest_group'           => $largestGroup
                ]
            ]);
        } catch (Exception $e) {
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // 2. SEARCH & AVAILABLE EMPLOYEES MODAL
    if ($action === 'available_employees') {
        $groupId = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;
        $q = trim($_GET['q'] ?? '');

        try {
            $sql = "SELECT id, name, role, email, department, initials, color_class, avatar_url, status FROM crm_team_staff WHERE LOWER(status) = 'active'";
            $params = [];

            if ($q !== '') {
                $sql .= " AND (name LIKE :q1 OR email LIKE :q2 OR role LIKE :q3 OR department LIKE :q4)";
                $params[':q1'] = "%$q%";
                $params[':q2'] = "%$q%";
                $params[':q3'] = "%$q%";
                $params[':q4'] = "%$q%";
            }

            $sql .= " ORDER BY name ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch current group members to flag already added
            $currentMemberIds = [];
            $leaderId = null;
            if ($groupId > 0) {
                $mstmt = $pdo->prepare("SELECT employee_id FROM crm_group_members WHERE group_id = ?");
                $mstmt->execute([$groupId]);
                $currentMemberIds = $mstmt->fetchAll(PDO::FETCH_COLUMN);

                $lstmt = $pdo->prepare("SELECT leader_id FROM crm_groups WHERE id = ?");
                $lstmt->execute([$groupId]);
                $leaderId = $lstmt->fetchColumn();
            }

            foreach ($employees as &$emp) {
                $emp['already_in_group'] = in_array($emp['id'], $currentMemberIds, true);
                $emp['is_leader'] = ($leaderId !== null && $emp['id'] === $leaderId);
            }
            unset($emp);

            sendJson(['status' => 'success', 'data' => $employees]);
        } catch (Exception $e) {
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // 3. SINGLE GROUP DETAILS (WITH CHART HIERARCHY)
    if ($id && ($action === 'get' || (empty($action) && !isset($_GET['group_id'])))) {
        try {
            $group = fetchGroupFullDetails($pdo, $id);
            if (!$group) {
                sendJson(['status' => 'error', 'message' => 'Group not found'], 404);
            }
            sendJson(['status' => 'success', 'data' => $group]);
        } catch (Exception $e) {
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // 3A. GET GROUP MESSAGES
    if ($action === 'get_group_messages' || $action === 'messages') {
        $groupId = (int)($_GET['group_id'] ?? ($id ?? 0));
        if ($groupId <= 0) {
            sendJson(['status' => 'error', 'message' => 'Valid Group ID required'], 400);
        }

        $user = getEffectiveStaffUser($pdo, $authUser);
        if (!isUserAuthorizedForGroup($pdo, $groupId, $user)) {
            sendJson(['status' => 'error', 'message' => 'Access denied. You are not an active member of this private group.'], 403);
        }

        $lastId = (int)($_GET['last_id'] ?? 0);

        try {
            // Auto mark delivery for incoming messages
            $pdo->prepare("
                UPDATE crm_group_message_receipts 
                SET delivered_at = NOW() 
                WHERE group_id = ? AND user_id = ? AND delivered_at IS NULL
            ")->execute([$groupId, $user['id']]);

            // Auto mark read if requested
            if (!empty($_GET['mark_read'])) {
                $pdo->prepare("
                    UPDATE crm_group_message_receipts 
                    SET read_at = NOW(), delivered_at = COALESCE(delivered_at, NOW())
                    WHERE group_id = ? AND user_id = ? AND read_at IS NULL
                ")->execute([$groupId, $user['id']]);
            }

            // Fetch messages not deleted for this user
            $sql = "
                SELECT c.*
                FROM crm_group_chats c
                WHERE c.group_id = :gid
                  AND c.id > :last_id
                  AND c.id NOT IN (
                      SELECT message_id FROM crm_group_message_deletions WHERE user_id = :uid
                  )
                ORDER BY c.id ASC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':gid'     => $groupId,
                ':last_id' => $lastId,
                ':uid'     => $user['id']
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $messages = [];
            foreach ($rows as $r) {
                $isMe = ($r['sender_id'] === $user['id'] || $r['sender_name'] === $user['name']);
                
                $content = $r['message'];
                $attUrl  = $r['attachment_url'];
                $attName = $r['attachment_name'];
                $attType = $r['attachment_type'];

                if ($r['deletion_state'] === 'unsent') {
                    $content = 'This message was unsent.';
                    $attUrl = null; $attName = null; $attType = null;
                } elseif ($r['deletion_state'] === 'deleted_everyone') {
                    $content = 'This message was deleted.';
                    $attUrl = null; $attName = null; $attType = null;
                }

                $status = 'sent';
                $readCount = 0;
                $deliveredCount = 0;
                $totalRecipients = 0;

                if ($isMe) {
                    $rcptStmt = $pdo->prepare("
                        SELECT 
                            COUNT(*) AS total,
                            SUM(CASE WHEN delivered_at IS NOT NULL THEN 1 ELSE 0 END) AS delivered,
                            SUM(CASE WHEN read_at IS NOT NULL THEN 1 ELSE 0 END) AS is_read
                        FROM crm_group_message_receipts
                        WHERE message_id = ?
                    ");
                    $rcptStmt->execute([$r['id']]);
                    $rcptData = $rcptStmt->fetch(PDO::FETCH_ASSOC);
                    if ($rcptData) {
                        $totalRecipients = (int)$rcptData['total'];
                        $deliveredCount = (int)$rcptData['delivered'];
                        $readCount = (int)$rcptData['is_read'];

                        if ($totalRecipients > 0 && $readCount >= $totalRecipients) {
                            $status = 'read'; // double blue ticks
                        } elseif ($readCount > 0) {
                            $status = 'read'; // read by some
                        } elseif ($deliveredCount > 0) {
                            $status = 'delivered'; // double grey ticks
                        } else {
                            $status = 'sent'; // single grey tick
                        }
                    }
                }

                $messages[] = [
                    'id'              => (int)$r['id'],
                    'group_id'        => (int)$r['group_id'],
                    'sender_id'       => $r['sender_id'],
                    'sender_name'     => $r['sender_name'],
                    'sender_role'     => $r['sender_role'],
                    'sender_color'    => $r['sender_color'],
                    'sender_avatar'   => $r['sender_avatar'],
                    'message_type'    => $r['message_type'],
                    'message'         => $content,
                    'attachment_type' => $attType,
                    'attachment_url'  => $attUrl,
                    'attachment_name' => $attName,
                    'reply_to_id'     => $r['reply_to_id'] ? (int)$r['reply_to_id'] : null,
                    'reply_to_name'   => $r['reply_to_name'],
                    'reply_to_text'   => $r['reply_to_text'],
                    'deletion_state'  => $r['deletion_state'],
                    'edited_at'       => $r['edited_at'],
                    'created_at'      => $r['created_at'],
                    'time_str'        => date('h:i a', strtotime($r['created_at'])),
                    'is_me'           => $isMe,
                    'is_own'          => $isMe,
                    'status'          => $status,
                    'receipt_summary' => $status,
                    'read_count'      => $readCount,
                    'delivered_count' => $deliveredCount,
                    'total_recipients'=> $totalRecipients
                ];
            }

            sendJson([
                'status'   => 'success',
                'group_id' => $groupId,
                'data'     => $messages
            ]);
        } catch (Exception $e) {
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // 3B. GET MESSAGE INFO (DELIVERY TICKS & READ RECEIPTS DETAILS)
    if ($action === 'get_message_info' || $action === 'message_info') {
        $messageId = (int)($_GET['message_id'] ?? 0);
        if ($messageId <= 0) {
            sendJson(['status' => 'error', 'message' => 'Valid Message ID required'], 400);
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM crm_group_chats WHERE id = ?");
            $stmt->execute([$messageId]);
            $msg = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$msg) {
                sendJson(['status' => 'error', 'message' => 'Message not found'], 404);
            }

            $user = getEffectiveStaffUser($pdo, $authUser);
            if (!isUserAuthorizedForGroup($pdo, (int)$msg['group_id'], $user)) {
                sendJson(['status' => 'error', 'message' => 'Access denied'], 403);
            }

            // Receipts
            $rStmt = $pdo->prepare("
                SELECT r.*, s.name, s.role, s.avatar_url, s.initials, s.color_class
                FROM crm_group_message_receipts r
                LEFT JOIN crm_team_staff s ON s.id = r.user_id
                WHERE r.message_id = ?
                ORDER BY r.read_at DESC, r.delivered_at DESC
            ");
            $rStmt->execute([$messageId]);
            $receipts = $rStmt->fetchAll(PDO::FETCH_ASSOC);

            $readBy = [];
            $deliveredTo = [];
            $pending = [];

            foreach ($receipts as $rc) {
                $pInfo = [
                    'user_id'     => $rc['user_id'],
                    'name'        => $rc['name'] ?: $rc['user_name'],
                    'role'        => $rc['role'] ?: 'Member',
                    'avatar'      => $rc['avatar_url'] ?: ($rc['initials'] ?: ''),
                    'color_class' => $rc['color_class'] ?: 'bg-blue-600',
                    'delivered_at'=> $rc['delivered_at'],
                    'read_at'     => $rc['read_at'],
                    'read_time'   => $rc['read_at'] ? date('M j, Y h:i a', strtotime($rc['read_at'])) : null,
                    'deliv_time'  => $rc['delivered_at'] ? date('M j, Y h:i a', strtotime($rc['delivered_at'])) : null,
                ];

                if ($rc['read_at']) {
                    $readBy[] = $pInfo;
                }
                if ($rc['delivered_at']) {
                    $deliveredTo[] = $pInfo;
                }
                if (!$rc['read_at'] && !$rc['delivered_at']) {
                    $pending[] = $pInfo;
                }
            }

            $content = $msg['message'];
            if ($msg['deletion_state'] === 'unsent') $content = 'This message was unsent.';
            if ($msg['deletion_state'] === 'deleted_everyone') $content = 'This message was deleted.';

            sendJson([
                'status' => 'success',
                'data'   => [
                    'id'              => (int)$msg['id'],
                    'group_id'        => (int)$msg['group_id'],
                    'sender_name'     => $msg['sender_name'],
                    'message'         => $content,
                    'deletion_state'  => $msg['deletion_state'],
                    'created_at'      => $msg['created_at'],
                    'sent_time'       => date('M j, Y h:i a', strtotime($msg['created_at'])),
                    'read_by'         => $readBy,
                    'delivered_to'    => $deliveredTo,
                    'pending'         => $pending,
                    'total_recipients'=> count($receipts),
                    'read_count'      => count($readBy),
                    'delivered_count' => count($deliveredTo)
                ]
            ]);
        } catch (Exception $e) {
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // 3C. REAL-TIME CHAT & WEBRTC CALL SYNC
    if ($action === 'chat_sync' || $action === 'sync') {
        $groupId = (int)($_GET['group_id'] ?? 0);
        if ($groupId <= 0) {
            sendJson(['status' => 'error', 'message' => 'Valid Group ID required'], 400);
        }

        $user = getEffectiveStaffUser($pdo, $authUser);
        if (!isUserAuthorizedForGroup($pdo, $groupId, $user)) {
            sendJson(['status' => 'error', 'message' => 'Access denied'], 403);
        }

        $lastMsgId = (int)($_GET['last_msg_id'] ?? 0);
        $lastSignalId = (int)($_GET['last_signal_id'] ?? 0);

        try {
            // Auto mark delivery
            $pdo->prepare("
                UPDATE crm_group_message_receipts 
                SET delivered_at = NOW() 
                WHERE group_id = ? AND user_id = ? AND delivered_at IS NULL
            ")->execute([$groupId, $user['id']]);

            // Auto mark read if requested
            if (!empty($_GET['mark_read'])) {
                $pdo->prepare("
                    UPDATE crm_group_message_receipts 
                    SET read_at = NOW(), delivered_at = COALESCE(delivered_at, NOW())
                    WHERE group_id = ? AND user_id = ? AND read_at IS NULL
                ")->execute([$groupId, $user['id']]);
            }

            // Fetch new messages
            $nStmt = $pdo->prepare("
                SELECT c.*
                FROM crm_group_chats c
                WHERE c.group_id = :gid AND c.id > :lmid
                  AND c.id NOT IN (SELECT message_id FROM crm_group_message_deletions WHERE user_id = :uid)
                ORDER BY c.id ASC
            ");
            $nStmt->execute([':gid' => $groupId, ':lmid' => $lastMsgId, ':uid' => $user['id']]);
            $newRows = $nStmt->fetchAll(PDO::FETCH_ASSOC);

            $formattedNew = [];
            foreach ($newRows as $nr) {
                $isMe = ($nr['sender_id'] === $user['id'] || $nr['sender_name'] === $user['name']);
                $content = $nr['message'];
                $attUrl = $nr['attachment_url'];
                if ($nr['deletion_state'] === 'unsent') {
                    $content = 'This message was unsent.';
                    $attUrl = null;
                } elseif ($nr['deletion_state'] === 'deleted_everyone') {
                    $content = 'This message was deleted.';
                    $attUrl = null;
                }
                $formattedNew[] = [
                    'id'              => (int)$nr['id'],
                    'group_id'        => (int)$nr['group_id'],
                    'sender_id'       => $nr['sender_id'],
                    'sender_name'     => $nr['sender_name'],
                    'sender_role'     => $nr['sender_role'],
                    'sender_color'    => $nr['sender_color'],
                    'sender_avatar'   => $nr['sender_avatar'],
                    'message_type'    => $nr['message_type'],
                    'message'         => $content,
                    'attachment_type' => $nr['attachment_type'],
                    'attachment_url'  => $attUrl,
                    'attachment_name' => $nr['attachment_name'],
                    'reply_to_id'     => $nr['reply_to_id'] ? (int)$nr['reply_to_id'] : null,
                    'reply_to_name'   => $nr['reply_to_name'],
                    'reply_to_text'   => $nr['reply_to_text'],
                    'deletion_state'  => $nr['deletion_state'],
                    'edited_at'       => $nr['edited_at'],
                    'created_at'      => $nr['created_at'],
                    'time_str'        => date('h:i a', strtotime($nr['created_at'])),
                    'is_me'           => $isMe,
                    'is_own'          => $isMe,
                    'status'          => 'sent',
                    'receipt_summary' => 'sent'
                ];
            }

            // Fetch any modified messages (unsent, deleted, or edited)
            $dStmt = $pdo->prepare("
                SELECT id, deletion_state, edited_at, message
                FROM crm_group_chats
                WHERE group_id = :gid AND id <= :lmid AND (deletion_state != 'none' OR edited_at IS NOT NULL)
            ");
            $dStmt->execute([':gid' => $groupId, ':lmid' => $lastMsgId]);
            $updatedRows = $dStmt->fetchAll(PDO::FETCH_ASSOC);

            // Active call in this group
            $cStmt = $pdo->prepare("
                SELECT * FROM crm_group_calls
                WHERE group_id = ? AND call_status IN ('calling', 'ringing', 'connecting', 'connected')
                ORDER BY id DESC LIMIT 1
            ");
            $cStmt->execute([$groupId]);
            $activeCall = $cStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            $participants = [];
            $signals = [];
            if ($activeCall) {
                $callId = (int)$activeCall['id'];
                $pStmt = $pdo->prepare("SELECT * FROM crm_group_call_participants WHERE call_id = ? AND status = 'joined'");
                $pStmt->execute([$callId]);
                $participants = $pStmt->fetchAll(PDO::FETCH_ASSOC);

                $sStmt = $pdo->prepare("
                    SELECT * FROM crm_group_call_signals
                    WHERE call_id = :cid AND id > :lsid
                      AND sender_id != :uid1
                      AND (receiver_id = :uid2 OR receiver_id IS NULL)
                    ORDER BY id ASC
                ");
                $sStmt->execute([':cid' => $callId, ':lsid' => $lastSignalId, ':uid1' => $user['id'], ':uid2' => $user['id']]);
                $signals = $sStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            sendJson([
                'status'           => 'success',
                'new_messages'     => $formattedNew,
                'updated_messages' => $updatedRows,
                'active_call'      => $activeCall,
                'participants'     => $participants,
                'signals'          => $signals
            ]);
        } catch (Exception $e) {
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // 3D. MEMBER CHART & WORKLOAD ANALYTICS
    if ($action === 'member_chart_data') {
        $empId = trim($_GET['employee_id'] ?? '');
        $groupId = (int)($_GET['group_id'] ?? 0);
        if (empty($empId)) {
            sendJson(['status' => 'error', 'message' => 'Employee ID is required'], 400);
        }

        try {
            $sstmt = $pdo->prepare("SELECT id, name, role, email, phone, department, initials, color_class, avatar_url, status FROM crm_team_staff WHERE id = ?");
            $sstmt->execute([$empId]);
            $member = $sstmt->fetch(PDO::FETCH_ASSOC);
            if (!$member) {
                sendJson(['status' => 'error', 'message' => 'Employee not found'], 404);
            }

            // Tasks assigned to this staff member
            $tstmt = $pdo->prepare("
                SELECT id, title, status, completed, priority, category, due_date, client_name, project_name
                FROM crm_tasks
                WHERE assigned_staff = ? OR assigned_staff LIKE ?
                ORDER BY id DESC
            ");
            $tstmt->execute([$member['name'], "%{$member['name']}%"]);
            $tasks = $tstmt->fetchAll(PDO::FETCH_ASSOC);

            $totalTasks = count($tasks);
            $completedTasks = 0;
            $inProgressTasks = 0;
            $pendingTasks = 0;

            $priorities = ['Urgent' => 0, 'High' => 0, 'Medium' => 0, 'Low' => 0];
            $categories = [];

            foreach ($tasks as $t) {
                if ($t['completed'] || strtolower($t['status'] ?? '') === 'completed') {
                    $completedTasks++;
                } elseif (strtolower($t['status'] ?? '') === 'in progress') {
                    $inProgressTasks++;
                } else {
                    $pendingTasks++;
                }

                $prio = ucfirst(strtolower($t['priority'] ?? 'Medium'));
                if (isset($priorities[$prio])) {
                    $priorities[$prio]++;
                } else {
                    $priorities['Medium']++;
                }

                $cat = $t['category'] ?: 'General';
                $categories[$cat] = ($categories[$cat] ?? 0) + 1;
            }

            $completionRate = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100) : 0;

            // Projects
            $pstmt = $pdo->prepare("
                SELECT id, title, status, progress, budget, category
                FROM crm_projects
                WHERE project_manager = ? OR team_members LIKE ?
                ORDER BY id DESC LIMIT 5
            ");
            $pstmt->execute([$member['name'], "%{$member['name']}%"]);
            $projects = $pstmt->fetchAll(PDO::FETCH_ASSOC);

            sendJson([
                'status' => 'success',
                'data'   => [
                    'member'             => $member,
                    'summary'            => [
                        'total_tasks'        => $totalTasks,
                        'completed_tasks'    => $completedTasks,
                        'in_progress_tasks'  => $inProgressTasks,
                        'pending_tasks'      => $pendingTasks,
                        'completion_rate'    => $completionRate
                    ],
                    'total_tasks'        => $totalTasks,
                    'completed_tasks'    => $completedTasks,
                    'in_progress'        => $inProgressTasks,
                    'pending_tasks'      => $pendingTasks,
                    'completion_rate'    => $completionRate,
                    'priority_breakdown' => $priorities,
                    'category_breakdown' => $categories,
                    'projects'           => $projects,
                    'recent_tasks'       => array_slice($tasks, 0, 8)
                ]
            ]);
        } catch (Exception $e) {
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // 4. LIST GROUPS (WITH MEMBER COUNT, LEADER & PREVIEWS)
    try {
        $q = trim($_GET['q'] ?? '');
        $sql = "
            SELECT 
                g.id,
                g.name,
                g.description,
                g.company_id,
                g.leader_id,
                g.color,
                g.icon,
                g.created_by,
                g.created_at,
                g.updated_at,
                c.name AS company_name,
                c.industry AS company_industry,
                s.name AS leader_name,
                s.role AS leader_role,
                s.email AS leader_email,
                s.initials AS leader_initials,
                s.color_class AS leader_color_class,
                s.avatar_url AS leader_avatar_url,
                COUNT(gm.employee_id) AS member_count
            FROM crm_groups g
            LEFT JOIN crm_companies c ON c.id = g.company_id
            LEFT JOIN crm_team_staff s ON s.id = g.leader_id
            LEFT JOIN crm_group_members gm ON gm.group_id = g.id
            WHERE 1=1
        ";
        $params = [];
        if ($q !== '') {
            $sql .= " AND (g.name LIKE :q1 OR g.description LIKE :q2 OR c.name LIKE :q3)";
            $params[':q1'] = "%$q%";
            $params[':q2'] = "%$q%";
            $params[':q3'] = "%$q%";
        }
        $sql .= " GROUP BY g.id ORDER BY g.id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch top 5 preview members for each group
        foreach ($groups as &$grp) {
            $previewStmt = $pdo->prepare("
                SELECT s.id, s.name, s.role, s.initials, s.color_class, s.avatar_url
                FROM crm_group_members gm
                JOIN crm_team_staff s ON s.id = gm.employee_id
                WHERE gm.group_id = ?
                ORDER BY CASE WHEN s.id = ? THEN 0 ELSE 1 END, s.name ASC
                LIMIT 5
            ");
            $previewStmt->execute([$grp['id'], $grp['leader_id'] ?? '']);
            $grp['preview_members'] = $previewStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($grp);

        sendJson(['status' => 'success', 'data' => $groups]);
    } catch (Exception $e) {
        sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}

// Handle DELETE HTTP method (direct delete)
if ($method === 'DELETE') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: $_GET;
    verifyRequestCsrf($input);

    $id = (int)($input['id'] ?? ($_GET['id'] ?? 0));
    if ($id <= 0) {
        sendJson(['status' => 'error', 'message' => 'Valid Group ID is required'], 400);
    }

    try {
        $stmt = $pdo->prepare("SELECT id, name FROM crm_groups WHERE id = ?");
        $stmt->execute([$id]);
        $grp = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$grp) {
            sendJson(['status' => 'error', 'message' => 'Group not found'], 404);
        }

        // Deleting a group: delete membership records and group record
        // CRITICAL: Employee records in crm_team_staff are completely preserved
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM crm_group_members WHERE group_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM crm_groups WHERE id = ?")->execute([$id]);
        $pdo->commit();

        sendJson([
            'status'  => 'success',
            'message' => "Group '{$grp['name']}' deleted successfully. Employee records were preserved.",
            'id'      => $id
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}

// Handle POST requests: create, update, delete, add_members, remove_member, change_leader
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: $_POST;
    $action = $input['action'] ?? ($_GET['action'] ?? 'create');

    verifyRequestCsrf($input);

    // 1. CREATE GROUP
    if ($action === 'create') {
        $name = trim($input['name'] ?? '');
        $description = trim($input['description'] ?? '');
        $companyId = !empty($input['company_id']) ? trim($input['company_id']) : null;
        $leaderId = !empty($input['leader_id']) ? trim($input['leader_id']) : null;
        $color = !empty($input['color']) ? trim($input['color']) : '#2563eb';
        $icon = !empty($input['icon']) ? trim($input['icon']) : 'users';
        $memberIds = isset($input['member_ids']) && is_array($input['member_ids']) ? $input['member_ids'] : [];

        // Validation: Name is required and length check
        if (mb_strlen($name) < 2) {
            sendJson(['status' => 'error', 'message' => 'Group name is required and must be at least 2 characters long'], 400);
        }
        if (mb_strlen($name) > 150) {
            sendJson(['status' => 'error', 'message' => 'Group name cannot exceed 150 characters'], 400);
        }

        // Validation: Company is required
        if (empty($companyId)) {
            sendJson(['status' => 'error', 'message' => 'Company is required. Please select a company for this group.'], 400);
        }

        // Validation: Company must exist in crm_companies
        $cchk = $pdo->prepare("SELECT id FROM crm_companies WHERE id = ?");
        $cchk->execute([$companyId]);
        if (!$cchk->fetch()) {
            sendJson(['status' => 'error', 'message' => 'The selected company does not exist in the database.'], 400);
        }

        // Validation: Unique group name (case-insensitive)
        $chk = $pdo->prepare("SELECT id FROM crm_groups WHERE LOWER(TRIM(name)) = LOWER(?)");
        $chk->execute([$name]);
        if ($chk->fetch()) {
            sendJson(['status' => 'error', 'message' => "A group with the name '{$name}' already exists. Please choose a different name."], 400);
        }

        // Validation: Leader must exist in crm_team_staff if specified
        if ($leaderId !== null) {
            $lchk = $pdo->prepare("SELECT id FROM crm_team_staff WHERE id = ?");
            $lchk->execute([$leaderId]);
            if (!$lchk->fetch()) {
                sendJson(['status' => 'error', 'message' => 'Selected group leader does not exist in the employee database'], 400);
            }
        }

        try {
            $pdo->beginTransaction();

            $insertStmt = $pdo->prepare("
                INSERT INTO crm_groups (name, description, company_id, leader_id, color, icon, created_by, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $insertStmt->execute([
                $name,
                $description ?: null,
                $companyId,
                $leaderId,
                $color,
                $icon,
                $authUser['name'] ?? 'Admin'
            ]);
            $groupId = (int)$pdo->lastInsertId();

            // Automatically add leader to group members if specified
            if ($leaderId !== null) {
                $mInsert = $pdo->prepare("INSERT IGNORE INTO crm_group_members (group_id, employee_id, role_in_group) VALUES (?, ?, 'leader')");
                $mInsert->execute([$groupId, $leaderId]);
            }

            // Add initial members
            if (!empty($memberIds)) {
                $mInsert = $pdo->prepare("INSERT IGNORE INTO crm_group_members (group_id, employee_id, role_in_group) VALUES (?, ?, 'member')");
                foreach ($memberIds as $mId) {
                    $mId = trim((string)$mId);
                    if (!empty($mId)) {
                        $mInsert->execute([$groupId, $mId]);
                    }
                }
            }

            $pdo->commit();

            // Notify system if notification service available
            if (class_exists('NotificationService')) {
                try {
                    NotificationService::create(
                        $pdo,
                        'system',
                        'group_created',
                        'New Group Created',
                        "Group '{$name}' was created successfully.",
                        'group',
                        (string)$groupId,
                        'View Group'
                    );
                } catch (Exception $ne) {
                    // Non-blocking notification failure
                }
            }

            $groupData = fetchGroupFullDetails($pdo, $groupId);
            sendJson([
                'status'  => 'success',
                'message' => "Group '{$name}' created successfully",
                'data'    => $groupData
            ], 201);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // 2. UPDATE GROUP DETAILS
    if ($action === 'update') {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            sendJson(['status' => 'error', 'message' => 'Valid Group ID is required'], 400);
        }

        $stmt = $pdo->prepare("SELECT * FROM crm_groups WHERE id = ?");
        $stmt->execute([$id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            sendJson(['status' => 'error', 'message' => 'Group not found'], 404);
        }

        $name = isset($input['name']) ? trim($input['name']) : $existing['name'];
        $description = isset($input['description']) ? trim($input['description']) : $existing['description'];
        $color = !empty($input['color']) ? trim($input['color']) : $existing['color'];
        $icon = !empty($input['icon']) ? trim($input['icon']) : $existing['icon'];
        $leaderId = array_key_exists('leader_id', $input) ? (!empty($input['leader_id']) ? trim($input['leader_id']) : null) : $existing['leader_id'];
        $companyId = array_key_exists('company_id', $input) ? (!empty($input['company_id']) ? trim($input['company_id']) : null) : $existing['company_id'];

        if (mb_strlen($name) < 2) {
            sendJson(['status' => 'error', 'message' => 'Group name is required and must be at least 2 characters'], 400);
        }
        if (mb_strlen($name) > 150) {
            sendJson(['status' => 'error', 'message' => 'Group name cannot exceed 150 characters'], 400);
        }

        // Company validation if company_id is provided or updated
        if (array_key_exists('company_id', $input)) {
            if (empty($companyId)) {
                sendJson(['status' => 'error', 'message' => 'Company is required. Please select a company for this group.'], 400);
            }
            $cchk = $pdo->prepare("SELECT id FROM crm_companies WHERE id = ?");
            $cchk->execute([$companyId]);
            if (!$cchk->fetch()) {
                sendJson(['status' => 'error', 'message' => 'The selected company does not exist in the database.'], 400);
            }
        }

        // Check duplicate name excluding current group
        $chk = $pdo->prepare("SELECT id FROM crm_groups WHERE LOWER(TRIM(name)) = LOWER(?) AND id != ?");
        $chk->execute([$name, $id]);
        if ($chk->fetch()) {
            sendJson(['status' => 'error', 'message' => "Another group named '{$name}' already exists. Please choose a unique name."], 400);
        }

        // Leader verification
        if ($leaderId !== null) {
            $lchk = $pdo->prepare("SELECT id FROM crm_team_staff WHERE id = ?");
            $lchk->execute([$leaderId]);
            if (!$lchk->fetch()) {
                sendJson(['status' => 'error', 'message' => 'Selected group leader does not exist in the employee database'], 400);
            }
        }

        try {
            $pdo->beginTransaction();

            $upd = $pdo->prepare("
                UPDATE crm_groups 
                SET name = ?, description = ?, company_id = ?, leader_id = ?, color = ?, icon = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $upd->execute([$name, $description, $companyId, $leaderId, $color, $icon, $id]);

            // If leader is assigned, ensure they are also in crm_group_members
            if ($leaderId !== null) {
                $mInsert = $pdo->prepare("INSERT IGNORE INTO crm_group_members (group_id, employee_id, role_in_group) VALUES (?, ?, 'leader')");
                $mInsert->execute([$id, $leaderId]);
            }

            $pdo->commit();

            $groupData = fetchGroupFullDetails($pdo, $id);
            sendJson([
                'status'  => 'success',
                'message' => "Group '{$name}' updated successfully",
                'data'    => $groupData
            ]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // 3. CHANGE GROUP LEADER
    if ($action === 'change_leader') {
        $groupId = (int)($input['group_id'] ?? 0);
        $newLeaderId = !empty($input['leader_id']) ? trim($input['leader_id']) : null;

        if ($groupId <= 0) {
            sendJson(['status' => 'error', 'message' => 'Valid Group ID is required'], 400);
        }

        $stmt = $pdo->prepare("SELECT * FROM crm_groups WHERE id = ?");
        $stmt->execute([$groupId]);
        $grp = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$grp) {
            sendJson(['status' => 'error', 'message' => 'Group not found'], 404);
        }

        if ($newLeaderId !== null) {
            $lchk = $pdo->prepare("SELECT id, name FROM crm_team_staff WHERE id = ?");
            $lchk->execute([$newLeaderId]);
            $newLeader = $lchk->fetch(PDO::FETCH_ASSOC);
            if (!$newLeader) {
                sendJson(['status' => 'error', 'message' => 'Selected leader does not exist in the CRM employee records'], 400);
            }
        }

        try {
            $pdo->beginTransaction();

            $upd = $pdo->prepare("UPDATE crm_groups SET leader_id = ?, updated_at = NOW() WHERE id = ?");
            $upd->execute([$newLeaderId, $groupId]);

            if ($newLeaderId !== null) {
                $ins = $pdo->prepare("INSERT IGNORE INTO crm_group_members (group_id, employee_id, role_in_group) VALUES (?, ?, 'leader')");
                $ins->execute([$groupId, $newLeaderId]);
            }

            $pdo->commit();

            $groupData = fetchGroupFullDetails($pdo, $groupId);
            sendJson([
                'status'  => 'success',
                'message' => $newLeaderId ? "Group leader assigned to {$newLeader['name']}" : "Group leader unassigned",
                'data'    => $groupData
            ]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // 4. ADD TEAM MEMBERS TO GROUP (SINGLE OR BULK)
    if ($action === 'add_members') {
        $groupId = (int)($input['group_id'] ?? 0);
        $employeeIds = $input['employee_ids'] ?? [];

        if (is_string($employeeIds)) {
            $employeeIds = array_filter(array_map('trim', explode(',', $employeeIds)));
        }

        if ($groupId <= 0) {
            sendJson(['status' => 'error', 'message' => 'Valid Group ID is required'], 400);
        }
        if (empty($employeeIds) || !is_array($employeeIds)) {
            sendJson(['status' => 'error', 'message' => 'Please select at least one team member to add'], 400);
        }

        $stmt = $pdo->prepare("SELECT id, name FROM crm_groups WHERE id = ?");
        $stmt->execute([$groupId]);
        $grp = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$grp) {
            sendJson(['status' => 'error', 'message' => 'Group not found'], 404);
        }

        try {
            $pdo->beginTransaction();

            $addedCount = 0;
            $ins = $pdo->prepare("INSERT IGNORE INTO crm_group_members (group_id, employee_id, role_in_group) VALUES (?, ?, 'member')");

            foreach ($employeeIds as $eId) {
                $eId = trim((string)$eId);
                if (empty($eId)) continue;

                // Validate employee exists in crm_team_staff
                $chkEmp = $pdo->prepare("SELECT id FROM crm_team_staff WHERE id = ?");
                $chkEmp->execute([$eId]);
                if ($chkEmp->fetch()) {
                    $ins->execute([$groupId, $eId]);
                    if ($ins->rowCount() > 0) {
                        $addedCount++;
                    }
                }
            }

            $pdo->commit();

            $groupData = fetchGroupFullDetails($pdo, $groupId);
            sendJson([
                'status'      => 'success',
                'message'     => "{$addedCount} member(s) added to '{$grp['name']}'",
                'added_count' => $addedCount,
                'data'        => $groupData
            ]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // 5. REMOVE MEMBER FROM GROUP
    if ($action === 'remove_member') {
        $groupId = (int)($input['group_id'] ?? 0);
        $employeeId = trim((string)($input['employee_id'] ?? ''));
        $newLeaderId = !empty($input['new_leader_id']) ? trim($input['new_leader_id']) : null;

        if ($groupId <= 0 || empty($employeeId)) {
            sendJson(['status' => 'error', 'message' => 'Group ID and Employee ID are required'], 400);
        }

        $stmt = $pdo->prepare("SELECT * FROM crm_groups WHERE id = ?");
        $stmt->execute([$groupId]);
        $grp = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$grp) {
            sendJson(['status' => 'error', 'message' => 'Group not found'], 404);
        }

        // Verify employee exists in CRM
        $estmt = $pdo->prepare("SELECT id, name FROM crm_team_staff WHERE id = ?");
        $estmt->execute([$employeeId]);
        $emp = $estmt->fetch(PDO::FETCH_ASSOC);
        $empName = $emp ? $emp['name'] : 'Team Member';

        try {
            $pdo->beginTransaction();

            // If removed employee is current leader
            $wasLeader = ($grp['leader_id'] !== null && $grp['leader_id'] === $employeeId);
            if ($wasLeader) {
                if ($newLeaderId !== null && $newLeaderId !== $employeeId) {
                    $nchk = $pdo->prepare("SELECT id FROM crm_team_staff WHERE id = ?");
                    $nchk->execute([$newLeaderId]);
                    if ($nchk->fetch()) {
                        $pdo->prepare("UPDATE crm_groups SET leader_id = ?, updated_at = NOW() WHERE id = ?")->execute([$newLeaderId, $groupId]);
                        $pdo->prepare("INSERT IGNORE INTO crm_group_members (group_id, employee_id, role_in_group) VALUES (?, ?, 'leader')")->execute([$groupId, $newLeaderId]);
                    }
                } else {
                    // Temporarily leave group leaderless per application rules
                    $pdo->prepare("UPDATE crm_groups SET leader_id = NULL, updated_at = NOW() WHERE id = ?")->execute([$groupId]);
                }
            }

            // Remove ONLY membership relationship
            // CRITICAL: Employee's account and records in crm_team_staff are untouched!
            $del = $pdo->prepare("DELETE FROM crm_group_members WHERE group_id = ? AND employee_id = ?");
            $del->execute([$groupId, $employeeId]);

            $pdo->commit();

            $groupData = fetchGroupFullDetails($pdo, $groupId);
            sendJson([
                'status'     => 'success',
                'message'    => "{$empName} removed from '{$grp['name']}'. Employee record preserved.",
                'was_leader' => $wasLeader,
                'data'       => $groupData
            ]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // 6. DELETE GROUP
    if ($action === 'delete') {
        $id = (int)($input['id'] ?? ($input['group_id'] ?? 0));
        if ($id <= 0) {
            sendJson(['status' => 'error', 'message' => 'Valid Group ID is required'], 400);
        }

        $stmt = $pdo->prepare("SELECT id, name FROM crm_groups WHERE id = ?");
        $stmt->execute([$id]);
        $grp = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$grp) {
            sendJson(['status' => 'error', 'message' => 'Group not found'], 404);
        }

        try {
            $pdo->beginTransaction();
            // Delete group memberships
            $pdo->prepare("DELETE FROM crm_group_members WHERE group_id = ?")->execute([$id]);
            // Delete group
            $pdo->prepare("DELETE FROM crm_groups WHERE id = ?")->execute([$id]);
            $pdo->commit();

            sendJson([
                'status'  => 'success',
                'message' => "Group '{$grp['name']}' deleted successfully. Employee records were preserved.",
                'id'      => $id
            ]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // 7. SEND GROUP MESSAGE
    if ($action === 'send_message') {
        $groupId = (int)($input['group_id'] ?? 0);
        $messageText = trim((string)($input['message'] ?? ''));
        $attType = !empty($input['attachment_type']) ? trim((string)$input['attachment_type']) : null;
        $attUrl = !empty($input['attachment_url']) ? trim((string)$input['attachment_url']) : null;
        $attName = !empty($input['attachment_name']) ? trim((string)$input['attachment_name']) : null;
        $replyToId = !empty($input['reply_to_id']) ? (int)$input['reply_to_id'] : null;

        if ($groupId <= 0) {
            sendJson(['status' => 'error', 'message' => 'Valid Group ID is required'], 400);
        }
        if ($messageText === '' && empty($attUrl)) {
            sendJson(['status' => 'error', 'message' => 'Message or attachment cannot be empty'], 400);
        }

        $user = getEffectiveStaffUser($pdo, $authUser);
        if (!isUserAuthorizedForGroup($pdo, $groupId, $user)) {
            sendJson(['status' => 'error', 'message' => 'Access denied. You are not a member of this private group.'], 403);
        }

        // Reply details
        $replyToName = null;
        $replyToText = null;
        if ($replyToId) {
            $rStmt = $pdo->prepare("SELECT sender_name, message, deletion_state FROM crm_group_chats WHERE id = ?");
            $rStmt->execute([$replyToId]);
            $rep = $rStmt->fetch(PDO::FETCH_ASSOC);
            if ($rep) {
                $replyToName = $rep['sender_name'];
                $replyToText = ($rep['deletion_state'] !== 'none') ? 'Message deleted' : substr($rep['message'], 0, 100);
            }
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO crm_group_chats 
                (group_id, sender_id, sender_name, sender_role, sender_color, sender_avatar, message_type, message, attachment_type, attachment_url, attachment_name, reply_to_id, reply_to_name, reply_to_text, deletion_state, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'none', NOW())
            ");
            $msgType = $attType ? ($attType === 'image' ? 'image' : 'file') : 'text';
            $stmt->execute([
                $groupId,
                $user['id'],
                $user['name'],
                $user['role'],
                $user['color'],
                $user['avatar'],
                $msgType,
                $messageText,
                $attType,
                $attUrl,
                $attName,
                $replyToId,
                $replyToName,
                $replyToText
            ]);
            $msgId = (int)$pdo->lastInsertId();

            // Populate delivery receipts for all other group members
            $memberIds = getGroupMemberIds($pdo, $groupId);
            $rcptInsert = $pdo->prepare("
                INSERT IGNORE INTO crm_group_message_receipts 
                (message_id, group_id, user_id, user_name, delivered_at, read_at)
                VALUES (?, ?, ?, ?, NULL, NULL)
            ");
            foreach ($memberIds as $mId) {
                if ($mId !== $user['id']) {
                    $rcptInsert->execute([$msgId, $groupId, $mId, '']);
                }
            }

            $pdo->commit();

            $createdMsg = [
                'id'              => $msgId,
                'group_id'        => $groupId,
                'sender_id'       => $user['id'],
                'sender_name'     => $user['name'],
                'sender_role'     => $user['role'],
                'sender_color'    => $user['color'],
                'sender_avatar'   => $user['avatar'],
                'message_type'    => $msgType,
                'message'         => $messageText,
                'attachment_type' => $attType,
                'attachment_url'  => $attUrl,
                'attachment_name' => $attName,
                'reply_to_id'     => $replyToId,
                'reply_to_name'   => $replyToName,
                'reply_to_text'   => $replyToText,
                'deletion_state'  => 'none',
                'created_at'      => date('Y-m-d H:i:s'),
                'time_str'        => date('h:i a'),
                'is_me'           => true,
                'is_own'          => true,
                'status'          => 'sent', // single grey tick
                'receipt_summary' => 'sent',
                'read_count'      => 0,
                'delivered_count' => 0,
                'total_recipients'=> count($memberIds) - 1
            ];

            sendJson(['status' => 'success', 'data' => $createdMsg]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // 8. EDIT MESSAGE
    if ($action === 'edit_message') {
        $msgId = (int)($input['message_id'] ?? 0);
        $newText = trim((string)($input['message'] ?? ''));
        if ($msgId <= 0 || $newText === '') {
            sendJson(['status' => 'error', 'message' => 'Message ID and text required'], 400);
        }

        $stmt = $pdo->prepare("SELECT * FROM crm_group_chats WHERE id = ?");
        $stmt->execute([$msgId]);
        $msg = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$msg) {
            sendJson(['status' => 'error', 'message' => 'Message not found'], 404);
        }

        $user = getEffectiveStaffUser($pdo, $authUser);
        if ($msg['sender_id'] !== $user['id'] && !$user['is_admin']) {
            sendJson(['status' => 'error', 'message' => 'Unauthorized. You can only edit your own messages.'], 403);
        }
        if ($msg['deletion_state'] !== 'none') {
            sendJson(['status' => 'error', 'message' => 'Cannot edit a deleted or unsent message'], 400);
        }

        $up = $pdo->prepare("UPDATE crm_group_chats SET message = ?, edited_at = NOW() WHERE id = ?");
        $up->execute([$newText, $msgId]);

        sendJson([
            'status'  => 'success',
            'message' => 'Message edited successfully',
            'data'    => [
                'id'        => $msgId,
                'message'   => $newText,
                'edited_at' => date('Y-m-d H:i:s')
            ]
        ]);
    }

    // 9. UNSEND MESSAGE
    if ($action === 'unsend_message') {
        $msgId = (int)($input['message_id'] ?? 0);
        if ($msgId <= 0) {
            sendJson(['status' => 'error', 'message' => 'Message ID required'], 400);
        }

        $stmt = $pdo->prepare("SELECT * FROM crm_group_chats WHERE id = ?");
        $stmt->execute([$msgId]);
        $msg = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$msg) {
            sendJson(['status' => 'error', 'message' => 'Message not found'], 404);
        }

        $user = getEffectiveStaffUser($pdo, $authUser);
        if ($msg['sender_id'] !== $user['id'] && !$user['is_admin']) {
            sendJson(['status' => 'error', 'message' => 'Unauthorized. You can only unsend your own messages.'], 403);
        }

        $up = $pdo->prepare("
            UPDATE crm_group_chats 
            SET deletion_state = 'unsent', message = 'This message was unsent.', attachment_url = NULL, attachment_name = NULL, attachment_type = NULL 
            WHERE id = ?
        ");
        $up->execute([$msgId]);

        sendJson([
            'status'  => 'success',
            'message' => 'Message unsent successfully',
            'data'    => [
                'id'             => $msgId,
                'deletion_state' => 'unsent',
                'message'        => 'This message was unsent.'
            ]
        ]);
    }

    // 10. DELETE MESSAGE FOR ME
    if ($action === 'delete_message_for_me') {
        $msgId = (int)($input['message_id'] ?? 0);
        if ($msgId <= 0) {
            sendJson(['status' => 'error', 'message' => 'Message ID required'], 400);
        }

        $user = getEffectiveStaffUser($pdo, $authUser);
        $ins = $pdo->prepare("INSERT IGNORE INTO crm_group_message_deletions (message_id, user_id, deleted_at) VALUES (?, ?, NOW())");
        $ins->execute([$msgId, $user['id']]);

        sendJson([
            'status'  => 'success',
            'message' => 'Message deleted for you',
            'id'      => $msgId
        ]);
    }

    // 11. DELETE MESSAGE FOR EVERYONE
    if ($action === 'delete_message_for_everyone') {
        $msgId = (int)($input['message_id'] ?? 0);
        if ($msgId <= 0) {
            sendJson(['status' => 'error', 'message' => 'Message ID required'], 400);
        }

        $stmt = $pdo->prepare("SELECT * FROM crm_group_chats WHERE id = ?");
        $stmt->execute([$msgId]);
        $msg = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$msg) {
            sendJson(['status' => 'error', 'message' => 'Message not found'], 404);
        }

        $user = getEffectiveStaffUser($pdo, $authUser);
        $isSender = ($msg['sender_id'] === $user['id']);
        $isAdmin = $user['is_admin'];

        // Check if group leader
        $lstmt = $pdo->prepare("SELECT leader_id FROM crm_groups WHERE id = ?");
        $lstmt->execute([(int)$msg['group_id']]);
        $leaderId = $lstmt->fetchColumn();
        $isLeader = ($leaderId !== false && $leaderId === $user['id']);

        if (!$isSender && !$isAdmin && !$isLeader) {
            sendJson(['status' => 'error', 'message' => 'Unauthorized. Only sender, group leader, or administrator can delete for everyone.'], 403);
        }

        $up = $pdo->prepare("
            UPDATE crm_group_chats 
            SET deletion_state = 'deleted_everyone', message = 'This message was deleted.', attachment_url = NULL, attachment_name = NULL, attachment_type = NULL 
            WHERE id = ?
        ");
        $up->execute([$msgId]);

        sendJson([
            'status'  => 'success',
            'message' => 'Message deleted for everyone',
            'data'    => [
                'id'             => $msgId,
                'deletion_state' => 'deleted_everyone',
                'message'        => 'This message was deleted.'
            ]
        ]);
    }

    // 12. MARK GROUP MESSAGES READ
    if ($action === 'mark_read') {
        $groupId = (int)($input['group_id'] ?? 0);
        if ($groupId <= 0) {
            sendJson(['status' => 'error', 'message' => 'Group ID required'], 400);
        }
        $user = getEffectiveStaffUser($pdo, $authUser);

        $stmt = $pdo->prepare("
            UPDATE crm_group_message_receipts 
            SET read_at = NOW(), delivered_at = COALESCE(delivered_at, NOW())
            WHERE group_id = ? AND user_id = ? AND read_at IS NULL
        ");
        $stmt->execute([$groupId, $user['id']]);

        sendJson([
            'status'  => 'success',
            'message' => 'Messages marked as read'
        ]);
    }

    // 13. START CALL
    if ($action === 'start_call') {
        $groupId = (int)($input['group_id'] ?? 0);
        $callType = ($input['call_type'] ?? '') === 'video' ? 'video' : 'audio';

        if ($groupId <= 0) {
            sendJson(['status' => 'error', 'message' => 'Valid Group ID required'], 400);
        }
        $user = getEffectiveStaffUser($pdo, $authUser);
        if (!isUserAuthorizedForGroup($pdo, $groupId, $user)) {
            sendJson(['status' => 'error', 'message' => 'Access denied'], 403);
        }

        // Check if active call already exists
        $chk = $pdo->prepare("SELECT * FROM crm_group_calls WHERE group_id = ? AND call_status IN ('calling', 'ringing', 'connecting', 'connected') ORDER BY id DESC LIMIT 1");
        $chk->execute([$groupId]);
        $call = $chk->fetch(PDO::FETCH_ASSOC);

        if (!$call) {
            $cins = $pdo->prepare("
                INSERT INTO crm_group_calls 
                (group_id, initiator_id, initiator_name, call_type, call_status, start_time, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'calling', NOW(), NOW(), NOW())
            ");
            $cins->execute([$groupId, $user['id'], $user['name'], $callType]);
            $callId = (int)$pdo->lastInsertId();

            $call = [
                'id'             => $callId,
                'group_id'       => $groupId,
                'initiator_id'   => $user['id'],
                'initiator_name' => $user['name'],
                'call_type'      => $callType,
                'call_status'    => 'calling',
                'is_presenting'  => 0
            ];
        } else {
            $callId = (int)$call['id'];
        }

        // Add or update participant as joined
        $pins = $pdo->prepare("
            INSERT INTO crm_group_call_participants 
            (call_id, user_id, user_name, user_role, user_avatar, status, joined_at, last_seen)
            VALUES (?, ?, ?, ?, ?, 'joined', NOW(), NOW())
            ON DUPLICATE KEY UPDATE status = 'joined', joined_at = NOW(), left_at = NULL, last_seen = NOW()
        ");
        $pins->execute([$callId, $user['id'], $user['name'], $user['role'], $user['avatar']]);

        sendJson([
            'status'  => 'success',
            'data'    => $call
        ]);
    }

    // 14. JOIN CALL
    if ($action === 'join_call') {
        $callId = (int)($input['call_id'] ?? 0);
        if ($callId <= 0) {
            sendJson(['status' => 'error', 'message' => 'Call ID required'], 400);
        }
        $user = getEffectiveStaffUser($pdo, $authUser);

        $pins = $pdo->prepare("
            INSERT INTO crm_group_call_participants 
            (call_id, user_id, user_name, user_role, user_avatar, status, joined_at, last_seen)
            VALUES (?, ?, ?, ?, ?, 'joined', NOW(), NOW())
            ON DUPLICATE KEY UPDATE status = 'joined', joined_at = NOW(), left_at = NULL, last_seen = NOW()
        ");
        $pins->execute([$callId, $user['id'], $user['name'], $user['role'], $user['avatar']]);

        // Transition call to connected
        $pdo->prepare("UPDATE crm_group_calls SET call_status = 'connected', updated_at = NOW() WHERE id = ? AND call_status != 'ended'")->execute([$callId]);

        sendJson([
            'status'  => 'success',
            'message' => 'Joined call successfully'
        ]);
    }

    // 15. LEAVE CALL
    if ($action === 'leave_call') {
        $callId = (int)($input['call_id'] ?? 0);
        $user = getEffectiveStaffUser($pdo, $authUser);

        $pdo->prepare("UPDATE crm_group_call_participants SET status = 'left', left_at = NOW() WHERE call_id = ? AND user_id = ?")->execute([$callId, $user['id']]);

        // Check if any participants left
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM crm_group_call_participants WHERE call_id = ? AND status = 'joined'");
        $cntStmt->execute([$callId]);
        if ((int)$cntStmt->fetchColumn() === 0) {
            $pdo->prepare("UPDATE crm_group_calls SET call_status = 'ended', end_time = NOW() WHERE id = ?")->execute([$callId]);
        }

        sendJson(['status' => 'success', 'message' => 'Left call']);
    }

    // 16. END CALL FOR EVERYONE
    if ($action === 'end_call') {
        $callId = (int)($input['call_id'] ?? 0);
        $user = getEffectiveStaffUser($pdo, $authUser);

        $pdo->prepare("UPDATE crm_group_calls SET call_status = 'ended', end_time = NOW() WHERE id = ?")->execute([$callId]);
        $pdo->prepare("UPDATE crm_group_call_participants SET status = 'left', left_at = NOW() WHERE call_id = ?")->execute([$callId]);

        // Broadcast end_call signal
        $pdo->prepare("INSERT INTO crm_group_call_signals (call_id, sender_id, receiver_id, signal_type, signal_data, created_at) VALUES (?, ?, NULL, 'end_call', '{}', NOW())")->execute([$callId, $user['id']]);

        sendJson([
            'status'  => 'success',
            'message' => 'Call ended for everyone',
            'data'    => [
                'id'          => $callId,
                'call_status' => 'ended'
            ]
        ]);
    }

    // 17. UPDATE CALL MEDIA STATE (MUTE, CAMERA, SCREEN SHARING)
    if ($action === 'update_call_media') {
        $callId = (int)($input['call_id'] ?? 0);
        $user = getEffectiveStaffUser($pdo, $authUser);
        $isMuted = !empty($input['is_muted']) ? 1 : 0;
        $isCameraOff = !empty($input['is_camera_off']) ? 1 : 0;
        $isScreenSharing = !empty($input['is_screen_sharing']) ? 1 : 0;

        $pdo->prepare("
            UPDATE crm_group_call_participants 
            SET is_muted = ?, is_camera_off = ?, is_screen_sharing = ?, last_seen = NOW()
            WHERE call_id = ? AND user_id = ?
        ")->execute([$isMuted, $isCameraOff, $isScreenSharing, $callId, $user['id']]);

        // Update call level presenting state
        if ($isScreenSharing) {
            $pdo->prepare("UPDATE crm_group_calls SET is_presenting = 1, presenter_id = ?, presenter_name = ? WHERE id = ?")->execute([$user['id'], $user['name'], $callId]);
        } else {
            // Check if current user was presenter
            $pdo->prepare("UPDATE crm_group_calls SET is_presenting = 0, presenter_id = NULL, presenter_name = NULL WHERE id = ? AND presenter_id = ?")->execute([$callId, $user['id']]);
        }

        // Send signal
        $sigData = json_encode([
            'user_id'           => $user['id'],
            'user_name'         => $user['name'],
            'is_muted'          => $isMuted,
            'is_camera_off'     => $isCameraOff,
            'is_screen_sharing' => $isScreenSharing
        ]);
        $pdo->prepare("INSERT INTO crm_group_call_signals (call_id, sender_id, receiver_id, signal_type, signal_data, created_at) VALUES (?, ?, NULL, 'media_update', ?, NOW())")->execute([$callId, $user['id'], $sigData]);

        sendJson(['status' => 'success', 'data' => ['is_muted' => $isMuted, 'is_camera_off' => $isCameraOff, 'is_screen_sharing' => $isScreenSharing]]);
    }

    // 18. SEND WEBRTC CALL SIGNAL
    if ($action === 'send_signal') {
        $callId = (int)($input['call_id'] ?? 0);
        $user = getEffectiveStaffUser($pdo, $authUser);
        $receiverId = !empty($input['receiver_id']) ? (string)$input['receiver_id'] : null;
        $sigType = trim((string)($input['signal_type'] ?? ''));
        $sigData = is_array($input['signal_data'] ?? null) ? json_encode($input['signal_data']) : (string)($input['signal_data'] ?? '{}');

        if ($callId <= 0 || empty($sigType)) {
            sendJson(['status' => 'error', 'message' => 'Call ID and signal type required'], 400);
        }

        $stmt = $pdo->prepare("
            INSERT INTO crm_group_call_signals 
            (call_id, sender_id, receiver_id, signal_type, signal_data, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$callId, $user['id'], $receiverId, $sigType, $sigData]);
        $sigId = (int)$pdo->lastInsertId();

        sendJson(['status' => 'success', 'signal_id' => $sigId]);
    }

    sendJson(['status' => 'error', 'message' => "Unknown action '{$action}'"], 400);
}

sendJson(['status' => 'error', 'message' => 'Method not allowed'], 405);
