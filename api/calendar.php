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

// Defensive column migration for crm_calendar_events
if ($pdo) {
    try { $pdo->exec("ALTER TABLE `crm_calendar_events` ADD COLUMN `end_time` TEXT DEFAULT ''"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `crm_calendar_events` ADD COLUMN `location` TEXT DEFAULT ''"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `crm_calendar_events` ADD COLUMN `color` TEXT DEFAULT '#2563eb'"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `crm_calendar_events` ADD COLUMN `description` TEXT DEFAULT ''"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `crm_calendar_events` ADD COLUMN `updated_at` DATETIME"); } catch (Exception $e) {}
}

function normalizeEventDate(string $rawDate): string {
    $rawDate = trim($rawDate);
    if (empty($rawDate)) {
        return date('Y-m-d');
    }
    // Match DD-MM-YYYY or DD/MM/YYYY
    if (preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/', $rawDate, $m)) {
        $d = (int)$m[1];
        $mo = (int)$m[2];
        $y = (int)$m[3];
        if ($mo > 12 && $d <= 12) {
            // Inverted: MM-DD-YYYY
            return sprintf('%04d-%02d-%02d', $y, $d, $mo);
        }
        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }
    $ts = strtotime($rawDate);
    if ($ts !== false && $ts > 0) {
        return date('Y-m-d', $ts);
    }
    return $rawDate;
}

function getCategoryColor(string $category): string {
    $colors = [
        'Meeting'  => '#2563eb',
        'Call'     => '#16a34a',
        'Review'   => '#d97706',
        'Deadline' => '#dc2626'
    ];
    return $colors[$category] ?? '#2563eb';
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

// GET ALL EVENTS
if ($method === 'GET') {
    if ($pdo) {
        try {
            $stmt = $pdo->query("SELECT * FROM crm_calendar_events ORDER BY event_date ASC, event_time ASC");
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
            sendJson(['status' => 'success', 'data' => $events]);
        } catch (Exception $e) {
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    } else {
        sendJson(['status' => 'error', 'message' => 'Database connection failed'], 500);
    }
}

// DELETE EVENT VIA DELETE METHOD
if ($method === 'DELETE') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: $_GET;
    $id = $input['id'] ?? ($_GET['id'] ?? '');

    if (empty($id)) {
        sendJson(['status' => 'error', 'message' => 'Event ID required'], 400);
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM crm_calendar_events WHERE id = ?");
        $stmt->execute([$id]);
        sendJson(['status' => 'success', 'message' => 'Event deleted successfully', 'id' => $id]);
    } catch (Exception $e) {
        sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}

// POST - CREATE, UPDATE, OR DELETE
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
        if (empty($id)) {
            sendJson(['status' => 'error', 'message' => 'Event ID required'], 400);
        }
        try {
            $stmt = $pdo->prepare("DELETE FROM crm_calendar_events WHERE id = ?");
            $stmt->execute([$id]);
            sendJson(['status' => 'success', 'message' => 'Event deleted successfully', 'id' => $id]);
        } catch (Exception $e) {
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // 2. UPDATE ACTION
    if ($action === 'update') {
        $id = $input['id'] ?? '';
        if (empty($id)) {
            sendJson(['status' => 'error', 'message' => 'Event ID required'], 400);
        }
        if (empty($input['title']) || empty($input['event_date'])) {
            sendJson(['status' => 'error', 'message' => 'Title and date are required'], 400);
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE crm_calendar_events SET
                    title = :title,
                    event_date = :event_date,
                    event_time = :event_time,
                    end_time = :end_time,
                    category = :category,
                    color = :color,
                    description = :description,
                    location = :location
                WHERE id = :id
            ");

            $category = trim((string)($input['category'] ?? 'Meeting'));
            $color = !empty($input['color']) ? trim((string)$input['color']) : getCategoryColor($category);
            $eventDate = normalizeEventDate((string)($input['event_date'] ?? ''));

            $stmt->execute([
                ':id' => $id,
                ':title' => trim($input['title']),
                ':event_date' => $eventDate,
                ':event_time' => trim($input['event_time'] ?? '10:00 AM'),
                ':end_time' => trim($input['end_time'] ?? ''),
                ':category' => $category,
                ':color' => $color,
                ':description' => trim($input['description'] ?? ''),
                ':location' => trim($input['location'] ?? '')
            ]);

            sendJson(['status' => 'success', 'success' => true, 'id' => $id, 'message' => 'Event updated successfully']);
        } catch (Exception $e) {
            sendJson(['status' => 'error', 'success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // 3. CREATE ACTION
    if (empty($input['title']) || empty($input['event_date'])) {
        sendJson(['status' => 'error', 'success' => false, 'message' => 'Title and event date are required'], 400);
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO crm_calendar_events 
            (title, event_date, event_time, end_time, category, color, description, location)
            VALUES 
            (:title, :event_date, :event_time, :end_time, :category, :color, :description, :location)
        ");

        $category = trim((string)($input['category'] ?? 'Meeting'));
        $color = !empty($input['color']) ? trim((string)$input['color']) : getCategoryColor($category);
        $eventDate = normalizeEventDate((string)($input['event_date'] ?? ''));

        $stmt->execute([
            ':title' => trim($input['title']),
            ':event_date' => $eventDate,
            ':event_time' => trim($input['event_time'] ?? '10:00 AM'),
            ':end_time' => trim($input['end_time'] ?? ''),
            ':category' => $category,
            ':color' => $color,
            ':description' => trim($input['description'] ?? ''),
            ':location' => trim($input['location'] ?? '')
        ]);

        sendJson(['status' => 'success', 'success' => true, 'id' => $pdo->lastInsertId(), 'message' => 'Event scheduled successfully']);
    } catch (Exception $e) {
        sendJson(['status' => 'error', 'success' => false, 'message' => $e->getMessage()], 500);
    }
}
