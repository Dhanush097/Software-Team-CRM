<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();

// Test that local user exists and has a valid password hash
$user = $pdo->query("SELECT id, name, email, role, status, password_hash FROM crm_users WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
echo "User ID: {$user['id']}, Email: {$user['email']}, Status: {$user['status']}\n";
echo "Password hash length: " . strlen($user['password_hash']) . "\n";
echo "Password hash starts with $2y$: " . (strpos($user['password_hash'], '$2y$') === 0 ? 'YES' : 'NO') . "\n";
