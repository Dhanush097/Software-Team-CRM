<?php
/**
 * Software CRM - Invoices, Billing, Payments & Receipts API
 */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/session_guard.php';
SessionGuard::requireAuth(true);
require_once __DIR__ . '/db.php';
$pdo = Database::getConnection();

function sendJson($data, $code = 200) {
    if ($code >= 200 && $code < 300) {
        if (!isset($data['success'])) {
            $data['success'] = true;
        }
    } else {
        if (!isset($data['success'])) {
            $data['success'] = false;
        }
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Generate unique sequential or formatted invoice number
function generateInvoiceNumber(PDO $pdo): string {
    $year = date('Y');
    $prefix = "INV-$year-";
    try {
        $stmt = $pdo->prepare("SELECT invoice_no FROM crm_cash_ledger WHERE invoice_no LIKE ? ORDER BY invoice_no DESC LIMIT 1");
        $stmt->execute(["$prefix%"]);
        $lastNo = $stmt->fetchColumn();
        if ($lastNo && preg_match('/-(\d+)$/', $lastNo, $m)) {
            $nextNum = (int)$m[1] + 1;
        } else {
            $nextNum = 1;
        }
        return $prefix . sprintf('%03d', $nextNum);
    } catch (Exception $e) {
        return $prefix . sprintf('%03d', rand(10, 999));
    }
}

// Generate unique receipt number (e.g. REC-2026-004)
function generateReceiptNumber(PDO $pdo): string {
    $year = date('Y');
    $prefix = "REC-$year-";
    try {
        $stmt = $pdo->prepare("SELECT receipt_no FROM crm_payments WHERE receipt_no LIKE ? ORDER BY receipt_no DESC LIMIT 1");
        $stmt->execute(["$prefix%"]);
        $lastNo = $stmt->fetchColumn();
        if ($lastNo && preg_match('/-(\d+)$/', $lastNo, $m)) {
            $nextNum = (int)$m[1] + 1;
        } else {
            $nextNum = 1;
        }
        return $prefix . sprintf('%03d', $nextNum);
    } catch (Exception $e) {
        return $prefix . sprintf('%03d', rand(10, 999));
    }
}

// Helper: Fetch line items for an invoice
function getInvoiceItems(PDO $pdo, array $invoice): array {
    $items = [];
    try {
        $stmt = $pdo->prepare("SELECT * FROM crm_invoice_items WHERE invoice_id = ? ORDER BY sort_order ASC, id ASC");
        $stmt->execute([$invoice['id']]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    // Fallback to items_json if table had no rows
    if (empty($items) && !empty($invoice['items_json'])) {
        $decoded = json_decode($invoice['items_json'], true);
        if (is_array($decoded) && !empty($decoded)) {
            $items = $decoded;
        }
    }

    // Default single line item if completely empty
    if (empty($items)) {
        $items = [
            [
                'id' => 1,
                'invoice_id' => $invoice['id'],
                'description' => $invoice['title'] ?: 'Software Engineering & Consulting Services',
                'quantity' => 1,
                'rate' => (float)$invoice['amount'],
                'amount' => (float)$invoice['amount'],
                'sort_order' => 1
            ]
        ];
    }
    return $items;
}

$method = $_SERVER['REQUEST_METHOD'];

// =========================================================================
// GET: VIEW INVOICE, VIEW RECEIPT, PRINT A4 INVOICE, PRINT A4 RECEIPT, LIST
// =========================================================================
if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    $id = trim($_GET['id'] ?? ($_GET['invoice_id'] ?? ''));

    // 1. PRINT / DOWNLOAD INVOICE (A4 Professional Print View)
    if ($action === 'print_invoice' || $action === 'pdf_invoice') {
        if (empty($id)) {
            header('Content-Type: text/plain');
            http_response_code(400);
            echo "Invoice ID is required.";
            exit;
        }
        $stmt = $pdo->prepare("SELECT * FROM crm_cash_ledger WHERE id = ? OR invoice_no = ?");
        $stmt->execute([$id, $id]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inv) {
            header('Content-Type: text/plain');
            http_response_code(404);
            echo "Invoice not found.";
            exit;
        }
        $items = getInvoiceItems($pdo, $inv);
        renderPrintableInvoice($inv, $items);
        exit;
    }

    // 2. PRINT / DOWNLOAD PAYMENT RECEIPT (A4 Professional Print View)
    if ($action === 'print_receipt' || $action === 'pdf_receipt') {
        $recId = trim($_GET['receipt_no'] ?? ($_GET['id'] ?? ($_GET['payment_id'] ?? '')));
        if (empty($recId)) {
            header('Content-Type: text/plain');
            http_response_code(400);
            echo "Receipt No or Payment ID is required.";
            exit;
        }
        $stmt = $pdo->prepare("SELECT * FROM crm_payments WHERE id = ? OR receipt_no = ?");
        $stmt->execute([$recId, $recId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$payment) {
            header('Content-Type: text/plain');
            http_response_code(404);
            echo "Payment receipt not found.";
            exit;
        }
        $invStmt = $pdo->prepare("SELECT * FROM crm_cash_ledger WHERE id = ? OR invoice_no = ?");
        $invStmt->execute([$payment['invoice_id'], $payment['invoice_no']]);
        $inv = $invStmt->fetch(PDO::FETCH_ASSOC) ?: [
            'id' => $payment['invoice_id'],
            'invoice_no' => $payment['invoice_no'],
            'customer_name' => $payment['customer_name'],
            'amount' => $payment['amount'],
            'paid_amount' => $payment['amount'],
            'balance_amount' => 0,
            'status' => 'Paid'
        ];
        renderPrintableReceipt($payment, $inv);
        exit;
    }

    // 3. GET SINGLE PAYMENT RECEIPT DETAILS (JSON)
    if ($action === 'get_receipt') {
        $recId = trim($_GET['receipt_no'] ?? ($_GET['id'] ?? ($_GET['payment_id'] ?? '')));
        if (empty($recId)) {
            sendJson(['status' => 'error', 'message' => 'Receipt identifier required'], 400);
        }
        $stmt = $pdo->prepare("SELECT * FROM crm_payments WHERE id = ? OR receipt_no = ?");
        $stmt->execute([$recId, $recId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$payment) {
            sendJson(['status' => 'error', 'message' => 'Payment receipt not found'], 404);
        }
        $invStmt = $pdo->prepare("SELECT * FROM crm_cash_ledger WHERE id = ? OR invoice_no = ?");
        $invStmt->execute([$payment['invoice_id'], $payment['invoice_no']]);
        $invoice = $invStmt->fetch(PDO::FETCH_ASSOC);

        sendJson([
            'status' => 'success',
            'data' => [
                'payment' => $payment,
                'invoice' => $invoice
            ]
        ]);
    }

    // 4. GET SINGLE INVOICE DETAILS (JSON)
    if (!empty($id) || $action === 'get_invoice') {
        if (empty($id)) sendJson(['status' => 'error', 'message' => 'Invoice ID required'], 400);
        $stmt = $pdo->prepare("SELECT * FROM crm_cash_ledger WHERE id = ? OR invoice_no = ?");
        $stmt->execute([$id, $id]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inv) {
            sendJson(['status' => 'error', 'message' => 'Invoice not found'], 404);
        }

        $items = getInvoiceItems($pdo, $inv);
        $payStmt = $pdo->prepare("SELECT * FROM crm_payments WHERE invoice_id = ? OR invoice_no = ? ORDER BY created_at DESC");
        $payStmt->execute([$inv['id'], $inv['invoice_no']]);
        $payments = $payStmt->fetchAll(PDO::FETCH_ASSOC);

        $inv['items'] = $items;
        $inv['payments'] = $payments;

        sendJson([
            'status' => 'success',
            'data' => $inv
        ]);
    }

    // 5. LIST ALL INVOICES (WITH FINANCIAL SUMMARY)
    $statusFilter = isset($_GET['status']) ? trim(strtolower($_GET['status'])) : 'all';

    if ($pdo) {
        try {
            $sql = "SELECT * FROM crm_cash_ledger WHERE 1=1";
            $params = [];

            if ($statusFilter !== 'all' && !empty($statusFilter)) {
                $sql .= " AND LOWER(status) = :st";
                $params[':st'] = $statusFilter;
            }

            $sql .= " ORDER BY transaction_date DESC LIMIT 200";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Populate items array for each record
            foreach ($records as &$r) {
                $r['items'] = getInvoiceItems($pdo, $r);
            }
            unset($r);

            // Calculate live totals across all valid invoices
            $allStmt = $pdo->query("SELECT * FROM crm_cash_ledger");
            $allInvoices = $allStmt->fetchAll(PDO::FETCH_ASSOC);

            $totInvoiced = 0.0;
            $totPaid = 0.0;
            $totPending = 0.0;
            $totOverdue = 0.0;

            $today = date('Y-m-d');

            foreach ($allInvoices as $invRow) {
                $amt = (float)$invRow['amount'];
                $paid = (float)($invRow['paid_amount'] ?? 0);
                $st = strtolower(trim($invRow['status'] ?? 'pending'));

                // If marked Paid but paid_amount is 0/null, assume full amount is paid
                if ($st === 'paid' && $paid <= 0) {
                    $paid = $amt;
                }
                $balance = max(0, $amt - $paid);

                $totInvoiced += $amt;
                $totPaid += $paid;

                // Check overdue status by due date or status string
                $isOverdue = ($st === 'overdue');
                if (!$isOverdue && $balance > 0 && !empty($invRow['due_date'])) {
                    $dueTs = strtotime($invRow['due_date']);
                    if ($dueTs && $dueTs < strtotime($today)) {
                        $isOverdue = true;
                    }
                }

                if ($isOverdue && $balance > 0) {
                    $totOverdue += $balance;
                } elseif ($balance > 0) {
                    $totPending += $balance;
                }
            }

            sendJson([
                'status' => 'success',
                'summary' => [
                    'total_invoiced' => round($totInvoiced, 2),
                    'total_revenue' => round($totPaid, 2),
                    'total_paid' => round($totPaid, 2),
                    'total_pending' => round($totPending, 2),
                    'total_overdue' => round($totOverdue, 2)
                ],
                'data' => $records
            ]);
        } catch (Exception $e) {
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    } else {
        sendJson(['status' => 'error', 'message' => 'Database not connected'], 500);
    }
}

// =========================================================================
// POST: RECORD PAYMENT, CREATE INVOICE, UPDATE INVOICE, DELETE INVOICE
// =========================================================================
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: $_POST;
    $action = $input['action'] ?? ($_GET['action'] ?? 'create');

    // ---------------------------------------------------------------------
    // 1. RECORD PAYMENT & GENERATE RECEIPT
    // ---------------------------------------------------------------------
    if ($action === 'record_payment') {
        $invId = trim($input['invoice_id'] ?? '');
        $amount = (float)($input['amount'] ?? 0);

        if (empty($invId) || $amount <= 0) {
            sendJson(['status' => 'error', 'message' => 'Invoice ID and valid positive amount are required.'], 400);
        }

        if (!$pdo) {
            sendJson(['status' => 'error', 'message' => 'Database unavailable'], 500);
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM crm_cash_ledger WHERE id = ? OR invoice_no = ?");
            $stmt->execute([$invId, $invId]);
            $inv = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$inv) {
                sendJson(['status' => 'error', 'message' => 'Invoice record not found.'], 404);
            }

            $curPaid = (float)($inv['paid_amount'] ?? 0);
            $totalAmt = (float)$inv['amount'];
            $curBalance = (float)($inv['balance_amount'] ?? max(0, $totalAmt - $curPaid));

            if ($amount > ($curBalance + 0.05)) {
                sendJson([
                    'status' => 'error',
                    'message' => 'Payment amount cannot exceed the remaining balance of ' . number_format($curBalance, 2)
                ], 400);
            }

            $newPaid = round($curPaid + $amount, 2);
            $newBalance = max(0, round($totalAmt - $newPaid, 2));
            $newStatus = ($newBalance <= 0.01) ? 'Paid' : 'Pending';
            $displayStatus = ($newBalance <= 0.01) ? 'Paid' : (($newPaid > 0) ? 'Partially Paid' : 'Pending');

            // Atomic transaction
            $pdo->beginTransaction();

            $upd = $pdo->prepare("UPDATE crm_cash_ledger SET paid_amount = ?, balance_amount = ?, status = ? WHERE id = ?");
            $upd->execute([$newPaid, $newBalance, $newStatus, $inv['id']]);

            // Generate receipt number (Screen 3: REC-2026-004)
            $receiptNo = generateReceiptNumber($pdo);
            $payId = 'pay_' . time() . '_' . rand(100, 999);
            $payDate = trim($input['payment_date'] ?? date('d M Y'));
            $payMethod = trim($input['payment_method'] ?? 'Online Transfer');
            $txnId = trim($input['transaction_id'] ?? ('TXN' . strtoupper(substr(md5(uniqid()), 0, 10))));
            $reference = trim($input['reference'] ?? ("Payment for Invoice " . ($inv['invoice_no'] ?: $inv['id'])));
            $notes = trim($input['notes'] ?? '');

            $curUser = SessionGuard::getUser();
            $recordedBy = $curUser['name'] ?? 'Admin';

            $insPay = $pdo->prepare("
                INSERT INTO crm_payments 
                (id, receipt_no, invoice_id, invoice_no, customer_name, amount, payment_date, payment_method, transaction_id, reference, notes, recorded_by, status, created_at)
                VALUES 
                (:id, :receipt_no, :invoice_id, :invoice_no, :customer_name, :amount, :payment_date, :payment_method, :transaction_id, :reference, :notes, :recorded_by, 'Paid', NOW())
            ");
            $insPay->execute([
                ':id' => $payId,
                ':receipt_no' => $receiptNo,
                ':invoice_id' => $inv['id'],
                ':invoice_no' => $inv['invoice_no'],
                ':customer_name' => $inv['customer_name'],
                ':amount' => $amount,
                ':payment_date' => $payDate,
                ':payment_method' => $payMethod,
                ':transaction_id' => $txnId,
                ':reference' => $reference,
                ':notes' => $notes,
                ':recorded_by' => $recordedBy
            ]);

            // Also log payment to contact history if contact exists in crm_contacts
            if (!empty($inv['client_id'])) {
                try {
                    $chkContact = $pdo->prepare("SELECT id FROM crm_contacts WHERE id = ?");
                    $chkContact->execute([$inv['client_id']]);
                    if ($chkContact->fetchColumn()) {
                        $histStmt = $pdo->prepare("
                            INSERT INTO crm_history (contact_id, staff, time_str, note_text, created_at)
                            VALUES (:cid, :staff, 'Just now', :note, NOW())
                        ");
                        $histStmt->execute([
                            ':cid' => $inv['client_id'],
                            ':staff' => $recordedBy,
                            ':note' => "Recorded payment of " . number_format($amount, 2) . " for Invoice " . ($inv['invoice_no'] ?: $inv['id']) . " via $payMethod (Receipt: $receiptNo)"
                        ]);
                    }
                } catch (Exception $e) {}
            }

            $pdo->commit();

            $paymentData = [
                'id' => $payId,
                'receipt_no' => $receiptNo,
                'invoice_id' => $inv['id'],
                'invoice_no' => $inv['invoice_no'],
                'customer_name' => $inv['customer_name'],
                'amount' => $amount,
                'payment_date' => $payDate,
                'payment_method' => $payMethod,
                'transaction_id' => $txnId,
                'reference' => $reference,
                'notes' => $notes,
                'invoice_total' => $totalAmt,
                'total_paid' => $newPaid,
                'balance_amount' => $newBalance,
                'status' => $displayStatus,
                'created_at' => date('Y-m-d H:i:s')
            ];

            // Trigger notification for payment received
            try {
                if (file_exists(__DIR__ . '/notification_service.php')) {
                    require_once __DIR__ . '/notification_service.php';
                    NotificationService::create($pdo, [
                        'category' => 'invoices',
                        'event_type' => 'invoice_paid',
                        'title' => 'Payment Received: ' . ($inv['invoice_no'] ?: $invId),
                        'message' => 'Payment of ₹' . number_format($amount, 2) . ' recorded for ' . ($inv['customer_name'] ?? 'Client') . '. Status: ' . $displayStatus,
                        'link_type' => 'invoice',
                        'link_id' => (string)($inv['id'] ?? $invId),
                        'action_label' => 'View Invoice',
                        'metadata' => [
                            'invoice_id' => $inv['id'] ?? $invId,
                            'invoice_no' => $inv['invoice_no'] ?? '',
                            'amount_paid' => $amount,
                            'receipt_no' => $receiptNo
                        ]
                    ]);
                }
            } catch (Exception $e) {
                error_log('Notification error on payment: ' . $e->getMessage());
            }

            sendJson([
                'status' => 'success',
                'success' => true,
                'message' => 'Payment recorded successfully',
                'receipt_no' => $receiptNo,
                'payment' => $paymentData,
                'data' => [
                    'invoice_id' => $inv['id'],
                    'invoice_no' => $inv['invoice_no'],
                    'amount_paid' => $amount,
                    'total_paid' => $newPaid,
                    'balance_amount' => $newBalance,
                    'status' => $displayStatus,
                    'db_status' => $newStatus,
                    'receipt_no' => $receiptNo,
                    'payment' => $paymentData
                ]
            ]);
        } catch (Exception $e) {
            if ($pdo && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // ---------------------------------------------------------------------
    // 2. CREATE INVOICE (ITEMIZED & CALCULATED ON SERVER)
    // ---------------------------------------------------------------------
    if ($action === 'create' || $action === 'create_invoice') {
        $customerName = trim($input['customer_name'] ?? ($input['client_name'] ?? ''));
        $title = trim($input['title'] ?? 'Software Development & Consulting Services');

        if (empty($customerName)) {
            sendJson(['status' => 'error', 'message' => 'Client / Customer name is required'], 400);
        }

        $items = $input['items'] ?? [];
        $subtotal = 0.0;
        $sanitizedItems = [];

        if (is_array($items) && !empty($items)) {
            foreach ($items as $idx => $it) {
                $desc = trim($it['description'] ?? '');
                if (empty($desc)) continue;
                $qty = max(0.01, (float)($it['quantity'] ?? 1));
                $rate = max(0, (float)($it['rate'] ?? 0));
                $lineAmt = round($qty * $rate, 2);
                $subtotal += $lineAmt;
                $sanitizedItems[] = [
                    'description' => $desc,
                    'quantity' => $qty,
                    'rate' => $rate,
                    'amount' => $lineAmt,
                    'sort_order' => $idx + 1
                ];
            }
        }

        // If no line items, fallback to single amount
        if (empty($sanitizedItems)) {
            $rawAmt = (float)($input['amount'] ?? 0);
            if ($rawAmt <= 0) {
                sendJson(['status' => 'error', 'message' => 'Please provide at least one line item or a valid invoice amount'], 400);
            }
            $subtotal = $rawAmt;
            $sanitizedItems[] = [
                'description' => $title,
                'quantity' => 1,
                'rate' => $rawAmt,
                'amount' => $rawAmt,
                'sort_order' => 1
            ];
        }

        // Financial calculations
        $discountPercent = max(0, min(100, (float)($input['discount_percent'] ?? 0)));
        $discountAmount = round($subtotal * ($discountPercent / 100), 2);
        $taxable = max(0, $subtotal - $discountAmount);

        $taxPercent = max(0, min(100, (float)($input['tax_percent'] ?? 0)));
        $taxAmount = round($taxable * ($taxPercent / 100), 2);

        $finalAmount = round($taxable + $taxAmount, 2);

        $status = ucfirst(strtolower(trim($input['status'] ?? 'Pending')));
        if (!in_array($status, ['Paid', 'Pending', 'Partially Paid', 'Overdue', 'Draft'])) {
            $status = 'Pending';
        }

        $paidAmount = ($status === 'Paid') ? $finalAmount : (float)($input['paid_amount'] ?? 0);
        $balanceAmount = max(0, round($finalAmount - $paidAmount, 2));

        $id = 'inv_' . time() . '_' . rand(100, 999);
        $invNo = trim($input['invoice_no'] ?? '') ?: generateInvoiceNumber($pdo);
        $issueDate = trim($input['issue_date'] ?? date('d M Y'));
        $dueDate = trim($input['due_date'] ?? date('d M Y', strtotime('+14 days')));
        $notes = trim($input['notes'] ?? "• Complete website development as per scope\n• Source code and documentation will be provided\n• 3 months of post-launch support");
        $terms = trim($input['terms'] ?? "50% advance, 50% on project completion.");
        $clientId = trim($input['client_id'] ?? '');
        $projectId = trim($input['project_id'] ?? '');

        try {
            $pdo->beginTransaction();

            $ins = $pdo->prepare("
                INSERT INTO crm_cash_ledger 
                (id, invoice_no, type, amount, title, category, payment_mode, handled_by, customer_name, customer_phone, status, due_date, domain, transaction_date, client_id, project_id, issue_date, subtotal, discount_percent, discount_amount, tax_percent, tax_amount, paid_amount, balance_amount, notes, terms, items_json)
                VALUES 
                (:id, :invoice_no, 'income', :amount, :title, :category, :payment_mode, :handled_by, :customer_name, :customer_phone, :status, :due_date, 'software.local', NOW(), :client_id, :project_id, :issue_date, :subtotal, :discount_percent, :discount_amount, :tax_percent, :tax_amount, :paid_amount, :balance_amount, :notes, :terms, :items_json)
            ");
            $ins->execute([
                ':id' => $id,
                ':invoice_no' => $invNo,
                ':amount' => $finalAmount,
                ':title' => $title,
                ':category' => trim($input['category'] ?? 'Development'),
                ':payment_mode' => trim($input['payment_mode'] ?? 'Online Transfer'),
                ':handled_by' => 'Admin',
                ':customer_name' => $customerName,
                ':customer_phone' => trim($input['customer_phone'] ?? ''),
                ':status' => $status,
                ':due_date' => $dueDate,
                ':client_id' => $clientId,
                ':project_id' => $projectId,
                ':issue_date' => $issueDate,
                ':subtotal' => $subtotal,
                ':discount_percent' => $discountPercent,
                ':discount_amount' => $discountAmount,
                ':tax_percent' => $taxPercent,
                ':tax_amount' => $taxAmount,
                ':paid_amount' => $paidAmount,
                ':balance_amount' => $balanceAmount,
                ':notes' => $notes,
                ':terms' => $terms,
                ':items_json' => json_encode($sanitizedItems, JSON_UNESCAPED_UNICODE)
            ]);

            // Insert into normalized crm_invoice_items
            $insItem = $pdo->prepare("
                INSERT INTO crm_invoice_items 
                (invoice_id, description, quantity, rate, amount, sort_order, created_at)
                VALUES 
                (:invoice_id, :description, :quantity, :rate, :amount, :sort_order, NOW())
            ");
            foreach ($sanitizedItems as $si) {
                $insItem->execute([
                    ':invoice_id' => $id,
                    ':description' => $si['description'],
                    ':quantity' => $si['quantity'],
                    ':rate' => $si['rate'],
                    ':amount' => $si['amount'],
                    ':sort_order' => $si['sort_order']
                ]);
            }

            $pdo->commit();

            // Trigger notification for new invoice created
            try {
                if (file_exists(__DIR__ . '/notification_service.php')) {
                    require_once __DIR__ . '/notification_service.php';
                    NotificationService::create($pdo, [
                        'category' => 'invoices',
                        'event_type' => 'invoice_created',
                        'title' => 'New Invoice ' . $invNo,
                        'message' => 'Invoice ' . $invNo . ' generated for ' . $customerName . ' (₹' . number_format($finalAmount, 2) . '). Due ' . $dueDate . '.',
                        'link_type' => 'invoice',
                        'link_id' => (string)$id,
                        'action_label' => 'View Invoice',
                        'metadata' => [
                            'invoice_id' => $id,
                            'invoice_no' => $invNo,
                            'customer_name' => $customerName,
                            'amount' => $finalAmount,
                            'due_date' => $dueDate
                        ]
                    ]);
                }
            } catch (Exception $e) {
                error_log('Notification error on invoice create: ' . $e->getMessage());
            }

            sendJson([
                'status' => 'success',
                'success' => true,
                'message' => 'Invoice created successfully',
                'id' => $id,
                'invoice_no' => $invNo,
                'data' => [
                    'id' => $id,
                    'invoice_no' => $invNo,
                    'customer_name' => $customerName,
                    'amount' => $finalAmount,
                    'subtotal' => $subtotal,
                    'discount_amount' => $discountAmount,
                    'tax_amount' => $taxAmount,
                    'paid_amount' => $paidAmount,
                    'balance_amount' => $balanceAmount,
                    'status' => $status,
                    'issue_date' => $issueDate,
                    'due_date' => $dueDate,
                    'items' => $sanitizedItems
                ]
            ]);
        } catch (Exception $e) {
            if ($pdo && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // ---------------------------------------------------------------------
    // 3. DELETE INVOICE
    // ---------------------------------------------------------------------
    if ($action === 'delete' || $action === 'delete_invoice') {
        $id = trim($input['id'] ?? ($_GET['id'] ?? ''));
        if (empty($id)) sendJson(['status' => 'error', 'message' => 'Invoice ID required'], 400);

        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM crm_invoice_items WHERE invoice_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM crm_payments WHERE invoice_id = ?")->execute([$id]);
            $del = $pdo->prepare("DELETE FROM crm_cash_ledger WHERE id = ?");
            $del->execute([$id]);
            $pdo->commit();

            sendJson(['status' => 'success', 'message' => 'Invoice deleted successfully']);
        } catch (Exception $e) {
            if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    sendJson(['status' => 'error', 'message' => 'Unknown action'], 400);
}

// =========================================================================
// HTML TEMPLATE RENDERERS: A4 PRINTABLE INVOICE & PAYMENT RECEIPT
// =========================================================================

function renderPrintableInvoice(array $inv, array $items) {
    $invNo = htmlspecialchars($inv['invoice_no'] ?: $inv['id']);
    $client = htmlspecialchars($inv['customer_name'] ?: 'Valued Client');
    $issueDate = htmlspecialchars($inv['issue_date'] ?: date('d M Y'));
    $dueDate = htmlspecialchars($inv['due_date'] ?: 'Due on receipt');
    $status = htmlspecialchars($inv['status'] ?: 'Pending');
    $subtotal = (float)($inv['subtotal'] ?: $inv['amount']);
    $discPercent = (float)($inv['discount_percent'] ?? 0);
    $discAmount = (float)($inv['discount_amount'] ?? 0);
    $taxPercent = (float)($inv['tax_percent'] ?? 0);
    $taxAmount = (float)($inv['tax_amount'] ?? 0);
    $total = (float)$inv['amount'];
    $paid = (float)($inv['paid_amount'] ?? ($status === 'Paid' ? $total : 0));
    $balance = (float)($inv['balance_amount'] ?? max(0, $total - $paid));
    $notes = nl2br(htmlspecialchars($inv['notes'] ?? "• Complete website development as per scope\n• Source code and documentation will be provided\n• 3 months of post-launch support"));
    $terms = nl2br(htmlspecialchars($inv['terms'] ?? "50% advance, 50% on project completion. Payment due within 14 days."));

    $statusColor = ($status === 'Paid') ? '#10b981' : (($status === 'Overdue') ? '#ef4444' : '#f59e0b');
    $statusBg = ($status === 'Paid') ? '#ecfdf5' : (($status === 'Overdue') ? '#fef2f2' : '#fffbeb');

    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Invoice - <?= $invNo ?></title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
    body { background: #f8fafc; color: #1e293b; padding: 24px; line-height: 1.5; }
    .invoice-card { max-width: 800px; margin: 0 auto; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 36px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
    .header-bar { display: flex; justify-content: space-between; align-items: flex-start; padding-bottom: 24px; border-bottom: 2px solid #f1f5f9; }
    .company-brand h1 { font-size: 22px; font-weight: 900; color: #2563eb; }
    .company-brand p { font-size: 12px; color: #64748b; margin-top: 2px; }
    .invoice-title-block { text-align: right; }
    .invoice-title-block h2 { font-size: 20px; font-weight: 900; color: #0f172a; }
    .status-badge { display: inline-block; padding: 4px 12px; border-radius: 9999px; font-size: 11px; font-weight: 800; margin-top: 6px; }
    .meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 24px 0; padding: 16px; background: #f8fafc; border-radius: 12px; font-size: 13px; }
    .meta-item strong { display: block; font-size: 11px; text-transform: uppercase; color: #64748b; margin-bottom: 2px; }
    table { width: 100%; border-collapse: collapse; margin: 24px 0; font-size: 13px; }
    th { text-align: left; padding: 10px 12px; background: #f1f5f9; color: #475569; font-size: 11px; text-transform: uppercase; font-weight: 700; border-radius: 6px; }
    td { padding: 12px; border-bottom: 1px solid #f1f5f9; }
    .text-right { text-align: right; }
    .text-center { text-align: center; }
    .summary-section { display: flex; justify-content: flex-end; margin-top: 16px; }
    .summary-table { width: 320px; font-size: 13px; }
    .summary-row { display: flex; justify-content: space-between; padding: 6px 0; color: #475569; }
    .summary-row.total { font-size: 16px; font-weight: 900; color: #0f172a; border-top: 2px solid #e2e8f0; padding-top: 10px; margin-top: 6px; }
    .notes-box { margin-top: 32px; padding: 16px; background: #f8fafc; border-left: 4px solid #2563eb; border-radius: 0 8px 8px 0; font-size: 12px; color: #475569; }
    .print-actions { max-width: 800px; margin: 16px auto; display: flex; gap: 12px; justify-content: flex-end; }
    .btn { padding: 10px 20px; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
    .btn-primary { background: #2563eb; color: #fff; border: none; }
    .btn-secondary { background: #fff; color: #475569; border: 1px solid #cbd5e1; }
    @media print {
      body { background: #fff; padding: 0; }
      .invoice-card { box-shadow: none; border: none; padding: 0; }
      .print-actions { display: none; }
    }
  </style>
</head>
<body>
  <div class="print-actions">
    <button onclick="window.print()" class="btn btn-primary">🖨️ Print / Save as PDF</button>
    <button onclick="window.close()" class="btn btn-secondary">Close</button>
  </div>

  <div class="invoice-card">
    <div class="header-bar">
      <div class="company-brand">
        <h1>Software CRM Inc</h1>
        <p>Software Engineering & Cloud Architecture</p>
        <p>support@softwarecrm.com • www.softwarecrm.com</p>
      </div>
      <div class="invoice-title-block">
        <h2>INVOICE</h2>
        <div style="font-family: monospace; font-size: 13px; color: #64748b;"><?= $invNo ?></div>
        <div class="status-badge" style="background: <?= $statusBg ?>; color: <?= $statusColor ?>; border: 1px solid <?= $statusColor ?>40;">
          <?= $status ?>
        </div>
      </div>
    </div>

    <div class="meta-grid">
      <div>
        <div class="meta-item">
          <strong>Billed To:</strong>
          <span style="font-weight: 800; font-size: 14px;"><?= $client ?></span>
        </div>
      </div>
      <div style="text-align: right;">
        <div class="meta-item">
          <strong>Issue Date:</strong>
          <span><?= $issueDate ?></span>
        </div>
        <div class="meta-item" style="margin-top: 6px;">
          <strong>Due Date:</strong>
          <span><?= $dueDate ?></span>
        </div>
      </div>
    </div>

    <table>
      <thead>
        <tr>
          <th>Description</th>
          <th class="text-center" style="width: 80px;">Qty</th>
          <th class="text-right" style="width: 120px;">Rate (₹)</th>
          <th class="text-right" style="width: 140px;">Amount (₹)</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $item): ?>
          <tr>
            <td style="font-weight: 600; color: #1e293b;"><?= htmlspecialchars($item['description'] ?? '') ?></td>
            <td class="text-center"><?= (float)($item['quantity'] ?? 1) ?></td>
            <td class="text-right"><?= number_format((float)($item['rate'] ?? 0), 2) ?></td>
            <td class="text-right" style="font-weight: 700;"><?= number_format((float)($item['amount'] ?? 0), 2) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="summary-section">
      <div class="summary-table">
        <div class="summary-row">
          <span>Subtotal</span>
          <span>₹<?= number_format($subtotal, 2) ?></span>
        </div>
        <?php if ($discPercent > 0 || $discAmount > 0): ?>
          <div class="summary-row" style="color: #dc2626;">
            <span>Discount (<?= $discPercent ?>%)</span>
            <span>- ₹<?= number_format($discAmount, 2) ?></span>
          </div>
        <?php endif; ?>
        <?php if ($taxPercent > 0 || $taxAmount > 0): ?>
          <div class="summary-row">
            <span>GST / Tax (<?= $taxPercent ?>%)</span>
            <span>₹<?= number_format($taxAmount, 2) ?></span>
          </div>
        <?php endif; ?>
        <div class="summary-row total">
          <span>Total Amount</span>
          <span>₹<?= number_format($total, 2) ?></span>
        </div>
        <div class="summary-row" style="color: #10b981; font-weight: 700; margin-top: 4px;">
          <span>Total Paid</span>
          <span>₹<?= number_format($paid, 2) ?></span>
        </div>
        <div class="summary-row" style="color: <?= $balance > 0 ? '#dc2626' : '#10b981' ?>; font-weight: 800;">
          <span>Remaining Balance</span>
          <span>₹<?= number_format($balance, 2) ?></span>
        </div>
      </div>
    </div>

    <?php if (!empty($inv['notes'])): ?>
      <div class="notes-box">
        <strong style="color: #0f172a; display: block; margin-bottom: 4px;">Notes / Deliverables:</strong>
        <div><?= $notes ?></div>
      </div>
    <?php endif; ?>

    <?php if (!empty($inv['terms'])): ?>
      <div class="notes-box" style="margin-top: 12px; border-left-color: #64748b;">
        <strong style="color: #0f172a; display: block; margin-bottom: 4px;">Payment Terms:</strong>
        <div><?= $terms ?></div>
      </div>
    <?php endif; ?>

    <div style="margin-top: 40px; text-align: center; font-size: 11px; color: #94a3b8; border-top: 1px solid #f1f5f9; padding-top: 16px;">
      Thank you for your business! If you have any questions regarding this invoice, please reach out to billing@softwarecrm.com.
    </div>
  </div>
</body>
</html>
    <?php
}

function renderPrintableReceipt(array $payment, array $inv) {
    $recNo = htmlspecialchars($payment['receipt_no'] ?: $payment['id']);
    $invNo = htmlspecialchars($payment['invoice_no'] ?: ($inv['invoice_no'] ?? 'INV'));
    $client = htmlspecialchars($payment['customer_name'] ?: ($inv['customer_name'] ?? 'Client'));
    $payDate = htmlspecialchars($payment['payment_date'] ?: date('d M Y'));
    $payMethod = htmlspecialchars($payment['payment_method'] ?: 'Online Transfer');
    $txnId = htmlspecialchars($payment['transaction_id'] ?: 'N/A');
    $reference = htmlspecialchars($payment['reference'] ?: "Payment for Invoice $invNo");
    $paidAmount = (float)$payment['amount'];
    $invTotal = (float)($inv['amount'] ?? $paidAmount);
    $totalPaid = (float)($inv['paid_amount'] ?? $paidAmount);
    $balance = (float)($inv['balance_amount'] ?? max(0, $invTotal - $totalPaid));
    $notes = htmlspecialchars($payment['notes'] ?? '');

    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Payment Receipt - <?= $recNo ?></title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
    body { background: #f8fafc; color: #1e293b; padding: 24px; line-height: 1.5; }
    .receipt-card { max-width: 600px; margin: 0 auto; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 36px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
    .success-header { text-align: center; padding-bottom: 24px; border-bottom: 2px dashed #e2e8f0; }
    .check-icon { width: 56px; height: 56px; background: #ecfdf5; border: 2px solid #10b981; color: #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 26px; margin: 0 auto 12px; }
    .success-header h1 { font-size: 20px; font-weight: 900; color: #0f172a; }
    .success-header p { font-size: 13px; color: #64748b; margin-top: 4px; }
    .rec-badge-bar { display: flex; justify-content: space-between; align-items: center; margin: 20px 0; padding: 12px 16px; background: #f8fafc; border-radius: 10px; font-size: 12px; }
    .info-list { margin: 20px 0; font-size: 13px; }
    .info-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f1f5f9; }
    .info-label { color: #64748b; font-weight: 500; }
    .info-val { font-weight: 700; color: #0f172a; text-align: right; }
    .amount-highlight { margin: 20px 0; padding: 16px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 12px; text-align: center; }
    .amount-highlight .lbl { font-size: 11px; text-transform: uppercase; color: #1d4ed8; font-weight: 700; }
    .amount-highlight .val { font-size: 28px; font-weight: 900; color: #1e3a8a; margin-top: 4px; }
    .balance-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; margin-top: 16px; font-size: 13px; }
    .print-actions { max-width: 600px; margin: 16px auto; display: flex; gap: 12px; justify-content: flex-end; }
    .btn { padding: 10px 20px; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
    .btn-primary { background: #2563eb; color: #fff; border: none; }
    .btn-secondary { background: #fff; color: #475569; border: 1px solid #cbd5e1; }
    @media print {
      body { background: #fff; padding: 0; }
      .receipt-card { box-shadow: none; border: none; padding: 0; }
      .print-actions { display: none; }
    }
  </style>
</head>
<body>
  <div class="print-actions">
    <button onclick="window.print()" class="btn btn-primary">🖨️ Print / Save Receipt</button>
    <button onclick="window.close()" class="btn btn-secondary">Close</button>
  </div>

  <div class="receipt-card">
    <div class="success-header">
      <div class="check-icon">✓</div>
      <h1>Payment Successful!</h1>
      <p>The payment has been recorded successfully into the system.</p>
    </div>

    <div class="rec-badge-bar">
      <div>
        <span style="color: #64748b;">Receipt No:</span>
        <strong style="color: #0f172a; font-family: monospace;"><?= $recNo ?></strong>
      </div>
      <div>
        <span style="color: #64748b;"><?= $payDate ?></span>
      </div>
    </div>

    <div class="amount-highlight">
      <div class="lbl">Amount Paid</div>
      <div class="val">₹<?= number_format($paidAmount, 2) ?></div>
    </div>

    <div class="info-list">
      <div class="info-row">
        <span class="info-label">Invoice Number</span>
        <span class="info-val" style="color: #2563eb; font-family: monospace;"><?= $invNo ?></span>
      </div>
      <div class="info-row">
        <span class="info-label">Client / Company</span>
        <span class="info-val"><?= $client ?></span>
      </div>
      <div class="info-row">
        <span class="info-label">Payment Date</span>
        <span class="info-val"><?= $payDate ?></span>
      </div>
      <div class="info-row">
        <span class="info-label">Payment Method</span>
        <span class="info-val"><?= $payMethod ?></span>
      </div>
      <div class="info-row">
        <span class="info-label">Transaction ID</span>
        <span class="info-val" style="font-family: monospace;"><?= $txnId ?></span>
      </div>
      <?php if (!empty($reference)): ?>
        <div class="info-row">
          <span class="info-label">Reference</span>
          <span class="info-val"><?= $reference ?></span>
        </div>
      <?php endif; ?>
    </div>

    <div class="balance-box">
      <div class="info-row">
        <span class="info-label">Invoice Total</span>
        <span class="info-val">₹<?= number_format($invTotal, 2) ?></span>
      </div>
      <div class="info-row">
        <span class="info-label">Total Paid to Date</span>
        <span class="info-val" style="color: #10b981;">₹<?= number_format($totalPaid, 2) ?></span>
      </div>
      <div class="info-row" style="border-bottom: none; font-size: 14px; font-weight: 800; padding-top: 10px;">
        <span class="info-label" style="color: #0f172a; font-weight: 800;">Remaining Balance</span>
        <span class="info-val" style="color: <?= $balance <= 0 ? '#10b981' : '#dc2626' ?>;">
          ₹<?= number_format($balance, 2) ?>
        </span>
      </div>
    </div>

    <?php if (!empty($notes)): ?>
      <div style="margin-top: 16px; padding: 12px; background: #f8fafc; border-radius: 8px; font-size: 12px; color: #64748b;">
        <strong>Notes:</strong> <?= $notes ?>
      </div>
    <?php endif; ?>

    <div style="margin-top: 32px; text-align: center; font-size: 11px; color: #94a3b8; border-top: 1px solid #f1f5f9; padding-top: 16px;">
      This is an electronically generated payment receipt from Software CRM Inc.
    </div>
  </div>
</body>
</html>
    <?php
}
