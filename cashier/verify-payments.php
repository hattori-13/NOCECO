<?php
session_start();
require_once '../db/dbcon.php';

// ---------------------------------------------------------------------
// SECURITY: STRICT ROLE VERIFICATION (Cashier or Admin)
// ---------------------------------------------------------------------
if (!isset($_SESSION['staff_id']) || ($_SESSION['role'] !== 'Main Administrator' && $_SESSION['role'] !== 'Cashier')) {
    header("Location: ../administrator.php");
    exit();
}

// Fetch session messages (PRG Pattern applied to prevent duplicate resubmissions)
$message = $_SESSION['msg'] ?? '';
$messageType = $_SESSION['msg_type'] ?? '';
unset($_SESSION['msg'], $_SESSION['msg_type']);

// ---------------------------------------------------------------------
// ACTION: APPROVE OR REJECT PAYMENT
// ---------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $payment_id = $_POST['payment_id'];
    $invoice_no = $_POST['invoice_no'];

    if ($_POST['action'] === 'approve') {
        try {
            $pdo->beginTransaction();

            // 1. Update Payment Status to Verified
            $stmt1 = $pdo->prepare("UPDATE payments SET status = 'Verified' WHERE payment_id = ? AND status = 'Pending'");
            $stmt1->execute([$payment_id]);

            if ($stmt1->rowCount() > 0) {
                // 2. Update Invoice Status to Paid
                $stmt2 = $pdo->prepare("UPDATE billing_invoices SET status = 'Paid' WHERE invoice_no = ?");
                $stmt2->execute([$invoice_no]);

                $pdo->commit();
                $_SESSION['msg'] = "GCash Reference verified! Invoice #$invoice_no marked as PAID.";
                $_SESSION['msg_type'] = "success";
            } else {
                $pdo->rollBack();
                $_SESSION['msg'] = "Payment already processed or not found.";
                $_SESSION['msg_type'] = "error";
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['msg'] = "Approval failed: " . $e->getMessage();
            $_SESSION['msg_type'] = "error";
        }
    }

    if ($_POST['action'] === 'reject') {
        try {
            // Mark payment as Invalid, leave Invoice as Unpaid
            $stmt = $pdo->prepare("UPDATE payments SET status = 'Invalid' WHERE payment_id = ?");
            $stmt->execute([$payment_id]);
            
            $_SESSION['msg'] = "Payment rejected. It has been marked as Invalid.";
            $_SESSION['msg_type'] = "error";
        } catch (PDOException $e) {
            $_SESSION['msg'] = "Action failed: " . $e->getMessage();
            $_SESSION['msg_type'] = "error";
        }
    }

    // POST/REDIRECT/GET: Redirect to self to kill POST data
    header("Location: verify-payments.php");
    exit();
}

