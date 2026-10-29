<?php
session_start();
$_SESSION['auth_user'] = [
    'id' => 8,
    'name' => 'Vangari Dhanush datta',
    'email' => 'vangaridhanush09@gmail.com',
    'role' => 'user',
    'employee_id' => 'staff_3ac9c3eea6e3',
    'avatar_url' => null
];
session_write_close();

$opts = [
    'http' => [
        'method' => 'GET',
        'header' => "Cookie: PHPSESSID=" . session_id() . "\r\n"
    ]
];
$context = stream_context_create($opts);
$html = file_get_contents('http://localhost/software_team/index.php?view=dashboard', false, $context);

echo "Response length: " . strlen($html) . "\n";
echo "Contains 'Good morning': " . (strpos($html, 'Good morning') !== false ? 'YES' : 'NO') . "\n";
echo "Contains 'crm-screen-1-logo': " . (strpos($html, 'crm-screen-1-logo') !== false ? 'YES' : 'NO') . "\n";
echo "Contains 'activeTab': " . (strpos($html, 'activeTab') !== false ? 'YES' : 'NO') . "\n";
echo "Contains 'Sign Out': " . (strpos($html, 'Sign Out') !== false ? 'YES' : 'NO') . "\n";
