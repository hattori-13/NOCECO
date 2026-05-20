<?php
// 1. START SESSION & INCLUDE DB
session_start();
require_once '../db/dbcon.php';

// ---------------------------------------------------------------------
// STANDARD RATES DATA (Kept to calculate dynamic AI Chatbot costs & Invoice breakdown)
// ---------------------------------------------------------------------
$standardRates = [
    ['Generation System Charge', 'Per_KWH', 6.6024, 0],
    ['Franchise/Benefit to Host', 'Per_KWH', 0.0000, 0],
    ['GRAM', 'Per_KWH', 0.0000, 0],
    ['ICERA', 'Per_KWH', 0.0000, 0],
    ['Power Act Reduction', 'Per_KWH', 0.0000, 0],
    ['Transmission Demand Charge', 'Per_KWH', 0.0000, 0],
    ['Transmission System Charge', 'Per_KWH', 1.8428, 0],
    ['System Loss Charge', 'Per_KWH', 1.0503, 0],
    ['Distribution Demand Charge', 'Per_KWH', 0.0000, 0],
    ['Distribution System Charge', 'Per_KWH', 0.5782, 0],
    ['Supply Retail Cust. Charge', 'Per_Customer', 0.0000, 0],
    ['Supply System Charge', 'Per_KWH', 0.6001, 0],
    ['Metering Retail Charge', 'Per_Customer', 5.0000, 0],
    ['Metering System Charge', 'Per_KWH', 0.4326, 0],
    ['Missionary Electrification', 'Per_KWH', 0.2763, 0],
    ['Environmental Charge', 'Per_KWH', 0.0025, 0],
    ['NPC Stranded Contract Cost', 'Per_KWH', 0.0000, 0],
    ['NPC Stranded Debts', 'Per_KWH', 0.0428, 0],
    ['UC - Fit All', 'Per_KWH', 0.2011, 0],
    ['GEA-ALL', 'Per_KWH', 0.0371, 0],
    ['REC Recovery', 'Per_KWH', 0.0181, 0],
    ['Inter Class Cross Subsidy', 'Per_KWH', 0.0000, 0],
    ['Lifeline Rate Subsidy', 'Per_KWH', 0.0100, 0],
    ['Loan Condonation Per KWH', 'Per_KWH', 0.0000, 0],
    ['Loan Condonation Per Conn', 'Per_Customer', 0.0000, 0],
    ['Power Cost Adj. Refund', 'Per_KWH', 0.0000, 0],
    ['Rein. Fund for Sus. CAPEX', 'Per_KWH', 0.2904, 0],
    ['Senior Citizen Subsidy', 'Per_KWH', 0.0001, 0],
    ['Generation VAT', 'Per_KWH', 0.8048, 0],
    ['Transmission VAT', 'Per_KWH', 0.2323, 0],
    ['DSM VAT', 'Per_KWH', 0.1992, 0]
];

// Calculate Total Rate Per KWH for the AI Assistant math
$totalRatePerKwh = 0;
foreach ($standardRates as $rate) {
    if ($rate[1] === 'Per_KWH') {
        $totalRatePerKwh += $rate[2];
    }
}

// ---------------------------------------------------------------------
// WEBHOOK RECEIVER
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['webhook']) && $_GET['webhook'] === 'paymongo') {
    $payload = file_get_contents('php://input');
    $event = json_decode($payload, true);
    
    if ($event['data']['attributes']['type'] == 'payment.paid') {
        $invoice_no = $event['data']['attributes']['data']['attributes']['description'];
        $stmt = $pdo->prepare("UPDATE billing_invoices SET status = 'Paid' WHERE invoice_no = ?");
        $stmt->execute([$invoice_no]);
    }
    http_response_code(200);
    exit();
}

// ---------------------------------------------------------------------
// SECURITY
// ---------------------------------------------------------------------
if (!isset($_SESSION['client_account']) || $_SESSION['role'] !== 'Consumer') {
    header("Location: ../index.php");
    exit();
}

$account_no = $_SESSION['client_account'];
$client_name = $_SESSION['client_name'];
$meter_no = $_SESSION['meter_no'];
$client_address = $_SESSION['address'] ?? 'Himamaylan City, Negros Occidental';

// ---------------------------------------------------------------------
// AJAX: PASSWORD UPDATE
// ---------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'update_password') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    $data = json_decode(file_get_contents('php://input'), true);
    $current_pwd = $data['current_pwd'] ?? '';
    $new_pwd     = $data['new_pwd'] ?? '';
    $confirm_pwd = $data['confirm_pwd'] ?? '';

    if (empty($current_pwd) || empty($new_pwd) || empty($confirm_pwd)) {
        echo json_encode(['success' => false, 'message' => 'All fields are required.']); exit();
    }
    if ($new_pwd !== $confirm_pwd) {
        echo json_encode(['success' => false, 'message' => 'New passwords do not match.']); exit();
    }

    $stmt = $pdo->prepare("SELECT password_hash FROM clients WHERE account_no = ?");
    $stmt->execute([$account_no]);
    $client = $stmt->fetch();

    if (!$client || !password_verify($current_pwd, $client['password_hash'])) {
        echo json_encode(['success' => false, 'message' => 'Incorrect current password.']); exit();
    }

    $new_hash = password_hash($new_pwd, PASSWORD_DEFAULT);
    $updateStmt = $pdo->prepare("UPDATE clients SET password_hash = ? WHERE account_no = ?");
    
    if ($updateStmt->execute([$new_hash, $account_no])) {
        echo json_encode(['success' => true, 'message' => 'Password updated successfully!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error. Failed to update password.']);
    }
    exit();
}

