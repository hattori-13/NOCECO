<?php
session_start();
require_once '../db/dbcon.php';

// ---------------------------------------------------------------------
// SECURITY: ROLE VERIFICATION
// ---------------------------------------------------------------------
if (!isset($_SESSION['staff_id']) || ($_SESSION['role'] !== 'Meter Reader' && $_SESSION['role'] !== 'Main Administrator')) {
    header("Location: ../administrator.php");
    exit();
}

// ---------------------------------------------------------------------
// ACTION: PROCESS CUT-OFF (Change Client Status to Disconnected)
// ---------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'disconnect_client') {
    $account_no = $_POST['account_no'];
    try {
        $stmt = $pdo->prepare("UPDATE clients SET status = 'Disconnected' WHERE account_no = ?");
        $stmt->execute([$account_no]);
        
        $_SESSION['msg'] = "Success: Account $account_no has been disconnected.";
        $_SESSION['msg_type'] = "success";
    } catch (PDOException $e) {
        $_SESSION['msg'] = "Error: Could not disconnect account.";
        $_SESSION['msg_type'] = "error";
    }
    
    // PRG Pattern
    header("Location: disconnected.php");
    exit();
}

// Fetch session messages
$message = $_SESSION['msg'] ?? '';
$messageType = $_SESSION['msg_type'] ?? '';
unset($_SESSION['msg'], $_SESSION['msg_type']);

