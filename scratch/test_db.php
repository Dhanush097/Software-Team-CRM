<?php
$configs = [
    ['host'=>'127.0.0.1','port'=>'3306','db'=>'pocket_crm_db','user'=>'root','pass'=>''],
    ['host'=>'127.0.0.1','port'=>'3306','db'=>'u213657911_crm','user'=>'root','pass'=>''],
    ['host'=>'127.0.0.1','port'=>'3306','db'=>'software_team_db','user'=>'root','pass'=>''],
];
foreach ($configs as $cfg) {
    try {
        $pdo = new PDO("mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['db']};charset=utf8mb4", $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT=>3]);
        echo "Connected: {$cfg['db']}\n";
        // Check tables
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        echo "Tables: " . implode(', ', $tables) . "\n";
        // Check crm_otp_challenges
        if (in_array('crm_otp_challenges', $tables)) {
            $count = $pdo->query("SELECT COUNT(*) FROM crm_otp_challenges WHERE is_used=0")->fetchColumn();
            echo "Active OTP challenges: $count\n";
            $recent = $pdo->query("SELECT email, purpose, expires_at, attempts, is_used FROM crm_otp_challenges ORDER BY id DESC LIMIT 5")->fetchAll();
            echo "Recent challenges:\n";
            foreach ($recent as $r) {
                echo "  " . json_encode($r) . "\n";
            }
        }
        // Check crm_users
        if (in_array('crm_users', $tables)) {
            $users = $pdo->query("SELECT id, name, email, status, role FROM crm_users LIMIT 10")->fetchAll();
            echo "Users:\n";
            foreach ($users as $u) {
                echo "  " . json_encode($u) . "\n";
            }
        }
        break;
    } catch (Exception $e) {
        echo "Failed {$cfg['db']}: " . $e->getMessage() . "\n";
    }
}
