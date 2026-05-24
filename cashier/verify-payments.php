<?php
session_start();
require_once '../db/dbcon.php';

// ---------------------------------------------------------------------
// 1. SET MANILA TIMEZONE & WEEKEND LOGIC
// ---------------------------------------------------------------------
date_default_timezone_set('Asia/Manila');

function getEffectiveDueDate($dateStr) {
    $ts = strtotime($dateStr);
    $dow = date('N', $ts); 
    if ($dow == 6) {
        return strtotime('+2 days', $ts); 
    } elseif ($dow == 7) {
        return strtotime('+1 day', $ts);  
    }
    return $ts; 
}

// ---------------------------------------------------------------------
// SECURITY: ROLE VERIFICATION
// ---------------------------------------------------------------------
if (!isset($_SESSION['staff_id']) || $_SESSION['role'] !== 'Cashier') {
    header("Location: ../administrator.php");
    exit();
}

$message = $_SESSION['msg'] ?? '';
$messageType = $_SESSION['msg_type'] ?? '';
unset($_SESSION['msg'], $_SESSION['msg_type']);

// ---------------------------------------------------------------------
// ACTION: PROCESS VERIFICATION (Approve or Reject)
// ---------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'verify_payment') {
    $upload_id = $_POST['upload_id'];
    $decision = $_POST['decision']; // 'Approve' or 'Reject'
    $account_no = $_POST['account_no'];

    try {
        $pdo->beginTransaction();

        if ($decision === 'Approve') {
            $selected_indexes = $_POST['selected_indexes'] ?? [];
            $grand_total = 0;
            $processed_invoices = [];

            // If there are unpaid bills checked, process them
            if (!empty($selected_indexes)) {
                foreach ($selected_indexes as $i) {
                    $inv = $_POST['invoice_nos'][$i];
                    $base = (float)$_POST['base_amounts'][$i];
                    $pen = (float)$_POST['penalty_amounts'][$i];
                    $amt = $base + $pen;

                    // 1. Mark Invoice Paid
                    $stmtUpdate = $pdo->prepare("UPDATE billing_invoices SET status = 'Paid', penalty_surcharge = ? WHERE invoice_no = ?");
                    $stmtUpdate->execute([$pen, $inv]);

                    // 2. Insert Official Payment Record
                    $payment_id = 'ONL-' . strtoupper(uniqid());
                    $stmtPayment = $pdo->prepare("INSERT INTO payments (payment_id, invoice_no, account_no, amount_paid, payment_method, status, payment_date) VALUES (?, ?, ?, ?, 'Online Verification', 'Success', NOW())");
                    $stmtPayment->execute([$payment_id, $inv, $account_no, $amt]);

                    $grand_total += $amt;
                    $processed_invoices[] = $inv;
                }
                
                // 3. Optional: SMS Notification
                $stmtClient = $pdo->prepare("SELECT contact_number FROM clients WHERE account_no = ?");
                $stmtClient->execute([$account_no]);
                $contact = $stmtClient->fetchColumn();
                
                if ($contact) {
                    $joined_invoices = implode(', ', $processed_invoices);
                    $smsMessage = "NOCECO: Your uploaded payment proof has been VERIFIED. We applied Php " . number_format($grand_total, 2) . " to Invoice(s): $joined_invoices. Thank you!";
                    $stmtSMS = $pdo->prepare("INSERT INTO sms_logs (account_no, contact_number, message_type, message_content) VALUES (?, ?, 'Payment Success', ?)");
                    $stmtSMS->execute([$account_no, $contact, $smsMessage]);
                }
            }

            // Mark the proof as verified
            $stmtProof = $pdo->prepare("UPDATE payment_proofs SET status = 'Verified' WHERE upload_id = ?");
            $stmtProof->execute([$upload_id]);
            
            $_SESSION['msg'] = "Success! Payment proof verified and applied to " . count($selected_indexes) . " invoice(s).";
            $_SESSION['msg_type'] = "success";

        } else {
            // Reject the proof
            $stmtProof = $pdo->prepare("UPDATE payment_proofs SET status = 'Rejected' WHERE upload_id = ?");
            $stmtProof->execute([$upload_id]);
            
            $_SESSION['msg'] = "Payment Proof has been Rejected.";
            $_SESSION['msg_type'] = "success";
        }

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['msg'] = "Error: " . $e->getMessage();
        $_SESSION['msg_type'] = "error";
    }
    
    header("Location: verify-payments.php");
    exit();
}