// ---------------------------------------------------------------------
// FETCH DISCONNECTION MASTERLIST (Only clients who are STILL 'Connected')
// ---------------------------------------------------------------------
try {
    $sql = "
        SELECT 
            c.first_name, 
            c.last_name, 
            c.account_no, 
            c.meter_no, 
            c.address,
            b.invoice_no, 
            b.billing_month, 
            b.amount_due, 
            b.due_date
        FROM clients c
        JOIN billing_invoices b ON c.account_no = b.account_no
        WHERE b.status = 'Unpaid' 
        AND c.status = 'Connected'
        AND CURDATE() > DATE_ADD(b.due_date, INTERVAL 7 DAY)
        ORDER BY b.due_date ASC
    ";
    
    $stmt = $pdo->query($sql);
    $disconnectionList = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Disconnection Query Error: " . $e->getMessage());
    $disconnectionList = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Disconnection List | NOCECO Readerman</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = {
        theme: {
          extend: {
            colors: { noceco: { bg: '#F5F5F7', text: '#1D1D1F', mustard: '#DBA111' } },
            fontFamily: { sans: ['-apple-system', 'BlinkMacSystemFont', '"Segoe UI"', 'Roboto', 'sans-serif'] },
            boxShadow: { 'apple': '0 4px 24px rgba(0, 0, 0, 0.04)', 'apple-sm': '0 2px 8px rgba(0, 0, 0, 0.04)' }
          }
        }
      }
    </script>
    <style>
        /* Mobile fixes */
        body { -webkit-tap-highlight-color: transparent; }
        .custom-scrollbar::-webkit-scrollbar { width: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #E5E7EB; border-radius: 10px; }
    </style>
</head>
<body class="bg-noceco-bg text-noceco-text flex flex-col h-screen overflow-hidden antialiased md:flex-row relative">

    <aside class="w-64 bg-white border-r border-gray-200 flex-col justify-between hidden md:flex z-20 shrink-0">
        <div>
            <div class="h-20 flex items-center px-8 border-b border-gray-100">
                <div class="w-8 h-8 rounded-full bg-noceco-mustard flex items-center justify-center mr-3 shadow-apple-sm">
                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                </div>
                <span class="font-bold text-lg tracking-tight">Readerman</span>
            </div>
            <nav class="p-4 space-y-1.5">
                <a href="readerman.php" class="flex items-center px-4 py-3 text-gray-500 hover:bg-gray-50 rounded-xl transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                    Input Readings
                </a>
                
                <!-- NEW: Disconnected Clients / Reconnection List -->
                <a href="reconnections.php" class="flex items-center px-4 py-3 text-gray-500 hover:bg-gray-50 rounded-xl transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                    Disconnected Clients
                </a>

                <a href="disconnected.php" class="flex items-center px-4 py-3 bg-red-50 text-red-600 font-bold rounded-xl shadow-sm border border-red-100">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                    Disconnection List
                </a>
            </nav>
        </div>
        <div class="p-4 border-t border-gray-100">
            <a href="../logout.php" class="flex items-center px-4 py-3 text-red-500 hover:bg-red-50 rounded-xl transition-colors font-medium">Logout</a>
        </div>
    </aside>

    <div class="flex-1 flex flex-col h-screen overflow-hidden relative">
        
        <header class="h-16 md:h-20 bg-white border-b border-gray-200 flex items-center justify-between px-4 lg:px-8 shrink-0 z-10 sticky top-0 shadow-sm md:shadow-none">
            <div class="flex items-center gap-3">
                <button onclick="window.location.href='dashboard.php'" class="md:hidden p-2 -ml-2 text-gray-500">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                </button>
                <div>
                    <h2 class="text-lg md:text-xl font-black tracking-tight text-gray-900">Disconnections</h2>
                    <p class="text-[10px] md:text-xs text-red-500 font-bold uppercase tracking-widest hidden sm:block">Exceeding 7-Day Grace Period</p>
                </div>
            </div>
            
            <div class="flex items-center gap-2">
                <div class="text-right hidden sm:block">
                    <p class="text-[10px] font-bold text-gray-400 uppercase">Field Officer</p>
                    <p class="text-sm font-black text-gray-900"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Meter Reader'); ?></p>
                </div>
                <div class="h-8 w-8 rounded-full bg-red-100 text-red-600 flex items-center justify-center font-bold text-sm border border-red-200">
                    <?php echo count($disconnectionList); ?>
                </div>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto p-4 lg:p-8 custom-scrollbar pb-24 md:pb-8">
            <div class="max-w-4xl mx-auto">
                
                <?php if ($message): ?>
                    <div class="mb-4 p-3 rounded-xl text-xs font-bold <?php echo $messageType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?> flex justify-between items-center shadow-sm">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <?php if (empty($disconnectionList)): ?>
                    <div class="bg-white rounded-[24px] shadow-apple p-10 text-center border border-gray-100 mt-10">
                        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-green-50 mb-4">
                            <svg class="w-8 h-8 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        </div>
                        <p class="text-gray-900 font-black text-xl mb-1">Area Cleared!</p>
                        <p class="text-gray-500 text-sm">No clients require disconnection at this time.</p>
                        <a href="dashboard.php" class="mt-6 inline-block bg-noceco-mustard text-white px-6 py-3 rounded-full font-bold text-sm">Return to Readings</a>
                    </div>
                <?php else: ?>

                    <!-- Enhanced Search Bar with Loading & Button -->
                    <div class="bg-white p-2 rounded-xl shadow-sm border border-gray-200 mb-6 flex items-center sticky top-0 z-20">
                        <!-- Default Search Icon -->
                        <svg id="searchIcon" class="w-5 h-5 text-gray-400 ml-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                        
                        <!-- Animated Loading Icon (Hidden by default) -->
                        <svg id="loadingIcon" class="w-5 h-5 text-noceco-mustard ml-3 shrink-0 animate-spin hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>

                        <input type="text" id="domSearchInput" placeholder="Search by name, account, or meter..." 
                               class="w-full px-3 py-2 text-sm bg-transparent border-none focus:ring-0 outline-none">
                        
                        <button type="button" id="searchBtn" class="bg-noceco-mustard hover:bg-yellow-600 text-white font-bold py-2 px-4 rounded-lg text-sm transition-colors shrink-0 shadow-sm ml-2">
                            Search
                        </button>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4" id="clientGrid">
                        <?php foreach ($disconnectionList as $client): 
                            $dueDate = new DateTime($client['due_date']);
                            $today = new DateTime();
                            $daysOverdue = $today->diff($dueDate)->days;
                        ?>
                            <div class="client-card bg-white rounded-[20px] shadow-apple-sm border border-red-100 overflow-hidden flex flex-col">
                                
                                <div class="bg-red-50/50 p-4 border-b border-red-100 flex justify-between items-start">
                                    <div>
                                        <p class="text-[10px] font-black text-red-600 uppercase tracking-widest mb-1 bg-red-100 px-2 py-0.5 rounded inline-block">
                                            <?php echo $daysOverdue; ?> Days Overdue
                                        </p>
                                        <h3 class="font-black text-lg text-gray-900 leading-tight">
                                            <?php echo htmlspecialchars($client['last_name'] . ', ' . $client['first_name']); ?>
                                        </h3>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Amount Due</p>
                                        <p class="text-xl font-black text-red-600 tracking-tight">₱<?php echo number_format($client['amount_due'], 2); ?></p>
                                    </div>
                                </div>

                                <div class="p-4 flex-1 space-y-3">
                                    <div class="flex items-start gap-3">
                                        <svg class="w-4 h-4 text-gray-400 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path></svg>
                                        <p class="text-sm font-medium text-gray-700 leading-snug"><?php echo htmlspecialchars($client['address']); ?></p>
                                    </div>
                                    
                                    <div class="bg-gray-50 rounded-xl p-3 grid grid-cols-2 gap-2 border border-gray-100">
                                        <div>
                                            <p class="text-[10px] font-bold text-gray-400 uppercase">Account No</p>
                                            <p class="text-sm font-mono font-bold text-gray-900"><?php echo htmlspecialchars($client['account_no']); ?></p>
                                        </div>
                                        <div>
                                            <p class="text-[10px] font-bold text-gray-400 uppercase">Meter No</p>
                                            <p class="text-sm font-mono font-bold text-noceco-mustard"><?php echo htmlspecialchars($client['meter_no']); ?></p>
                                        </div>
                                    </div>
                                </div>

                                <div class="p-4 border-t border-gray-100">
                                    <form id="form_<?php echo htmlspecialchars($client['account_no']); ?>" action="disconnected.php" method="POST" class="w-full">
                                        <input type="hidden" name="action" value="disconnect_client">
                                        <input type="hidden" name="account_no" value="<?php echo htmlspecialchars($client['account_no']); ?>">
                                        
                                        <!-- Trigger for Custom Modal -->
                                        <button type="button" onclick="openConfirmModal('<?php echo htmlspecialchars($client['meter_no']); ?>', 'form_<?php echo htmlspecialchars($client['account_no']); ?>')" 
                                                class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-3.5 rounded-xl shadow-[0_4px_14px_rgba(220,38,38,0.3)] transition-all flex items-center justify-center gap-2 active:scale-95">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                                            Confirm Disconnection
                                        </button>
                                    </form>
                                </div>

                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            </div>
        </main>

        <!-- Mobile Navigation Updated (3 buttons) -->
        <div class="md:hidden fixed bottom-0 left-0 right-0 bg-white border-t border-gray-200 flex justify-around items-center p-2 pb-safe z-30 shadow-[0_-4px_24px_rgba(0,0,0,0.05)]">
            <a href="dashboard.php" class="flex flex-col items-center p-2 text-gray-400 hover:text-noceco-mustard w-1/3">
                <svg class="w-6 h-6 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                <span class="text-[10px] font-bold">Readings</span>
            </a>
            
            <a href="reconnections.php" class="flex flex-col items-center p-2 text-gray-400 hover:text-noceco-mustard w-1/3">
                <svg class="w-6 h-6 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                <span class="text-[10px] font-bold">Reconnect</span>
            </a>

            <a href="disconnected.php" class="flex flex-col items-center p-2 text-red-600 w-1/3 relative">
                <div class="absolute -top-1 right-8 w-2 h-2 bg-red-500 rounded-full animate-ping"></div>
                <div class="absolute -top-1 right-8 w-2 h-2 bg-red-600 rounded-full"></div>
                <svg class="w-6 h-6 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                <span class="text-[10px] font-bold">Cut-offs</span>
            </a>
        </div>
    </div>

    <!-- CUSTOM CONFIRMATION MODAL -->
    <div id="customConfirmModal" class="fixed inset-0 z-[100] hidden items-center justify-center bg-black/40 backdrop-blur-sm px-4 opacity-0 transition-opacity duration-300">
        <div class="bg-white rounded-[24px] p-6 w-full max-w-sm shadow-2xl transform scale-95 transition-all duration-300" id="modalContent">
            <div class="mx-auto flex items-center justify-center h-14 w-14 rounded-full bg-red-100 mb-4">
                <svg class="h-7 w-7 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
            </div>
            <h3 class="text-xl font-black text-center text-gray-900 mb-2">Confirm Cut-off</h3>
            <p class="text-sm text-center text-gray-500 mb-6">Are you sure you have physically disconnected meter <span id="modalMeterDisplay" class="font-bold text-gray-900"></span>?</p>
            <div class="flex gap-3">
                <button type="button" onclick="closeConfirmModal()" class="flex-1 bg-gray-100 text-gray-700 font-bold py-3 rounded-xl hover:bg-gray-200 transition-colors">Cancel</button>
                <button type="button" id="confirmActionBtn" class="flex-1 bg-red-600 text-white font-bold py-3 rounded-xl hover:bg-red-700 transition-colors shadow-md shadow-red-500/30">Yes, Disconnect</button>
            </div>
        </div>
    </div>

    <script>
        // --- SEARCH FUNCTIONALITY WITH LOADING ICON ---
        const searchInput = document.getElementById('domSearchInput');
        const searchBtn = document.getElementById('searchBtn');
        const searchIcon = document.getElementById('searchIcon');
        const loadingIcon = document.getElementById('loadingIcon');

        function triggerSearch() {
            if(!searchInput) return;

            // Show loading state
            searchIcon.classList.add('hidden');
            loadingIcon.classList.remove('hidden');

            // Simulate processing time for UI feedback
            setTimeout(() => {
                const filter = searchInput.value.toUpperCase();
                const cards = document.querySelectorAll('.client-card');
                
                cards.forEach(card => {
                    const text = card.innerText.toUpperCase();
                    if (text.includes(filter)) {
                        card.style.display = '';
                    } else {
                        card.style.display = 'none';
                    }
                });

                // Restore default icon
                loadingIcon.classList.add('hidden');
                searchIcon.classList.remove('hidden');
            }, 400); // 400ms loading effect
        }

        searchInput?.addEventListener('keyup', function(e) {
            if(e.key === 'Enter') { triggerSearch(); }
        });
        
        searchBtn?.addEventListener('click', triggerSearch);

        // Optional: trigger immediately when input is cleared manually
        searchInput?.addEventListener('input', function(e) {
            if(e.target.value === '') { triggerSearch(); }
        });


        // --- CUSTOM CONFIRM MODAL FUNCTIONALITY ---
        let activeFormId = null;
        const modalOverlay = document.getElementById('customConfirmModal');
        const modalContent = document.getElementById('modalContent');
        const meterDisplay = document.getElementById('modalMeterDisplay');
        const confirmBtn = document.getElementById('confirmActionBtn');

        function openConfirmModal(meterNo, formId) {
            activeFormId = formId;
            meterDisplay.textContent = meterNo;
            
            // Show modal and trigger animations
            modalOverlay.classList.remove('hidden');
            modalOverlay.classList.add('flex');
            
            // Allow display block to render before applying opacity/scale
            requestAnimationFrame(() => {
                modalOverlay.classList.remove('opacity-0');
                modalContent.classList.remove('scale-95');
                modalContent.classList.add('scale-100');
            });
        }

        function closeConfirmModal() {
            modalOverlay.classList.add('opacity-0');
            modalContent.classList.remove('scale-100');
            modalContent.classList.add('scale-95');
            
            // Wait for transition before hiding
            setTimeout(() => {
                modalOverlay.classList.add('hidden');
                modalOverlay.classList.remove('flex');
                activeFormId = null;
            }, 300);
        }

        confirmBtn.addEventListener('click', function() {
            if(activeFormId) {
                // Disable button to prevent double-click
                confirmBtn.innerHTML = 'Processing...';
                confirmBtn.classList.add('opacity-70', 'cursor-not-allowed');
                
                // Submit the specific form
                document.getElementById(activeFormId).submit();
            }
        });
    </script>
</body>
</html>