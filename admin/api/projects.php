<?php
/**
 * Software CRM - Projects Management API
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$seed = Database::getSeedData();
$projects = $seed['projects'] ?? [];

$status = strtolower(trim($_GET['status'] ?? 'all'));

$filtered = [];
foreach ($projects as $p) {
    $pStatus = strtolower($p['status'] ?? '');
    if ($status === 'all' || $pStatus === $status) {
        $filtered[] = $p;
    }
}

jsonResponse(true, [
    'total'    => count($projects),
    'count'    => count($filtered),
    'projects' => $filtered
], 'Projects retrieved');