// ---------------------------------------------------------------------
// AJAX ENDPOINTS
// ---------------------------------------------------------------------
if (isset($_GET['ajax'])) {
    $tab = $_GET['ajax'];

    // --- TAB: BILLING HISTORY ---
    if ($tab === 'history') {
        $stmt = $pdo->prepare("SELECT * FROM billing_invoices WHERE account_no = ? ORDER BY reading_date DESC");
        $stmt->execute([$account_no]);
        $history = $stmt->fetchAll();
        
        $recentBills = array_slice($history, 0, 3);
        
        $comparisonHtml = '';
        if (count($recentBills) >= 2) {
            $curr = $recentBills[0];
            $prev = $recentBills[1];
            
            $kwhDiff = $curr['kwh_used'] - $prev['kwh_used'];
            $kwhPct = $prev['kwh_used'] > 0 ? round((abs($kwhDiff) / $prev['kwh_used']) * 100) : 0;
            
            if ($kwhDiff > 0) {
                $trendIcon = '<svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path></svg>';
                $trendText = "<span class='text-red-500 font-bold'>+{$kwhPct}%</span> compared to last month";
            } else if ($kwhDiff < 0) {
                $trendIcon = '<svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 17h8m0 0v-8m0 8l-8-8-4 4-6-6"></path></svg>';
                $trendText = "<span class='text-green-500 font-bold'>-{$kwhPct}%</span> compared to last month";
            } else {
                $trendIcon = '<svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 12h14"></path></svg>';
                $trendText = "<span class='text-gray-500 font-bold'>No change</span> compared to last month";
            }

            $comparisonHtml = '
            <div class="bg-gray-50 theme-card-inner border border-gray-200 rounded-2xl p-4 mb-6 flex items-center gap-4">
                <div class="w-12 h-12 rounded-full bg-white theme-card-icon shadow-sm flex items-center justify-center shrink-0 border border-gray-100">
                    '.$trendIcon.'
                </div>
                <div>
                    <h4 class="text-sm font-bold text-gray-900 theme-text-primary">Consumption Trend</h4>
                    <p class="text-xs text-gray-600 theme-text-secondary mt-0.5">You used '.$trendText.'. ('.abs($kwhDiff).' kWh diff)</p>
                </div>
            </div>';
        }

        $carouselHtml = '<div class="mb-8">';
        $carouselHtml .= '<h3 class="text-xs font-bold text-gray-400 theme-text-secondary uppercase tracking-widest mb-4">Past 3 Months Overview</h3>';
        $carouselHtml .= $comparisonHtml;
        
        $carouselHtml .= '<div class="flex overflow-x-auto snap-x snap-mandatory gap-4 pb-4 custom-scrollbar">';
        
        foreach ($recentBills as $bill) {
            $effRate = $bill['kwh_used'] > 0 ? ($bill['amount_due'] / $bill['kwh_used']) : 0;
            $statusBadge = $bill['status'] === 'Paid'
                ? '<span class="text-[10px] bg-green-500/20 text-green-300 border border-green-500/30 px-2 py-1 rounded-md uppercase font-bold tracking-widest">PAID</span>'
                : '<span class="text-[10px] bg-red-500/20 text-red-300 border border-red-500/30 px-2 py-1 rounded-md uppercase font-bold tracking-widest">UNPAID</span>';

            $carouselHtml .= '
            <div class="snap-center shrink-0 w-[85%] sm:w-[300px] app-gradient text-white rounded-[24px] p-6 relative overflow-hidden shadow-apple transition-transform duration-300 hover:scale-[1.02]">
                <div class="absolute -right-10 -top-10 w-32 h-32 bg-noceco-mustard/20 rounded-full blur-2xl"></div>
                <div class="flex justify-between items-start mb-5 relative z-10">
                    <div>
                        <p class="font-black text-xl tracking-tight text-white">'.$bill['billing_month'].'</p>
                        <p class="text-[10px] text-gray-300">INV: '.$bill['invoice_no'].'</p>
                    </div>
                    '.$statusBadge.'
                </div>
                <div class="mb-5 relative z-10">
                    <p class="text-xs text-gray-300 uppercase tracking-widest mb-1">Total Due</p>
                    <p class="text-3xl font-black text-noceco-mustard">₱'.number_format($bill['amount_due'], 2).'</p>
                </div>
                <div class="flex justify-between items-end border-t border-white/10 pt-4 relative z-10">
                    <div>
                        <p class="text-[10px] text-gray-300 uppercase tracking-widest mb-0.5">Consumption</p>
                        <p class="font-bold text-sm text-white">'.$bill['kwh_used'].' kWh</p>
                    </div>
                    <div class="text-right">
                        <p class="text-[10px] text-gray-300 uppercase tracking-widest mb-0.5">Est. Rate</p>
                        <p class="font-bold text-sm text-white">₱'.number_format($effRate, 2).' <span class="text-gray-300 font-normal text-xs">/kWh</span></p>
                    </div>
                </div>
            </div>';
        }
        $carouselHtml .= '</div></div>';

        $html = '<div class="bg-white theme-card rounded-[24px] shadow-apple border border-gray-100 overflow-hidden p-6 md:p-8">';
        
        if (empty($history)) {
            $html .= '<div class="p-8 text-center text-gray-500 theme-text-secondary text-sm">No billing records found.</div>';
        } else {
            $html .= $carouselHtml;
            $html .= '<div class="border-t border-gray-100 pt-8">';
            $html .= '<div class="flex justify-between items-center mb-4"><h3 class="text-lg font-bold text-gray-900 theme-text-primary">Complete History</h3><span class="text-xs bg-gray-100 theme-card-inner text-gray-500 theme-text-secondary px-3 py-1 rounded-full font-bold">'.count($history).' Records</span></div>';
            $html .= '<div class="divide-y divide-gray-100">';
            
            foreach ($history as $bill) {
                $badge = $bill['status'] === 'Paid' ? '<span class="text-green-500 font-bold bg-green-50 px-2 py-1 rounded text-[10px] uppercase border border-green-100 tracking-wider">PAID</span>' : '<span class="text-red-500 font-bold bg-red-50 px-2 py-1 rounded text-[10px] uppercase border border-red-100 tracking-wider">UNPAID</span>';
                
                // ADDED ONCLICK FUNCTION HERE TO TRIGGER INVOICE MODAL
                $html .= '
                <div onclick="viewInvoice(\''.$bill['invoice_no'].'\')" class="cursor-pointer py-4 flex justify-between items-center hover:bg-gray-50 hover:opacity-80 transition-colors rounded-xl px-2 -mx-2">
                    <div class="flex items-center gap-4">
                        <div class="w-10 h-10 rounded-full bg-gray-100 theme-card-icon flex items-center justify-center text-gray-400 shrink-0">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                        </div>
                        <div>
                            <p class="font-bold text-gray-900 theme-text-primary">'.$bill['billing_month'].'</p>
                            <p class="text-[11px] text-gray-500 theme-text-secondary">INV: '.$bill['invoice_no'].' • <strong>'.$bill['kwh_used'].' kWh</strong></p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="font-bold text-gray-900 theme-text-primary">Php '.number_format($bill['amount_due'], 2).'</p>
                        <p class="mt-1.5">'.$badge.'</p>
                    </div>
                </div>';
            }
            $html .= '</div></div>';
        }
        $html .= '</div>';
        
        echo $html; exit();
    }

    // --- TAB: PROFILE SETTINGS WITH THEME SELECTOR ---
    if ($tab === 'profile') {
        $stmt = $pdo->prepare("SELECT * FROM clients WHERE account_no = ?");
        $stmt->execute([$account_no]);
        $profile = $stmt->fetch();
        
        $html = '
        <div class="bg-white theme-card rounded-[24px] shadow-apple p-6 md:p-8 border border-gray-100">
            <h3 class="text-lg font-bold text-gray-900 theme-text-primary mb-6 flex items-center"><svg class="w-5 h-5 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg> Personal Information</h3>
           <form class="space-y-5" onsubmit="event.preventDefault(); alert(\'Contact number updated successfully.\');">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div><label class="block text-xs font-bold text-gray-500 theme-text-secondary mb-1">Account Number</label><input type="text" readonly value="'.$profile['account_no'].'" class="w-full p-3.5 bg-gray-50 theme-input rounded-xl text-gray-500 font-medium cursor-not-allowed"></div>
                    <div><label class="block text-xs font-bold text-gray-500 theme-text-secondary mb-1">Meter Number</label><input type="text" readonly value="'.$profile['meter_no'].'" class="w-full p-3.5 bg-gray-50 theme-input rounded-xl text-gray-500 font-medium cursor-not-allowed"></div>
                    <div><label class="block text-xs font-bold text-gray-500 theme-text-secondary mb-1">First Name</label><input type="text" readonly value="'.$profile['first_name'].'" class="w-full p-3.5 bg-gray-50 theme-input border border-gray-200 rounded-xl text-gray-500 font-medium cursor-not-allowed outline-none"></div>
                    <div><label class="block text-xs font-bold text-gray-500 theme-text-secondary mb-1">Last Name</label><input type="text" readonly value="'.$profile['last_name'].'" class="w-full p-3.5 bg-gray-50 theme-input border border-gray-200 rounded-xl text-gray-500 font-medium cursor-not-allowed outline-none"></div>
                    <div class="md:col-span-2"><label class="block text-xs font-bold text-gray-500 theme-text-secondary mb-1">Contact Number</label><input type="text" value="'.$profile['contact_number'].'" pattern="[0-9\s]+" title="Allows numbers and spaces (e.g., 0912 345 6789)" class="w-full p-3.5 bg-white theme-input border border-gray-200 rounded-xl focus:ring-2 focus:ring-noceco-mustard transition-all outline-none"></div>
                </div>
                <div class="flex justify-end mt-4"><button type="submit" class="bg-gray-100 hover:bg-gray-200 text-gray-800 px-6 py-3 rounded-xl font-bold transition-colors text-sm">Save Information</button></div>
            </form>
            
            <hr class="my-8 border-gray-100 theme-border">
            
           


            <hr class="my-8 border-gray-100 theme-border">
            
            <h3 class="text-lg font-bold text-gray-900 theme-text-primary mb-6 flex items-center"><svg class="w-5 h-5 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8V7z"></path></svg> Security & Password</h3>
            <form id="pwd_form" class="space-y-5" onsubmit="updatePassword(event)">
                <div class="space-y-4 max-w-md">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 theme-text-secondary mb-1">Current Password</label>
                        <input type="password" id="current_pwd" required class="w-full p-3.5 bg-white theme-input border border-gray-200 rounded-xl focus:ring-2 focus:ring-noceco-mustard transition-all outline-none" placeholder="Enter current password">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 theme-text-secondary mb-1">New Password</label>
                        <div class="relative">
                            <input type="password" id="new_pwd" required class="w-full p-3.5 pr-12 bg-white theme-input border border-gray-200 rounded-xl focus:ring-2 focus:ring-noceco-mustard transition-all outline-none" placeholder="Create new password">
                            <button type="button" onclick="toggleVisibility(\'new_pwd\', \'icon_new\')" class="absolute inset-y-0 right-0 pr-4 flex items-center text-gray-400 hover:text-noceco-mustard focus:outline-none">
                                <svg id="icon_new" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                            </button>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 theme-text-secondary mb-1">Confirm New Password</label>
                        <div class="relative">
                            <input type="password" id="confirm_pwd" required class="w-full p-3.5 pr-12 bg-white theme-input border border-gray-200 rounded-xl focus:ring-2 focus:ring-noceco-mustard transition-all outline-none" placeholder="Repeat new password">
                            <button type="button" onclick="toggleVisibility(\'confirm_pwd\', \'icon_confirm\')" class="absolute inset-y-0 right-0 pr-4 flex items-center text-gray-400 hover:text-noceco-mustard focus:outline-none">
                                <svg id="icon_confirm" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                            </button>
                        </div>
                    </div>
                </div>
                <div id="pwd_message" class="hidden text-sm font-bold mt-2"></div>
                <div class="mt-6">
                    <button type="submit" class="bg-gray-900 hover:bg-black text-white px-8 py-3.5 rounded-xl font-bold shadow-apple-sm transition-colors w-full md:w-auto text-sm">Update Password</button>
                </div>
            </form>
        </div>';
        echo $html; exit();
    }

    // --- TAB: GET SPECIFIC INVOICE FOR MODAL (CLICKED FROM HISTORY) ---
    if ($tab === 'get_invoice') {
        $invNo = $_GET['invoice_no'] ?? '';
        $stmtInv = $pdo->prepare("SELECT * FROM billing_invoices WHERE invoice_no = ? AND account_no = ?");
        $stmtInv->execute([$invNo, $account_no]);
        $invoiceData = $stmtInv->fetch();

        if (!$invoiceData) {
            echo '<div class="p-10 text-center text-gray-500">Invoice not found.</div>';
            exit();
        }

        // Penalty Logic check
        $penaltyAmount = 0;
        $today = strtotime(date('Y-m-d'));
        if ($invoiceData['status'] === 'Unpaid' && $today > strtotime($invoiceData['due_date'])) {
            $penaltyAmount = $invoiceData['amount_due'] * 0.05;
        } else if ($invoiceData['status'] === 'Paid' && isset($invoiceData['penalty_surcharge'])) {
            $penaltyAmount = (float)$invoiceData['penalty_surcharge'];
        }
        $amountAfterDue = $invoiceData['amount_due'] + $penaltyAmount;

        // Render Invoice Template purely in HTML
        ?>
        <div id="invoicePaper" class="bg-white shadow-2xl relative text-left flex flex-col" style="width: 794px; min-height: 1123px; padding: 40px; box-sizing: border-box; margin:0 auto;">
            <div class="flex items-center justify-between border-b-2 border-gray-800 pb-6 mb-6">
                <div class="flex items-center gap-4">
                    <img src="../images/NOCECO.png" alt="NOCECO Logo" style="width: 80px; height: 80px; object-fit: contain;" onerror="this.src='https://via.placeholder.com/80/DBA111/FFFFFF?text=N'">
                    <div>
                        <h2 class="text-3xl font-black text-gray-900 tracking-tighter">NOCECO</h2>
                        <p class="text-xs font-bold text-gray-500 uppercase tracking-widest">Negros Occidental Electric Cooperative</p>
                        <p class="text-[10px] text-gray-400">Sitio Naga, Brgy. Binicuil, Kabankalan City</p>
                    </div>
                </div>
                <div class="text-right">
                    <h1 class="text-3xl font-bold text-gray-200 uppercase tracking-widest">Statement</h1>
                    <p class="font-bold text-gray-900 mt-1">Invoice #: <?php echo htmlspecialchars($invoiceData['invoice_no']); ?></p>
                    <p class="text-sm text-gray-500">Billing Month: <?php echo htmlspecialchars($invoiceData['billing_month']); ?></p>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-8 mb-8 border border-gray-200 p-6 rounded-lg bg-gray-50/50">
                <div>
                    <p class="text-xs font-bold text-gray-400 uppercase mb-1">Billed To</p>
                    <p class="font-bold text-gray-900 text-lg"><?php echo htmlspecialchars($client_name); ?></p>
                    <p class="text-sm text-gray-600 mt-1"><?php echo htmlspecialchars($client_address); ?></p>
                </div>
                <div class="text-right">
                    <p class="text-xs font-bold text-gray-400 uppercase mb-1">Account Info</p>
                    <p class="font-bold text-gray-900">Acct No: <span class="font-normal"><?php echo htmlspecialchars($account_no); ?></span></p>
                    <p class="font-bold text-gray-900">Meter No: <span class="font-normal"><?php echo htmlspecialchars($meter_no); ?></span></p>
                    <p class="font-bold text-gray-900 mt-2">Due Date: <span class="text-red-600"><?php echo date('F d, Y', strtotime($invoiceData['due_date'])); ?></span></p>
                </div>
            </div>

            <div class="bg-gray-900 text-white p-4 flex justify-between items-center rounded-lg mb-8">
                <div>
                    <p class="text-xs text-gray-400 uppercase font-bold tracking-widest">Total Consumption</p>
                    <p class="text-2xl font-black"><?php echo htmlspecialchars($invoiceData['kwh_used']); ?> kWh</p>
                </div>
                <div class="text-right">
                    <p class="text-xs text-gray-400 uppercase font-bold tracking-widest">Amount <?php echo $invoiceData['status'] === 'Paid' ? 'Paid' : 'Due'; ?></p>
                    <p class="text-3xl font-black text-noceco-mustard">Php <?php echo number_format($invoiceData['amount_due'], 2); ?></p>
                </div>
            </div>

            <h3 class="font-bold text-gray-900 border-b pb-2 mb-4 text-sm uppercase tracking-widest">Current Charges Breakdown</h3>
            <div class="grid grid-cols-2 gap-x-12 gap-y-1 text-xs mb-8 flex-1">
                <?php
                $kwhUsed = (float)$invoiceData['kwh_used'];
                foreach($standardRates as $rate):
                    $chargeName = $rate[0];
                    $chargeType = $rate[1];
                    $chargeRate = $rate[2];
                    $lineCost = ($chargeType === 'Per_KWH') ? ($chargeRate * $kwhUsed) : $chargeRate;
                    
                    if ($lineCost > 0 || $chargeRate > 0):
                ?>
                    <div class="flex justify-between items-center border-b border-gray-100 py-1.5">
                        <span class="text-gray-600"><?php echo htmlspecialchars($chargeName); ?></span>
                        <span class="font-medium text-gray-900">₱ <?php echo number_format($lineCost, 2); ?></span>
                    </div>
                <?php
                    endif;
                endforeach;
                ?>
            </div>

            <?php if ($invoiceData['status'] === 'Unpaid'): ?>
            <div class="mt-4 border border-red-200 bg-red-50 p-4 rounded-lg text-red-800 text-sm flex items-start gap-4 mb-[80px]">
                <svg class="w-6 h-6 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                <div>
                    <p class="font-bold text-base mb-1">Late Payment Notice</p>
                    <p>A penalty charge of <strong>5%</strong> will be added if payment is made after the specified due date (<?php echo date('M d, Y', strtotime($invoiceData['due_date'])); ?>). Total amount payable will be <strong class="text-lg">Php <?php echo number_format($amountAfterDue, 2); ?></strong>.</p>
                </div>
            </div>
            <?php elseif ($penaltyAmount > 0): ?>
            <div class="mt-4 border border-gray-200 bg-gray-50 p-4 rounded-lg text-gray-800 text-sm flex items-start gap-4 mb-[80px]">
                <div>
                    <p class="font-bold text-base mb-1">Penalty Included</p>
                    <p>This bill includes a late penalty surcharge of Php <?php echo number_format($penaltyAmount, 2); ?>.</p>
                </div>
            </div>
            <?php endif; ?>

            <div class="absolute bottom-10 left-10 right-10 border-t pt-4 text-center">
                <?php if ($invoiceData['status'] === 'Paid'): ?>
                    <p class="text-xs text-green-600 font-bold mb-1">This invoice has been successfully paid.</p>
                <?php else: ?>
                    <p class="text-xs text-gray-500 font-bold mb-1">Please pay on or before the due date to avoid disconnection.</p>
                <?php endif; ?>
                <p class="text-[10px] text-gray-400">Generated securely by NOCECO Billing System on <?php echo date('Y-m-d H:i:s'); ?></p>
            </div>
        </div>
        <?php
        exit();
    }

    // --- TAB: AI CHATBOT ---
    if ($tab === 'chatbot') {
        global $totalRatePerKwh;
        $html = '
        <div class="bg-white theme-card rounded-[24px] shadow-apple border border-gray-100 flex flex-col h-[600px] overflow-hidden relative">
            <div class="bg-gray-900 p-4 text-white flex items-center justify-between shadow-sm z-10">
                <div class="flex items-center gap-3">
                    <div class="relative">
                        <div class="w-10 h-10 rounded-full bg-blue-500 flex items-center justify-center text-xl shadow-inner">🤖</div>
                        <div class="absolute bottom-0 right-0 w-3 h-3 bg-green-400 border-2 border-gray-900 rounded-full"></div>
                    </div>
                    <div>
                        <h3 class="font-bold text-sm leading-tight">Energy Assistant</h3>
                        <p class="text-[10px] text-gray-400">Powered by NOCECO AI</p>
                    </div>
                </div>
                <div class="text-[10px] bg-white/10 px-2 py-1 rounded text-gray-300">Rate: ₱'.number_format($totalRatePerKwh, 4).'/kWh</div>
            </div>
            <div id="chat-history" class="flex-1 overflow-y-auto p-6 space-y-4 theme-card-inner bg-gray-50/50 custom-scrollbar">
                <div class="flex items-start gap-3">
                    <div class="w-8 h-8 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center shrink-0 shadow-sm">🤖</div>
                    <div class="bg-white theme-input p-3.5 rounded-2xl rounded-tl-none shadow-sm border border-gray-200 text-sm text-gray-700 theme-text-primary max-w-[85%] leading-relaxed">
                        Hello '.$client_name.'! 👋 I am your NOCECO Energy Assistant. <br><br>Want to know what is driving up your bill? Tell me what appliances you use (e.g., <strong>"Aircon"</strong>, <strong>"TV"</strong>, <strong>"Ref"</strong>, or <strong>"Fan"</strong>) and I will estimate their monthly cost for you!
                    </div>
                </div>
            </div>
            <div class="p-4 bg-white theme-card border-t border-gray-100 flex gap-2">
                <input type="text" id="chat-input" class="flex-1 bg-gray-100 theme-input border border-transparent rounded-full px-5 py-3 focus:bg-white focus:border-noceco-mustard focus:ring-2 focus:ring-noceco-mustard/20 outline-none text-sm transition-all theme-text-primary" placeholder="Type an appliance name..." onkeypress="if(event.key === \'Enter\') sendChatMessage('.$totalRatePerKwh.')">
                <button onclick="sendChatMessage('.$totalRatePerKwh.')" class="w-12 h-12 bg-noceco-mustard text-white rounded-full flex items-center justify-center hover:bg-noceco-mustardHover transition-colors shadow-sm shrink-0">
                    <svg class="w-5 h-5 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path></svg>
                </button>
            </div>
        </div>';
        echo $html; exit();
    }
}

