<?php
session_start();
require_once '../db/dbcon.php';

// ---------------------------------------------------------------------
// SECURITY: ROLE VERIFICATION
// ---------------------------------------------------------------------
if (!isset($_SESSION['staff_id']) || $_SESSION['role'] !== 'Meter Reader') {
    header("Location: ../administrator.php");
    exit();
}

$message = '';
$messageType = '';
$clientData = null;
$previousReading = 0;
$generatedInvoice = null; 

// ---------------------------------------------------------------------
// FLASH MESSAGE HANDLER (Prevents Duplicate Resubmissions on Refresh)
// ---------------------------------------------------------------------
if (isset($_SESSION['flash_msg'])) {
    $message = $_SESSION['flash_msg'];
    $messageType = $_SESSION['flash_type'];
    $generatedInvoice = $_SESSION['flash_invoice'] ?? null;

    unset($_SESSION['flash_msg']);
    unset($_SESSION['flash_type']);
    unset($_SESSION['flash_invoice']);
}

// ---------------------------------------------------------------------
// STEP 1: SEARCH FOR ACCOUNT (Detects if Already Billed)
// ---------------------------------------------------------------------
if (isset($_GET['search_account']) && !empty(trim($_GET['search_account']))) {
    $search_term = trim($_GET['search_account']);
    $clean_term = str_replace('-', '', $search_term); // Allow searching without hyphens
    $current_billing_month = date('F Y'); // e.g., April 2026
    
    try {
        // Smart Search: Looks for Account No (with or without hyphens) OR Meter No.
        $stmt = $pdo->prepare("SELECT account_no, first_name, last_name, meter_no, contact_number, address, consumer_type 
                               FROM clients 
                               WHERE (account_no = ? OR REPLACE(account_no, '-', '') = ? OR meter_no = ?) 
                               AND status = 'Connected'");
        $stmt->execute([$search_term, $clean_term, $search_term]);
        $clientData = $stmt->fetch();

        if ($clientData) {
            $account_no = $clientData['account_no'];

            // ANTI-DUPLICATE: Check if already billed this month
            $stmtCheck = $pdo->prepare("SELECT * FROM billing_invoices WHERE account_no = ? AND billing_month = ? LIMIT 1");
            $stmtCheck->execute([$account_no, $current_billing_month]);
            $existingBill = $stmtCheck->fetch();

            if ($existingBill) {
                // ACCOUNT ALREADY BILLED! Retrieve exact 31 lines and show the receipt.
                $message = "Notice: This account was already billed for $current_billing_month.";
                $messageType = "success";

                // Fetch Line Items and join with Catalog to get Type (Per_KWH) and VATability
                $stmtLines = $pdo->prepare("
                    SELECT l.charge_description, l.rate_applied, l.calculated_amount, c.charge_type, c.is_vatable 
                    FROM invoice_line_items l
                    LEFT JOIN billing_rates_catalog c ON l.charge_description = c.charge_description
                    WHERE l.invoice_no = ?
                ");
                $stmtLines->execute([$existingBill['invoice_no']]);
                $lines = $stmtLines->fetchAll();

                $vatable_sales = 0;
                $exempt_sales = 0;
                $explicit_vat_total = 0;
                $lineItemsData = [];

                foreach ($lines as $line) {
                    $is_vat_item = (stripos($line['charge_description'], 'VAT') !== false);
                    $amt = (float)$line['calculated_amount'];

                    if ($is_vat_item) {
                        $explicit_vat_total += $amt;
                    } else {
                        // Reconstruct VAT grouping for the receipt summary
                        if (isset($line['is_vatable']) && $line['is_vatable'] == 1) {
                            $vatable_sales += $amt;
                        } else {
                            $exempt_sales += $amt;
                        }
                    }

                    $lineItemsData[] = [
                        'desc' => $line['charge_description'],
                        'type' => $line['charge_type'] ?? 'Per_KWH',
                        'rate' => $line['rate_applied'],
                        'amt'  => $amt,
                        'is_vat_item' => $is_vat_item
                    ];
                }

                // Construct the UI object
                $generatedInvoice = [
                    'invoice_no' => $existingBill['invoice_no'],
                    'account_no' => $account_no,
                    'client_name' => $clientData['first_name'] . ' ' . $clientData['last_name'],
                    'address' => $clientData['address'],
                    'meter_no' => $clientData['meter_no'],
                    'consumer_type' => $clientData['consumer_type'],
                    'billing_month' => $existingBill['billing_month'],
                    'reading_date' => $existingBill['reading_date'],
                    'due_date' => $existingBill['due_date'],
                    'current_reading' => $existingBill['current_reading'],
                    'previous_reading' => $existingBill['previous_reading'],
                    'kwh_used' => $existingBill['kwh_used'],
                    'line_items' => $lineItemsData,
                    'vatable_sales' => $vatable_sales,
                    'exempt_sales' => $exempt_sales,
                    'explicit_vat_total' => $explicit_vat_total,
                    'grand_total' => $existingBill['amount_due']
                ];
                
                // Clear clientData so the Input Reading form does NOT show
                $clientData = null; 

            } else {
                // NOT BILLED YET: Fetch previous reading to show the Input Form
                $stmtLastRead = $pdo->prepare("SELECT current_reading FROM billing_invoices WHERE account_no = ? ORDER BY reading_date DESC LIMIT 1");
                $stmtLastRead->execute([$account_no]);
                $lastRecord = $stmtLastRead->fetch();
                $previousReading = $lastRecord ? $lastRecord['current_reading'] : 0;
            }
        } else {
            $message = "Account or Meter not found. Please verify the numbers.";
            $messageType = "error";
        }
    } catch (PDOException $e) {
        $message = "Database Error: " . $e->getMessage();
        $messageType = "error";
    }
}

// ---------------------------------------------------------------------
// STEP 2: PROCESS NEW READING & RENDER DIGITAL RECEIPT
// ---------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'submit_reading') {
    $account_no = $_POST['account_no'];
    $contact_number = $_POST['contact_number'];
    $current_reading = (int)$_POST['current_reading'];
    $previous_reading = (int)$_POST['previous_reading'];
    $reading_date = date('Y-m-d');
    $billing_month = date('F Y'); 
    $due_date = date('Y-m-d', strtotime($reading_date . ' + 9 days'));

    // FINAL ANTI-DUPLICATE POST LOCK
    $stmtCheck = $pdo->prepare("SELECT invoice_no FROM billing_invoices WHERE account_no = ? AND billing_month = ?");
    $stmtCheck->execute([$account_no, $billing_month]);

    if ($stmtCheck->rowCount() > 0) {
        $message = "Duplicate Blocked: This account has already been billed for $billing_month.";
        $messageType = "error";
        $clientData = [
            'account_no' => $account_no, 'contact_number' => $contact_number, 
            'first_name' => $_POST['fname'], 'last_name' => $_POST['lname'], 
            'meter_no' => $_POST['meter_no'], 'address' => $_POST['address'], 'consumer_type' => $_POST['consumer_type']
        ];
    } elseif ($current_reading < $previous_reading) {
        $message = "Error: Current reading cannot be lower than previous reading ($previous_reading).";
        $messageType = "error";
        $clientData = [
            'account_no' => $account_no, 'contact_number' => $contact_number, 
            'first_name' => $_POST['fname'], 'last_name' => $_POST['lname'], 
            'meter_no' => $_POST['meter_no'], 'address' => $_POST['address'], 'consumer_type' => $_POST['consumer_type']
        ];
    } else {
        $kwh_used = $current_reading - $previous_reading;
        $invoice_no = date('ymd') . rand(1000, 9999);

        try {
            $pdo->beginTransaction();

            $stmtRates = $pdo->query("SELECT charge_description, charge_type, current_rate, is_vatable FROM billing_rates_catalog WHERE status = 'Active'");
            $rates = $stmtRates->fetchAll();

            $base_sales = 0;
            $vatable_sales = 0;
            $exempt_sales = 0;
            $explicit_vat_total = 0;
            $grand_total = 0;
            $lineItemsData = []; 

            foreach ($rates as $rate) {
                $amount = 0;
                
                if ($rate['charge_type'] == 'Per_KWH') {
                    $amount = $kwh_used * $rate['current_rate'];
                } elseif ($rate['charge_type'] == 'Per_Customer' || $rate['charge_type'] == 'Fixed') {
                    $amount = $rate['current_rate'];
                }
                
                $is_vat_item = (stripos($rate['charge_description'], 'VAT') !== false);

                if ($is_vat_item) {
                    $explicit_vat_total += $amount;
                } else {
                    $base_sales += $amount;
                    if ($rate['is_vatable'] == 1) {
                        $vatable_sales += $amount;
                    } else {
                        $exempt_sales += $amount;
                    }
                }

                $grand_total += $amount;
                
                $lineItemsData[] = [
                    'desc' => $rate['charge_description'],
                    'type' => $rate['charge_type'],
                    'rate' => $rate['current_rate'],
                    'amt'  => $amount,
                    'is_vat_item' => $is_vat_item
                ];
            }

            $stmtInvoice = $pdo->prepare("INSERT INTO billing_invoices (invoice_no, account_no, billing_month, reading_date, previous_reading, current_reading, kwh_used, total_sales, amount_due, due_date, status, meter_reader_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Unpaid', ?)");
            $stmtInvoice->execute([$invoice_no, $account_no, $billing_month, $reading_date, $previous_reading, $current_reading, $kwh_used, $base_sales, $grand_total, $due_date, $_SESSION['staff_id']]);

            $stmtLineItem = $pdo->prepare("INSERT INTO invoice_line_items (invoice_no, charge_description, rate_applied, calculated_amount) VALUES (?, ?, ?, ?)");
            foreach ($lineItemsData as $item) {
                $stmtLineItem->execute([$invoice_no, $item['desc'], $item['rate'], $item['amt']]);
            }

            $smsMessage = "NOCECO Alert: Your bill for $billing_month is Php " . number_format($grand_total, 2) . ". Due date is $due_date. KWH Used: $kwh_used.";
            $stmtSMS = $pdo->prepare("INSERT INTO sms_logs (account_no, contact_number, message_type, message_content) VALUES (?, ?, 'New Bill', ?)");
            $stmtSMS->execute([$account_no, $contact_number, $smsMessage]);

            $pdo->commit();
            
            // PRG PATTERN: SESSION FLASH TO PREVENT REFRESH DUPLICATION
            $_SESSION['flash_msg'] = "Bill successfully generated for $account_no.";
            $_SESSION['flash_type'] = "success";
            $_SESSION['flash_invoice'] = [
                'invoice_no' => $invoice_no, 'account_no' => $account_no,
                'client_name' => $_POST['fname'] . ' ' . $_POST['lname'],
                'address' => $_POST['address'], 'meter_no' => $_POST['meter_no'],
                'consumer_type' => $_POST['consumer_type'], 'billing_month' => $billing_month,
                'reading_date' => $reading_date, 'due_date' => $due_date,
                'current_reading' => $current_reading, 'previous_reading' => $previous_reading,
                'kwh_used' => $kwh_used, 'line_items' => $lineItemsData,
                'vatable_sales' => $vatable_sales, 'exempt_sales' => $exempt_sales,
                'explicit_vat_total' => $explicit_vat_total, 'grand_total' => $grand_total
            ];

            // Redirect to self to clear POST memory
            header("Location: readerman.php");
            exit();

        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "Transaction Failed: " . $e->getMessage();
            $messageType = "error";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Field Reader | NOCECO</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = {
        theme: {
          extend: {
            colors: { noceco: { bg: '#F5F5F7', text: '#1D1D1F', mustard: '#DBA111', mustardHover: '#B8860B' } },
            fontFamily: { 
                sans: ['-apple-system', 'BlinkMacSystemFont', '"Segoe UI"', 'Roboto', 'sans-serif'],
                mono: ['ui-monospace', 'SFMono-Regular', 'Menlo', 'Monaco', 'Consolas', 'monospace']
            },
            boxShadow: { 'apple': '0 4px 24px rgba(0, 0, 0, 0.04)', 'apple-sm': '0 2px 12px rgba(0, 0, 0, 0.04)' }
          }
        }
      }
    </script>
    <style>
        .custom-scrollbar::-webkit-scrollbar { display: block; width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }
    </style>
</head>
<body class="bg-noceco-bg text-noceco-text min-h-screen pb-20">

    <header class="bg-white px-6 py-4 border-b border-gray-200 sticky top-0 z-10 shadow-sm flex justify-between items-center">
        <div>
            <h1 class="text-xl font-bold tracking-tight">Field Reader</h1>
            <p class="text-xs text-noceco-mustard font-medium"><?php echo htmlspecialchars($_SESSION['full_name']); ?></p>
        </div>
        <a href="logout.php" class="p-2 rounded-full bg-red-50 text-red-500 hover:bg-red-100 transition-colors">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
        </a>
    </header>

   <main class="p-4 max-w-md mx-auto">
        
        <?php if (!empty($message)): ?>
            <div class="mb-4 p-4 rounded-xl text-sm font-medium <?php echo $messageType === 'success' ? 'bg-green-50 text-green-700 border-green-200' : 'bg-red-50 text-red-700 border-red-200'; ?> border flex items-center justify-between shadow-sm">
                <span><?php echo htmlspecialchars($message); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($generatedInvoice): ?>
            <?php
                // QUICK BACKEND FETCH: Calculate Previous Unpaid Bills & Penalties
                $previous_unpaid = 0;
                $previous_penalty = 0;
                
                $stmtArrears = $pdo->prepare("SELECT amount_due, due_date FROM billing_invoices WHERE account_no = ? AND status = 'Unpaid' AND invoice_no != ?");
                $stmtArrears->execute([$generatedInvoice['account_no'], $generatedInvoice['invoice_no']]);
                $arrears = $stmtArrears->fetchAll();
                
                $today = strtotime(date('Y-m-d'));
                foreach ($arrears as $arr) {
                    $previous_unpaid += (float)$arr['amount_due'];
                    // Apply 5% penalty if the old bill is past its due date
                    if ($today > strtotime($arr['due_date'])) {
                        $previous_penalty += ((float)$arr['amount_due'] * 0.05);
                    }
                }
                
                // Calculate the true grand total including arrears
                $absolute_grand_total = $generatedInvoice['grand_total'] + $previous_unpaid + $previous_penalty;
            ?>

            <div class="bg-white rounded-[20px] shadow-apple border border-gray-200 overflow-hidden mb-6 font-mono text-sm relative">
                <div class="absolute top-0 left-0 right-0 h-2 bg-[url('data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMCIgaGVpZ2h0PSIxMCI+PHBvbHlnb24gcG9pbnRzPSIwLDEwIDUsMCAxMCwxMCIgZmlsbD0iI2QxZDVkYiIvPjwvc3ZnPg==')] opacity-30"></div>
                
                <div class="p-6 pt-8">
                    <div class="text-center mb-6">
                        <h2 class="font-bold text-base tracking-tight uppercase">NOCECO</h2>
                        <p class="text-[10px] text-gray-500">NEGROS OCCIDENTAL ELECTRIC COOPERATIVE</p>
                        <p class="text-[11px] font-bold mt-2 border-b border-dashed border-gray-300 pb-2">BILLING INVOICE<br><?php echo date('m/d/Y h:i a'); ?></p>
                    </div>

                    <div class="text-[11px] space-y-1 mb-4 text-gray-700">
                        <div class="flex justify-between"><span class="font-bold text-gray-900">Acc No:</span> <span><?php echo htmlspecialchars($generatedInvoice['account_no']); ?></span></div>
                        <div class="flex justify-between"><span class="font-bold text-gray-900">Name:</span> <span class="uppercase"><?php echo htmlspecialchars($generatedInvoice['client_name']); ?></span></div>
                        <div class="flex justify-between"><span class="font-bold text-gray-900">INV No:</span> <span><?php echo htmlspecialchars($generatedInvoice['invoice_no']); ?></span></div>
                        <div class="mt-1 pb-2 border-b border-dashed border-gray-300">
                            <span class="font-bold text-gray-900 block">Address:</span>
                            <span class="uppercase"><?php echo htmlspecialchars($generatedInvoice['address']); ?></span>
                        </div>
                    </div>

                    <div class="text-[11px] space-y-1 mb-4 text-gray-700 pb-3 border-b border-dashed border-gray-300">
                        <div class="flex justify-between"><span>Billing Month:</span> <span class="font-bold text-gray-900"><?php echo htmlspecialchars($generatedInvoice['billing_month']); ?></span></div>
                        <div class="flex justify-between"><span>Meter No:</span> <span><?php echo htmlspecialchars($generatedInvoice['meter_no']); ?></span></div>
                        <div class="flex justify-between"><span>Type:</span> <span><?php echo htmlspecialchars($generatedInvoice['consumer_type']); ?></span></div>
                        <div class="flex justify-between pt-2">
                            <span>Prev Read: <?php echo $generatedInvoice['previous_reading']; ?></span>
                            <span>Curr Read: <?php echo $generatedInvoice['current_reading']; ?></span>
                        </div>
                        <div class="flex justify-between font-bold text-gray-900 text-xs mt-1">
                            <span>KWH USED</span>
                            <span><?php echo $generatedInvoice['kwh_used']; ?></span>
                        </div>
                    </div>

                    <div class="max-h-64 overflow-y-auto custom-scrollbar pr-2 mb-4">
                        <p class="text-[11px] tracking-[0.2em] mb-2 font-bold text-center">C H A R G E S</p>
                        <div class="flex justify-between text-[10px] font-bold border-b border-gray-300 pb-1 mb-2 uppercase text-gray-500">
                            <span class="w-1/2">Description</span><span class="w-1/4 text-center">Rate</span><span class="w-1/4 text-right">Amount</span>
                        </div>
                        
                        <div class="space-y-1">
                            <?php 
                            $vatItems = [];
                            foreach ($generatedInvoice['line_items'] as $item): 
                                if ($item['is_vat_item']) { $vatItems[] = $item; continue; }
                                $rateSuffix = $item['type'] === 'Per_KWH' ? '/kwh' : ($item['type'] === 'Per_Customer' ? '/Cust' : '');
                                $shortDesc = strlen($item['desc']) > 16 ? substr($item['desc'], 0, 14) . '..' : $item['desc'];
                            ?>
                            <div class="flex justify-between text-[11px] leading-tight">
                                <span class="w-1/2 truncate pr-1"><?php echo htmlspecialchars($shortDesc); ?></span>
                                <span class="w-1/4 text-center text-gray-500"><?php echo number_format($item['rate'], 4) . $rateSuffix; ?></span>
                                <span class="w-1/4 text-right"><?php echo number_format($item['amt'], 2); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if (!empty($vatItems)): ?>
                            <p class="text-[11px] mb-2 mt-4 font-bold border-t border-dashed border-gray-300 pt-2">Vat Detail:</p>
                            <div class="space-y-1 text-gray-600 pl-1">
                                <?php foreach ($vatItems as $item): 
                                    $rateSuffix = $item['type'] === 'Per_KWH' ? '/kwh' : '';
                                ?>
                                <div class="flex justify-between text-[11px] leading-tight">
                                    <span class="w-1/2 truncate pr-1"><?php echo htmlspecialchars($item['desc']); ?></span>
                                    <span class="w-1/4 text-left">x <?php echo number_format($item['rate'], 4) . $rateSuffix; ?></span>
                                    <span class="w-1/4 text-right"><?php echo number_format($item['amt'], 2); ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="pt-3 border-t-2 border-dashed border-gray-400">
                        <div class="flex justify-between text-[11px] mb-1"><span class="text-gray-600">VATable Sales</span><span><?php echo number_format($generatedInvoice['vatable_sales'], 2); ?></span></div>
                        <div class="flex justify-between text-[11px] mb-1"><span class="text-gray-600">VAT-Exempt Sales</span><span><?php echo number_format($generatedInvoice['exempt_sales'], 2); ?></span></div>
                        <div class="flex justify-between text-[11px] font-bold mb-3"><span>TOTAL VAT (12%)</span><span><?php echo number_format($generatedInvoice['explicit_vat_total'], 2); ?></span></div>
                        
                        <div class="flex justify-between text-[11px] font-bold text-gray-900 border-t border-gray-300 pt-2 mb-2">
                            <span>CURRENT BILL AMOUNT</span>
                            <span>Php <?php echo number_format($generatedInvoice['grand_total'], 2); ?></span>
                        </div>

                        <?php if ($previous_unpaid > 0): ?>
                            <div class="bg-red-50 p-2 mb-2 rounded border border-red-100">
                                <div class="flex justify-between text-[11px] font-bold text-red-600 mb-1">
                                    <span>PREVIOUS UNPAID</span>
                                    <span><?php echo number_format($previous_unpaid, 2); ?></span>
                                </div>
                                <?php if ($previous_penalty > 0): ?>
                                    <div class="flex justify-between text-[10px] text-red-500 pl-2">
                                        <span>+ Arrears Penalty (5%)</span>
                                        <span><?php echo number_format($previous_penalty, 2); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="flex justify-between font-bold text-lg pt-2 border-t border-gray-800 text-gray-900">
                            <span>TOTAL DUE</span>
                            <span>Php <?php echo number_format($absolute_grand_total, 2); ?></span>
                        </div>
                        
                        <div class="flex justify-between text-[11px] text-red-600 font-bold mt-1">
                            <span>DUE DATE</span>
                            <span><?php echo htmlspecialchars($generatedInvoice['due_date']); ?></span>
                        </div>

                        <div class="text-center mt-4 border border-gray-200 bg-gray-50 p-2 rounded">
                            <p class="text-[9px] text-gray-800 font-bold leading-tight mb-1">LATE PAYMENT NOTICE</p>
                            <p class="text-[8px] text-gray-600 leading-tight">
                                A 5% penalty (Php <?php echo number_format($generatedInvoice['grand_total'] * 0.05, 2); ?>) will be added to your CURRENT BILL if paid after <?php echo htmlspecialchars($generatedInvoice['due_date']); ?>.
                            </p>
                        </div>

                        <p class="text-[9px] text-center text-gray-400 mt-4 pt-4 border-t border-gray-100">Reader: <?php echo strtoupper(htmlspecialchars($_SESSION['full_name'])); ?></p>
                    </div>
                </div>
            </div>

            <a href="readerman.php" class="w-full bg-gray-900 hover:bg-black text-white font-bold text-lg py-4 rounded-2xl shadow-apple transition-all flex justify-center items-center">
                Scan Next Meter
            </a>

        <?php elseif (!$clientData): ?>
            <div class="bg-white rounded-[24px] p-6 md:p-8 shadow-apple border border-gray-100 mt-2">
                <h2 class="text-xl font-bold mb-2 text-gray-900">Find Meter Record</h2>
                <p class="text-sm text-gray-500 mb-6">Enter Account No. or Meter No.</p>
                
                <form action="readerman.php" method="GET" onsubmit="showLoading()">
                    <div class="flex flex-col gap-3">
                        <input type="text" name="search_account" id="search_account" required autocomplete="off"
                            class="w-full text-2xl font-bold px-5 py-4 rounded-xl bg-gray-50 border border-gray-200 focus:outline-none focus:ring-2 focus:ring-noceco-mustard transition-all tracking-wide text-gray-900 placeholder-gray-300"
                            placeholder="e.g., 26-328-66378" autofocus>
                        
                        <button type="submit" id="searchBtn" class="w-full bg-noceco-mustard hover:bg-noceco-mustardHover text-white py-4 rounded-xl font-bold text-lg transition-all flex items-center justify-center shadow-[0_4px_14px_rgba(219,161,17,0.3)]">
                            <span id="btnText">Search Record</span>
                            <svg id="btnSpinner" class="animate-spin h-5 w-5 hidden ml-2 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                        </button>
                    </div>
                </form>
            </div>

            <!-- NEW FEATURE: Disconnected Clients Button -->
            <a href="disconnected.php" class="mt-4 w-full bg-white hover:bg-gray-50 text-gray-700 py-4 rounded-2xl font-bold text-base transition-all flex items-center justify-center shadow-apple border border-gray-200">
                <svg class="w-5 h-5 mr-2 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path>
                </svg>
                View Disconnected Clients
            </a>
            <!-- END NEW FEATURE -->

        <?php else: ?>
            <div class="bg-white rounded-t-[20px] p-5 border-b border-gray-100 shadow-apple mt-2">
                <div class="flex justify-between items-start mb-1">
                    <h3 class="text-lg font-bold text-gray-900"><?php echo htmlspecialchars($clientData['last_name'] . ', ' . $clientData['first_name']); ?></h3>
                    <a href="readerman.php" class="text-xs text-gray-400 hover:text-red-500 font-medium bg-gray-50 px-2 py-1 rounded">Cancel</a>
                </div>
                <p class="text-sm text-gray-500">Acc: <span class="font-semibold text-gray-900"><?php echo htmlspecialchars($clientData['account_no']); ?></span></p>
                <p class="text-sm text-gray-500">Meter No: <span class="font-semibold text-noceco-mustard"><?php echo htmlspecialchars($clientData['meter_no']); ?></span></p>
            </div>

            <div class="bg-white rounded-b-[20px] p-6 shadow-apple mb-8">
                <form action="readerman.php" method="POST" onsubmit="showSubmitLoading()">
                    <input type="hidden" name="action" value="submit_reading">
                    <input type="hidden" name="account_no" value="<?php echo htmlspecialchars($clientData['account_no']); ?>">
                    <input type="hidden" name="contact_number" value="<?php echo htmlspecialchars($clientData['contact_number']); ?>">
                    <input type="hidden" name="previous_reading" value="<?php echo $previousReading; ?>">
                    
                    <input type="hidden" name="fname" value="<?php echo htmlspecialchars($clientData['first_name']); ?>">
                    <input type="hidden" name="lname" value="<?php echo htmlspecialchars($clientData['last_name']); ?>">
                    <input type="hidden" name="address" value="<?php echo htmlspecialchars($clientData['address']); ?>">
                    <input type="hidden" name="consumer_type" value="<?php echo htmlspecialchars($clientData['consumer_type']); ?>">
                    <input type="hidden" name="meter_no" value="<?php echo htmlspecialchars($clientData['meter_no']); ?>">

                    <div class="mb-5 p-4 bg-gray-50 rounded-xl border border-gray-100 flex justify-between items-center">
                        <p class="text-sm font-medium text-gray-500">Previous Reading</p>
                        <p class="text-2xl font-bold text-gray-400"><?php echo number_format($previousReading); ?></p>
                    </div>

                    <div class="mb-8">
                        <label class="block text-sm font-bold text-gray-700 mb-2 uppercase tracking-widest text-center">Current Reading</label>
                        <input type="number" name="current_reading" required inputmode="numeric"
                            class="w-full text-5xl font-black text-center py-6 rounded-2xl bg-white border-2 border-noceco-mustard focus:outline-none focus:ring-4 focus:ring-noceco-mustard/20 transition-all text-gray-900 shadow-inner"
                            placeholder="0" autofocus>
                    </div>

                    <button type="submit" id="generateBtn" onclick="return confirm('Finalize reading and generate the official bill?');"
                        class="w-full bg-gray-900 hover:bg-black text-white font-bold text-lg py-5 rounded-2xl shadow-apple transition-all flex justify-center items-center gap-2">
                        <span id="generateText">Generate Official Bill</span>
                        <svg id="generateSpinner" class="animate-spin h-5 w-5 hidden ml-2 text-noceco-mustard" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </main>

    <script>
        // UX: Show loading spinner on search
        function showLoading() {
            const btn = document.getElementById('searchBtn');
            const text = document.getElementById('btnText');
            const spinner = document.getElementById('btnSpinner');
            
            if(btn && text && spinner) {
                text.textContent = 'Searching...';
                spinner.classList.remove('hidden');
                btn.classList.add('opacity-80', 'cursor-not-allowed');
            }
        }

        // UX: Show loading spinner on form submit
        function showSubmitLoading() {
            const btn = document.getElementById('generateBtn');
            const text = document.getElementById('generateText');
            const spinner = document.getElementById('generateSpinner');
            
            if(btn && text && spinner) {
                text.textContent = 'Processing...';
                spinner.classList.remove('hidden');
                btn.classList.add('opacity-80', 'cursor-not-allowed');
            }
        }
    </script>
</body>
</html>