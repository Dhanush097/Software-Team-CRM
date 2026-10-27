<?php
/**
 * Software CRM - Dashboard Metrics & Recent Activity API
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$pdo = Database::getConnection();
$seed = Database::getSeedData();

$stats = $seed['stats'] ?? [
    'contacts_count' => 86,
    'contacts_trend' => 12,
    'projects_count' => 12,
    'projects_trend' => 20,
    'tasks_count' => 8,
    'tasks_trend' => -14,
    'revenue_total' => 12450,
    'revenue_trend' => 32,
    'currency' => '$'
];

$recentActivity = $seed['recent_activity'] ?? [];

// If MySQL or SQLite has actual data, count them dynamically
if ($pdo) {
    try {
        $countQuery = $pdo->query("SELECT COUNT(*) as c FROM crm_contacts");
        if ($countQuery) {
            $c = (int)$countQuery->fetchColumn();
            if ($c > 0) $stats['contacts_count'] = $c;
        }
    } catch (Exception $e) {}
}

jsonResponse(true, [
    'stats'           => $stats,
    'recent_activity' => $recentActivity,
    'user'            => $seed['user'] ?? null
], 'Dashboard metrics retrieved');