// ---------------------------------------------------------------------
// FETCH INITIAL DASHBOARD DATA & OVERALL TOTAL
// ---------------------------------------------------------------------
$stmtUnpaid = $pdo->prepare("SELECT * FROM billing_invoices WHERE account_no = ? AND status = 'Unpaid' ORDER BY reading_date DESC");
$stmtUnpaid->execute([$account_no]);
$unpaidBills = $stmtUnpaid->fetchAll();

$currentBill = !empty($unpaidBills) ? $unpaidBills[0] : null;

// OVERALL TOTAL CALCULATION (Adds all unpaid + any late penalties)
$overallTotal = 0;
$today = strtotime(date('Y-m-d'));
$hasUnpaid = false;

foreach ($unpaidBills as $bill) {
    $hasUnpaid = true;
    $amt = (float)$bill['amount_due'];
    if ($today > strtotime($bill['due_date'])) {
        $amt += ($amt * 0.05); // Include 5% penalty
    }
    $overallTotal += $amt;
}

// If no unpaid bill, fetch the latest paid bill for the invoice modal
if (!$currentBill) {
    $stmtLatest = $pdo->prepare("SELECT * FROM billing_invoices WHERE account_no = ? ORDER BY reading_date DESC LIMIT 1");
    $stmtLatest->execute([$account_no]);
    $invoiceData = $stmtLatest->fetch();
} else {
    $invoiceData = $currentBill;
}