// ---------------------------------------------------------------------
// FETCH PENDING UPLOADS & UNPAID BILLS
// ---------------------------------------------------------------------
$stmtPending = $pdo->query("SELECT * FROM payment_proofs WHERE status = 'Pending' ORDER BY upload_date ASC");
$pendingUploads = $stmtPending->fetchAll(PDO::FETCH_ASSOC);

$unpaidBills = [];
foreach ($pendingUploads as $upload) {
    $acc = $upload['account_no'];
    if (!isset($unpaidBills[$acc])) {
        $stmtBills = $pdo->prepare("SELECT invoice_no, billing_month, due_date, amount_due FROM billing_invoices WHERE account_no = ? AND status = 'Unpaid' ORDER BY due_date ASC");
        $stmtBills->execute([$acc]);
        $bills = $stmtBills->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($bills as &$b) {
            $effTs = getEffectiveDueDate($b['due_date']);
            $today = strtotime(date('Y-m-d'));
            $b['penalty'] = ($today > $effTs) ? ((float)$b['amount_due'] * 0.05) : 0;
            $b['total'] = (float)$b['amount_due'] + $b['penalty'];
        }
        $unpaidBills[$acc] = $bills;
    }
}

// ---------------------------------------------------------------------
// FETCH HISTORY & VOID MODAL DATA
// ---------------------------------------------------------------------
$stmtHistory = $pdo->query("SELECT * FROM payment_proofs WHERE status != 'Pending' ORDER BY upload_date DESC LIMIT 50");
$uploadHistory = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);

$stmtVoided = $pdo->query("SELECT p.*, c.first_name, c.last_name FROM payments p JOIN clients c ON p.account_no = c.account_no WHERE p.status IN ('Voided', '') ORDER BY p.payment_date DESC");
$voidedPayments = $stmtVoided->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Payments | NOCECO</title>
    <script src="https://cdn.tailwindcss.com"></script>
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
    </style>
