<?php
/**
 * Software CRM - Contacts Management API
 * Fetches contacts, filters by category, searches, and provides single contact details
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$pdo = Database::getConnection();
$seed = Database::getSeedData();
$contacts = $seed['contacts'] ?? [];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// GET: List or Single Contact
if ($method === 'GET') {
    $contactId = trim($_GET['id'] ?? '');
    $category  = strtolower(trim($_GET['category'] ?? 'all'));
    $search    = strtolower(trim($_GET['search'] ?? ''));

    // Single Contact Detail
    if (!empty($contactId)) {
        foreach ($contacts as $c) {
            if ($c['id'] === $contactId) {
                jsonResponse(true, $c, 'Contact details found');
            }
        }
        jsonResponse(false, null, 'Contact not found', 404);
    }

    // Filter List
    $filtered = [];
    foreach ($contacts as $c) {
        $cCat = strtolower($c['category'] ?? '');
        $cName = strtolower($c['name'] ?? '');
        $cComp = strtolower($c['company'] ?? '');

        // Category filter
        if ($category !== 'all' && $cCat !== $category) {
            continue;
        }

        // Search filter
        if (!empty($search)) {
            if (strpos($cName, $search) === false && strpos($cComp, $search) === false) {
                continue;
            }
        }

        $filtered[] = $c;
    }

    jsonResponse(true, [
        'total' => count($contacts),
        'count' => count($filtered),
        'contacts' => $filtered
    ], 'Contacts retrieved');
}

// POST: Add New Contact
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    if (empty($input['name'])) {
        jsonResponse(false, null, 'Contact name is required', 400);
    }

    $newId = 'c_' . time() . '_' . rand(10, 99);
    $initials = strtoupper(substr($input['name'], 0, 2));

    $newContact = [
        'id'           => $newId,
        'name'         => trim($input['name']),
        'company'      => trim($input['company'] ?? 'Independent Client'),
        'position'     => trim($input['position'] ?? 'Client'),
        'category'     => trim($input['category'] ?? 'Lead'),
        'email'        => trim($input['email'] ?? ''),
        'phone'        => trim($input['phone'] ?? ''),
        'location'     => trim($input['location'] ?? 'Mumbai, India'),
        'source'       => trim($input['source'] ?? 'Direct Entry'),
        'tags'         => $input['tags'] ?? ['New Client'],
        'initials'     => $initials,
        'avatar_color' => 'linear-gradient(135deg, #3B82F6, #1D4ED8)',
        'last_activity'=> 'Contact created just now'
    ];

    jsonResponse(true, $newContact, 'Contact added successfully');
}
