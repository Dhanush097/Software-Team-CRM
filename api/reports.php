<?php
/**
 * Software CRM - Reports, Financial Analytics & Expense Management API
 * 
 * Capabilities:
 * 1. Financial Summary (Total Revenue, Payments Received, Outstanding Receivables, Expenses, Gross/Net Profit)
 * 2. Monthly Financial Breakdown & Trend Analytics (Revenue Trend, Income vs Expenses, Profit Trend, Status Distribution)
 * 3. Detailed Payment Report (Search, Month/Date Filters, Method, Status, Sorting, Totals)
 * 4. Expense Management & Report (Monthly, Category Breakdown, Add/Edit/Delete Expense)
 * 5. Transparent Profit & Loss Calculations (Documented Cash vs Accrual Basis)
 * 6. Multi-Format Report Export (PDF, Excel .xlsx, CSV, Printable A4)
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/session_guard.php';
// Require authenticated session
SessionGuard::requireAuth(true);

require_once __DIR__ . '/db.php';
$pdo = Database::getConnection();

if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection unavailable']);
    exit;
}

// -----------------------------------------------------------------------------
// HELPER: DATE NORMALIZER & VALIDATOR
// -----------------------------------------------------------------------------
function normalizeDateStr(?string $dateStr, ?string $fallback = null): string {
    if (empty($dateStr)) {
        return !empty($fallback) ? date('Y-m-d', strtotime($fallback)) : date('Y-m-d');
    }
    $ts = strtotime($dateStr);
    if ($ts === false || $ts <= 0) {
        $ts = !empty($fallback) ? strtotime($fallback) : time();
    }
    return date('Y-m-d', $ts);
}

function parsePeriodBounds(array $params): array {
    $periodType = strtolower(trim($params['period_type'] ?? 'month'));
    $now = time();

    if ($periodType === 'custom' && !empty($params['start_date']) && !empty($params['end_date'])) {
        $start = normalizeDateStr($params['start_date']);
        $end = normalizeDateStr($params['end_date']);
        if ($start > $end) {
            $tmp = $start;
            $start = $end;
            $end = $tmp;
        }
        $label = date('d M Y', strtotime($start)) . ' - ' . date('d M Y', strtotime($end));
        return [
            'type'       => 'custom',
            'start_date' => $start,
            'end_date'   => $end,
            'label'      => $label,
            'month_key'  => date('Y-m', strtotime($start)),
            'year'       => (int)date('Y', strtotime($start))
        ];
    } elseif ($periodType === 'year' && !empty($params['year'])) {
        $yr = (int)$params['year'];
        $start = "$yr-01-01";
        $end = "$yr-12-31";
        return [
            'type'       => 'year',
            'start_date' => $start,
            'end_date'   => $end,
            'label'      => "Year $yr",
            'month_key'  => "$yr-01",
            'year'       => $yr
        ];
    } else {
        // Default: Month selector
        $monthStr = trim($params['month'] ?? date('Y-m', $now));
        if (!preg_match('/^\d{4}-\d{2}$/', $monthStr)) {
            $monthStr = date('Y-m', $now);
        }
        $ts = strtotime($monthStr . '-01');
        $start = date('Y-m-01', $ts);
        $end = date('Y-m-t', $ts);
        $label = date('F Y', $ts);
        return [
            'type'       => 'month',
            'start_date' => $start,
            'end_date'   => $end,
            'label'      => $label,
            'month_key'  => $monthStr,
            'year'       => (int)date('Y', $ts)
        ];
    }
}

// -----------------------------------------------------------------------------
// CORE FINANCIAL REPORT ENGINE
// -----------------------------------------------------------------------------
function calculateFinancialSummary(PDO $pdo, array $period): array {
    $startDate = $period['start_date'];
    $endDate = $period['end_date'];
    $today = date('Y-m-d');

    // 1. Fetch Invoices in Period (Total Invoiced, Pending, Overdue)
    $invStmt = $pdo->query("SELECT * FROM `crm_cash_ledger`");
    $allInvoices = $invStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalInvoiced = 0.0;
    $periodPendingAmt = 0.0;
    $periodOverdueAmt = 0.0;
    $totalReceivables = 0.0;
    $invoicesCreatedCount = 0;
    $invoicesPaidCount = 0;
    $invoicesPendingCount = 0;
    $invoicesOverdueCount = 0;

    $paidInvoicesSum = 0.0;
    $partiallyPaidSum = 0.0;

    foreach ($allInvoices as $inv) {
        $status = strtolower(trim($inv['status'] ?? 'pending'));
        if ($status === 'cancelled' || $status === 'void') {
            continue;
        }

        $invDate = normalizeDateStr($inv['issue_date'] ?? '', $inv['transaction_date'] ?? null);
        $dueDate = normalizeDateStr($inv['due_date'] ?? '', $invDate);
        $amount = (float)($inv['amount'] ?? 0);
        $paid = (float)($inv['paid_amount'] ?? 0);
        $balance = (float)($inv['balance_amount'] ?? max(0, $amount - $paid));

        // Invoice was created/issued in the selected reporting period
        if ($invDate >= $startDate && $invDate <= $endDate) {
            $totalInvoiced += $amount;
            $invoicesCreatedCount++;

            if ($status === 'paid' || $balance <= 0) {
                $invoicesPaidCount++;
                $paidInvoicesSum += $amount;
            } else {
                if ($dueDate < $today) {
                    $invoicesOverdueCount++;
                    $periodOverdueAmt += $balance;
                } else {
                    $invoicesPendingCount++;
                    $periodPendingAmt += $balance;
                }
                if ($status === 'partially paid' || $paid > 0) {
                    $partiallyPaidSum += $paid;
                }
            }
        }

        // Outstanding Receivables as of reporting period end:
        // Any invoice issued on/before end date with unpaid balance
        if ($invDate <= $endDate && $balance > 0) {
            $totalReceivables += $balance;
        }
    }

    // 2. Fetch Verified Cash Payments Received during Period (Based on ACTUAL Payment Date)
    $payStmt = $pdo->query("SELECT * FROM `crm_payments`");
    $allPayments = $payStmt->fetchAll(PDO::FETCH_ASSOC);

    $paymentsReceived = 0.0;
    $paymentsCount = 0;
    $paymentsByMethod = [];

    foreach ($allPayments as $p) {
        $pStatus = strtolower(trim($p['status'] ?? 'paid'));
        if ($pStatus === 'failed' || $pStatus === 'refunded' || $pStatus === 'void') {
            continue;
        }

        $pDate = normalizeDateStr($p['payment_date'] ?? '', $p['created_at'] ?? null);
        if ($pDate >= $startDate && $pDate <= $endDate) {
            $pAmt = (float)($p['amount'] ?? 0);
            $paymentsReceived += $pAmt;
            $paymentsCount++;

            $method = trim($p['payment_method'] ?? 'Online Transfer');
            if (empty($method)) $method = 'Online Transfer';
            $paymentsByMethod[$method] = ($paymentsByMethod[$method] ?? 0.0) + $pAmt;
        }
    }

    // 3. Fetch Recorded Business Expenses during Period
    $expStmt = $pdo->query("SELECT * FROM `crm_expenses`");
    $allExpenses = $expStmt ? $expStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $totalExpenses = 0.0;
    $expensesCount = 0;
    $expensesByCategory = [];

    foreach ($allExpenses as $e) {
        $eStatus = strtolower(trim($e['status'] ?? 'paid'));
        if ($eStatus === 'cancelled' || $eStatus === 'rejected') {
            continue;
        }

        $eDate = normalizeDateStr($e['expense_date'] ?? '', $e['created_at'] ?? null);
        if ($eDate >= $startDate && $eDate <= $endDate) {
            $eAmt = (float)($e['amount'] ?? 0);
            $totalExpenses += $eAmt;
            $expensesCount++;

            $cat = trim($e['category'] ?? 'General');
            if (empty($cat)) $cat = 'General';
            $expensesByCategory[$cat] = ($expensesByCategory[$cat] ?? 0.0) + $eAmt;
        }
    }

    // 4. Transparent Financial & Profit Calculations
    // Cost of Goods Sold (COGS) — Direct project costs if segregated
    $directCogs = 0.0; // In pure consulting/agency CRM, direct software labor is not segregated in invoice line items
    $cogsAvailable = false;
    $cogsNote = "Software consulting model: direct labor costs are included in operating overhead.";

    $grossProfit = $totalInvoiced - $directCogs;
    $netProfitCash = $paymentsReceived - $totalExpenses; // Cash Basis Net Inflow
    $netProfitAccrual = $totalInvoiced - $totalExpenses; // Accrual Basis Net Inflow

    $cashMarginPercent = $paymentsReceived > 0 ? round(($netProfitCash / $paymentsReceived) * 100, 1) : 0.0;
    $accrualMarginPercent = $totalInvoiced > 0 ? round(($netProfitAccrual / $totalInvoiced) * 100, 1) : 0.0;

    // 5. Build 6-Month Historical Revenue Trend
    $historicalMonths = [];
    $currTs = strtotime($period['end_date']);
    for ($i = 5; $i >= 0; $i--) {
        $mTs = strtotime("-$i month", $currTs);
        $mStart = date('Y-m-01', $mTs);
        $mEnd = date('Y-m-t', $mTs);
        $mKey = date('Y-m', $mTs);
        $mLabel = date('M Y', $mTs);

        $mInvoiced = 0.0;
        foreach ($allInvoices as $inv) {
            $invDate = normalizeDateStr($inv['issue_date'] ?? '', $inv['transaction_date'] ?? null);
            if ($invDate >= $mStart && $invDate <= $mEnd) {
                $status = strtolower(trim($inv['status'] ?? 'pending'));
                if ($status !== 'cancelled' && $status !== 'void') {
                    $mInvoiced += (float)($inv['amount'] ?? 0);
                }
            }
        }

        $mReceived = 0.0;
        foreach ($allPayments as $p) {
            $pDate = normalizeDateStr($p['payment_date'] ?? '', $p['created_at'] ?? null);
            if ($pDate >= $mStart && $pDate <= $mEnd) {
                $pStatus = strtolower(trim($p['status'] ?? 'paid'));
                if ($pStatus !== 'failed' && $pStatus !== 'refunded' && $pStatus !== 'void') {
                    $mReceived += (float)($p['amount'] ?? 0);
                }
            }
        }

        $mExpense = 0.0;
        foreach ($allExpenses as $e) {
            $eDate = normalizeDateStr($e['expense_date'] ?? '', $e['created_at'] ?? null);
            if ($eDate >= $mStart && $eDate <= $mEnd) {
                $eStatus = strtolower(trim($e['status'] ?? 'paid'));
                if ($eStatus !== 'cancelled' && $eStatus !== 'rejected') {
                    $mExpense += (float)($e['amount'] ?? 0);
                }
            }
        }

        $historicalMonths[] = [
            'month_key'  => $mKey,
            'label'      => $mLabel,
            'invoiced'   => $mInvoiced,
            'payments'   => $mReceived,
            'expenses'   => $mExpense,
            'net_profit' => $mReceived - $mExpense
        ];
    }

    // 6. Expense Breakdown with Percentages & Palette Colors
    $palette = ['#3b82f6', '#10b981', '#f59e0b', '#ec4899', '#8b5cf6', '#06b6d4', '#f97316', '#64748b'];
    $expenseBreakdown = [];
    $ci = 0;
    foreach ($expensesByCategory as $cat => $amt) {
        $pct = $totalExpenses > 0 ? round(($amt / $totalExpenses) * 100, 1) : 0.0;
        $expenseBreakdown[] = [
            'category'   => $cat,
            'amount'     => $amt,
            'percentage' => $pct,
            'color'      => $palette[$ci % count($palette)]
        ];
        $ci++;
    }

    // Sort expense categories highest first
    usort($expenseBreakdown, fn($a, $b) => $b['amount'] <=> $a['amount']);

    // 7. Payment Status Distribution Breakdown
    $statusDistribution = [
        [
            'status' => 'Paid',
            'amount' => $paidInvoicesSum,
            'count'  => $invoicesPaidCount,
            'color'  => '#10b981'
        ],
        [
            'status' => 'Pending',
            'amount' => $periodPendingAmt,
            'count'  => $invoicesPendingCount,
            'color'  => '#f59e0b'
        ],
        [
            'status' => 'Overdue',
            'amount' => $periodOverdueAmt,
            'count'  => $invoicesOverdueCount,
            'color'  => '#ef4444'
        ]
    ];

    return [
        'period'                  => $period,
        'summary_cards'           => [
            'total_revenue'          => $totalInvoiced,
            'payments_received'      => $paymentsReceived,
            'outstanding_payments'   => $totalReceivables,
            'total_expenses'         => $totalExpenses,
            'gross_profit'           => $grossProfit,
            'net_profit'             => $netProfitCash,
            'net_profit_accrual'     => $netProfitAccrual,
            'cash_margin_percent'    => $cashMarginPercent,
            'accrual_margin_percent' => $accrualMarginPercent,
            'currency_symbol'        => '₹',
            'currency_code'          => 'INR'
        ],
        'monthly_metrics'         => [
            'total_invoiced'          => $totalInvoiced,
            'payments_received'       => $paymentsReceived,
            'pending_invoice_amount'  => $periodPendingAmt,
            'overdue_invoice_amount'  => $periodOverdueAmt,
            'total_expenses'          => $totalExpenses,
            'gross_profit'            => $grossProfit,
            'net_profit'              => $netProfitCash,
            'invoices_created_count'  => $invoicesCreatedCount,
            'invoices_paid_count'     => $invoicesPaidCount,
            'invoices_pending_count'  => $invoicesPendingCount,
            'invoices_overdue_count'  => $invoicesOverdueCount,
            'payments_count'          => $paymentsCount,
            'expenses_count'          => $expensesCount
        ],
        'accounting_rules'        => [
            'revenue_basis'          => 'Accrual (sum of valid invoices issued in period)',
            'cash_basis'             => 'Actual verified payment receipt records within date range',
            'receivables_basis'      => 'Active non-cancelled invoice balances outstanding',
            'cogs_status'            => $cogsAvailable ? 'Available' : 'Estimated (100% services margin)',
            'cogs_note'              => $cogsNote
        ],
        'charts'                  => [
            'revenue_trend'              => $historicalMonths,
            'expense_breakdown'          => $expenseBreakdown,
            'payment_status_distribution'=> $statusDistribution,
            'payments_by_method'         => $paymentsByMethod
        ]
    ];
}

// -----------------------------------------------------------------------------
// XLSX GENERATOR (100% Pure PHP & ZipArchive)
// -----------------------------------------------------------------------------
function generateXlsxReport(string $reportTitle, string $periodLabel, array $sheets): string {
    $tempFile = tempnam(sys_get_temp_dir(), 'crm_rep_');
    $zip = new ZipArchive();
    if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new Exception("Unable to create XLSX zip file");
    }

    // [Content_Types].xml
    $typesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
        '<Default Extension="xml" ContentType="application/xml"/>' .
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
        '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';

    $sheetIndex = 1;
    foreach ($sheets as $s) {
        $typesXml .= '<Override PartName="/xl/worksheets/sheet' . $sheetIndex . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $sheetIndex++;
    }
    $typesXml .= '</Types>';
    $zip->addFromString('[Content_Types].xml', $typesXml);

    // _rels/.rels
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
        '</Relationships>');

    // xl/_rels/workbook.xml.rels
    $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $sheetIndex = 1;
    foreach ($sheets as $s) {
        $wbRels .= '<Relationship Id="rId' . $sheetIndex . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sheetIndex . '.xml"/>';
        $sheetIndex++;
    }
    $wbRels .= '<Relationship Id="rId' . $sheetIndex . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    $wbRels .= '</Relationships>';
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

    // xl/styles.xml
    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
        '<fonts count="3">' .
        '<font><sz val="10"/><name val="Segoe UI"/></font>' .
        '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Segoe UI"/></font>' .
        '<font><b/><sz val="12"/><color rgb="FF0E1726"/><name val="Segoe UI"/></font>' .
        '</fonts>' .
        '<fills count="3">' .
        '<fill><patternFill patternType="none"/></fill>' .
        '<fill><patternFill patternType="gray125"/></fill>' .
        '<fill><patternFill patternType="solid"><fgColor rgb="FF0E1726"/></patternFill></fill>' .
        '</fills>' .
        '<borders count="1"><border><left/><right/><top/><bottom/></border></borders>' .
        '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
        '<cellXfs count="4">' .
        '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' .
        '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>' .
        '<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>' .
        '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>' .
        '</cellXfs>' .
        '</styleSheet>';
    $zip->addFromString('xl/styles.xml', $stylesXml);

    // xl/workbook.xml
    $wbXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        '<sheets>';
    $sheetIndex = 1;
    foreach ($sheets as $s) {
        $sheetTitle = htmlspecialchars(substr($s['title'] ?? "Sheet$sheetIndex", 0, 31));
        $wbXml .= '<sheet name="' . $sheetTitle . '" sheetId="' . $sheetIndex . '" r:id="rId' . $sheetIndex . '"/>';
        $sheetIndex++;
    }
    $wbXml .= '</sheets></workbook>';
    $zip->addFromString('xl/workbook.xml', $wbXml);

    // Build worksheets
    $colLetters = ['A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','T','U','V','W','X','Y','Z'];
    $sheetIndex = 1;

    foreach ($sheets as $s) {
        $headers = $s['headers'] ?? [];
        $rows = $s['rows'] ?? [];

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<sheetData>';

        // Row 1: Title Header
        $sheetXml .= '<row r="1">';
        $sheetXml .= '<c r="A1" t="inlineStr" s="3"><is><t>' . htmlspecialchars($reportTitle . ' — ' . ($s['title'] ?? '') . ' (' . $periodLabel . ')') . '</t></is></c>';
        $sheetXml .= '</row>';

        // Row 3: Column Headers
        $sheetXml .= '<row r="3">';
        foreach ($headers as $ci => $h) {
            $col = $colLetters[$ci] ?? ('A' . $ci);
            $sheetXml .= '<c r="' . $col . '3" t="inlineStr" s="1"><is><t>' . htmlspecialchars($h) . '</t></is></c>';
        }
        $sheetXml .= '</row>';

        // Row 4+: Data Rows
        $rowNum = 4;
        foreach ($rows as $row) {
            $sheetXml .= '<row r="' . $rowNum . '">';
            foreach ($row as $ci => $val) {
                $col = $colLetters[$ci] ?? ('A' . $ci);
                if (is_numeric($val) && !is_string($val)) {
                    $sheetXml .= '<c r="' . $col . $rowNum . '" s="2"><v>' . $val . '</v></c>';
                } else {
                    $sheetXml .= '<c r="' . $col . $rowNum . '" t="inlineStr" s="0"><is><t>' . htmlspecialchars((string)$val) . '</t></is></c>';
                }
            }
            $sheetXml .= '</row>';
            $rowNum++;
        }

        $sheetXml .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet' . $sheetIndex . '.xml', $sheetXml);
        $sheetIndex++;
    }

    $zip->close();
    return $tempFile;
}

// -----------------------------------------------------------------------------
// PDF GENERATOR (100% Pure PHP Standard PDF-1.4 Spec)
// -----------------------------------------------------------------------------
function generatePdfReport(string $title, string $period, array $metrics, array $tables): string {
    $pdf = "%PDF-1.4\n";
    $objects = [];

    $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
    $objects[5] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";

    $stream = "q\n";
    // Top banner (Dark Navy #0e1726)
    $stream .= "0.05 0.09 0.15 rg 0 760 595.28 81.89 re f\n";

    $cleanTitle = preg_replace('/[^\x20-\x7E]/', '', str_replace(['₹', 'INR'], ['Rs.', 'Rs.'], $title));
    $cleanPeriod = preg_replace('/[^\x20-\x7E]/', '', $period);
    $timestamp = date('Y-m-d H:i:s');

    $stream .= "BT /F2 16 Tf 1 1 1 rg 36 805 Td (" . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $cleanTitle) . ") Tj ET\n";
    $stream .= "BT /F1 9 Tf 0.7 0.75 0.8 rg 36 786 Td (Period: " . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $cleanPeriod) . "   |   Generated: " . $timestamp . "   |   Currency: INR) Tj ET\n";

    $y = 720;
    $stream .= "BT /F2 12 Tf 0.1 0.15 0.2 rg 36 " . $y . " Td (Financial Performance Summary) Tj ET\n";
    $y -= 22;

    // Draw 3x2 cards
    $cardW = 165;
    $cardH = 46;
    $margin = 36;
    $gap = 14;

    $ci = 0;
    foreach ($metrics as $label => $val) {
        $col = $ci % 3;
        $row = floor($ci / 3);
        $cx = $margin + $col * ($cardW + $gap);
        $cy = $y - ($row * ($cardH + 10));

        // Card background
        $stream .= "0.96 0.97 0.98 rg " . $cx . " " . $cy . " " . $cardW . " " . $cardH . " re f\n";
        $stream .= "0.85 0.88 0.92 RG 1 w " . $cx . " " . $cy . " " . $cardW . " " . $cardH . " re S\n";

        // Label
        $cLabel = preg_replace('/[^\x20-\x7E]/', '', $label);
        $stream .= "BT /F1 8.5 Tf 0.4 0.45 0.5 rg " . ($cx + 10) . " " . ($cy + 30) . " Td (" . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $cLabel) . ") Tj ET\n";

        // Value
        $cVal = preg_replace('/[^\x20-\x7E]/', '', str_replace(['₹', 'INR'], ['Rs. ', 'Rs. '], (string)$val));
        $stream .= "BT /F2 12 Tf 0.1 0.15 0.2 rg " . ($cx + 10) . " " . ($cy + 13) . " Td (" . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $cVal) . ") Tj ET\n";

        $ci++;
        if ($ci >= 6) break;
    }

    $y -= 120;

    // Tables
    foreach ($tables as $tTitle => $tData) {
        if ($y < 120) break;

        $cTTitle = preg_replace('/[^\x20-\x7E]/', '', $tTitle);
        $stream .= "BT /F2 11 Tf 0.1 0.15 0.2 rg 36 " . $y . " Td (" . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $cTTitle) . ") Tj ET\n";
        $y -= 16;

        $headers = $tData['headers'] ?? [];
        $rows = array_slice($tData['rows'] ?? [], 0, 7);
        $colCount = max(1, count($headers));
        $widths = $tData['widths'] ?? array_fill(0, $colCount, 523 / $colCount);

        // Header row
        $stream .= "0.92 0.94 0.97 rg 36 " . ($y - 4) . " 523 18 re f\n";
        $stream .= "0.8 0.85 0.9 RG 0.8 w 36 " . ($y - 4) . " 523 18 re S\n";

        $hx = 42;
        foreach ($headers as $hi => $h) {
            $cH = preg_replace('/[^\x20-\x7E]/', '', (string)$h);
            $stream .= "BT /F2 8 Tf 0.15 0.2 0.25 rg " . $hx . " " . ($y + 2) . " Td (" . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $cH) . ") Tj ET\n";
            $hx += $widths[$hi];
        }
        $y -= 20;

        foreach ($rows as $row) {
            if ($y < 50) break;
            $stream .= "0.9 0.92 0.95 RG 0.5 w 36 " . ($y - 2) . " 523 0.5 re S\n";
            $rx = 42;
            foreach ($row as $ri => $cell) {
                $cCell = preg_replace('/[^\x20-\x7E]/', '', str_replace(['₹', 'INR'], ['Rs. ', 'Rs. '], (string)$cell));
                $cCell = substr($cCell, 0, 34);
                $stream .= "BT /F1 7.5 Tf 0.2 0.25 0.3 rg " . $rx . " " . ($y + 3) . " Td (" . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $cCell) . ") Tj ET\n";
                $rx += $widths[$ri];
            }
            $y -= 17;
        }
        $y -= 16;
    }

    // Confidential footer
    $stream .= "BT /F1 7.5 Tf 0.5 0.55 0.6 rg 36 22 Td (Software CRM Financial Module -- Authorized Internal Use Only  |  Report Period: " . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $cleanPeriod) . ") Tj ET\n";
    $stream .= "Q\n";

    $streamLen = strlen($stream);
    $objects[6] = "<< /Length " . $streamLen . " >>\nstream\n" . $stream . "endstream";
    $objects[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>";

    $body = "";
    $xref = [];
    $offset = strlen($pdf);

    for ($i = 1; $i <= 6; $i++) {
        $xref[$i] = $offset;
        $objStr = "$i 0 obj\n" . $objects[$i] . "\nendobj\n";
        $body .= $objStr;
        $offset += strlen($objStr);
    }

    $xrefStart = strlen($pdf) + strlen($body);
    $xrefStr = "xref\n0 7\n0000000000 65535 f \n";
    for ($i = 1; $i <= 6; $i++) {
        $xrefStr .= sprintf("%010d 00000 n \n", $xref[$i]);
    }
    $trailer = "trailer\n<< /Size 7 /Root 1 0 R >>\nstartxref\n" . $xrefStart . "\n%%EOF\n";

    return $pdf . $body . $xrefStr . $trailer;
}

// -----------------------------------------------------------------------------
// PRINTABLE A4 HTML REPORT GENERATOR
// -----------------------------------------------------------------------------
function renderPrintableReport(array $summary, array $payments, array $expenses): void {
    $period = $summary['period']['label'];
    $cards = $summary['summary_cards'];
    $rules = $summary['accounting_rules'];
    $timestamp = date('d M Y, h:i A');

    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Financial Report - <?= htmlspecialchars($period) ?> - Software CRM</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
    body { background: #f8fafc; color: #1e293b; padding: 24px; font-size: 13px; line-height: 1.5; }
    .page-container { max-width: 900px; margin: 0 auto; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
    .header-bar { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #0e1726; padding-bottom: 20px; margin-bottom: 24px; }
    .logo-title { font-size: 22px; font-weight: 800; color: #0e1726; letter-spacing: -0.5px; }
    .meta-tag { font-size: 12px; color: #64748b; margin-top: 4px; }
    .badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; background: #eff6ff; color: #2563eb; }
    
    .grid-cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 30px; }
    .card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px 16px; }
    .card-label { font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
    .card-value { font-size: 20px; font-weight: 800; color: #0f172a; margin-top: 6px; }
    
    .section-title { font-size: 14px; font-weight: 800; color: #0f172a; margin: 24px 0 10px; border-left: 4px solid #2563eb; padding-left: 8px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 11.5px; }
    th { background: #f1f5f9; color: #475569; font-weight: 700; text-align: left; padding: 8px 10px; border-bottom: 1px solid #cbd5e1; }
    td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; color: #334155; }
    .text-right { text-align: right; }
    .font-mono { font-family: monospace; font-size: 11px; }
    
    .footer-note { font-size: 11px; color: #94a3b8; text-align: center; border-top: 1px solid #e2e8f0; padding-top: 20px; margin-top: 30px; }
    
    @media print {
      body { background: #ffffff; padding: 0; }
      .page-container { border: none; box-shadow: none; padding: 15mm; max-width: 100%; }
      .no-print { display: none !important; }
      @page { size: A4 portrait; margin: 10mm; }
    }
  </style>
</head>
<body>

  <div class="no-print" style="max-width: 900px; margin: 0 auto 16px; display: flex; justify-content: flex-end; gap: 10px;">
    <button onclick="window.print()" style="background: #2563eb; color: #fff; border: none; padding: 8px 18px; border-radius: 8px; font-weight: 700; cursor: pointer;">
      🖨️ Print / Save as PDF
    </button>
    <button onclick="window.close()" style="background: #e2e8f0; color: #475569; border: none; padding: 8px 14px; border-radius: 8px; font-weight: 600; cursor: pointer;">
      Close
    </button>
  </div>

  <div class="page-container">
    <div class="header-bar">
      <div>
        <div class="logo-title">Software CRM Financial Report</div>
        <div class="meta-tag">Period: <strong><?= htmlspecialchars($period) ?></strong> &bull; Generated: <?= $timestamp ?></div>
      </div>
      <div style="text-align: right;">
        <span class="badge">Official Financial Summary</span>
        <div style="font-size: 11px; color: #94a3b8; margin-top: 6px;">Currency: Indian Rupee (INR / ₹)</div>
      </div>
    </div>

    <div class="grid-cards">
      <div class="card">
        <div class="card-label">Total Revenue (Invoiced)</div>
        <div class="card-value">₹<?= number_format($cards['total_revenue'], 2) ?></div>
      </div>
      <div class="card">
        <div class="card-label">Payments Received (Cash)</div>
        <div class="card-value" style="color: #10b981;">₹<?= number_format($cards['payments_received'], 2) ?></div>
      </div>
      <div class="card">
        <div class="card-label">Outstanding Receivables</div>
        <div class="card-value" style="color: #ef4444;">₹<?= number_format($cards['outstanding_payments'], 2) ?></div>
      </div>
      <div class="card">
        <div class="card-label">Total Expenses</div>
        <div class="card-value" style="color: #64748b;">₹<?= number_format($cards['total_expenses'], 2) ?></div>
      </div>
      <div class="card">
        <div class="card-label">Gross Profit (100% Margin)</div>
        <div class="card-value" style="color: #3b82f6;">₹<?= number_format($cards['gross_profit'], 2) ?></div>
      </div>
      <div class="card">
        <div class="card-label">Net Profit (Cash Inflow)</div>
        <div class="card-value" style="color: <?= $cards['net_profit'] >= 0 ? '#10b981' : '#dc2626' ?>;">
          ₹<?= number_format($cards['net_profit'], 2) ?>
        </div>
      </div>
    </div>

    <!-- Payments Table -->
    <div class="section-title">Verified Cash Payments Received (<?= count($payments) ?> Records)</div>
    <table>
      <thead>
        <tr>
          <th>Receipt No</th>
          <th>Invoice No</th>
          <th>Client Name</th>
          <th>Payment Date</th>
          <th>Method</th>
          <th>Reference / Txn</th>
          <th class="text-right">Amount (₹)</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($payments)): ?>
          <tr><td colspan="7" style="text-align:center; padding: 20px; color: #94a3b8;">No payments recorded in this period.</td></tr>
        <?php else: ?>
          <?php foreach (array_slice($payments, 0, 15) as $p): ?>
            <tr>
              <td class="font-mono"><?= htmlspecialchars($p['receipt_no'] ?: $p['id']) ?></td>
              <td class="font-mono"><?= htmlspecialchars($p['invoice_no'] ?: '-') ?></td>
              <td><strong><?= htmlspecialchars($p['customer_name'] ?: '-') ?></strong></td>
              <td><?= htmlspecialchars($p['payment_date'] ?: date('d M Y', strtotime($p['created_at']))) ?></td>
              <td><?= htmlspecialchars($p['payment_method'] ?: 'Online Transfer') ?></td>
              <td class="font-mono"><?= htmlspecialchars($p['transaction_id'] ?: ($p['reference'] ?: '-')) ?></td>
              <td class="text-right" style="font-weight: 700; color: #10b981;">₹<?= number_format((float)$p['amount'], 2) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <!-- Expenses Table -->
    <div class="section-title">Recorded Business Operating Expenses (<?= count($expenses) ?> Records)</div>
    <table>
      <thead>
        <tr>
          <th>Expense ID</th>
          <th>Date</th>
          <th>Category</th>
          <th>Description</th>
          <th>Payment Method</th>
          <th>Reference</th>
          <th class="text-right">Amount (₹)</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($expenses)): ?>
          <tr><td colspan="7" style="text-align:center; padding: 20px; color: #94a3b8;">No expenses recorded in this period.</td></tr>
        <?php else: ?>
          <?php foreach (array_slice($expenses, 0, 15) as $e): ?>
            <tr>
              <td class="font-mono"><?= htmlspecialchars($e['id']) ?></td>
              <td><?= htmlspecialchars($e['expense_date']) ?></td>
              <td><span style="font-weight:600; color:#2563eb;"><?= htmlspecialchars($e['category']) ?></span></td>
              <td><?= htmlspecialchars($e['description']) ?></td>
              <td><?= htmlspecialchars($e['payment_method']) ?></td>
              <td class="font-mono"><?= htmlspecialchars($e['reference'] ?: '-') ?></td>
              <td class="text-right" style="font-weight: 700;">₹<?= number_format((float)$e['amount'], 2) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <div class="footer-note">
      Software CRM &bull; Financial calculations: Accrual revenue + verified cash receipts &bull; Confidential &bull; Generated by <?= htmlspecialchars($_SESSION['auth_user']['name'] ?? 'System') ?>
    </div>
  </div>

</body>
</html>
    <?php
}

// -----------------------------------------------------------------------------
// ROUTER & ACTION HANDLER
// -----------------------------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'];
$action = trim($_GET['action'] ?? 'summary');

if ($method === 'GET') {

    // -------------------------------------------------------------------------
    // 1. GET FINANCIAL SUMMARY & DASHBOARD METRICS
    // -------------------------------------------------------------------------
    if ($action === 'summary') {
        $period = parsePeriodBounds($_GET);
        $summary = calculateFinancialSummary($pdo, $period);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'data' => $summary], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -------------------------------------------------------------------------
    // 2. GET DETAILED PAYMENT REPORT
    // -------------------------------------------------------------------------
    if ($action === 'payments') {
        $period = parsePeriodBounds($_GET);
        $methodFilter = trim($_GET['payment_method'] ?? 'all');
        $statusFilter = trim($_GET['status'] ?? 'all');
        $search = trim($_GET['search'] ?? '');
        $sortBy = trim($_GET['sort_by'] ?? 'payment_date');
        $sortOrder = strtolower(trim($_GET['sort_order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

        $payStmt = $pdo->query("SELECT * FROM `crm_payments` ORDER BY created_at DESC");
        $all = $payStmt->fetchAll(PDO::FETCH_ASSOC);

        $filtered = [];
        $totalAmount = 0.0;

        foreach ($all as $p) {
            $pDate = normalizeDateStr($p['payment_date'] ?? '', $p['created_at'] ?? null);

            // Filter by date range
            if ($pDate < $period['start_date'] || $pDate > $period['end_date']) {
                continue;
            }

            // Filter by method
            if ($methodFilter !== 'all' && strtolower($p['payment_method'] ?? '') !== strtolower($methodFilter)) {
                continue;
            }

            // Filter by status
            if ($statusFilter !== 'all' && strtolower($p['status'] ?? 'paid') !== strtolower($statusFilter)) {
                continue;
            }

            // Search filter
            if (!empty($search)) {
                $haystack = strtolower(($p['invoice_no'] ?? '') . ' ' . ($p['receipt_no'] ?? '') . ' ' . ($p['customer_name'] ?? '') . ' ' . ($p['transaction_id'] ?? '') . ' ' . ($p['reference'] ?? ''));
                if (strpos($haystack, strtolower($search)) === false) {
                    continue;
                }
            }

            $p['amount'] = (float)$p['amount'];
            $p['normalized_date'] = $pDate;
            $totalAmount += $p['amount'];
            $filtered[] = $p;
        }

        // Sort
        usort($filtered, function($a, $b) use ($sortBy, $sortOrder) {
            $valA = $a[$sortBy] ?? ($a['normalized_date'] ?? '');
            $valB = $b[$sortBy] ?? ($b['normalized_date'] ?? '');
            if (is_numeric($valA) && is_numeric($valB)) {
                $cmp = $valA <=> $valB;
            } else {
                $cmp = strcmp((string)$valA, (string)$valB);
            }
            return $sortOrder === 'ASC' ? $cmp : -$cmp;
        });

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success'      => true,
            'period'       => $period,
            'total_amount' => $totalAmount,
            'count'        => count($filtered),
            'data'         => $filtered
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -------------------------------------------------------------------------
    // 3. GET EXPENSE REPORT & LIST
    // -------------------------------------------------------------------------
    if ($action === 'expenses') {
        $period = parsePeriodBounds($_GET);
        $catFilter = trim($_GET['category'] ?? 'all');
        $methodFilter = trim($_GET['payment_method'] ?? 'all');
        $search = trim($_GET['search'] ?? '');
        $sortBy = trim($_GET['sort_by'] ?? 'expense_date');
        $sortOrder = strtolower(trim($_GET['sort_order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

        $expStmt = $pdo->query("SELECT * FROM `crm_expenses` ORDER BY expense_date DESC, id DESC");
        $all = $expStmt ? $expStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $filtered = [];
        $totalAmount = 0.0;
        $categoryTotals = [];

        foreach ($all as $e) {
            $eDate = normalizeDateStr($e['expense_date'] ?? '', $e['created_at'] ?? null);

            // Filter date
            if ($eDate < $period['start_date'] || $eDate > $period['end_date']) {
                continue;
            }

            // Filter category
            if ($catFilter !== 'all' && strtolower($e['category'] ?? '') !== strtolower($catFilter)) {
                continue;
            }

            // Filter payment method
            if ($methodFilter !== 'all' && strtolower($e['payment_method'] ?? '') !== strtolower($methodFilter)) {
                continue;
            }

            // Search
            if (!empty($search)) {
                $haystack = strtolower(($e['description'] ?? '') . ' ' . ($e['category'] ?? '') . ' ' . ($e['reference'] ?? '') . ' ' . ($e['notes'] ?? ''));
                if (strpos($haystack, strtolower($search)) === false) {
                    continue;
                }
            }

            $e['amount'] = (float)$e['amount'];
            $totalAmount += $e['amount'];
            $cat = $e['category'] ?: 'General';
            $categoryTotals[$cat] = ($categoryTotals[$cat] ?? 0.0) + $e['amount'];
            $filtered[] = $e;
        }

        // Sort
        usort($filtered, function($a, $b) use ($sortBy, $sortOrder) {
            $valA = $a[$sortBy] ?? '';
            $valB = $b[$sortBy] ?? '';
            if (is_numeric($valA) && is_numeric($valB)) {
                $cmp = $valA <=> $valB;
            } else {
                $cmp = strcmp((string)$valA, (string)$valB);
            }
            return $sortOrder === 'ASC' ? $cmp : -$cmp;
        });

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success'         => true,
            'period'          => $period,
            'total_amount'    => $totalAmount,
            'count'           => count($filtered),
            'category_totals' => $categoryTotals,
            'data'            => $filtered
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -------------------------------------------------------------------------
    // 4. MULTI-FORMAT REPORT EXPORT (PDF, XLSX, CSV, PRINT)
    // -------------------------------------------------------------------------
    if ($action === 'export') {
        $format = strtolower(trim($_GET['format'] ?? 'csv'));
        $period = parsePeriodBounds($_GET);
        $summary = calculateFinancialSummary($pdo, $period);

        // Fetch payments for period
        $payStmt = $pdo->query("SELECT * FROM `crm_payments` ORDER BY created_at DESC");
        $allPays = $payStmt->fetchAll(PDO::FETCH_ASSOC);
        $periodPays = [];
        foreach ($allPays as $p) {
            $pDate = normalizeDateStr($p['payment_date'] ?? '', $p['created_at'] ?? null);
            if ($pDate >= $period['start_date'] && $pDate <= $period['end_date']) {
                $periodPays[] = $p;
            }
        }

        // Fetch expenses for period
        $expStmt = $pdo->query("SELECT * FROM `crm_expenses` ORDER BY expense_date DESC");
        $allExps = $expStmt ? $expStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $periodExps = [];
        foreach ($allExps as $e) {
            $eDate = normalizeDateStr($e['expense_date'] ?? '', $e['created_at'] ?? null);
            if ($eDate >= $period['start_date'] && $eDate <= $period['end_date']) {
                $periodExps[] = $e;
            }
        }

        $dateSlug = $period['type'] === 'custom' 
            ? ($period['start_date'] . '_to_' . $period['end_date']) 
            : $period['month_key'];

        // --- A. PRINTABLE A4 VIEW ---
        if ($format === 'print') {
            renderPrintableReport($summary, $periodPays, $periodExps);
            exit;
        }

        // --- B. NATIVE PDF DOWNLOAD ---
        if ($format === 'pdf' || $format === 'pnl_pdf') {
            $metrics = [
                'Total Invoiced Revenue' => 'Rs. ' . number_format($summary['summary_cards']['total_revenue'], 2),
                'Payments Received'     => 'Rs. ' . number_format($summary['summary_cards']['payments_received'], 2),
                'Outstanding Receivables'=> 'Rs. ' . number_format($summary['summary_cards']['outstanding_payments'], 2),
                'Total Expenses'        => 'Rs. ' . number_format($summary['summary_cards']['total_expenses'], 2),
                'Gross Profit'          => 'Rs. ' . number_format($summary['summary_cards']['gross_profit'], 2),
                'Net Profit (Cash Flow)'=> 'Rs. ' . number_format($summary['summary_cards']['net_profit'], 2)
            ];

            $payRows = [];
            foreach ($periodPays as $p) {
                $payRows[] = [
                    $p['receipt_no'] ?: $p['id'],
                    $p['invoice_no'] ?: '-',
                    $p['customer_name'] ?: '-',
                    $p['payment_date'] ?: date('d M Y', strtotime($p['created_at'])),
                    $p['payment_method'] ?: 'Online Transfer',
                    'Rs. ' . number_format((float)$p['amount'], 2)
                ];
            }

            $expRows = [];
            foreach ($periodExps as $e) {
                $expRows[] = [
                    $e['id'],
                    $e['expense_date'],
                    $e['category'],
                    $e['description'],
                    $e['payment_method'],
                    'Rs. ' . number_format((float)$e['amount'], 2)
                ];
            }

            $tables = [
                'Payments Received (' . count($periodPays) . ' Total)' => [
                    'headers' => ['Receipt No', 'Invoice No', 'Client', 'Date', 'Method', 'Amount'],
                    'widths'  => [80, 80, 130, 80, 80, 73],
                    'rows'    => $payRows
                ],
                'Operating Expenses (' . count($periodExps) . ' Total)' => [
                    'headers' => ['Expense ID', 'Date', 'Category', 'Description', 'Method', 'Amount'],
                    'widths'  => [75, 75, 95, 145, 65, 68],
                    'rows'    => $expRows
                ]
            ];

            $pdfData = generatePdfReport("Software CRM - Financial & P&L Report", $period['label'], $metrics, $tables);

            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="crm_financial_report_' . $dateSlug . '.pdf"');
            header('Content-Length: ' . strlen($pdfData));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
            echo $pdfData;
            exit;
        }

        // --- C. AUTHENTIC EXCEL (.XLSX) DOWNLOAD ---
        if ($format === 'xlsx') {
            $sheet1Rows = [
                ['Total Revenue (Invoiced)', $summary['summary_cards']['total_revenue']],
                ['Total Payments Received', $summary['summary_cards']['payments_received']],
                ['Outstanding Receivables', $summary['summary_cards']['outstanding_payments']],
                ['Total Business Expenses', $summary['summary_cards']['total_expenses']],
                ['Gross Profit', $summary['summary_cards']['gross_profit']],
                ['Net Profit (Cash Basis)', $summary['summary_cards']['net_profit']],
                ['Net Profit (Accrual Basis)', $summary['summary_cards']['net_profit_accrual']],
                ['Invoices Created Count', $summary['monthly_metrics']['invoices_created_count']],
                ['Invoices Paid Count', $summary['monthly_metrics']['invoices_paid_count']],
                ['Invoices Pending Count', $summary['monthly_metrics']['invoices_pending_count']],
                ['Invoices Overdue Count', $summary['monthly_metrics']['invoices_overdue_count']]
            ];

            $sheet2Rows = [];
            foreach ($periodPays as $p) {
                $sheet2Rows[] = [
                    $p['receipt_no'] ?: $p['id'],
                    $p['invoice_no'] ?: '-',
                    $p['customer_name'] ?: '-',
                    $p['payment_date'] ?: date('d M Y', strtotime($p['created_at'])),
                    $p['payment_method'] ?: 'Online Transfer',
                    (float)$p['amount'],
                    $p['status'] ?: 'Paid',
                    $p['transaction_id'] ?: ($p['reference'] ?: '-')
                ];
            }

            $sheet3Rows = [];
            foreach ($periodExps as $e) {
                $sheet3Rows[] = [
                    $e['id'],
                    $e['expense_date'],
                    $e['category'],
                    $e['description'],
                    (float)$e['amount'],
                    $e['payment_method'],
                    $e['reference'] ?: '-',
                    $e['notes'] ?: ''
                ];
            }

            $sheets = [
                [
                    'title'   => 'Financial Summary',
                    'headers' => ['Financial Metric / KPI', 'Value (INR / ₹)'],
                    'rows'    => $sheet1Rows
                ],
                [
                    'title'   => 'Payments Received',
                    'headers' => ['Receipt No', 'Invoice No', 'Client Name', 'Payment Date', 'Method', 'Amount (INR)', 'Status', 'Transaction Reference'],
                    'rows'    => $sheet2Rows
                ],
                [
                    'title'   => 'Operating Expenses',
                    'headers' => ['Expense ID', 'Date', 'Category', 'Description', 'Amount (INR)', 'Payment Method', 'Reference', 'Notes'],
                    'rows'    => $sheet3Rows
                ]
            ];

            $xlsxFile = generateXlsxReport("Software CRM Financial Master Report", $period['label'], $sheets);

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="crm_financial_report_' . $dateSlug . '.xlsx"');
            header('Content-Length: ' . filesize($xlsxFile));
            header('Cache-Control: max-age=0');
            readfile($xlsxFile);
            @unlink($xlsxFile);
            exit;
        }

        // --- D. CSV EXPORTS (RFC 4180 Escaped + UTF-8 BOM) ---
        if ($format === 'payments_csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="crm_payment_report_' . $dateSlug . '.csv"');
            echo "\xEF\xBB\xBF"; // UTF-8 BOM for Microsoft Excel

            $out = fopen('php://output', 'w');
            fputcsv($out, ['Software CRM - Payment Report', 'Period: ' . $period['label'], 'Generated: ' . date('Y-m-d H:i:s')]);
            fputcsv($out, []);
            fputcsv($out, ['Payment ID / Receipt No', 'Invoice No', 'Client Name', 'Payment Date', 'Payment Method', 'Amount (INR)', 'Status', 'Transaction Reference']);

            foreach ($periodPays as $p) {
                fputcsv($out, [
                    $p['receipt_no'] ?: $p['id'],
                    $p['invoice_no'] ?: '-',
                    $p['customer_name'] ?: '-',
                    $p['payment_date'] ?: date('d M Y', strtotime($p['created_at'])),
                    $p['payment_method'] ?: 'Online Transfer',
                    number_format((float)$p['amount'], 2, '.', ''),
                    $p['status'] ?: 'Paid',
                    $p['transaction_id'] ?: ($p['reference'] ?: '-')
                ]);
            }
            fputcsv($out, []);
            fputcsv($out, ['TOTAL PAYMENTS RECEIVED', '', '', '', '', number_format($summary['summary_cards']['payments_received'], 2, '.', '')]);
            fclose($out);
            exit;
        }

        if ($format === 'expenses_csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="crm_expense_report_' . $dateSlug . '.csv"');
            echo "\xEF\xBB\xBF";

            $out = fopen('php://output', 'w');
            fputcsv($out, ['Software CRM - Expense Report', 'Period: ' . $period['label'], 'Generated: ' . date('Y-m-d H:i:s')]);
            fputcsv($out, []);
            fputcsv($out, ['Expense ID', 'Date', 'Category', 'Description', 'Amount (INR)', 'Payment Method', 'Reference', 'Notes', 'Recorded By']);

            foreach ($periodExps as $e) {
                fputcsv($out, [
                    $e['id'],
                    $e['expense_date'],
                    $e['category'],
                    $e['description'],
                    number_format((float)$e['amount'], 2, '.', ''),
                    $e['payment_method'],
                    $e['reference'] ?: '-',
                    $e['notes'] ?: '',
                    $e['recorded_by'] ?: ''
                ]);
            }
            fputcsv($out, []);
            fputcsv($out, ['TOTAL EXPENSES', '', '', '', number_format($summary['summary_cards']['total_expenses'], 2, '.', '')]);
            fclose($out);
            exit;
        }

        if ($format === 'pnl_csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="crm_pnl_report_' . $dateSlug . '.csv"');
            echo "\xEF\xBB\xBF";

            $out = fopen('php://output', 'w');
            fputcsv($out, ['Software CRM - Profit and Loss Statement', 'Period: ' . $period['label'], 'Generated: ' . date('Y-m-d H:i:s')]);
            fputcsv($out, []);
            fputcsv($out, ['Account Category', 'Line Item / Metric', 'Amount (INR)']);
            fputcsv($out, ['REVENUE', 'Total Invoiced Sales (Accrual)', number_format($summary['summary_cards']['total_revenue'], 2, '.', '')]);
            fputcsv($out, ['REVENUE', 'Total Cash Receipts Collected', number_format($summary['summary_cards']['payments_received'], 2, '.', '')]);
            fputcsv($out, ['COST OF SALES', 'Direct Cost of Goods / Services Sold', '0.00']);
            fputcsv($out, ['GROSS PROFIT', 'Gross Profit (Revenue - COGS)', number_format($summary['summary_cards']['gross_profit'], 2, '.', '')]);
            fputcsv($out, []);
            fputcsv($out, ['OPERATING EXPENSES', 'Category Breakdown', '']);

            foreach ($summary['charts']['expense_breakdown'] as $eb) {
                fputcsv($out, ['EXPENSE', $eb['category'], number_format($eb['amount'], 2, '.', '')]);
            }
            fputcsv($out, ['TOTAL EXPENSES', 'Total Operating Overhead', number_format($summary['summary_cards']['total_expenses'], 2, '.', '')]);
            fputcsv($out, []);
            fputcsv($out, ['NET PROFIT', 'Net Profit (Cash Basis)', number_format($summary['summary_cards']['net_profit'], 2, '.', '')]);
            fputcsv($out, ['NET PROFIT', 'Net Profit (Accrual Basis)', number_format($summary['summary_cards']['net_profit_accrual'], 2, '.', '')]);
            fputcsv($out, ['RECEIVABLES', 'Unpaid Customer Invoices Outstanding', number_format($summary['summary_cards']['outstanding_payments'], 2, '.', '')]);
            fclose($out);
            exit;
        }

        // Default Master CSV
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="crm_financial_summary_' . $dateSlug . '.csv"');
        echo "\xEF\xBB\xBF";

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Software CRM - Comprehensive Financial Report', 'Period: ' . $period['label'], 'Generated: ' . date('Y-m-d H:i:s')]);
        fputcsv($out, []);
        fputcsv($out, ['METRIC', 'VALUE (INR / ₹)']);
        fputcsv($out, ['Total Revenue (Invoiced)', number_format($summary['summary_cards']['total_revenue'], 2, '.', '')]);
        fputcsv($out, ['Total Payments Received (Cash)', number_format($summary['summary_cards']['payments_received'], 2, '.', '')]);
        fputcsv($out, ['Outstanding Receivables', number_format($summary['summary_cards']['outstanding_payments'], 2, '.', '')]);
        fputcsv($out, ['Total Recorded Expenses', number_format($summary['summary_cards']['total_expenses'], 2, '.', '')]);
        fputcsv($out, ['Gross Profit', number_format($summary['summary_cards']['gross_profit'], 2, '.', '')]);
        fputcsv($out, ['Net Profit (Cash Inflow)', number_format($summary['summary_cards']['net_profit'], 2, '.', '')]);
        fputcsv($out, ['Net Profit (Accrual Inflow)', number_format($summary['summary_cards']['net_profit_accrual'], 2, '.', '')]);
        fputcsv($out, ['Invoices Created Count', $summary['monthly_metrics']['invoices_created_count']]);
        fputcsv($out, ['Invoices Paid Count', $summary['monthly_metrics']['invoices_paid_count']]);
        fputcsv($out, ['Invoices Pending Count', $summary['monthly_metrics']['invoices_pending_count']]);
        fputcsv($out, ['Invoices Overdue Count', $summary['monthly_metrics']['invoices_overdue_count']]);
        fclose($out);
        exit;
    }
}

// -----------------------------------------------------------------------------
// POST / DELETE ACTIONS: EXPENSE CREATION, UPDATES & DELETION
// -----------------------------------------------------------------------------
$rawInput = file_get_contents('php://input');
$inputData = json_decode($rawInput, true) ?: $_POST;

if ($method === 'POST') {
    $sessUser = $_SESSION['auth_user'] ?? ['name' => 'Admin'];
    $recordedBy = trim($sessUser['name'] ?? 'Admin');

    // --- ADD EXPENSE ---
    if ($action === 'add_expense') {
        $date = normalizeDateStr($inputData['expense_date'] ?? date('Y-m-d'));
        $cat = trim($inputData['category'] ?? '');
        $desc = trim($inputData['description'] ?? '');
        $amt = (float)($inputData['amount'] ?? 0);
        $method = trim($inputData['payment_method'] ?? 'Bank Transfer');
        $ref = trim($inputData['reference'] ?? '');
        $notes = trim($inputData['notes'] ?? '');

        if (empty($cat)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Expense category is required.']);
            exit;
        }

        if (empty($desc)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Expense description is required.']);
            exit;
        }

        if ($amt <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Valid expense amount greater than 0 is required.']);
            exit;
        }

        $expId = 'exp_' . time() . '_' . rand(100, 999);

        $stmt = $pdo->prepare("
            INSERT INTO `crm_expenses`
            (`id`, `expense_date`, `category`, `description`, `amount`, `payment_method`, `reference`, `notes`, `recorded_by`, `status`, `created_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Paid', NOW())
        ");
        $stmt->execute([$expId, $date, $cat, $desc, $amt, $method, $ref, $notes, $recordedBy]);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'message' => 'Expense recorded successfully!',
            'data'    => [
                'id'             => $expId,
                'expense_date'   => $date,
                'category'       => $cat,
                'description'    => $desc,
                'amount'         => $amt,
                'payment_method' => $method,
                'reference'      => $ref,
                'notes'          => $notes,
                'recorded_by'    => $recordedBy,
                'status'         => 'Paid'
            ]
        ]);
        exit;
    }

    // --- UPDATE EXPENSE ---
    if ($action === 'update_expense') {
        $id = trim($inputData['id'] ?? '');
        if (empty($id)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Expense ID is required.']);
            exit;
        }

        $date = normalizeDateStr($inputData['expense_date'] ?? date('Y-m-d'));
        $cat = trim($inputData['category'] ?? '');
        $desc = trim($inputData['description'] ?? '');
        $amt = (float)($inputData['amount'] ?? 0);
        $method = trim($inputData['payment_method'] ?? 'Bank Transfer');
        $ref = trim($inputData['reference'] ?? '');
        $notes = trim($inputData['notes'] ?? '');

        if ($amt <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Valid expense amount greater than 0 is required.']);
            exit;
        }

        $stmt = $pdo->prepare("
            UPDATE `crm_expenses`
            SET `expense_date` = ?, `category` = ?, `description` = ?, `amount` = ?, `payment_method` = ?, `reference` = ?, `notes` = ?
            WHERE `id` = ?
        ");
        $stmt->execute([$date, $cat, $desc, $amt, $method, $ref, $notes, $id]);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'message' => 'Expense updated successfully!']);
        exit;
    }
}

// --- DELETE EXPENSE ---
if ($method === 'DELETE' || ($method === 'POST' && $action === 'delete_expense')) {
    $id = trim($_GET['id'] ?? ($inputData['id'] ?? ''));
    if (empty($id)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Expense ID is required for deletion.']);
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM `crm_expenses` WHERE `id` = ?");
    $stmt->execute([$id]);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'message' => 'Expense deleted successfully!']);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown reports API action']);
exit;
