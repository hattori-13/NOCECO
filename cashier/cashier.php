<?php
session_start();
require_once '../db/dbcon.php';

// ---------------------------------------------------------------------
// 1. SET MANILA TIMEZONE & WEEKEND LOGIC
// ---------------------------------------------------------------------
date_default_timezone_set('Asia/Manila');

// Helper function: If due date is weekend, push effective due date to Monday
function getEffectiveDueDate($dateStr) {
    $ts = strtotime($dateStr);
    $dow = date('N', $ts); // 1 = Mon, 6 = Sat, 7 = Sun
    if ($dow == 6) {
        return strtotime('+2 days', $ts); // Shift Saturday to Monday
    } elseif ($dow == 7) {
        return strtotime('+1 day', $ts);  // Shift Sunday to Monday
    }
    return $ts; // Weekday remains the same
}

// ---------------------------------------------------------------------
// SECURITY: ROLE VERIFICATION
// ---------------------------------------------------------------------
if (!isset($_SESSION['staff_id']) || $_SESSION['role'] !== 'Cashier') {
    header("Location: ../administrator.php");
    exit();
}

// Fetch session messages
$message = $_SESSION['msg'] ?? '';
$messageType = $_SESSION['msg_type'] ?? '';
unset($_SESSION['msg'], $_SESSION['msg_type']);

// Fetch receipt data if a payment was just processed
$receiptData = $_SESSION['receipt_data'] ?? null;
unset($_SESSION['receipt_data']);

// Fetch Global Standard Rates for the breakdown
$stmtRates = $pdo->query("SELECT charge_description, charge_type, current_rate FROM billing_rates_catalog WHERE status = 'Active'");
$standardRates = $stmtRates->fetchAll(PDO::FETCH_ASSOC);

// ---------------------------------------------------------------------
// ACTION: PROCESS PAYMENT & GENERATE LETTER-SIZE RECEIPT
// ---------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'process_payment') {
    $invoice_nos = $_POST['invoice_nos'] ?? [];
    $base_amounts = $_POST['base_amounts'] ?? [];
    $penalty_amounts = $_POST['penalty_amounts'] ?? [];
    $account_no = $_POST['account_no'];
    $contact_number = $_POST['contact_number'];
    
    $grand_total_paid = 0;
    $processed_items = [];

    try {
        $pdo->beginTransaction();

        $stmtClient = $pdo->prepare("SELECT first_name, last_name, address, meter_no FROM clients WHERE account_no = ?");
        $stmtClient->execute([$account_no]);
        $client = $stmtClient->fetch();
        $consumer_name = $client['last_name'] . ', ' . $client['first_name'];
        
        for ($i = 0; $i < count($invoice_nos); $i++) {
            $inv = $invoice_nos[$i];
            $pen = (float)$penalty_amounts[$i];
            $base = (float)$base_amounts[$i];
            $amt = $base + $pen;

            $stmtInv = $pdo->prepare("SELECT billing_month, due_date, kwh_used, amount_due FROM billing_invoices WHERE invoice_no = ? AND status = 'Unpaid'");
            $stmtInv->execute([$inv]);
            $invRow = $stmtInv->fetch();

            if ($invRow) {
                // Update Invoice
                $stmtUpdate = $pdo->prepare("UPDATE billing_invoices SET status = 'Paid', penalty_surcharge = ? WHERE invoice_no = ?");
                $stmtUpdate->execute([$pen, $inv]);

                // Record Payment
                $payment_id = 'CASH-' . strtoupper(uniqid());
                $stmtPayment = $pdo->prepare("INSERT INTO payments (payment_id, invoice_no, account_no, amount_paid, payment_method, status, payment_date) VALUES (?, ?, ?, ?, 'Over-the-Counter', 'Success', NOW())");
                $stmtPayment->execute([$payment_id, $inv, $account_no, $amt]);
                
                $grand_total_paid += $amt;
                
                $processed_items[] = [
                    'invoice_no' => $inv,
                    'billing_month' => $invRow['billing_month'],
                    'due_date' => $invRow['due_date'],
                    'kwh_used' => $invRow['kwh_used'],
                    'amount_due' => $invRow['amount_due'],
                    'base' => $base,
                    'penalty' => $pen,
                    'total' => $amt
                ];
            }
        }

        if (count($processed_items) > 0) {
            $joined_invoices = implode(', ', array_column($processed_items, 'invoice_no'));
            $smsMessage = "NOCECO: We received your payment of Php " . number_format($grand_total_paid, 2) . " for Invoice(s): $joined_invoices. Thank you!";
            $stmtSMS = $pdo->prepare("INSERT INTO sms_logs (account_no, contact_number, message_type, message_content) VALUES (?, ?, 'Payment Success', ?)");
            $stmtSMS->execute([$account_no, $contact_number, $smsMessage]);

            $pdo->commit();

            $_SESSION['receipt_data'] = [
                'account_no' => $account_no,
                'consumer_name' => $consumer_name,
                'address' => $client['address'],
                'meter_no' => $client['meter_no'],
                'items' => $processed_items
            ];

            $_SESSION['msg'] = "Payment Successful! Generating Official Statement Receipt...";
            $_SESSION['msg_type'] = "success";
        } else {
            $pdo->rollBack();
            $_SESSION['msg'] = "Invoices already paid or not found.";
            $_SESSION['msg_type'] = "error";
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['msg'] = "Error: " . $e->getMessage();
        $_SESSION['msg_type'] = "error";
    }

    header("Location: cashier.php");
    exit();
}