</head>
<body class="bg-noceco-bg text-gray-900 flex h-screen overflow-hidden">

    <aside class="w-64 bg-white border-r border-gray-200 flex flex-col z-20 shrink-0">
        <div class="h-20 flex items-center px-8 border-b border-gray-100">
            <span class="font-bold text-xl tracking-tight text-noceco-mustard">NOCECO <span class="text-gray-900">POS</span></span>
        </div>
        <nav class="flex-1 p-4 space-y-2">
            <a href="cashier.php" class="flex items-center px-4 py-3 text-gray-500 hover:bg-gray-50 rounded-xl transition-colors">
                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                Cashier POS
            </a>
            <a href="verify-payments.php" class="flex items-center px-4 py-3 bg-noceco-bg text-noceco-mustard font-bold rounded-xl shadow-sm">
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
            <a href="../logout.php" class="flex items-center px-4 py-3 text-red-500 font-medium hover:bg-red-50 rounded-xl">Logout</a>
        </div>
    </aside>

    <div class="flex-1 flex flex-col h-screen overflow-hidden relative">
        <header class="h-20 bg-white/80 backdrop-blur-md border-b border-gray-200 flex items-center justify-between px-8 sticky top-0 z-10">
            <div>
                <h2 class="text-lg font-bold">Online Payment Verification</h2>
                <p class="text-xs text-gray-500">Cross-check consumer uploads with bank records.</p>
            </div>
            <div class="text-right">
                <p class="text-xs font-bold text-gray-400 uppercase">Pending Review</p>
                <p class="text-lg font-black text-noceco-mustard"><?php echo count($pendingUploads); ?> Tickets</p>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto p-8 custom-scrollbar">
            
            <?php if ($message): ?>
                <div class="mb-6 p-4 rounded-xl text-sm font-bold <?php echo $messageType === 'success' ? 'bg-green-50 text-green-700 border border-green-100' : 'bg-red-50 text-red-700 border border-red-100'; ?> flex items-center gap-3">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if(empty($pendingUploads)): ?>
                <div class="bg-white rounded-[24px] p-16 text-center shadow-apple border border-gray-100">
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-green-50 mb-4">
                        <svg class="w-8 h-8 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    </div>
                    <p class="text-gray-900 font-black text-xl mb-1">All Caught Up!</p>
                    <p class="text-gray-500 text-sm">No pending payment proofs await verification.</p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                    <?php foreach($pendingUploads as $upload): 
                        // Note: Because this is inside cashier folder, the DB path "uploads/img.jpg" needs "../" to resolve to root.
                        $imgSrc = '../' . htmlspecialchars($upload['image_url']);
                    ?>
                        <div class="bg-white rounded-[20px] border border-gray-200 shadow-apple overflow-hidden flex flex-col hover:shadow-lg transition-all relative">
                            <div class="h-40 bg-gray-900 relative overflow-hidden flex items-center justify-center border-b border-gray-100">
                                <img src="<?php echo $imgSrc; ?>" class="w-full h-full object-cover opacity-30 absolute blur-md">
                                <img src="<?php echo $imgSrc; ?>" class="max-w-full max-h-full object-contain relative z-10">
                            </div>
                            <div class="p-5 flex-1 flex flex-col">
                                <span class="bg-yellow-100 text-yellow-800 text-[10px] font-bold px-2 py-1 rounded uppercase tracking-widest w-max mb-3">Pending Review</span>
                                <h3 class="font-bold text-gray-900 line-clamp-1"><?php echo htmlspecialchars($upload['client_name']); ?></h3>
                                <p class="font-mono text-xs font-bold text-noceco-mustard mt-1 bg-yellow-50 inline-block px-2 py-0.5 rounded border border-yellow-100 self-start">Acc: <?php echo htmlspecialchars($upload['account_no']); ?></p>
                                <div class="mt-4 mb-2">
                                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Reference No</p>
                                    <p class="text-sm font-bold text-gray-900"><?php echo htmlspecialchars($upload['reference_number']); ?></p>
                                </div>
                                
                                <button onclick="openVerifyModal('<?php echo $upload['upload_id']; ?>', '<?php echo addslashes($upload['account_no']); ?>', '<?php echo addslashes($upload['client_name']); ?>', '<?php echo addslashes($upload['reference_number']); ?>', '<?php echo addslashes($upload['image_url']); ?>', '<?php echo date('M d, Y h:i A', strtotime($upload['upload_date'])); ?>')" 
                                        class="mt-auto w-full bg-gray-900 hover:bg-black text-white py-3 rounded-xl text-xs font-bold transition-colors shadow-md flex items-center justify-center gap-2">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                    Review Proof
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="mt-12">
                <h3 class="font-bold text-gray-900 mb-4 text-lg">Verification History</h3>
                <div class="bg-white rounded-2xl shadow-apple border border-gray-100 overflow-hidden">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-gray-50 border-b border-gray-100 text-[10px] uppercase text-gray-400 tracking-widest">
                            <tr>
                                <th class="p-4">Upload Date</th>
                                <th class="p-4">Account Details</th>
                                <th class="p-4">Reference No</th>
                                <th class="p-4">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 max-h-[300px] overflow-y-auto block custom-scrollbar" style="display: table-row-group;">
                            <?php if(empty($uploadHistory)): ?>
                                <tr><td colspan="4" class="p-8 text-center text-gray-500 italic">No historical records found.</td></tr>
                            <?php else: foreach($uploadHistory as $h): ?>
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="p-4 text-gray-600 font-medium"><?php echo date('M d, Y', strtotime($h['upload_date'])); ?><br><span class="text-[10px]"><?php echo date('h:i A', strtotime($h['upload_date'])); ?></span></td>
                                    <td class="p-4">
                                        <p class="font-bold text-gray-900"><?php echo htmlspecialchars($h['client_name']); ?></p>
                                        <p class="font-mono text-[10px] font-bold text-noceco-mustard"><?php echo htmlspecialchars($h['account_no']); ?></p>
                                    </td>
                                    <td class="p-4 font-mono font-bold text-gray-700"><?php echo htmlspecialchars($h['reference_number']); ?></td>
                                    <td class="p-4">
                                        <?php if($h['status'] === 'Verified'): ?>
                                            <span class="bg-green-100 text-green-700 px-3 py-1.5 rounded-md text-[10px] font-bold uppercase tracking-widest border border-green-200">Verified</span>
                                        <?php elseif($h['status'] === 'Rejected'): ?>
                                            <span class="bg-red-100 text-red-700 px-3 py-1.5 rounded-md text-[10px] font-bold uppercase tracking-widest border border-red-200">Rejected</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        </main>
    </div>

    <div id="verifyModal" class="fixed inset-0 z-50 bg-gray-900/80 backdrop-blur-sm hidden flex justify-center items-center p-4">
        <div class="bg-white w-full max-w-5xl rounded-[24px] shadow-2xl overflow-hidden flex flex-col md:flex-row max-h-[90vh]">
            
            <div class="md:w-1/2 bg-gray-100 relative flex items-center justify-center border-r border-gray-200 group">
                <a id="modal_img_link" href="#" target="_blank" class="absolute top-4 right-4 bg-white/80 backdrop-blur text-gray-700 px-3 py-1.5 rounded-lg text-xs font-bold shadow-sm hover:bg-white z-10 transition-colors">
                    View Full Screen ↗
                </a>
                <div class="w-full h-full p-4 overflow-auto custom-scrollbar flex items-center justify-center">
                    <img id="modal_img" src="" class="max-w-full rounded-lg shadow-sm border border-gray-200 cursor-zoom-in transition-transform">
                </div>
            </div>

            <div class="md:w-1/2 bg-white flex flex-col h-full overflow-hidden">
                <div class="p-6 border-b border-gray-100 bg-gray-50 flex justify-between items-start shrink-0">
                    <div>
                        <h3 class="font-black text-gray-900 text-xl tracking-tight leading-none mb-1">Verify Payment</h3>
                        <p id="modal_date" class="text-xs text-gray-500 font-medium"></p>
                    </div>
                    <button type="button" onclick="document.getElementById('verifyModal').classList.add('hidden');" class="text-gray-400 hover:text-gray-900 bg-white rounded-full p-1.5 shadow-sm border border-gray-200">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>

                <div class="p-6 overflow-y-auto custom-scrollbar flex-1">
                    <div class="mb-6 bg-blue-50 border border-blue-100 rounded-xl p-4">
                        <p class="text-[10px] font-bold text-blue-500 uppercase tracking-widest mb-1">Consumer Details</p>
                        <p id="modal_client" class="font-bold text-blue-900 text-base mb-2"></p>
                        <p class="text-[10px] font-bold text-blue-500 uppercase tracking-widest mb-1">Submitted Reference No.</p>
                        <p id="modal_ref" class="font-mono text-xl font-black text-blue-900 bg-white inline-block px-3 py-1 rounded shadow-sm"></p>
                    </div>

                    <h4 class="font-bold text-gray-900 text-sm mb-3">Unpaid Invoices for this Account</h4>
                    
                    <form id="verifyForm" action="verify-payments.php" method="POST">
                        <input type="hidden" name="action" value="verify_payment">
                        <input type="hidden" name="upload_id" id="modal_upload_id" value="">
                        <input type="hidden" name="account_no" id="modal_account_no" value="">
                        <input type="hidden" name="decision" id="modal_decision" value="">

                        <div id="modal_invoices" class="space-y-2 mb-6"></div>
                    </form>
                </div>

                <div class="p-6 border-t border-gray-100 bg-gray-50 shrink-0">
                    <div class="flex justify-between items-center mb-4">
                        <span class="text-xs font-bold text-gray-500 uppercase tracking-widest">Total to Apply</span>
                        <span id="modal_total_amount" class="text-2xl font-black text-noceco-mustard">₱0.00</span>
                    </div>
                    <div class="flex gap-3">
                        <button type="button" onclick="submitDecision('Reject')" class="w-1/3 bg-white hover:bg-red-50 text-red-600 border-2 border-red-100 hover:border-red-200 font-bold py-3.5 rounded-xl transition-all text-sm">
                            Reject Proof
                        </button>
                        <button type="button" id="approve_btn" onclick="submitDecision('Approve')" class="w-2/3 bg-gray-900 hover:bg-black text-white font-bold py-3.5 rounded-xl shadow-[0_4px_14px_rgba(0,0,0,0.2)] transition-all flex justify-center items-center gap-2 text-sm disabled:opacity-50 disabled:cursor-not-allowed">
                            <svg class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                            Approve & Mark Paid
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="voidModal" class="fixed inset-0 bg-gray-900/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-4xl rounded-[24px] shadow-2xl flex flex-col max-h-[85vh]">
            <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/50 rounded-t-[24px]">
                <h3 class="font-bold text-xl text-red-600">Voided Transactions</h3>
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
                                <td class="p-3"><p class="font-bold text-gray-900"><?php echo htmlspecialchars($vp['last_name'] . ', ' . $vp['first_name']); ?></p></td>
                                <td class="p-3 text-right"><p class="font-black text-gray-400 line-through">₱<?php echo number_format($vp['amount_paid'], 2); ?></p></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Load Unpaid Bills Data globally via PHP JSON encoding
        const unpaidData = <?php echo json_encode($unpaidBills); ?>;

        function openVerifyModal(uploadId, accountNo, clientName, refNo, imgUrl, dateStr) {
            document.getElementById('modal_upload_id').value = uploadId;
            document.getElementById('modal_account_no').value = accountNo;
            document.getElementById('modal_decision').value = '';
            
            document.getElementById('modal_ref').innerText = refNo;
            document.getElementById('modal_client').innerText = clientName + ' (' + accountNo + ')';
            document.getElementById('modal_date').innerText = 'Uploaded: ' + dateStr;
            
            // Note: Add root path resolution for the image since it's inside cashier folder
            const fullImgPath = '../' + imgUrl;
            document.getElementById('modal_img').src = fullImgPath;
            document.getElementById('modal_img_link').href = fullImgPath;
            
            const bills = unpaidData[accountNo] || [];
            const container = document.getElementById('modal_invoices');
            
            if (bills.length === 0) {
                container.innerHTML = '<div class="p-4 bg-green-50 border border-green-100 rounded-xl text-green-700 text-sm font-medium">No unpaid invoices found. This account may be fully paid. You can still approve this proof to clear it from the queue.</div>';
            } else {
                let html = '<div class="space-y-2">';
                bills.forEach((b, i) => {
                    let penText = b.penalty > 0 ? `<p class="text-[10px] text-red-500 font-bold">+ ₱${parseFloat(b.penalty).toFixed(2)} Late Penalty</p>` : '';
                    html += `
                    <label class="flex items-center gap-3 p-3 bg-white border-2 border-gray-100 rounded-xl cursor-pointer hover:border-noceco-mustard transition-colors">
                        <input type="checkbox" name="selected_indexes[]" value="${i}" class="w-5 h-5 text-noceco-mustard border-gray-300 rounded focus:ring-noceco-mustard" onchange="updateTotal()">
                        <input type="hidden" name="invoice_nos[${i}]" value="${b.invoice_no}">
                        <input type="hidden" name="base_amounts[${i}]" value="${b.amount_due}">
                        <input type="hidden" name="penalty_amounts[${i}]" value="${b.penalty}">
                        
                        <div class="flex-1">
                            <p class="font-bold text-gray-900">${b.billing_month} <span class="text-xs text-gray-400 font-normal">#${b.invoice_no}</span></p>
                            ${penText}
                        </div>
                        <div class="text-right font-black text-gray-900">
                            ₱${parseFloat(b.total).toFixed(2)}
                        </div>
                    </label>`;
                });
                html += '</div>';
                container.innerHTML = html;
            }
            
            updateTotal();
            document.getElementById('verifyModal').classList.remove('hidden');
        }

        function updateTotal() {
            const checkboxes = document.querySelectorAll('input[name="selected_indexes[]"]:checked');
            let total = 0;
            checkboxes.forEach(cb => {
                const index = cb.value;
                const base = parseFloat(document.querySelector(`input[name="base_amounts[${index}]"]`).value);
                const pen = parseFloat(document.querySelector(`input[name="penalty_amounts[${index}]"]`).value);
                total += (base + pen);
            });
            document.getElementById('modal_total_amount').innerText = '₱' + total.toFixed(2);
            
            // Safeguard: Disable Approve button if bills exist but none are selected
            const approveBtn = document.getElementById('approve_btn');
            const totalCheckboxes = document.querySelectorAll('input[name="selected_indexes[]"]').length;
            
            if (checkboxes.length === 0 && totalCheckboxes > 0) {
                approveBtn.disabled = true;
            } else {
                approveBtn.disabled = false;
            }
        }

        function submitDecision(decision) {
            if (decision === 'Approve') {
                const checkboxes = document.querySelectorAll('input[name="selected_indexes[]"]:checked');
                const totalCheckboxes = document.querySelectorAll('input[name="selected_indexes[]"]').length;
                if (checkboxes.length === 0 && totalCheckboxes > 0) {
                    alert("Please select at least one invoice to apply the payment to.");
                    return;
                }
                if(!confirm("Verify this payment and apply it to the selected invoices? This action is permanent.")) return;
            } else {
                if(!confirm("Are you sure you want to REJECT this payment proof?")) return;
            }
            
            document.getElementById('modal_decision').value = decision;
            document.getElementById('verifyForm').submit();
        }

        // Void Modal logic
        function openVoidModal() { document.getElementById('voidModal').classList.remove('hidden'); }
        function closeVoidModal() { document.getElementById('voidModal').classList.add('hidden'); }
    </script>
</body>
</html>