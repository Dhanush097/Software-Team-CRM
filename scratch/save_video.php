<?php
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    if (!empty($input) && strlen($input) > 1000) {
        $targetDir = dirname(__DIR__) . '/data';
        if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
        $targetFile = $targetDir . '/crm_intro_animation.webm';
        file_put_contents($targetFile, $input);
        echo json_encode(['success' => true, 'bytes' => strlen($input), 'file' => $targetFile]);
        exit;
    }
}
echo json_encode(['success' => false, 'error' => 'No data']);