// ---------------------------------------------------------------------
// ACTION: VOID PAYMENT
// ---------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'void_payment') {
    $payment_id = $_POST['payment_id'];
    
    try {
        $pdo->beginTransaction();
        $stmtGet = $pdo->prepare("SELECT invoice_no FROM payments WHERE payment_id = ? AND status IN ('Success', '')");
        $stmtGet->execute([$payment_id]);
        $payment = $stmtGet->fetch();
        
        if ($payment) {
            $stmtVoid = $pdo->prepare("UPDATE payments SET status = 'Voided' WHERE payment_id = ?");
            $stmtVoid->execute([$payment_id]);
            
            $stmtRevert = $pdo->prepare("UPDATE billing_invoices SET status = 'Unpaid', penalty_surcharge = 0 WHERE invoice_no = ?");
            $stmtRevert->execute([$payment['invoice_no']]);
            
            $pdo->commit();
            $_SESSION['msg'] = "Transaction ($payment_id) successfully voided.";
            $_SESSION['msg_type'] = "success";
        } else {
            $pdo->rollBack();
            $_SESSION['msg'] = "Transaction not found or already voided.";
            $_SESSION['msg_type'] = "error";
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['msg'] = "Error voiding transaction: " . $e->getMessage();
        $_SESSION['msg_type'] = "error";
    }
    
    header("Location: cashier.php");
    exit();
}

// ---------------------------------------------------------------------
// DATABASE SEARCH LOGIC (Group Unpaid Bills by Account)
// ---------------------------------------------------------------------
$groupedResults = [];
if (isset($_GET['search_query']) && !empty(trim($_GET['search_query']))) {
    $query = trim($_GET['search_query']);
    $searchTerm = "%$query%";

    try {
        $sql = "SELECT c.account_no, c.first_name, c.last_name, c.address, c.contact_number,
                       b.invoice_no, b.billing_month, b.amount_due, b.due_date, b.status, b.kwh_used
                FROM clients c
                LEFT JOIN billing_invoices b ON c.account_no = b.account_no AND b.status = 'Unpaid'
                WHERE c.account_no LIKE ? OR c.last_name LIKE ?
                ORDER BY b.due_date ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$searchTerm, $searchTerm]);
        $results = $stmt->fetchAll();

        foreach ($results as $row) {
            $acc = $row['account_no'];
            if (!isset($groupedResults[$acc])) {
                $groupedResults[$acc] = [
                    'account_no' => $acc,
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'contact_number' => $row['contact_number'],
                    'invoices' => []
                ];
            }
            if (!empty($row['invoice_no'])) {
                $groupedResults[$acc]['invoices'][] = $row;
            }
        }
    } catch (PDOException $e) {
        $message = "Search Error: " . $e->getMessage();
        $messageType = "error";
    }
}

// ---------------------------------------------------------------------
// DATA FETCHING: TODAY'S METRICS & LISTS
// ---------------------------------------------------------------------
$stmtAll = $pdo->query("SELECT account_no, first_name, last_name, status FROM clients ORDER BY last_name ASC LIMIT 300");
$allConsumers = $stmtAll->fetchAll();

$stmtToday = $pdo->query("SELECT p.*, c.first_name, c.last_name FROM payments p JOIN clients c ON p.account_no = c.account_no WHERE DATE(p.payment_date) = CURDATE() AND p.status = 'Success' ORDER BY p.payment_date DESC");
$todaysPayments = $stmtToday->fetchAll();

$stmtVoided = $pdo->query("SELECT p.*, c.first_name, c.last_name FROM payments p JOIN clients c ON p.account_no = c.account_no WHERE p.status IN ('Voided', '') ORDER BY p.payment_date DESC");
$voidedPayments = $stmtVoided->fetchAll();

$stmtGraph = $pdo->query("SELECT DATE(payment_date) as date, SUM(amount_paid) as total FROM payments WHERE status = 'Success' AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY DATE(payment_date) ORDER BY date ASC");
$graphData = $stmtGraph->fetchAll();
$dates = [];
$totals = [];
foreach($graphData as $g) {
    $dates[] = date('M d', strtotime($g['date']));
    $totals[] = $g['total'];
}

