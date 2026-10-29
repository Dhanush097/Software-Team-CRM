<?php
require_once __DIR__ . '/session_guard.php';
SessionGuard::requireAuth(true);
require_once __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $search = isset($_GET['q']) ? trim($_GET['q']) : '';
    $domain = isset($_GET['domain']) ? trim($_GET['domain']) : 'all';
    $type   = isset($_GET['type']) ? trim($_GET['type']) : 'all';

    if (isset($pdo)) {
        $sql = "SELECT * FROM crm_contacts WHERE 1=1";
        $params = [];

        if ($domain !== 'all') {
            $sql .= " AND primary_domain = :domain";
            $params[':domain'] = $domain;
        }

        if ($type !== 'all') {
            $sql .= " AND type = :type";
            $params[':type'] = $type;
        }

        if ($search !== '') {
            $sql .= " AND (name LIKE :q OR phone LIKE :q OR occupation LIKE :q OR address LIKE :q OR tags_json LIKE :q)";
            $params[':q'] = "%$search%";
        }

        $sql .= " ORDER BY created_at DESC LIMIT 100";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $contacts = $stmt->fetchAll();

        foreach ($contacts as &$c) {
            $c['tags'] = json_decode($c['tags_json'] ?? '[]', true);
        }

        sendJson(['status' => 'success', 'data' => $contacts]);
    } else {
        sendJson(['status' => 'mock', 'message' => 'Connect Hostinger MySQL in api/db.php']);
    }
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($input['name']) || empty($input['phone'])) {
        sendJson(['error' => 'Name and phone are required'], 400);
    }

    if (isset($pdo)) {
        $id = 'c_' . time() . '_' . rand(100, 999);
        $stmt = $pdo->prepare("
            INSERT INTO crm_contacts 
            (id, type, tag_label, name, phone, email, avatar_url, pincode, primary_domain, address, website, occupation, family_details, future_plans, other_info, tags_json)
            VALUES 
            (:id, :type, :tag_label, :name, :phone, :email, :avatar_url, :pincode, :primary_domain, :address, :website, :occupation, :family_details, :future_plans, :other_info, :tags_json)
        ");

        $stmt->execute([
            ':id' => $id,
            ':type' => $input['type'] ?? 'customer',
            ':tag_label' => $input['tagLabel'] ?? 'Customer',
            ':name' => $input['name'],
            ':phone' => $input['phone'],
            ':email' => $input['email'] ?? null,
            ':avatar_url' => $input['avatar'] ?? null,
            ':pincode' => $input['pincode'] ?? '302033',
            ':primary_domain' => $input['primaryDomain'] ?? 'sarekam.com',
            ':address' => $input['address'] ?? '',
            ':website' => $input['website'] ?? '',
            ':occupation' => $input['occupation'] ?? '',
            ':family_details' => $input['familyDetails'] ?? '',
            ':future_plans' => $input['futurePlans'] ?? '',
            ':other_info' => $input['otherInfo'] ?? '',
            ':tags_json' => json_encode($input['tags'] ?? [])
        ]);

        // Trigger notification for new client
        try {
            if (file_exists(__DIR__ . '/notification_service.php')) {
                require_once __DIR__ . '/notification_service.php';
                NotificationService::create($pdo, [
                    'category' => 'clients',
                    'event_type' => 'client_added',
                    'title' => 'New Client Added: ' . $input['name'],
                    'message' => 'New client ' . $input['name'] . (!empty($input['email']) ? ' (' . $input['email'] . ')' : '') . ' added to CRM records.',
                    'link_type' => 'contact',
                    'link_id' => (string)$id,
                    'action_label' => 'View Client',
                    'metadata' => ['contact_id' => $id, 'name' => $input['name'], 'phone' => $input['phone']]
                ]);
            }
        } catch (Exception $e) {
            error_log('Notification error on contact save: ' . $e->getMessage());
        }

        sendJson(['status' => 'success', 'id' => $id, 'message' => 'Contact saved successfully']);
    }
}