// ---------------------------------------------------------------------
// FETCH PENDING PAYMENTS (Consumers who submitted via QRPH)
// ---------------------------------------------------------------------
$stmt = $pdo->query("SELECT p.*, c.first_name, c.last_name 
                     FROM payments p 
                     JOIN clients c ON p.account_no = c.account_no 
                     WHERE p.status = 'Pending' 
                     ORDER BY p.payment_date ASC");
$pendingPayments = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Payments | NOCECO POS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = {
        theme: {
          extend: {
            colors: { noceco: { bg: '#F5F5F7', text: '#1D1D1F', mustard: '#DBA111', mustardHover: '#B8860B' } },
            fontFamily: { sans: ['-apple-system', 'BlinkMacSystemFont', '"Segoe UI"', 'Roboto', 'sans-serif'] },
            boxShadow: { 'apple': '0 4px 24px rgba(0, 0, 0, 0.04)', 'apple-sm': '0 2px 8px rgba(0, 0, 0, 0.04)' }
          }
        }
      }
    </script>
</head>
<body class="bg-noceco-bg text-gray-900 flex h-screen overflow-hidden">

    <aside class="w-64 bg-white border-r border-gray-200 flex flex-col z-20">
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
        </nav>
        <div class="p-4 border-t border-gray-100">
            <a href="logout.php" class="flex items-center px-4 py-3 text-red-500 font-medium hover:bg-red-50 rounded-xl transition-colors">Logout</a>
        </div>
    </aside>

    <div class="flex-1 flex flex-col h-screen overflow-hidden relative">
        
        <header class="h-20 bg-white/80 backdrop-blur-md border-b border-gray-200 flex items-center justify-between px-8 sticky top-0 z-10">
            <div>
                <h2 class="text-lg font-bold">Payment Verification Queue</h2>
                <p class="text-xs text-gray-500">Cross-check GCash Reference Numbers before approving.</p>
            </div>
            <div class="flex items-center gap-4">
                <div class="text-right">
                    <p class="text-xs font-bold text-gray-400 uppercase">Pending Verifications</p>
                    <p class="text-lg font-black <?php echo count($pendingPayments) > 0 ? 'text-red-500 animate-pulse' : 'text-gray-900'; ?>">
                        <?php echo count($pendingPayments); ?> items
                    </p>
                </div>
                <div class="h-10 w-10 rounded-full bg-gray-100 flex items-center justify-center font-bold text-gray-700 border border-gray-200">
                    <?php echo substr($_SESSION['full_name'], 0, 1); ?>
                </div>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto p-8 space-y-8 custom-scrollbar">
            
            <?php if ($message): ?>
                <div class="p-4 rounded-xl text-sm font-bold <?php echo $messageType === 'success' ? 'bg-green-50 text-green-700 border border-green-100' : 'bg-red-50 text-red-700 border border-red-100'; ?> flex items-center gap-3 shadow-sm">
                    <?php if($messageType === 'success'): ?>
                        <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <?php else: ?>
                        <svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <?php endif; ?>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div class="bg-white rounded-[24px] shadow-apple border border-gray-200 overflow-hidden flex flex-col">
                <div class="p-6 border-b border-gray-100 bg-gray-50/50 flex justify-between items-center">
                    <h3 class="font-bold text-gray-900">Submitted GCash Transactions</h3>
                    
                    <div class="flex gap-2">
                        <input type="text" id="domSearchInput" placeholder="Search reference or name..." class="px-4 py-2 text-sm bg-white border border-gray-300 rounded-lg focus:ring-2 focus:ring-noceco-mustard outline-none transition-all">
                    </div>
                </div>

                <div class="overflow-x-auto max-h-[600px] overflow-y-auto custom-scrollbar">
                    <table class="w-full text-left text-sm border-collapse">
                        <thead class="bg-white sticky top-0 border-b border-gray-100 shadow-sm z-10">
                            <tr class="text-[10px] text-gray-400 uppercase tracking-widest font-black">
                                <th class="p-5">Date Submitted</th>
                                <th class="p-5">Consumer Info</th>
                                <th class="p-5">Invoice #</th>
                                <th class="p-5">Amount</th>
                                <th class="p-5">GCash Ref No.</th>
                                <th class="p-5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100" id="verificationBody">
                            <?php if (empty($pendingPayments)): ?>
                                <tr>
                                    <td colspan="6" class="p-20 text-center">
                                        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-green-50 mb-4">
                                            <svg class="w-8 h-8 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                        </div>
                                        <p class="text-gray-500 font-medium">All online payments have been verified.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($pendingPayments as $p): ?>
                                <tr class="hover:bg-gray-50/80 transition-colors verification-row">
                                    <td class="p-5 text-gray-500 font-medium">
                                        <?php echo date('M d, Y', strtotime($p['payment_date'])); ?>
                                        <div class="text-[10px]"><?php echo date('h:i A', strtotime($p['payment_date'])); ?></div>
                                    </td>
                                    <td class="p-5">
                                        <p class="font-bold text-gray-900"><?php echo htmlspecialchars($p['last_name'] . ', ' . $p['first_name']); ?></p>
                                        <p class="text-[10px] font-medium text-noceco-mustard tracking-wide">Acc: <?php echo $p['account_no']; ?></p>
                                    </td>
                                    <td class="p-5 font-mono font-bold text-gray-700">#<?php echo $p['invoice_no']; ?></td>
                                    <td class="p-5 font-black text-gray-900 text-lg">₱<?php echo number_format($p['amount_paid'], 2); ?></td>
                                    <td class="p-5">
                                        <span class="bg-[#007DFE]/10 text-[#007DFE] px-3 py-1.5 rounded-lg font-mono font-bold border border-[#007DFE]/20 text-sm tracking-wider">
                                            <?php echo htmlspecialchars($p['reference_no']); ?>
                                        </span>
                                    </td>
                                    <td class="p-5 text-right whitespace-nowrap space-x-2">
                                        <form action="verify-payments.php" method="POST" class="inline">
                                            <input type="hidden" name="payment_id" value="<?php echo $p['payment_id']; ?>">
                                            <input type="hidden" name="invoice_no" value="<?php echo $p['invoice_no']; ?>">
                                            
                                            <button type="submit" name="action" value="approve" onclick="return confirm('APPROVE: Did you verify this GCash Reference Number in your records?')"
                                                class="bg-green-500 hover:bg-green-600 text-white text-xs font-bold px-4 py-2.5 rounded-lg transition-all shadow-[0_4px_10px_rgba(34,197,94,0.3)]">
                                                Approve
                                            </button>
                                            
                                            <button type="submit" name="action" value="reject" onclick="return confirm('REJECT: Are you sure this payment is invalid?')"
                                                class="bg-red-50 text-red-500 hover:bg-red-500 hover:text-white text-xs font-bold px-4 py-2.5 rounded-lg transition-all border border-red-100 hover:border-red-500">
                                                Reject
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        </main>
    </div>

    <script>
        const searchInput = document.getElementById('domSearchInput');
        const tableRows = document.querySelectorAll('.verification-row');

        searchInput.addEventListener('input', function(e) {
            const filter = e.target.value.toUpperCase();
            tableRows.forEach(row => {
                const text = row.innerText.toUpperCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        });
    </script>

    <style>
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #E5E7EB; border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #D1D5DB; }
    </style>
</body>
</html>