$dailyTotal = 0;
$successCount = count($todaysPayments);
foreach ($todaysPayments as $tp) {
    $dailyTotal += $tp['amount_paid'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cashier Terminal | NOCECO</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
      tailwind.config = {
        theme: {
          extend: {
            colors: { noceco: { bg: '#F5F5F7', mustard: '#DBA111', mustardHover: '#B8860B' } },
            fontFamily: { sans: ['-apple-system', 'BlinkMacSystemFont', '"Segoe UI"', 'Roboto', 'sans-serif'] },
            boxShadow: { 'apple': '0 4px 24px rgba(0, 0, 0, 0.04)' }
          }
        }
      }
    </script>
    <style>
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #E5E7EB; border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #D1D5DB; }
        
        @media print {
            @page { size: letter; margin: 0; }
            body { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            body * { visibility: hidden; }
            #print-area, #print-area * { visibility: visible; }
            #print-area { position: absolute; left: 0; top: 0; width: 100%; background: white; }
            .invoicePaper {
                width: 8.5in !important; height: 11in !important; max-height: 11in !important;
                margin: 0 !important; padding: 0.4in 0.5in !important; box-sizing: border-box !important;
                page-break-after: always; page-break-inside: avoid; overflow: hidden !important;
                box-shadow: none !important; border: none !important; display: flex; flex-direction: column;
            }
        }
    </style>
</head>
<body class="bg-noceco-bg text-gray-900 flex h-screen overflow-hidden relative">

    <?php if ($receiptData): ?>
    <div id="print-area" class="hidden print:block">
        <?php foreach ($receiptData['items'] as $invoiceData): ?>
            <div class="invoicePaper bg-white relative text-left">
                <div class="absolute top-6 right-8 text-green-600 font-black border-2 border-green-600 px-3 py-1 rounded rotate-[15deg] opacity-70 text-xl tracking-widest">PAID</div>
                
                <div class="flex items-center justify-between border-b-2 border-gray-800 pb-4 mb-4 mt-2">
                    <div class="flex items-center gap-3">
                        <img src="../images/NOCECO.png" alt="NOCECO Logo" style="width: 60px; height: 60px; object-fit: contain;">
                        <div>
                            <h2 class="text-2xl font-black text-gray-900 tracking-tighter leading-none">NOCECO</h2>
                            <p class="text-[10px] font-bold text-gray-500 uppercase tracking-widest">Negros Occidental Electric Cooperative</p>
                            <p class="text-[9px] text-gray-400">Sitio Naga, Brgy. Binicuil, Kabankalan City</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <h1 class="text-2xl font-bold text-gray-200 uppercase tracking-widest leading-none">Statement</h1>
                        <p class="font-bold text-gray-900 mt-1 text-sm">Invoice #: <?php echo htmlspecialchars($invoiceData['invoice_no']); ?></p>
                        <p class="text-[11px] text-gray-500">Billing Month: <?php echo htmlspecialchars($invoiceData['billing_month']); ?></p>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-6 mb-4 border border-gray-200 p-4 rounded-lg bg-gray-50/50">
                    <div>
                        <p class="text-[10px] font-bold text-gray-400 uppercase mb-1">Billed To</p>
                        <p class="font-bold text-gray-900 text-base"><?php echo htmlspecialchars($receiptData['consumer_name']); ?></p>
                        <p class="text-[11px] text-gray-600 mt-1"><?php echo htmlspecialchars($receiptData['address']); ?></p>
                    </div>
                    <div class="text-right">
                        <p class="text-[10px] font-bold text-gray-400 uppercase mb-1">Account Info</p>
                        <p class="font-bold text-gray-900 text-[11px]">Acct No: <span class="font-normal"><?php echo htmlspecialchars($receiptData['account_no']); ?></span></p>
                        <p class="font-bold text-gray-900 text-[11px]">Meter No: <span class="font-normal"><?php echo htmlspecialchars($receiptData['meter_no']); ?></span></p>
                        <p class="font-bold text-gray-900 mt-1 text-[11px]">Due Date: <span class="text-red-600"><?php echo date('F d, Y', strtotime($invoiceData['due_date'])); ?></span></p>
                    </div>
                </div>

                <div class="bg-gray-900 text-white p-3 flex justify-between items-center rounded-lg mb-4">
                    <div>
                        <p class="text-[10px] text-gray-400 uppercase font-bold tracking-widest">Total Consumption</p>
                        <p class="text-xl font-black"><?php echo htmlspecialchars($invoiceData['kwh_used']); ?> kWh</p>
                    </div>
                    <div class="text-right">
                        <p class="text-[10px] text-gray-400 uppercase font-bold tracking-widest">Total Amount Paid</p>
                        <p class="text-2xl font-black text-noceco-mustard">Php <?php echo number_format($invoiceData['total'], 2); ?></p>
                    </div>
                </div>

                <h3 class="font-bold text-gray-900 border-b pb-1 mb-2 text-[11px] uppercase tracking-widest">Current Charges Breakdown</h3>
                <div class="grid grid-cols-2 gap-x-8 gap-y-[2px] text-[10px] mb-4 flex-1">
                    <?php
                        $kwhUsed = (float)$invoiceData['kwh_used'];
                        foreach($standardRates as $rate):
                            $chargeName = $rate['charge_description'];
                            $lineCost = ($rate['charge_type'] === 'Per_KWH') ? ($rate['current_rate'] * $kwhUsed) : $rate['current_rate'];
                            if ($lineCost > 0 || $rate['current_rate'] > 0):
                    ?>
                        <div class="flex justify-between items-center border-b border-gray-100 py-[2px]">
                            <span class="text-gray-600 truncate mr-2"><?php echo htmlspecialchars($chargeName); ?></span>
                            <span class="font-medium text-gray-900">₱ <?php echo number_format($lineCost, 2); ?></span>
                        </div>
                    <?php endif; endforeach; ?>
                </div>

                <?php if ($invoiceData['penalty'] > 0): ?>
                <div class="mt-2 border border-red-200 bg-red-50 p-3 rounded-lg text-red-800 text-[11px] flex items-start gap-3">
                    <svg class="w-4 h-4 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                    <div>
                        <p class="font-bold mb-0.5">Penalty Applied</p>
                        <p>A 5% penalty charge (Php <?php echo number_format($invoiceData['penalty'], 2); ?>) was added for payment beyond due date.</p>
                    </div>
                </div>
                <?php endif; ?>

                <div class="mt-auto border-t border-gray-200 pt-3 text-center">
                    <p class="text-[11px] text-gray-500 font-bold mb-0.5">Payment successfully processed by NOCECO Cashier.</p>
                    <p class="text-[9px] text-gray-400">Generated securely by NOCECO Billing System on <?php echo date('Y-m-d H:i:s'); ?></p>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="flex w-full print:hidden">
        <aside class="w-64 bg-white border-r border-gray-200 flex flex-col z-20">
            <div class="h-20 flex items-center px-8 border-b border-gray-100">
                <span class="font-bold text-xl tracking-tight text-noceco-mustard">NOCECO <span class="text-gray-900">POS</span></span>
            </div>
            <nav class="flex-1 p-4 space-y-2">
                <a href="cashier.php" class="flex items-center px-4 py-3 bg-noceco-bg text-noceco-mustard font-bold rounded-xl">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                    Cashier POS
                </a>
                <a href="verify-payments.php" class="flex items-center px-4 py-3 text-gray-500 hover:bg-gray-50 rounded-xl transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    Verify Online Payments
                </a>
                
                <div class="my-4 border-t border-gray-100"></div>

                <button onclick="openVoidModal()" class="w-full text-left flex items-center px-4 py-3 text-gray-500 hover:bg-red-50 hover:text-red-600 rounded-xl transition-colors font-medium">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    Voided Transactions
                </button>
            </nav>
            <div class="p-4 border-t border-gray-100">
                <a href="logout.php" class="flex items-center px-4 py-3 text-red-500 font-medium hover:bg-red-50 rounded-xl">Logout</a>
            </div>
        </aside>

        <div class="flex-1 flex flex-col h-screen overflow-hidden relative">
            <header class="h-20 bg-white/80 backdrop-blur-md border-b border-gray-200 flex items-center justify-between px-8 sticky top-0 z-10">
                <div>
                    <h2 class="text-lg font-bold">Terminal #01</h2>
                    <p class="text-xs text-gray-500">Welcome back, <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Cashier'); ?></p>
                </div>
                <div class="text-right">
                    <p class="text-xs font-bold text-gray-400 uppercase">Today's POS Collection</p>
                    <p class="text-lg font-black text-green-600">₱<?php echo number_format($dailyTotal, 2); ?></p>
                </div>
            </header>

            <main class="flex-1 overflow-y-auto p-8 space-y-8 custom-scrollbar">
                
                <?php if ($message): ?>
                    <div class="p-4 rounded-xl text-sm font-bold <?php echo $messageType === 'success' ? 'bg-green-50 text-green-700 border border-green-100' : 'bg-red-50 text-red-700 border border-red-100'; ?> flex items-center gap-3">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <div class="bg-white rounded-[20px] p-6 shadow-apple border border-gray-100">
                    <form action="cashier.php" method="GET" class="relative flex items-center">
                        <svg class="w-6 h-6 text-gray-400 absolute left-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                        <input type="text" name="search_query" required value="<?php echo isset($_GET['search_query']) ? htmlspecialchars($_GET['search_query']) : ''; ?>"
                            class="w-full pl-14 pr-32 py-4 bg-noceco-bg/50 border border-gray-200 rounded-xl text-lg focus:outline-none focus:ring-2 focus:ring-noceco-mustard focus:bg-white transition-all"
                            placeholder="Search Account No. or Last Name to pay bill..." autofocus>
                        <button type="submit" class="absolute right-2 top-2 bottom-2 bg-gray-900 hover:bg-black text-white font-medium px-6 rounded-lg transition-colors">Find Bill</button>
                    </form>
                </div>

                <?php if (isset($_GET['search_query'])): ?>
                    <?php if (empty($groupedResults)): ?>
                        <div class="bg-white rounded-[20px] p-10 text-center shadow-apple border border-gray-100">
                            <p class="text-gray-500 font-medium">No pending bills found for this search.</p>
                            <a href="cashier.php" class="text-noceco-mustard text-sm font-bold hover:underline mt-2 inline-block">Clear Search</a>
                        </div>
                    <?php else: ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <?php foreach ($groupedResults as $acc => $data): 
                                // Check if any invoice has penalties
                                $account_has_penalty = false;
                                foreach ($data['invoices'] as $inv) {
                                    $today = strtotime(date('Y-m-d'));
                                    $effectiveDueTs = getEffectiveDueDate($inv['due_date']);
                                    if ($today > $effectiveDueTs) $account_has_penalty = true;
                                }
                            ?>
                                <div class="bg-white rounded-[20px] p-6 shadow-apple border border-gray-100 flex flex-col h-full relative">
                                    
                                    <div class="border-b border-gray-100 pb-4 mb-4">
                                        <h4 class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars($data['last_name'] . ', ' . $data['first_name']); ?></h4>
                                        <p class="text-sm font-medium text-noceco-mustard">Acc: <?php echo htmlspecialchars($acc); ?></p>
                                    </div>
                                    
                                    <?php if (count($data['invoices']) > 0): ?>
                                        
                                        <?php if ($account_has_penalty): ?>
                                        <div class="mb-4 p-3 bg-yellow-50 rounded-xl border border-yellow-200 flex justify-between items-center shadow-inner">
                                            <div>
                                                <p class="text-xs font-bold text-yellow-800 tracking-tight">Holiday / Extension Override</p>
                                                <p class="text-[10px] text-yellow-600 leading-tight mt-0.5">Waive penalties if due date was a holiday.</p>
                                            </div>
                                            <label class="relative inline-flex items-center cursor-pointer">
                                                <input type="checkbox" class="sr-only peer" onchange="toggleHolidayOverride('<?php echo htmlspecialchars($acc); ?>', this.checked)">
                                                <div class="w-9 h-5 bg-gray-300 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-noceco-mustard"></div>
                                            </label>
                                        </div>
                                        <?php endif; ?>

                                        <div class="space-y-4 mb-6 flex-1">
                                            <?php
                                            $grand_total = 0;
                                            $grand_base = 0;
                                            $grand_pen = 0;
                                            $oldest_invoice = null;
                                            
                                            foreach ($data['invoices'] as $index => $inv):
                                                $today = strtotime(date('Y-m-d'));
                                                $effectiveDueTs = getEffectiveDueDate($inv['due_date']);
                                                $is_past_due = ($today > $effectiveDueTs);
                                                
                                                $base_amount = (float)$inv['amount_due'];
                                                $penalty = $is_past_due ? ($base_amount * 0.05) : 0;
                                                $inv_total = $base_amount + $penalty;
                                                
                                                $grand_base += $base_amount;
                                                $grand_pen += $penalty;
                                                $grand_total += $inv_total;

                                                if ($index === 0) {
                                                    $oldest_invoice = [
                                                        'invoice_no' => $inv['invoice_no'],
                                                        'base_amount' => $base_amount,
                                                        'penalty' => $penalty,
                                                        'total' => $inv_total
                                                    ];
                                                }
                                            ?>
                                                <div class="bg-gray-50 rounded-xl p-4 border border-gray-200 shadow-sm">
                                                    <div class="flex justify-between items-center mb-1">
                                                        <span class="text-sm font-bold text-gray-900">Invoice #<?php echo htmlspecialchars($inv['invoice_no']); ?></span>
                                                        <span class="text-sm font-bold <?php echo $is_past_due ? 'text-red-600' : 'text-gray-600'; ?>">
                                                            Due: <?php echo htmlspecialchars($inv['due_date']); ?>
                                                        </span>
                                                    </div>
                                                    <p class="text-sm text-gray-600 mb-3">Billing Month: <span class="font-bold"><?php echo htmlspecialchars($inv['billing_month']); ?></span></p>
                                                    
                                                    <div class="space-y-1">
                                                        <div class="flex justify-between text-sm">
                                                            <span class="text-gray-500 font-medium">Base Amount:</span>
                                                            <span class="font-bold text-gray-900">₱<?php echo number_format($base_amount, 2); ?></span>
                                                        </div>
                                                        
                                                        <?php if ($is_past_due): ?>
                                                        <div class="flex justify-between text-sm text-red-500 pen-display-<?php echo $acc; ?>" data-orig="<?php echo $penalty; ?>">
                                                            <span class="font-medium">Penalty (5%):</span>
                                                            <span class="font-bold">+ ₱<?php echo number_format($penalty, 2); ?></span>
                                                        </div>
                                                        <?php endif; ?>
                                                        
                                                        <div class="flex justify-between items-end border-t border-gray-200 mt-2 pt-2">
                                                            <span class="text-xs font-bold text-gray-900 uppercase tracking-widest">Subtotal</span>
                                                            <span class="text-lg font-black text-gray-900 total-display-<?php echo $acc; ?>" data-base="<?php echo $base_amount; ?>" data-pen="<?php echo $penalty; ?>">
                                                                ₱<?php echo number_format($inv_total, 2); ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <?php if (count($data['invoices']) > 1): ?>
                                        <div class="flex gap-3 mt-auto">
                                            <form action="cashier.php" method="POST" class="w-1/2">
                                                <input type="hidden" name="action" value="process_payment">
                                                <input type="hidden" name="account_no" value="<?php echo htmlspecialchars($acc); ?>">
                                                <input type="hidden" name="contact_number" value="<?php echo htmlspecialchars($data['contact_number']); ?>">
                                                
                                                <input type="hidden" name="invoice_nos[]" value="<?php echo htmlspecialchars($oldest_invoice['invoice_no']); ?>">
                                                <input type="hidden" name="base_amounts[]" value="<?php echo $oldest_invoice['base_amount']; ?>">
                                                <input type="hidden" name="penalty_amounts[]" class="pen-hidden-<?php echo $acc; ?>" data-orig="<?php echo $oldest_invoice['penalty']; ?>" value="<?php echo $oldest_invoice['penalty']; ?>">
                                                
                                                <button type="submit" id="btn-oldest-<?php echo $acc; ?>" data-base="<?php echo $oldest_invoice['base_amount']; ?>" data-pen="<?php echo $oldest_invoice['penalty']; ?>" onclick="return confirm('Pay PREVIOUS bill only?');"
                                                        class="w-full bg-white border-2 border-noceco-mustard text-noceco-mustard hover:bg-noceco-mustard hover:text-white font-bold py-3 px-2 rounded-xl transition-all text-xs text-center shadow-sm">
                                                    Pay Oldest (₱<?php echo number_format($oldest_invoice['total'], 2); ?>)
                                                </button>
                                            </form>
                                            
                                            <form action="cashier.php" method="POST" class="w-1/2">
                                                <input type="hidden" name="action" value="process_payment">
                                                <input type="hidden" name="account_no" value="<?php echo htmlspecialchars($acc); ?>">
                                                <input type="hidden" name="contact_number" value="<?php echo htmlspecialchars($data['contact_number']); ?>">
                                                
                                                <?php foreach ($data['invoices'] as $inv):
                                                    $today = strtotime(date('Y-m-d'));
                                                    $effTs = getEffectiveDueDate($inv['due_date']);
                                                    $p_amount = ($today > $effTs) ? ((float)$inv['amount_due'] * 0.05) : 0;
                                                ?>
                                                    <input type="hidden" name="invoice_nos[]" value="<?php echo htmlspecialchars($inv['invoice_no']); ?>">
                                                    <input type="hidden" name="base_amounts[]" value="<?php echo $inv['amount_due']; ?>">
                                                    <input type="hidden" name="penalty_amounts[]" class="pen-hidden-<?php echo $acc; ?>" data-orig="<?php echo $p_amount; ?>" value="<?php echo $p_amount; ?>">
                                                <?php endforeach; ?>
                                                
                                                <button type="submit" id="btn-all-<?php echo $acc; ?>" data-base="<?php echo $grand_base; ?>" data-pen="<?php echo $grand_pen; ?>" onclick="return confirm('Pay ALL bills?');"
                                                        class="w-full bg-noceco-mustard hover:bg-noceco-mustardHover text-white font-bold py-3 px-2 rounded-xl shadow-md transition-all text-xs text-center">
                                                    Pay All (₱<?php echo number_format($grand_total, 2); ?>)
                                                </button>
                                            </form>
                                        </div>
                                        <?php else: ?>
                                        <form action="cashier.php" method="POST" class="mt-auto">
                                            <input type="hidden" name="action" value="process_payment">
                                            <input type="hidden" name="account_no" value="<?php echo htmlspecialchars($acc); ?>">
                                            <input type="hidden" name="contact_number" value="<?php echo htmlspecialchars($data['contact_number']); ?>">
                                            
                                            <input type="hidden" name="invoice_nos[]" value="<?php echo htmlspecialchars($oldest_invoice['invoice_no']); ?>">
                                            <input type="hidden" name="base_amounts[]" value="<?php echo $oldest_invoice['base_amount']; ?>">
                                            <input type="hidden" name="penalty_amounts[]" class="pen-hidden-<?php echo $acc; ?>" data-orig="<?php echo $oldest_invoice['penalty']; ?>" value="<?php echo $oldest_invoice['penalty']; ?>">
                                            
                                            <button type="submit" id="btn-all-<?php echo $acc; ?>" data-base="<?php echo $oldest_invoice['base_amount']; ?>" data-pen="<?php echo $oldest_invoice['penalty']; ?>" onclick="return confirm('Confirm cash payment?');"
                                                    class="w-full bg-gray-900 hover:bg-black text-white font-bold py-3 px-4 rounded-xl shadow-md transition-all">
                                                Accept Cash (₱<?php echo number_format($oldest_invoice['total'], 2); ?>)
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <section class="bg-white rounded-[24px] shadow-apple border border-gray-200 overflow-hidden">
                    <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
                        <h3 class="font-bold text-gray-900">Consumer Masterlist</h3>
                        <div class="flex gap-2">
                            <input type="text" id="domSearchInput" placeholder="Filter table..." class="px-3 py-1.5 text-sm bg-white border border-gray-300 rounded-lg focus:ring-1 focus:ring-noceco-mustard outline-none">
                            <button id="domSearchBtn" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-1.5 text-sm font-bold rounded-lg transition-colors">Filter</button>
                        </div>
                    </div>
                    <div class="max-h-[350px] overflow-y-auto custom-scrollbar">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-white sticky top-0 border-b border-gray-100 shadow-sm z-10">
                                <tr class="text-[10px] text-gray-400 uppercase tracking-widest">
                                    <th class="p-4">Account No.</th>
                                    <th class="p-4">Consumer Name</th>
                                    <th class="p-4 text-center">Status</th>
                                    <th class="p-4 text-right">Quick Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100" id="masterlistBody">
                                <?php foreach($allConsumers as $c): ?>
                                <tr class="hover:bg-gray-50 masterlist-row">
                                    <td class="p-4 font-mono font-bold"><?php echo $c['account_no']; ?></td>
                                    <td class="p-4 font-semibold"><?php echo $c['last_name'].', '.$c['first_name']; ?></td>
                                    <td class="p-4 text-center">
                                        <span class="px-2 py-1 rounded text-[10px] font-bold <?php echo $c['status'] === 'Connected' ? 'bg-green-50 text-green-600' : 'bg-red-50 text-red-600'; ?>">
                                            <?php echo strtoupper($c['status']); ?>
                                        </span>
                                    </td>
                                    <td class="p-4 text-right">
                                        <form action="cashier.php" method="GET" class="inline">
                                            <input type="hidden" name="search_query" value="<?php echo $c['account_no']; ?>">
                                            <button type="submit" class="text-xs font-bold text-noceco-mustard hover:underline">Check Bill</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <div class="bg-white rounded-[24px] shadow-apple border border-gray-200 overflow-hidden flex flex-col">
                        <div class="p-6 border-b border-gray-100 bg-gray-50/50 flex justify-between items-center">
                            <h3 class="font-bold">Today's Transactions</h3>
                            <span class="text-[10px] font-black bg-green-500 text-white px-2 py-1 rounded"><?php echo $successCount; ?> PAID TODAY</span>
                        </div>
                        <div class="flex-1 max-h-[300px] overflow-y-auto custom-scrollbar">
                            <?php if(empty($todaysPayments)): ?>
                                <p class="p-10 text-center text-gray-400 italic text-sm">No successful transactions today.</p>
                            <?php else: ?>
                                <table class="w-full text-left text-sm">
                                    <tbody class="divide-y divide-gray-100">
                                        <?php foreach($todaysPayments as $tp): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="p-4">
                                                <p class="font-bold text-gray-900"><?php echo htmlspecialchars($tp['last_name']); ?></p>
                                                <p class="text-[10px] text-gray-400">
                                                    <?php echo date('h:i A', strtotime($tp['payment_date'])); ?> • <?php echo htmlspecialchars($tp['payment_method']); ?> 
                                                    <span class="ml-1 text-gray-300">| ID: <?php echo htmlspecialchars($tp['payment_id']); ?></span>
                                                </p>
                                            </td>
                                            <td class="p-4 text-right font-black text-green-600">
                                                ₱<?php echo number_format($tp['amount_paid'], 2); ?>
                                            </td>
                                            <td class="p-4 text-right w-16">
                                                <form action="cashier.php" method="POST" class="inline">
                                                    <input type="hidden" name="action" value="void_payment">
                                                    <input type="hidden" name="payment_id" value="<?php echo htmlspecialchars($tp['payment_id']); ?>">
                                                    <button type="submit" onclick="return confirm('Are you sure you want to VOID this transaction?');" 
                                                            class="text-xs font-bold text-red-500 hover:text-red-700 bg-red-50 hover:bg-red-100 px-2 py-1 rounded transition-colors">
                                                        Void
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="bg-white rounded-[24px] shadow-apple border border-gray-200 p-6 flex flex-col">
                        <h3 class="font-bold mb-4">7-Day Revenue</h3>
                        <div class="flex-1 relative min-h-[250px]">
                            <canvas id="paymentChart"></canvas>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <div id="voidModal" class="fixed inset-0 bg-gray-900/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-4xl rounded-[24px] shadow-2xl flex flex-col max-h-[85vh]">
            <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/50 rounded-t-[24px]">
                <div>
                    <h3 class="font-bold text-xl text-red-600 flex items-center gap-2">Voided Transactions</h3>
                </div>
                <button onclick="closeVoidModal()" class="text-gray-400 hover:text-gray-900 p-2 bg-white rounded-full shadow-sm border border-gray-100">X</button>
            </div>
            
            <div class="p-6 overflow-y-auto custom-scrollbar flex-1">
                <?php if(empty($voidedPayments)): ?>
                    <p class="text-center text-gray-500 py-10">No voided transactions found.</p>
                <?php else: ?>
                    <table class="w-full text-left text-sm">
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach($voidedPayments as $vp): ?>
                            <tr class="hover:bg-red-50/30">
                                <td class="p-3">
                                    <span class="inline-block px-2 py-0.5 rounded text-[10px] font-bold bg-red-100 text-red-600 mb-1">VOIDED</span>
                                    <p class="font-mono text-xs text-gray-600"><?php echo htmlspecialchars($vp['payment_id']); ?></p>
                                </td>
                                <td class="p-3">
                                    <p class="font-bold text-gray-900"><?php echo htmlspecialchars($vp['last_name'] . ', ' . $vp['first_name']); ?></p>
                                </td>
                                <td class="p-3 text-right">
                                    <p class="font-black text-gray-400 line-through">₱<?php echo number_format($vp['amount_paid'], 2); ?></p>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        <?php if ($receiptData): ?>
            window.onload = function() { window.print(); };
        <?php endif; ?>

        // DOM-level Search filter
        const searchInput = document.getElementById('domSearchInput');
        const searchBtn = document.getElementById('domSearchBtn');
        const tableRows = document.querySelectorAll('.masterlist-row');

        function executeDomSearch() {
            const filter = searchInput.value.toUpperCase();
            tableRows.forEach(row => {
                const text = row.innerText.toUpperCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        }
        searchBtn.addEventListener('click', executeDomSearch);
        searchInput.addEventListener('keypress', function(e) { if(e.key === 'Enter') executeDomSearch(); });

        // Void Modal Controls
        function openVoidModal() { document.getElementById('voidModal').classList.remove('hidden'); }
        function closeVoidModal() { document.getElementById('voidModal').classList.add('hidden'); }

        // Dynamic Holiday Override Toggle Logic
        function toggleHolidayOverride(acc, isHoliday) {
            // 1. Update UI Text
            document.querySelectorAll('.pen-display-' + acc).forEach(el => {
                let orig = parseFloat(el.getAttribute('data-orig'));
                if (isHoliday) {
                    el.innerHTML = '<span class="text-gray-400 line-through">Penalty Waived</span> <span class="text-green-500 font-bold ml-2">₱0.00</span>';
                } else {
                    el.innerHTML = '<span class="font-medium text-red-500">Penalty (5%):</span> <span class="font-bold text-red-500">+ ₱' + orig.toFixed(2) + '</span>';
                }
            });

            // 2. Update Subtotals
            document.querySelectorAll('.total-display-' + acc).forEach(el => {
                let base = parseFloat(el.getAttribute('data-base'));
                let pen = parseFloat(el.getAttribute('data-pen'));
                let total = isHoliday ? base : (base + pen);
                el.innerText = '₱' + total.toFixed(2);
            });

            // 3. Update Hidden Inputs for POST
            document.querySelectorAll('.pen-hidden-' + acc).forEach(el => {
                let orig = parseFloat(el.getAttribute('data-orig'));
                el.value = isHoliday ? 0 : orig;
            });

            // 4. Update Button Totals
            let btnOldest = document.getElementById('btn-oldest-' + acc);
            if (btnOldest) {
                let base = parseFloat(btnOldest.getAttribute('data-base'));
                let pen = parseFloat(btnOldest.getAttribute('data-pen'));
                let total = isHoliday ? base : (base + pen);
                btnOldest.innerText = 'Pay Oldest (₱' + total.toFixed(2) + ')';
            }

            let btnAll = document.getElementById('btn-all-' + acc);
            if (btnAll) {
                let base = parseFloat(btnAll.getAttribute('data-base'));
                let pen = parseFloat(btnAll.getAttribute('data-pen'));
                let total = isHoliday ? base : (base + pen);
                btnAll.innerText = 'Pay All/Accept Cash (₱' + total.toFixed(2) + ')';
            }
        }

        // Chart.js
        const ctx = document.getElementById('paymentChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($dates); ?>,
                datasets: [{
                    label: 'Total Collected (₱)', data: <?php echo json_encode($totals); ?>,
                    backgroundColor: '#DBA111', borderRadius: 6, barThickness: 24
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
                scales: { y: { display: false }, x: { grid: { display: false }, ticks: { font: { size: 10 } } } }
            }
        });
    </script>
</body>
</html>