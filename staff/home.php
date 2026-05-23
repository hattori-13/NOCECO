<?php
session_start();
require_once '../db/dbcon.php';

// ---------------------------------------------------------------------
// SECURITY: ROLE VERIFICATION
// ---------------------------------------------------------------------
if (!isset($_SESSION['staff_id']) || ($_SESSION['role'] !== 'Staff' && $_SESSION['role'] !== 'Main Administrator')) {
    header("Location: ../administrator.php");
    exit();
}

$message = $_SESSION['msg'] ?? '';
$messageType = $_SESSION['msg_type'] ?? '';
unset($_SESSION['msg'], $_SESSION['msg_type']);

// ---------------------------------------------------------------------
// ACTION: CHANGE CONSUMER PASSWORD
// ---------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $account_no = trim($_POST['account_no']);
    $new_password = $_POST['new_password'];
    
    // Encrypt the new password
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

    try {
        $stmt = $pdo->prepare("UPDATE clients SET password_hash = ? WHERE account_no = ?");
        $stmt->execute([$hashed_password, $account_no]);
        
        $_SESSION['msg'] = "Success: Password for Account $account_no updated.";
        $_SESSION['msg_type'] = "success";
    } catch (PDOException $e) {
        $_SESSION['msg'] = "Error updating password. Please try again.";
        $_SESSION['msg_type'] = "error";
    }
    
    header("Location: home.php");
    exit();
}

// ---------------------------------------------------------------------
// FETCH DATA
// ---------------------------------------------------------------------
$viewAccount = $_GET['view_account'] ?? null;
$clientBills = [];
$clientDetails = null;

