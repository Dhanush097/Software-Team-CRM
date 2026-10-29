<?php
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/session_guard.php';

// Helper to simulate an authenticated session
function setTestSession($userId = 1, $name = 'Suresh Kumar', $role = 'admin', $email = 'suresh@softwarecrm.com', $empId = 'staff_88ecd10437ec') {
    $_SESSION['auth_user'] = [
        'id'          => $userId,
        'name'        => $name,
        'email'       => $email,
        'role'        => $role,
        'status'      => 'active',
        'employee_id' => $empId
    ];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

setTestSession();
echo "Test session setup complete. CSRF: " . $_SESSION['csrf_token'] . "\n";
