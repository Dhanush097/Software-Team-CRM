<?php
require_once __DIR__ . '/../api/db.php';
$pdo = Database::getConnection();

echo "=== RECENT SECURITY LOGS ===\n";
try {
    $stmt = $pdo->query("SELECT * FROM `crm_security_logs` ORDER BY `id` DESC LIMIT 10");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n=== USERS MATCHING vangaridhanush09 ===\n";
try {
    $stmt = $pdo->query("SELECT * FROM `crm_users` WHERE `email` LIKE '%vangaridhanush%' OR `email` LIKE '%dhanush%'");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n=== ALL CRM USERS (Top 10) ===\n";
try {
    $stmt = $pdo->query("SELECT `id`, `name`, `email`, `role`, `status` FROM `crm_users` LIMIT 10");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n=== STAFF MATCHING vangaridhanush09 ===\n";
try {
    $stmt = $pdo->query("SELECT * FROM `crm_team_staff` WHERE `email` LIKE '%vangaridhanush%' OR `email` LIKE '%dhanush%'");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n=== RECENT OTP CHALLENGES ===\n";
try {
    $stmt = $pdo->query("SELECT * FROM `crm_otp_challenges` ORDER BY `id` DESC LIMIT 10");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
