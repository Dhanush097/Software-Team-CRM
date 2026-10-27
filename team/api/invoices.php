<?php
/**
 * Software CRM - Invoices & Payments API
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$seed = Database::getSeedData();
$invoices = $seed['invoices'] ?? [];

$status = strtolower(trim($_GET['status'] ?? 'all'));

$filtered = [];
$totalAmount = 0;
foreach ($invoices as $inv) {
    $iStatus = strtolower($inv['status'] ?? '');
    if ($status === 'all' || $iStatus === $status) {
        $filtered[] = $inv;
        $totalAmount += (float)($inv['amount'] ?? 0);
    }
}

jsonResponse(true, [
    'total_count'   => count($invoices),
    'count'         => count($filtered),
    'sum_amount'    => $totalAmount,
    'invoices'      => $filtered
], 'Invoices retrieved');
