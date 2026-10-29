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

// GET ALL OR SINGLE TEAM MEMBER
if ($method === 'GET') {
    if (!$pdo) {
        sendJson(['status' => 'error', 'message' => 'Database connection failed'], 500);
    }

    $id = isset($_GET['id']) ? trim($_GET['id']) : null;
    $status = isset($_GET['status']) ? trim($_GET['status']) : 'all';

    try {
        if ($id) {
            $stmt = $pdo->prepare("SELECT * FROM crm_team_staff WHERE id = ?");
            $stmt->execute([$id]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$member) {
                sendJson(['status' => 'error', 'message' => 'Team member not found'], 404);
            }
            sendJson(['status' => 'success', 'data' => $member]);
        } else {
            $sql = "SELECT * FROM crm_team_staff WHERE 1=1";
            $params = [];
            if ($status !== 'all') {
                $sql .= " AND LOWER(status) = LOWER(:status)";
                $params[':status'] = $status;
            }
            $sql .= " ORDER BY CASE WHEN status = 'Active' THEN 0 ELSE 1 END, id ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $staff = $stmt->fetchAll(PDO::FETCH_ASSOC);
            sendJson(['status' => 'success', 'data' => $staff]);
        }
    } catch (Exception $e) {
        sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}

// DELETE HTTP METHOD
if ($method === 'DELETE') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: $_GET;
    $id = $input['id'] ?? ($_GET['id'] ?? '');

    if (empty($id)) {
        sendJson(['status' => 'error', 'message' => 'ID is required'], 400);
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM crm_team_staff WHERE id = ?");
        $stmt->execute([$id]);
        sendJson(['status' => 'success', 'message' => 'Team member removed', 'id' => $id]);
    } catch (Exception $e) {
        sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}

// POST - CREATE, UPDATE, STATUS, DELETE
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: $_POST;
    $action = $input['action'] ?? ($_GET['action'] ?? 'create');

    if (!$pdo) {
        sendJson(['status' => 'error', 'message' => 'Database not connected'], 500);
    }

    // 1. DELETE ACTION
    if ($action === 'delete') {
        $id = $input['id'] ?? '';
        if (empty($id)) sendJson(['status' => 'error', 'message' => 'ID is required'], 400);

        try {
            $stmt = $pdo->prepare("DELETE FROM crm_team_staff WHERE id = ?");
            $stmt->execute([$id]);
            sendJson(['status' => 'success', 'message' => 'Team member removed', 'id' => $id]);
        } catch (Exception $e) {
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // 2. TOGGLE OR UPDATE STATUS
    if ($action === 'status') {
        $id = $input['id'] ?? '';
        if (empty($id)) sendJson(['status' => 'error', 'message' => 'ID is required'], 400);

        try {
            $stmt = $pdo->prepare("SELECT status FROM crm_team_staff WHERE id = ?");
            $stmt->execute([$id]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$member) sendJson(['status' => 'error', 'message' => 'Team member not found'], 404);

            $newStatus = isset($input['status']) ? trim($input['status']) : ($member['status'] === 'Active' ? 'Inactive' : 'Active');
            $upd = $pdo->prepare("UPDATE crm_team_staff SET status = ? WHERE id = ?");
            $upd->execute([$newStatus, $id]);

            sendJson(['status' => 'success', 'message' => 'Status updated to ' . $newStatus, 'id' => $id, 'new_status' => $newStatus]);
        } catch (Exception $e) {
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // 3. UPDATE PROFILE
    if ($action === 'update') {
        $id = $input['id'] ?? '';
        if (empty($id)) sendJson(['status' => 'error', 'message' => 'ID is required'], 400);

        try {
            $stmt = $pdo->prepare("SELECT * FROM crm_team_staff WHERE id = ?");
            $stmt->execute([$id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                sendJson(['status' => 'error', 'message' => 'Team member not found'], 404);
            }

            $name = isset($input['name']) && trim($input['name']) !== '' ? trim($input['name']) : $existing['name'];
            $role = isset($input['role']) && trim($input['role']) !== '' ? trim($input['role']) : $existing['role'];
            $email = isset($input['email']) ? trim($input['email']) : $existing['email'];
            $phone = isset($input['phone']) ? trim($input['phone']) : $existing['phone'];
            $company = isset($input['company']) ? trim($input['company']) : $existing['company'];
            $location = isset($input['location']) ? trim($input['location']) : $existing['location'];
            $department = isset($input['department']) ? trim($input['department']) : $existing['department'];
            $website = isset($input['website']) ? trim($input['website']) : $existing['website'];
            $status = isset($input['status']) ? trim($input['status']) : $existing['status'];
            $bio = isset($input['bio']) ? trim($input['bio']) : $existing['bio'];
            $skills = isset($input['skills']) ? trim($input['skills']) : $existing['skills'];
            $initials = isset($input['initials']) && trim($input['initials']) !== '' ? trim($input['initials']) : $existing['initials'];
            $colorClass = isset($input['color_class']) && trim($input['color_class']) !== '' ? trim($input['color_class']) : $existing['color_class'];

            $upd = $pdo->prepare("
                UPDATE crm_team_staff SET
                    name = :name,
                    role = :role,
                    email = :email,
                    phone = :phone,
                    company = :company,
                    location = :location,
                    department = :department,
                    website = :website,
                    status = :status,
                    initials = :initials,
                    color_class = :color_class,
                    bio = :bio,
                    skills = :skills
                WHERE id = :id
            ");

            $upd->execute([
                ':id' => $id,
                ':name' => $name,
                ':role' => $role,
                ':email' => $email,
                ':phone' => $phone,
                ':company' => $company,
                ':location' => $location,
                ':department' => $department,
                ':website' => $website,
                ':status' => $status,
                ':initials' => $initials,
                ':color_class' => $colorClass,
                ':bio' => $bio,
                ':skills' => $skills
            ]);

            sendJson(['status' => 'success', 'message' => 'Team member updated successfully', 'id' => $id]);
        } catch (Exception $e) {
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // 4. CREATE NEW MEMBER
    if (empty($input['name'])) {
        sendJson(['status' => 'error', 'message' => 'Name is required'], 400);
    }

    try {
        $id = (!empty($input['id'])) ? trim($input['id']) : ('tm_' . time() . '_' . rand(100, 999));
        $name = trim($input['name']);
        $words = explode(' ', $name);
        $initials = '';
        foreach ($words as $w) {
            if (!empty($w)) $initials .= strtoupper($w[0]);
        }
        $initials = !empty($input['initials']) ? trim($input['initials']) : (substr($initials, 0, 2) ?: 'TM');

        $colors = ['bg-blue-600', 'bg-purple-600', 'bg-rose-500', 'bg-amber-500', 'bg-emerald-600', 'bg-cyan-600', 'bg-indigo-600', 'bg-teal-600'];
        $colorClass = !empty($input['color_class']) ? trim($input['color_class']) : $colors[rand(0, count($colors) - 1)];

        $stmt = $pdo->prepare("
            INSERT INTO crm_team_staff 
            (id, name, role, email, phone, company, location, department, website, joined_date, status, initials, color_class, bio, skills)
            VALUES 
            (:id, :name, :role, :email, :phone, :company, :location, :department, :website, :joined_date, :status, :initials, :color_class, :bio, :skills)
        ");

        $memberData = [
            ':id' => $id,
            ':name' => $name,
            ':role' => trim($input['role'] ?? 'Software Engineer'),
            ':email' => trim($input['email'] ?? ''),
            ':phone' => trim($input['phone'] ?? ''),
            ':company' => trim($input['company'] ?? 'Software CRM Inc'),
            ':location' => trim($input['location'] ?? 'Bangalore, India'),
            ':department' => trim($input['department'] ?? 'Engineering'),
            ':website' => trim($input['website'] ?? 'https://softwarecrm.com'),
            ':joined_date' => trim($input['joined_date'] ?? ('Joined ' . date('d M Y'))),
            ':status' => trim($input['status'] ?? 'Active'),
            ':initials' => $initials,
            ':color_class' => $colorClass,
            ':bio' => trim($input['bio'] ?? ''),
            ':skills' => trim($input['skills'] ?? '')
        ];

        $stmt->execute($memberData);

        sendJson([
            'status' => 'success',
            'id' => $id,
            'data' => [
                'id' => $id,
                'name' => $name,
                'role' => $memberData[':role'],
                'email' => $memberData[':email'],
                'phone' => $memberData[':phone'],
                'status' => $memberData[':status']
            ],
            'message' => 'Team member created successfully'
        ]);
    } catch (Exception $e) {
        sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}
