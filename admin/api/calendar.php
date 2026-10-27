<?php
/**
 * Software CRM - Calendar Events API
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$seed = Database::getSeedData();
$events = $seed['calendar_events'] ?? [];

$month = trim($_GET['month'] ?? 'August 2024');

jsonResponse(true, [
    'month'  => $month,
    'total'  => count($events),
    'events' => $events
], 'Calendar events retrieved');