if ($viewAccount) {
    // 1. Specific Account View Mode
    $stmtClient = $pdo->prepare("SELECT * FROM clients WHERE account_no = ?");
    $stmtClient->execute([$viewAccount]);
    $clientDetails = $stmtClient->fetch();

    $stmtBills = $pdo->prepare("SELECT * FROM billing_invoices WHERE account_no = ? ORDER BY due_date DESC");
    $stmtBills->execute([$viewAccount]);
    $clientBills = $stmtBills->fetchAll();
} else {
    // 2. Masterlist Mode
    $stmtAll = $pdo->query("SELECT * FROM clients ORDER BY created_at DESC");
    $allClients = $stmtAll->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Staff Dashboard | NOCECO</title>
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
    <style>
        body { -webkit-tap-highlight-color: transparent; }
        .custom-scrollbar::-webkit-scrollbar { width: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #E5E7EB; border-radius: 10px; }
        
        /* STRICT PRINT CSS FOR METER STICKER */
        @media print {
            body * { visibility: hidden; }
            .print-hide { display: none !important; }
            #print-sticker-area, #print-sticker-area * { visibility: visible; }
            #print-sticker-area {
                position: absolute;
                left: 0;
                top: 0;
                width: 3.5in;
                height: 4in;
                border: 3px solid #1D1D1F;
                padding: 15px;
                background: white;
                border-radius: 12px;
                text-align: center;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
            }
        }
    </style>
</head>
<body class="bg-noceco-bg text-noceco-text flex flex-col md:flex-row h-screen overflow-hidden antialiased">

    <div id="print-sticker-area" class="hidden print:flex flex-col items-center justify-center bg-white mx-auto">
        
        <img src="../images/NOCECO.png" 
             class="w-16 h-16 object-cover rounded-full shadow-sm border-[3px] border-gray-900 mb-2" 
             alt="NOCECO Tower">
        
        <h1 class="text-3xl font-black uppercase tracking-widest text-gray-900 leading-none mb-1">NOCECO</h1>
        <p class="text-[10px] font-bold text-gray-500 uppercase tracking-widest border-b-2 border-gray-900 pb-2 mb-4 w-full text-center">Meter Identifier</p>
        
        <p id="sticker-name" class="font-bold text-lg text-gray-900 leading-tight mb-1 truncate w-full px-2">CONSUMER NAME</p>
        <p id="sticker-account" class="font-mono text-sm font-bold text-gray-800 bg-gray-100 border border-gray-200 px-3 py-1 rounded mb-4">00-000-00000</p>
        
        <img id="sticker-qr" src="" alt="QR Code" class="w-32 h-32 shadow-sm rounded-lg mb-2">
        <p class="text-[8px] text-gray-400 font-bold uppercase mt-1 tracking-widest">Scan to access E-Billing</p>
    </div>


    <aside class="w-64 bg-white border-r border-gray-200 flex-col justify-between hidden md:flex z-20 shrink-0 print-hide">
        <div>
            <div class="h-20 flex items-center px-8 border-b border-gray-100">
                <div class="w-8 h-8 rounded-full bg-noceco-mustard flex items-center justify-center mr-3 shadow-apple-sm">
                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                </div>
                <span class="font-bold text-lg tracking-tight">Staff Desk</span>
            </div>
            <nav class="p-4 space-y-1.5">
                <a href="home.php" class="flex items-center px-4 py-3 bg-noceco-bg text-noceco-mustard font-bold rounded-xl shadow-sm border border-gray-100">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                    Manage Accounts
                </a>
            </nav>
        </div>
        <div class="p-4 border-t border-gray-100">
            <a href="logout.php" class="flex items-center px-4 py-3 text-red-500 hover:bg-red-50 rounded-xl transition-colors font-medium">Logout</a>
        </div>
    </aside>

    <div class="flex-1 flex flex-col h-screen overflow-hidden relative print-hide">
        
        <header class="h-16 md:h-20 bg-white border-b border-gray-200 flex items-center justify-between px-4 lg:px-8 shrink-0 z-10 sticky top-0">
            <div class="flex items-center gap-3">
                <div>
                    <h2 class="text-lg md:text-xl font-black tracking-tight text-gray-900">Customer Support</h2>
                    <p class="text-[10px] md:text-xs text-gray-500 font-bold uppercase tracking-widest hidden sm:block">Profiles & Meter Stickers</p>
                </div>
            </div>
            
            <div class="flex items-center gap-3">
                <div class="text-right hidden sm:block">
                    <p class="text-[10px] font-bold text-gray-400 uppercase">Active Desk</p>
                    <p class="text-sm font-black text-gray-900"><?php echo htmlspecialchars($_SESSION['full_name']); ?></p>
                </div>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto p-4 lg:p-8 custom-scrollbar bg-noceco-bg">
            <div class="max-w-6xl mx-auto">
                
                <?php if ($message): ?>
                    <div class="mb-6 p-4 rounded-xl text-xs font-bold <?php echo $messageType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?> shadow-sm">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <?php if ($viewAccount && $clientDetails): ?>
                    <div class="mb-6">
                        <a href="home.php" class="text-noceco-mustard font-bold text-sm hover:underline flex items-center gap-1">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                            Back to Accounts
                        </a>
                    </div>

                    <div class="bg-white rounded-[24px] shadow-apple border border-gray-200 overflow-hidden flex flex-col md:flex-row mb-8">
                        
                        <div class="p-6 md:p-8 border-b md:border-b-0 md:border-r border-gray-100 md:w-1/3 bg-gray-50 flex flex-col">
                            <div class="w-16 h-16 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center font-black text-2xl mb-4 shadow-sm border border-blue-200">
                                <?php echo substr($clientDetails['first_name'], 0, 1) . substr($clientDetails['last_name'], 0, 1); ?>
                            </div>
                            <h2 class="text-xl md:text-2xl font-black text-gray-900 leading-tight mb-1"><?php echo htmlspecialchars($clientDetails['last_name'] . ', ' . $clientDetails['first_name']); ?></h2>
                            <p class="font-mono text-sm font-bold text-noceco-mustard mb-6 bg-yellow-50 inline-block px-3 py-1 rounded-lg border border-yellow-100 self-start">ACC: <?php echo htmlspecialchars($clientDetails['account_no']); ?></p>
                            
                            <div class="space-y-4 text-sm mb-8 flex-1">
                                <div><span class="block text-[10px] text-gray-400 font-bold uppercase tracking-widest">ID Number</span><span class="font-mono font-bold text-gray-900"><?php echo htmlspecialchars($clientDetails['id_number']); ?></span></div>
                                <div><span class="block text-[10px] text-gray-400 font-bold uppercase tracking-widest">Meter No</span><span class="font-mono font-bold text-gray-900"><?php echo htmlspecialchars($clientDetails['meter_no']); ?></span></div>
                                <div><span class="block text-[10px] text-gray-400 font-bold uppercase tracking-widest">Address</span><span class="font-medium text-gray-700"><?php echo htmlspecialchars($clientDetails['address']); ?></span></div>
                                <div><span class="block text-[10px] text-gray-400 font-bold uppercase tracking-widest mb-1">Grid Status</span>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold <?php echo $clientDetails['status'] === 'Connected' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                                        <?php echo strtoupper($clientDetails['status']); ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="space-y-3 mt-auto">
                                <button onclick="openPasswordModal('<?php echo htmlspecialchars($clientDetails['account_no']); ?>', '<?php echo addslashes($clientDetails['first_name'] . ' ' . $clientDetails['last_name']); ?>')" 
                                        class="w-full bg-white hover:bg-gray-50 text-gray-700 border border-gray-200 font-bold py-3 rounded-xl shadow-sm transition-all text-sm">
                                    Reset Password
                                </button>
                                <button onclick="triggerPrintSticker('<?php echo addslashes($clientDetails['first_name'] . ' ' . $clientDetails['last_name']); ?>', '<?php echo addslashes($clientDetails['account_no']); ?>')" 
                                        class="w-full bg-gray-900 hover:bg-black text-white font-bold py-3 rounded-xl shadow-md transition-all flex justify-center items-center gap-2 text-sm">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                                    Print Sticker
                                </button>
                            </div>
                        </div>
                        
                        <div class="p-6 md:p-8 md:w-2/3 bg-white">
                            <h3 class="text-lg font-bold text-gray-900 mb-6 border-b border-gray-100 pb-2">Billing History</h3>
                            <div class="overflow-x-auto max-h-[400px] md:max-h-[600px] overflow-y-auto custom-scrollbar">
                                <table class="w-full text-left text-sm whitespace-nowrap">
                                    <thead class="bg-white sticky top-0 border-b border-gray-100 z-10 shadow-sm">
                                        <tr class="text-[10px] font-black text-gray-400 uppercase tracking-widest">
                                            <th class="p-4">Invoice No</th>
                                            <th class="p-4">Billing Month</th>
                                            <th class="p-4">Amount Due</th>
                                            <th class="p-4 text-center">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <?php if(empty($clientBills)): ?>
                                            <tr><td colspan="4" class="p-8 text-center text-gray-400 italic">No billing history found.</td></tr>
                                        <?php else: foreach($clientBills as $bill): ?>
                                            <tr class="hover:bg-gray-50 transition-colors">
                                                <td class="p-4 font-mono font-bold text-gray-700">#<?php echo htmlspecialchars($bill['invoice_no']); ?></td>
                                                <td class="p-4 text-gray-600 font-medium"><?php echo htmlspecialchars($bill['billing_month']); ?></td>
                                                <td class="p-4 font-black text-gray-900">₱<?php echo number_format($bill['amount_due'], 2); ?></td>
                                                <td class="p-4 text-center">
                                                    <?php if($bill['status'] == 'Paid'): ?>
                                                        <span class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-widest">Paid</span>
                                                    <?php else: ?>
                                                        <span class="bg-red-100 text-red-600 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-widest">Unpaid</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                <?php else: ?>
                    <div class="bg-white p-2 rounded-xl shadow-sm border border-gray-200 mb-6 flex items-center sticky top-0 z-20">
                        <svg class="w-5 h-5 text-gray-400 ml-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                        <input type="text" id="domSearchInput" placeholder="Search accounts, names, or meters..." 
                               class="w-full px-3 py-2 text-sm bg-transparent border-none focus:ring-0 outline-none">
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php foreach($allClients as $c): ?>
                            <div class="client-card bg-white rounded-[20px] shadow-apple-sm border border-gray-100 p-5 flex flex-col hover:shadow-apple transition-shadow relative overflow-hidden">
                                <div class="absolute top-0 left-0 w-1 h-full <?php echo $c['status'] === 'Connected' ? 'bg-green-500' : 'bg-red-500'; ?>"></div>
                                
                                <div class="flex justify-between items-start mb-3">
                                    <div>
                                        <h3 class="font-bold text-base text-gray-900 leading-tight line-clamp-1"><?php echo htmlspecialchars($c['last_name'] . ', ' . $c['first_name']); ?></h3>
                                        <p class="font-mono text-[11px] font-bold text-noceco-mustard mt-1 bg-yellow-50 inline-block px-2 py-0.5 rounded border border-yellow-100"><?php echo htmlspecialchars($c['account_no']); ?></p>
                                    </div>
                                    <span class="text-[9px] font-black uppercase tracking-widest px-2 py-1 rounded shrink-0 <?php echo $c['status'] === 'Connected' ? 'bg-green-50 text-green-600' : 'bg-red-50 text-red-600'; ?>">
                                        <?php echo $c['status']; ?>
                                    </span>
                                </div>
                                
                                <div class="text-xs text-gray-500 mb-5 flex-1 space-y-1">
                                    <p><strong class="text-gray-400 uppercase tracking-widest text-[9px]">Meter:</strong> <span class="font-mono font-bold text-gray-700"><?php echo htmlspecialchars($c['meter_no']); ?></span></p>
                                    <p class="line-clamp-2 leading-relaxed"><svg class="w-3 h-3 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path></svg><?php echo htmlspecialchars($c['address']); ?></p>
                                </div>

                                <div class="grid grid-cols-2 gap-2 border-t border-gray-100 pt-3 mt-auto">
                                    <a href="home.php?view_account=<?php echo htmlspecialchars($c['account_no']); ?>" class="bg-gray-50 hover:bg-gray-100 text-gray-700 text-center py-2 rounded-lg font-bold text-[11px] transition-colors border border-gray-200 uppercase tracking-wide">
                                        View Profile
                                    </a>
                                    <button onclick="openPasswordModal('<?php echo htmlspecialchars($c['account_no']); ?>', '<?php echo addslashes($c['first_name'] . ' ' . $c['last_name']); ?>')" class="bg-white hover:bg-yellow-50 text-noceco-mustard text-center py-2 rounded-lg font-bold text-[11px] transition-colors border border-yellow-100 uppercase tracking-wide">
                                        Reset Pass
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            </div>
        </main>
    </div>

    <div id="passwordModal" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-sm rounded-[24px] shadow-2xl overflow-hidden transform transition-all">
            <div class="p-5 border-b border-gray-100 bg-gray-50 flex justify-between items-center">
                <h3 class="font-black text-gray-900 text-lg">Reset Password</h3>
                <button type="button" onclick="closePasswordModal()" class="text-gray-400 hover:text-gray-900 bg-white border border-gray-200 rounded-full p-1 shadow-sm">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <form action="home.php" method="POST" class="p-6 space-y-4">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="account_no" id="modal_account_no" value="">
                
                <div class="bg-blue-50 border border-blue-100 p-4 rounded-xl text-sm text-blue-800">
                    Modifying security credentials for:<br>
                    <strong id="modal_consumer_name" class="text-base font-black text-blue-900 mt-1 block"></strong>
                </div>

                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-2">New Security Password</label>
                    <input type="text" name="new_password" required minlength="5" placeholder="Enter new password"
                           class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-noceco-mustard outline-none transition-all font-mono font-bold text-gray-900">
                </div>

                <div class="pt-4 flex gap-3">
                    <button type="button" onclick="closePasswordModal()" class="w-1/2 bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold py-3 rounded-xl transition-colors text-sm">Cancel</button>
                    <button type="submit" onclick="return confirm('Confirm security update?')" class="w-1/2 bg-gray-900 hover:bg-black text-white font-bold py-3 rounded-xl shadow-md transition-colors text-sm">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // DOM Search Filter for Cards
        document.getElementById('domSearchInput')?.addEventListener('input', function(e) {
            const filter = e.target.value.toUpperCase();
            const cards = document.querySelectorAll('.client-card');
            
            cards.forEach(card => {
                const text = card.innerText.toUpperCase();
                if (text.includes(filter)) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        });

        // Password Modal Logic
        function openPasswordModal(accountNo, consumerName) {
            document.getElementById('modal_account_no').value = accountNo;
            document.getElementById('modal_consumer_name').innerText = consumerName;
            document.getElementById('passwordModal').classList.remove('hidden');
        }

        function closePasswordModal() {
            document.getElementById('passwordModal').classList.add('hidden');
        }

        // Print Sticker Logic (Using API per instructions)
        function triggerPrintSticker(name, account) {
            document.getElementById('sticker-name').innerText = name;
            document.getElementById('sticker-account').innerText = account;
            
            // Set the QR Code image URL via API
            document.getElementById('sticker-qr').src = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' + encodeURIComponent(account);
            
            // 500ms delay ensures the QR code finishes downloading before the print dialog opens
            setTimeout(() => {
                window.print();
            }, 500); 
        }
    </script>
</body>
</html>