$stmtHistory = $pdo->prepare("SELECT billing_month, kwh_used, amount_due, status, due_date FROM billing_invoices WHERE account_no = ? ORDER BY reading_date DESC LIMIT 6");
$stmtHistory->execute([$account_no]);
$billingHistory = $stmtHistory->fetchAll();

$chartLabels = [];
$chartData = [];
foreach (array_reverse($billingHistory) as $bh) {
    $chartLabels[] = substr($bh['billing_month'], 0, 3);
    $chartData[] = $bh['kwh_used'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>My NOCECO Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script>
      tailwind.config = {
        theme: {
          extend: {
            colors: { noceco: { bg: '#F5F5F7', text: '#1D1D1F', mustard: '#DBA111', mustardHover: '#B8860B' } },
            fontFamily: { sans: ['-apple-system', 'BlinkMacSystemFont', '"Segoe UI"', 'Roboto', 'sans-serif'] },
            boxShadow: { 'apple': '0 4px 24px rgba(0, 0, 0, 0.06)', 'apple-sm': '0 2px 12px rgba(0, 0, 0, 0.04)' }
          }
        }
      }
    </script>
    <style>
        ::-webkit-scrollbar { display: none; }
        .custom-scrollbar::-webkit-scrollbar { display: block; width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }
        .app-gradient { background: linear-gradient(135deg, #1D1D1F 0%, #353538 100%); }
        .nav-active { background-color: rgba(219, 161, 17, 0.1); color: #DBA111 !important; font-weight: bold; }
        
        /* THEME SYSTEM STYLES */
        body.theme-dark { background-color: #111827; color: #f9fafb; }
        body.theme-dark .theme-card { background-color: #1f2937 !important; border-color: #374151 !important; color: #fff; }
        body.theme-dark .theme-card-inner { background-color: #374151 !important; border-color: #4b5563 !important; }
        body.theme-dark .theme-text-primary { color: #f9fafb !important; }
        body.theme-dark .theme-text-secondary { color: #9ca3af !important; }
        body.theme-dark .theme-input { background-color: #374151 !important; border-color: #4b5563 !important; color: #fff !important; }
        body.theme-dark .theme-card-icon { background-color: #4b5563 !important; }

        body.theme-gradient { background: linear-gradient(135deg, #1e3a8a, #9333ea); color: #fff; }
        body.theme-gradient .theme-card { background-color: rgba(255, 255, 255, 0.1) !important; backdrop-filter: blur(10px); border-color: rgba(255,255,255,0.2) !important; color: #fff; }
        body.theme-gradient .theme-card-inner { background-color: rgba(255, 255, 255, 0.1) !important; border-color: rgba(255, 255, 255, 0.2) !important; }
        body.theme-gradient .theme-text-primary { color: #ffffff !important; }
        body.theme-gradient .theme-text-secondary { color: #e5e7eb !important; }
        body.theme-gradient .theme-input { background-color: rgba(255, 255, 255, 0.2) !important; border-color: rgba(255, 255, 255, 0.3) !important; color: #fff !important; }
        body.theme-gradient .theme-card-icon { background-color: rgba(255, 255, 255, 0.2) !important; color: #fff !important; }
    </style>
</head>
<body class="bg-noceco-bg text-noceco-text flex h-screen overflow-hidden pb-16 md:pb-0 transition-colors duration-300">

    <aside class="w-64 theme-card bg-white border-r border-gray-200 flex flex-col justify-between hidden md:flex z-20">
        <div>
            <div class="h-20 flex items-center px-8 border-b border-gray-100 theme-border">
                <div class="w-8 h-8 rounded-full bg-noceco-mustard flex items-center justify-center mr-3 shadow-apple-sm">
                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                </div>
                <span class="font-bold text-lg tracking-tight theme-text-primary">NOCECO</span>
            </div>
            <nav class="p-4 space-y-2">
                <button onclick="window.location.reload();" class="w-full flex items-center px-4 py-3 bg-noceco-bg/80 theme-card-inner text-noceco-mustard font-medium rounded-xl transition-colors text-left">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
                    My Dashboard
                </button>
                <button onclick="loadTab('history', this)" class="nav-btn w-full flex items-center px-4 py-3 text-gray-500 theme-text-secondary hover:bg-gray-50 hover:opacity-80 rounded-xl transition-colors text-left">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    Billing History
                </button>
                <button onclick="loadTab('chatbot', this)" class="nav-btn w-full flex items-center px-4 py-3 text-gray-500 theme-text-secondary hover:bg-gray-50 hover:opacity-80 rounded-xl transition-colors text-left">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path></svg>
                    AI Assistant
                </button>
                <a href="mybill.php" class="w-full flex items-center px-4 py-3 text-gray-500 theme-text-secondary hover:bg-gray-50 hover:opacity-80 rounded-xl transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path></svg>
                    Pay Online
                </a>
                <button onclick="loadTab('profile', this)" class="nav-btn w-full flex items-center px-4 py-3 text-gray-500 theme-text-secondary hover:bg-gray-50 hover:opacity-80 rounded-xl transition-colors text-left">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                    Profile Settings
                </button>
            </nav>
        </div>
        <div class="p-4 border-t border-gray-100 theme-border">
            <a href="logout.php" class="flex items-center px-4 py-3 text-red-500 hover:bg-red-50 hover:opacity-80 rounded-xl transition-colors font-medium">
                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                Logout
            </a>
        </div>
    </aside>

    <nav class="md:hidden theme-card fixed bottom-0 left-0 right-0 bg-white/90 backdrop-blur-xl border-t border-gray-200 flex justify-around items-center px-2 py-3 z-50 pb-safe shadow-[0_-4px_24px_rgba(0,0,0,0.02)]">
        <button onclick="window.location.reload();" class="flex flex-col items-center text-noceco-mustard">
            <svg class="w-6 h-6 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
            <span class="text-[10px] font-medium">Home</span>
        </button>
        <button onclick="loadTab('history', this)" class="nav-btn-mobile flex flex-col items-center text-gray-400 theme-text-secondary hover:text-noceco-mustard transition-colors">
            <svg class="w-6 h-6 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
            <span class="text-[10px] font-medium">Bills</span>
        </button>
        <button onclick="loadTab('chatbot', this)" class="nav-btn-mobile flex flex-col items-center text-gray-400 theme-text-secondary hover:text-noceco-mustard transition-colors">
            <svg class="w-6 h-6 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path></svg>
            <span class="text-[10px] font-medium">Ask AI</span>
        </button>
        <a href="mybill.php" class="flex flex-col items-center text-gray-400 theme-text-secondary hover:text-noceco-mustard transition-colors">
            <svg class="w-6 h-6 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path></svg>
            <span class="text-[10px] font-medium">Pay</span>
        </a>
        <button onclick="loadTab('profile', this)" class="nav-btn-mobile flex flex-col items-center text-gray-400 theme-text-secondary hover:text-noceco-mustard transition-colors">
            <svg class="w-6 h-6 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
            <span class="text-[10px] font-medium">Profile</span>
        </button>
    </nav>

    <main class="flex-1 overflow-y-auto w-full relative">
        <header class="px-6 py-6 md:px-10 md:py-8 flex justify-between items-center bg-white/90 md:bg-transparent sticky top-0 z-[60] backdrop-blur-md">
            <div>
                <h1 class="text-2xl font-bold tracking-tight theme-text-primary text-gray-900">Hello, <?php echo explode(' ', trim($client_name))[0]; ?>! 👋</h1>
                <p class="text-sm text-gray-500 theme-text-secondary">Account: <?php echo htmlspecialchars($account_no); ?></p>
            </div>
            <div class="flex items-center gap-3">
                <div class="relative">
                    <button onclick="document.getElementById('notification-panel').classList.toggle('hidden');" class="p-2 bg-white theme-card rounded-full shadow-apple text-gray-400 hover:text-noceco-mustard transition-colors">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
                        <?php if ($hasUnpaid): ?>
                            <span class="absolute top-1 right-1 w-2.5 h-2.5 bg-red-500 rounded-full border-2 border-white theme-border"></span>
                        <?php endif; ?>
                    </button>

                    <div id="notification-panel" class="hidden absolute right-0 mt-3 w-80 bg-white theme-card rounded-2xl shadow-2xl border border-gray-100 overflow-hidden z-[70]">
                        <div class="p-4 border-b border-gray-100 theme-border bg-gray-50 theme-card-inner"><h4 class="font-bold text-gray-900 theme-text-primary">Alerts</h4></div>
                        <div class="p-4">
                            <?php if ($hasUnpaid): ?>
                                <div class="flex items-start text-sm">
                                    <div class="w-8 h-8 rounded-full bg-red-50 text-red-500 flex items-center justify-center mr-3 shrink-0"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg></div>
                                    <div><p class="font-bold text-gray-900 theme-text-primary">Outstanding Balance Notice</p>
                                    <p class="text-gray-500 theme-text-secondary mt-1">You have a total outstanding balance of Php <?php echo number_format($overallTotal, 2); ?>. Please pay immediately to avoid penalties or disconnection.</p></div>
                                </div>
                            <?php else: ?>
                                <p class="text-gray-500 theme-text-secondary text-sm text-center">You are all caught up!</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <a href="logout.php" class="p-2 bg-white theme-card rounded-full shadow-apple text-red-400 hover:text-white hover:bg-red-500 transition-colors" title="Logout">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                </a>
            </div>
        </header>

        <div id="main_content" class="px-4 md:px-10 pb-10 max-w-5xl mx-auto space-y-6 relative z-10">
            
            <div class="app-gradient rounded-[24px] p-6 md:p-8 text-white shadow-apple relative overflow-hidden">
                <div class="absolute -right-10 -top-10 w-40 h-40 bg-noceco-mustard/20 rounded-full blur-2xl"></div>
                <div class="flex justify-between items-start mb-6 relative z-10">
                    <div>
                        <p class="text-sm text-gray-400 font-medium tracking-wide uppercase">Overall Total Balance</p>
                        <?php if ($hasUnpaid): ?>
                            <h2 class="text-4xl md:text-5xl font-black mt-1 tracking-tight text-white">Php <?php echo number_format($overallTotal, 2); ?></h2>
                        <?php else: ?>
                            <h2 class="text-4xl md:text-5xl font-black mt-1 tracking-tight text-white">Php 0.00</h2>
                        <?php endif; ?>
                    </div>
                    <?php if ($currentBill): ?>
                        <div class="text-right bg-white/10 px-4 py-2 rounded-xl backdrop-blur-sm border border-white/10">
                            <p class="text-[10px] text-gray-300 uppercase font-bold tracking-wider mb-1">Due Date</p>
                            <p class="text-sm font-bold text-white"><?php echo date('M d, Y', strtotime($currentBill['due_date'])); ?></p>
                        </div>
                    <?php else: ?>
                        <span class="px-3 py-1 bg-green-500/20 text-green-400 text-xs font-bold rounded-full border border-green-500/30">Fully Paid</span>
                    <?php endif; ?>
                </div>

                <?php if ($hasUnpaid): ?>
                    <a href="mybill.php" class="block text-center w-full bg-noceco-mustard hover:bg-noceco-mustardHover text-white font-bold py-4 rounded-xl transition-all shadow-[0_4px_14px_rgba(219,161,17,0.3)] relative z-10">
                        Pay Bill Online
                    </a>
                    <p class="text-center text-xs text-gray-400 mt-3 relative z-10">Includes previous unpaid bills & applicable penalties.</p>
                <?php else: ?>
                    <div class="w-full bg-white/10 text-white font-medium py-4 rounded-xl text-center border border-white/20 relative z-10">
                        You have no pending bills.
                    </div>
                <?php endif; ?>
            </div>

            <div class="bg-white theme-card rounded-[24px] p-6 md:p-8 shadow-apple border border-gray-100 flex flex-col md:flex-row justify-between items-center gap-4">
                <div class="text-center md:text-left">
                    <h3 class="text-sm font-bold text-gray-500 theme-text-secondary uppercase tracking-widest mb-1">Latest Consumption</h3>
                    <p class="text-3xl font-black text-gray-900 theme-text-primary tracking-tight">
                        <?php echo $invoiceData ? $invoiceData['kwh_used'] : '0'; ?>
                        <span class="text-sm font-bold text-gray-400 theme-text-secondary">kWh</span>
                    </p>
                </div>
                <button onclick="viewInvoice('<?php echo $invoiceData ? $invoiceData['invoice_no'] : ''; ?>')" class="w-full md:w-auto bg-gray-900 hover:bg-black text-white px-8 py-3.5 rounded-xl font-bold transition-colors shadow-apple-sm text-sm border border-transparent theme-border">
                    View Monthly Invoice
                </button>
            </div>

            <div class="bg-white theme-card rounded-[24px] p-6 shadow-apple border border-gray-100">
                <h3 class="text-sm font-bold text-gray-500 theme-text-secondary uppercase tracking-widest mb-4">6-Month Consumption Trend</h3>
                <div class="relative h-48 w-full">
                    <canvas id="kwhChart"></canvas>
                </div>
            </div>

        </div>
    </main>

    <div id="invoiceModal" class="hidden fixed inset-0 z-[100] bg-black/80 backdrop-blur-sm flex justify-center items-start overflow-x-hidden overflow-y-auto pt-4 md:pt-10 pb-20 custom-scrollbar w-full">
        <div id="invoiceWrapper" class="w-full max-w-[850px] flex flex-col items-center">
            
            <div class="w-full flex justify-between items-center mb-4 px-4 sticky top-0 z-20">
                <button onclick="closeInvoiceModal()" class="text-white hover:text-red-400 font-bold bg-black/70 px-4 py-2 rounded-full backdrop-blur shadow-lg">
                    Close ✕
                </button>
                <button onclick="downloadHDInvoice()" id="downloadBtn" class="bg-noceco-mustard hover:bg-noceco-mustardHover text-white px-6 py-2 rounded-full font-bold shadow-lg transition-all flex items-center">
                    <svg class="w-5 h-5 mr-2 hidden md:block" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                    Download HD
                </button>
            </div>

            <div id="invoiceContainer" class="origin-top transition-transform duration-200 ease-out">
                <div id="invoicePaper" class="bg-white shadow-2xl relative flex items-center justify-center" style="width: 794px; min-height: 1123px; padding: 40px; box-sizing: border-box; margin: 0 auto;">
                    <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-noceco-mustard"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // 1. UI Theme Initializer
        function setTheme(theme) {
            localStorage.setItem('ui_theme', theme);
            applyTheme(theme);
        }
        function applyTheme(theme) {
            const body = document.body;
            body.classList.remove('theme-light', 'theme-dark', 'theme-gradient');
            body.classList.add(`theme-${theme}`);
        }
        
        // Load Theme on DOM Load
        document.addEventListener('DOMContentLoaded', () => {
            applyTheme(localStorage.getItem('ui_theme') || 'light');
            initChart();
        });

        // 2. Chart.js Initialization
        function initChart() {
            const canvas = document.getElementById('kwhChart');
            if (!canvas) return;
        
            const ctx = canvas.getContext('2d');
            const labels = <?php echo json_encode($chartLabels); ?>;
            const dataPoints = <?php echo json_encode($chartData); ?>;

            const gradient = ctx.createLinearGradient(0, 0, 0, 400);
            gradient.addColorStop(0, 'rgba(219, 161, 17, 0.4)');
            gradient.addColorStop(1, 'rgba(219, 161, 17, 0.05)');

            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'kWh Used',
                        data: dataPoints,
                        borderColor: '#DBA111',
                        backgroundColor: gradient,
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 2,
                        pointHoverRadius: 8,
                        pointBackgroundColor: '#DBA111',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        hoverBackgroundColor: '#fff',
                        hoverBorderColor: '#DBA111',
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { intersect: false, mode: 'index' },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            enabled: true,
                            backgroundColor: 'rgba(31, 41, 55, 0.9)',
                            titleFont: { size: 14, weight: 'bold' },
                            bodyFont: { size: 13 },
                            padding: 12,
                            displayColors: false,
                            callbacks: {
                                label: function(context) { return `Consumption: ${context.parsed.y} kWh`; }
                            }
                        }
                    },
                    scales: {
                        y: { display: false, beginAtZero: true },
                        x: { grid: { display: false }, ticks: { color: '#9ca3af', font: { size: 11 } } }
                    }
                }
            });
        }

        // 3. AJAX Tab Navigation
        function loadTab(tabName, element) {
            document.querySelectorAll('.nav-btn, .nav-btn-mobile').forEach(btn => {
                btn.classList.remove('nav-active', 'text-noceco-mustard');
                btn.classList.add('text-gray-500', 'text-gray-400');
            });
            if(element) {
                element.classList.add('nav-active', 'text-noceco-mustard');
                element.classList.remove('text-gray-500', 'text-gray-400');
            }

            const contentArea = document.getElementById('main_content');
            contentArea.innerHTML = '<div class="flex justify-center p-10"><div class="animate-spin rounded-full h-8 w-8 border-b-2 border-noceco-mustard"></div></div>';

            fetch(`home.php?ajax=${tabName}`)
                .then(response => response.text())
                .then(html => {
                    contentArea.innerHTML = html;
                })
                .catch(err => {
                    contentArea.innerHTML = '<p class="text-red-500 text-center">Failed to load content.</p>';
                });
        }

        // 4. Password Visibility Toggle
        window.toggleVisibility = function(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (input.type === 'password') {
                input.type = 'text';
                icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.542-7a10.05 10.05 0 011.52-3.13m2.77-2.77A10.05 10.05 0 0112 5c4.478 0 8.268 2.943 9.542 7a10.05 10.05 0 01-1.52 3.13m-2.77 2.77L4 4m16 16l-3.23-3.23M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>';
            } else {
                input.type = 'password';
                icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>';
            }
        };

        // 5. Handle AJAX Password Update
        window.updatePassword = function(event) {
            event.preventDefault();
            const current_pwd = document.getElementById('current_pwd').value;
            const new_pwd = document.getElementById('new_pwd').value;
            const confirm_pwd = document.getElementById('confirm_pwd').value;
            const msgBox = document.getElementById('pwd_message');
            const submitBtn = event.target.querySelector('button[type="submit"]');

            if (new_pwd !== confirm_pwd) {
                msgBox.className = 'block text-sm font-bold text-red-500 mt-2';
                msgBox.textContent = 'New passwords do not match!'; return;
            }

            submitBtn.disabled = true; submitBtn.innerHTML = 'Updating...'; msgBox.classList.add('hidden');

            fetch('home.php?action=update_password', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ current_pwd, new_pwd, confirm_pwd })
            })
            .then(res => res.json())
            .then(data => {
                submitBtn.disabled = false; submitBtn.innerHTML = 'Update Password'; msgBox.classList.remove('hidden');
                if (data.success) {
                    msgBox.className = 'block text-sm font-bold text-green-500 mt-2';
                    msgBox.textContent = data.message;
                    document.getElementById('pwd_form').reset();
                } else {
                    msgBox.className = 'block text-sm font-bold text-red-500 mt-2';
                    msgBox.textContent = data.message;
                }
            }).catch(err => {
                submitBtn.disabled = false; submitBtn.innerHTML = 'Update Password';
                msgBox.classList.remove('hidden'); msgBox.className = 'block text-sm font-bold text-red-500 mt-2';
                msgBox.textContent = 'A connection error occurred. Please try again.';
            });
        };

        // 6. AI Chatbot Logic
        window.sendChatMessage = function(rate) {
            const input = document.getElementById('chat-input');
            const message = input.value.trim();
            if(!message) return;

            const chatHistory = document.getElementById('chat-history');
            chatHistory.innerHTML += `<div class="flex items-start gap-3 justify-end animate-fade-in"><div class="bg-gray-900 text-white p-3.5 rounded-2xl rounded-tr-none shadow-sm text-sm max-w-[85%]">${message}</div></div>`;
            input.value = ''; chatHistory.scrollTop = chatHistory.scrollHeight;

            setTimeout(() => {
                let reply = "I'm not sure about that specific item. But generally, anything that heats up or cools down uses the most electricity!";
                const lowerMsg = message.toLowerCase();
               
                if(lowerMsg.includes('aircon') || lowerMsg.includes('ac')) {
                    let cost = 1.12 * rate * 8 * 30;
                    reply = `A standard **1.5 HP Air Conditioner** consumes about 1.12 kW. At our current rate, running it for 8 hours a night costs around **Php ${cost.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})} per month**. Keep filters clean to save energy! ❄️`;
                } else if(lowerMsg.includes('ref') || lowerMsg.includes('fridge')) {
                    let cost = 0.20 * rate * 24 * 30;
                    reply = `A standard **Refrigerator** (0.20 kW) runs 24/7, costing roughly **Php ${cost.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})} per month**. Ensure the door seals are tight! 🧊`;
                } else if(lowerMsg.includes('tv') || lowerMsg.includes('television')) {
                    let cost = 0.05 * rate * 5 * 30;
                    reply = `An **LED TV** uses very little power (~0.05 kW). Watching 5 hours a day adds only about **Php ${cost.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})} per month** to your bill. 📺`;
                } else if(lowerMsg.includes('fan')) {
                    let cost = 0.06 * rate * 12 * 30;
                    reply = `An **Electric Fan** (0.06 kW) used for 12 hours a day will add approximately **Php ${cost.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}** to your monthly bill. 🌀`;
                } else if(lowerMsg.includes('iron') || lowerMsg.includes('plantsa')) {
                    let cost = 1.00 * rate * 1 * 4;
                    reply = `A **Clothes Iron** consumes a lot of power quickly (~1.00 kW). Ironing once a week for an hour costs roughly **Php ${cost.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})} per month**. Try to iron clothes in large batches! 👕`;
                } else if(lowerMsg.includes('hello') || lowerMsg.includes('hi')) {
                    reply = `Hi there! Tell me an appliance you use, and I will calculate its cost for you.`;
                }

                chatHistory.innerHTML += `<div class="flex items-start gap-3 animate-fade-in"><div class="w-8 h-8 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center shrink-0 shadow-sm">🤖</div><div class="bg-white theme-input p-3.5 rounded-2xl rounded-tl-none shadow-sm border border-gray-200 text-sm text-gray-700 theme-text-primary max-w-[85%] leading-relaxed">${reply}</div></div>`;
                chatHistory.scrollTop = chatHistory.scrollHeight;
            }, 600);
        };

        // 7. Modal Mobile Scaling, AJAX Invoice & HTML2Canvas Logic
        function scaleInvoiceForMobile() {
            const wrapper = document.getElementById('invoiceWrapper');
            const container = document.getElementById('invoiceContainer');
            if(!wrapper || !container) return;

            const targetWidth = 820;
            const screenWidth = window.innerWidth;

            if (screenWidth < targetWidth) {
                const scaleRatio = screenWidth / targetWidth;
                container.style.transform = `scale(${scaleRatio})`;
                const scaledHeight = 1123 * scaleRatio;
                wrapper.style.height = `${scaledHeight + 50}px`;
            } else {
                container.style.transform = `scale(1)`;
                wrapper.style.height = `auto`;
            }
        }

        function viewInvoice(invoiceNo) {
            if (!invoiceNo) return alert('No invoice number found.');
            
            document.getElementById('invoiceModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
            
            const container = document.getElementById('invoiceContainer');
            container.innerHTML = '<div id="invoicePaper" class="bg-white shadow-2xl relative flex items-center justify-center" style="width: 794px; min-height: 1123px; padding: 40px; box-sizing: border-box; margin:0 auto;"><div class="animate-spin rounded-full h-12 w-12 border-b-2 border-noceco-mustard"></div></div>';
            scaleInvoiceForMobile();

            fetch(`home.php?ajax=get_invoice&invoice_no=${invoiceNo}`)
                .then(response => response.text())
                .then(html => {
                    container.innerHTML = html;
                    scaleInvoiceForMobile();
                })
                .catch(err => {
                    container.innerHTML = '<div class="text-white text-center p-10">Error loading invoice data.</div>';
                });
        }

        // For the main dashboard button logic
        function openInvoiceModal() {
            viewInvoice('<?php echo $invoiceData ? $invoiceData['invoice_no'] : ''; ?>');
        }

        function closeInvoiceModal() {
            document.getElementById('invoiceModal').classList.add('hidden');
            document.body.style.overflow = 'auto';
            window.removeEventListener('resize', scaleInvoiceForMobile);
        }

        window.addEventListener('resize', scaleInvoiceForMobile);

        function downloadHDInvoice() {
            const paper = document.getElementById('invoicePaper');
            const container = document.getElementById('invoiceContainer');
            const btn = document.getElementById('downloadBtn');
            const originalText = btn.innerHTML;
           
            btn.innerHTML = 'Generating...';
            btn.disabled = true;

            const currentTransform = container.style.transform;
            container.style.transform = 'scale(1)';

            setTimeout(() => {
                html2canvas(paper, {
                    scale: 3,
                    useCORS: true,
                    backgroundColor: "#ffffff",
                    logging: false
                }).then(canvas => {
                    const link = document.createElement('a');
                    link.download = 'NOCECO_Invoice.png';
                    link.href = canvas.toDataURL('image/png', 1.0);
                    link.click();

                    btn.innerHTML = originalText;
                    btn.disabled = false;
                    container.style.transform = currentTransform;
                }).catch(err => {
                    alert("Failed to generate image. Please try again.");
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                    container.style.transform = currentTransform;
                });
            }, 100);
        }
    </script>
</body>
</html>