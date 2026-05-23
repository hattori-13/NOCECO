<?php
// 1. START SESSION & INCLUDE DB
session_start();
require_once '../db/dbcon.php';

// ---------------------------------------------------------------------
// SECURITY: STRICT ROLE VERIFICATION (MAIN ADMIN ONLY)
// ---------------------------------------------------------------------
if (!isset($_SESSION['staff_id']) || $_SESSION['role'] !== 'Main Administrator') {
    header("Location: ../administrator.php");
    exit();
}

$message = '';
$messageType = '';

// ---------------------------------------------------------------------
// AJAX ENDPOINT: FETCH BILLING HISTORY
// ---------------------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'history' && isset($_GET['account_no'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("SELECT invoice_no, billing_month, amount_due, due_date, status FROM billing_invoices WHERE account_no = ? ORDER BY reading_date DESC");
        $stmt->execute([$_GET['account_no']]);
        $history = $stmt->fetchAll();
        echo json_encode(['status' => 'success', 'data' => $history]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit();
}

// ---------------------------------------------------------------------
// FORM PROCESSING: EDIT, TOGGLE STATUS, & DELETE
// ---------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? '';

    // ACTION: TOGGLE STATUS (Connect / Disconnect)
    if ($action === 'toggle_status') {
        $account_no = $_POST['account_no'];
        $current_status = $_POST['current_status'];
        $new_status = ($current_status === 'Connected') ? 'Disconnected' : 'Connected';

        try {
            $stmt = $pdo->prepare("UPDATE clients SET status = ? WHERE account_no = ?");
            $stmt->execute([$new_status, $account_no]);
            $message = "Account $account_no is now $new_status.";
            $messageType = "success";
        } catch (PDOException $e) {
            $message = "Status update failed: " . $e->getMessage();
            $messageType = "error";
        }
    }

    // ACTION: DELETE CONSUMER
    if ($action === 'delete') {
        $account_no = $_POST['account_no'];
        try {
            $stmt = $pdo->prepare("DELETE FROM clients WHERE account_no = ?");
            $stmt->execute([$account_no]);
            $message = "Account $account_no has been permanently deleted.";
            $messageType = "success";
        } catch (PDOException $e) {
            // Error Code 23000 is a Foreign Key Constraint violation (they have bills)
            if ($e->getCode() == 23000) {
                $message = "Integrity Lock: Cannot delete account $account_no because they have a billing history. Please Deactivate the account instead.";
            } else {
                $message = "Deletion failed: " . $e->getMessage();
            }
            $messageType = "error";
        }
    }

        // ACTION: EDIT CONSUMER
        if ($action === 'edit') {

            $account_no     = $_POST['account_no'];
            $contact_number = trim($_POST['contact_number']);
            $consumer_type  = $_POST['consumer_type'];
            $new_password   = trim($_POST['password']);

            try {

                // IF PASSWORD FIELD IS FILLED
                if (!empty($new_password)) {

                    // HASH PASSWORD
                    $hashedPassword = password_hash($new_password, PASSWORD_DEFAULT);

                    $stmt = $pdo->prepare("
                        UPDATE clients 
                        SET contact_number = ?, 
                            consumer_type = ?, 
                            password = ?
                        WHERE account_no = ?
                    ");

                    $stmt->execute([
                        $contact_number,
                        $consumer_type,
                        $hashedPassword,
                        $account_no
                    ]);

                } else {

                    // UPDATE WITHOUT CHANGING PASSWORD
                    $stmt = $pdo->prepare("
                        UPDATE clients 
                        SET contact_number = ?, 
                            consumer_type = ?
                        WHERE account_no = ?
                    ");

                    $stmt->execute([
                        $contact_number,
                        $consumer_type,
                        $account_no
                    ]);
                }

                $message = "Account $account_no successfully updated.";
                $messageType = "success";

            } catch (PDOException $e) {

                $message = "Update failed: " . $e->getMessage();
                $messageType = "error";
            }
        }
}

// ---------------------------------------------------------------------
// FETCH ALL CONSUMERS FOR THE TABLE
// ---------------------------------------------------------------------
$searchQuery = trim($_GET['search'] ?? '');
try {
    if (!empty($searchQuery)) {
        $stmt = $pdo->prepare("SELECT * FROM clients WHERE account_no LIKE ? OR last_name LIKE ? OR first_name LIKE ? ORDER BY last_name ASC");
        $term = "%$searchQuery%";
        $stmt->execute([$term, $term, $term]);
    } else {
        $stmt = $pdo->query("SELECT * FROM clients ORDER BY created_at DESC LIMIT 50");
    }
    $clients = $stmt->fetchAll();
} catch (PDOException $e) {
    $message = "Failed to load clients: " . $e->getMessage();
    $messageType = "error";
    $clients = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Consumers | NOCECO System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = {
        theme: {
          extend: {
            colors: {
              noceco: { bg: '#F5F5F7', text: '#1D1D1F', mustard: '#DBA111', mustardHover: '#B8860B' }
            },
            fontFamily: { sans: ['-apple-system', 'BlinkMacSystemFont', '"Segoe UI"', 'Roboto', 'sans-serif'] },
            boxShadow: { 'apple': '0 4px 24px rgba(0, 0, 0, 0.04)', 'apple-sm': '0 2px 8px rgba(0, 0, 0, 0.04)' }
          }
        }
      }
    </script>
</head>
<body class="bg-noceco-bg text-noceco-text flex h-screen overflow-hidden">

    <aside class="w-64 bg-white border-r border-gray-200 flex flex-col justify-between hidden md:flex z-20 relative">
        <div>
            <div class="h-20 flex items-center px-8 border-b border-gray-100">
                <div class="w-8 h-8 rounded-full bg-noceco-mustard flex items-center justify-center mr-3 shadow-[0_2px_10px_rgba(219,161,17,0.4)]">
                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                </div>
                <span class="font-bold text-lg tracking-tight">NOCECO</span>
            </div>

            <nav class="p-4 space-y-1.5">
                <a href="../admin/admin-dashboard.php" class="flex items-center px-4 py-3 text-gray-500 hover:bg-gray-50 hover:text-gray-900 rounded-xl transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                    Dashboard
                </a>
               
                <a href="../admin/register-admin.php" class="flex items-center px-4 py-3 text-gray-500 hover:bg-gray-50 hover:text-gray-900 rounded-xl transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path></svg>
                    Register Staff
                </a>

                <a href="add-consumer.php" class="flex items-center px-4 py-3 text-gray-500 hover:bg-gray-50 hover:text-gray-900 rounded-xl transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m-3-3h6m-6 0H9m-3 0h3m-3 0a3 3 0 00-3 3v1m15-4a3 3 0 013 3v1m-15 4a3 3 0 003 3h3a3 3 0 003-3m-6 0v-1m6 1v-1"></path></svg>
                    Add Consumer
                </a>

                <a href="manage-consumers.php" class="flex items-center px-4 py-3 bg-noceco-bg/80 text-noceco-mustard font-medium rounded-xl">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                    Manage Consumers
                </a>

                <a href="#" class="flex items-center px-4 py-3 text-gray-500 hover:bg-gray-50 hover:text-gray-900 rounded-xl transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    Billing Rates
                </a>
            </nav>
        </div>

        <div class="p-4 border-t border-gray-100">
            <a href="logout.php" class="flex items-center px-4 py-3 text-red-500 hover:bg-red-50 rounded-xl transition-colors font-medium">
                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                Logout
            </a>
        </div>
    </aside>

    <main class="flex-1 flex flex-col h-screen overflow-y-auto relative">
        
        <header class="h-20 bg-white/80 backdrop-blur-md border-b border-gray-200 flex items-center justify-between px-8 sticky top-0 z-10">
            <div>
                <h2 class="text-xl font-semibold tracking-tight">Database Records</h2>
                <p class="text-xs text-gray-500">Consumer Modification Portal</p>
            </div>

            <div class="flex items-center gap-4">
                <div class="flex flex-col items-end">
                    <span class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                    <span class="text-xs text-noceco-mustard font-medium"><?php echo htmlspecialchars($_SESSION['role']); ?></span>
                </div>
                <div class="h-10 w-10 rounded-full bg-gradient-to-tr from-noceco-mustard to-yellow-300 shadow-apple-sm flex items-center justify-center text-white font-bold">
                    <?php echo substr($_SESSION['full_name'], 0, 1); ?>
                </div>
            </div>
        </header>

        <div class="p-8">
            
            <?php if (!empty($message)): ?>
                <div class="mb-6 p-4 rounded-xl text-sm font-medium <?php echo $messageType === 'success' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div class="flex flex-col md:flex-row justify-between items-center mb-6 gap-4">
                <form method="GET" action="manage-consumers.php" class="relative w-full md:w-96">
                    <svg class="w-5 h-5 text-gray-400 absolute left-3 top-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($searchQuery); ?>" placeholder="Search Name or Account No..." 
                        class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-gray-200 focus:outline-none focus:ring-2 focus:ring-noceco-mustard">
                </form>
                <a href="add-consumer.php" class="bg-gray-900 hover:bg-black text-white px-5 py-2.5 rounded-xl text-sm font-semibold transition-colors shadow-apple-sm flex items-center">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                    New Consumer
                </a>
            </div>

            <div class="bg-white rounded-[20px] shadow-apple border border-gray-100/50 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-gray-50/50 border-b border-gray-100 text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                <th class="p-5">Account No</th>
                                <th class="p-5">Consumer Profile</th>
                                <th class="p-5">Meter Info</th>
                                <th class="p-5">Status</th>
                                <th class="p-5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if (empty($clients)): ?>
                                <tr>
                                    <td colspan="5" class="p-8 text-center text-gray-500">No records found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($clients as $client): ?>
                                    <tr class="hover:bg-gray-50/50 transition-colors">
                                        <td class="p-5">
                                            <span class="font-medium text-gray-900"><?php echo htmlspecialchars($client['account_no']); ?></span>
                                            <br><span class="text-xs text-gray-400">ID: <?php echo htmlspecialchars($client['id_number']); ?></span>
                                        </td>
                                        <td class="p-5">
                                            <div class="font-semibold text-gray-900"><?php echo htmlspecialchars($client['last_name'] . ', ' . $client['first_name']); ?></div>
                                            <div class="text-xs text-gray-500 truncate max-w-[200px]"><?php echo htmlspecialchars($client['address']); ?></div>
                                        </td>
                                        <td class="p-5">
                                            <span class="text-sm font-medium text-gray-700"><?php echo htmlspecialchars($client['meter_no']); ?></span>
                                            <br><span class="text-xs text-noceco-mustard"><?php echo htmlspecialchars($client['consumer_type']); ?></span>
                                        </td>
                                        <td class="p-5">
                                            <?php if ($client['status'] === 'Connected'): ?>
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-green-50 text-green-700 border border-green-100">
                                                    <span class="w-1.5 h-1.5 bg-green-500 rounded-full mr-1.5"></span>Connected
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-red-50 text-red-700 border border-red-100">
                                                    <span class="w-1.5 h-1.5 bg-red-500 rounded-full mr-1.5"></span>Disconnected
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-5 text-right space-x-2 whitespace-nowrap">
                                            
                                            <button onclick="openHistoryModal('<?php echo $client['account_no']; ?>', '<?php echo addslashes($client['first_name'] . ' ' . $client['last_name']); ?>')" 
                                                    class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-blue-50 text-blue-600 hover:bg-blue-100 transition-colors" title="Billing History">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                            </button>

                                            <button onclick="openEditModal('<?php echo $client['account_no']; ?>', '<?php echo $client['contact_number']; ?>', '<?php echo $client['consumer_type']; ?>')"
                                                    class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-gray-50 text-gray-600 hover:bg-gray-200 transition-colors" title="Edit Record">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                                            </button>

                                            <form action="manage-consumers.php" method="POST" class="inline">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="account_no" value="<?php echo htmlspecialchars($client['account_no']); ?>">
                                                <input type="hidden" name="current_status" value="<?php echo htmlspecialchars($client['status']); ?>">
                                                <button type="submit" onclick="return confirm('Toggle connection status for this account?');"
                                                        class="inline-flex items-center justify-center w-8 h-8 rounded-lg <?php echo $client['status'] === 'Connected' ? 'bg-yellow-50 text-yellow-600 hover:bg-yellow-100' : 'bg-green-50 text-green-600 hover:bg-green-100'; ?> transition-colors" title="Toggle Connection">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                                                </button>
                                            </form>

                                            <form action="manage-consumers.php" method="POST" class="inline">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="account_no" value="<?php echo htmlspecialchars($client['account_no']); ?>">
                                                <button type="submit" onclick="return confirm('WARNING: Are you sure you want to delete this record? This action cannot be undone.');"
                                                        class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-red-50 text-red-600 hover:bg-red-100 transition-colors" title="Delete Record">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
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

        </div>
    </main>

    <div id="historyModal" class="fixed inset-0 bg-gray-900/40 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4 transition-opacity">
        <div class="bg-white rounded-[24px] shadow-2xl w-full max-w-2xl overflow-hidden flex flex-col max-h-[80vh]">
            <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
                <div>
                    <h3 class="text-lg font-bold text-gray-900" id="modalClientName">Client Name</h3>
                    <p class="text-sm text-gray-500">Financial History Records</p>
                </div>
                <button onclick="closeModal('historyModal')" class="p-2 bg-gray-100 hover:bg-gray-200 rounded-full transition-colors">
                    <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div class="p-6 overflow-y-auto bg-white flex-1">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="text-xs font-semibold text-gray-500 uppercase border-b border-gray-100">
                            <th class="pb-3">Invoice / Month</th>
                            <th class="pb-3 text-right">Amount</th>
                            <th class="pb-3 text-right">Status</th>
                        </tr>
                    </thead>
                    <tbody id="historyTableBody" class="divide-y divide-gray-50">
                        </tbody>
                </table>
                <div id="historyLoading" class="text-center py-8 text-sm text-gray-400">Loading records...</div>
            </div>
        </div>
    </div>

    <div id="editModal" class="fixed inset-0 bg-gray-900/40 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4 transition-opacity">

    <div class="bg-white rounded-[24px] shadow-2xl w-full max-w-md overflow-hidden">

        <!-- HEADER -->
        <div class="p-6 border-b border-gray-100 flex justify-between items-center">

            <h3 class="text-lg font-bold text-gray-900">
                Edit Consumer Details
            </h3>

            <button onclick="closeModal('editModal')"
                class="p-2 bg-gray-100 hover:bg-gray-200 rounded-full transition-colors">

                <svg class="w-5 h-5 text-gray-600"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24">

                    <path stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M6 18L18 6M6 6l12 12">
                    </path>

                </svg>
            </button>

        </div>

        <!-- FORM -->
        <form action="manage-consumers.php" method="POST" class="p-6 space-y-4">

            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="account_no" id="edit_account_no">

            <!-- CONTACT NUMBER -->
            <div>

                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Contact Number
                </label>

                <input type="text"
                    name="contact_number"
                    id="edit_contact"
                    required
                    class="w-full px-4 py-3 rounded-xl bg-gray-50 border border-gray-200 focus:ring-2 focus:ring-noceco-mustard focus:bg-white transition-all">

            </div>

            <!-- CONSUMER TYPE -->
            <div>

                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Consumer Type
                </label>

                <select name="consumer_type"
                    id="edit_type"
                    required
                    class="w-full px-4 py-3 rounded-xl bg-gray-50 border border-gray-200 focus:ring-2 focus:ring-noceco-mustard focus:bg-white transition-all appearance-none">

                    <option value="RESIDENTIAL">Residential</option>
                    <option value="COMMERCIAL">Commercial</option>
                    <option value="INDUSTRIAL">Industrial</option>

                </select>

            </div>

            <!-- PASSWORD -->
            <div>

                <label class="block text-sm font-medium text-gray-700 mb-1">
                    New Password
                </label>

                <div class="relative">

                    <input type="password"
                        name="password"
                        id="edit_password"
                        placeholder="Leave blank to keep current password"
                        class="w-full px-4 py-3 pr-12 rounded-xl bg-gray-50 border border-gray-200 focus:ring-2 focus:ring-noceco-mustard focus:bg-white transition-all">

                    <!-- SHOW / HIDE PASSWORD -->
                    <button type="button"
                        onclick="togglePassword()"
                        class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-700">

                        <svg class="w-5 h-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24">

                            <path stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0">
                            </path>

                            <path stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M2.458 12C3.732 7.943 7.523 5 12 5
                                c4.478 0 8.268 2.943 9.542 7
                                -1.274 4.057-5.064 7-9.542 7
                                -4.477 0-8.268-2.943-9.542-7z">
                            </path>

                        </svg>

                    </button>

                </div>

                <p class="text-xs text-gray-400 mt-1">
                    Leave blank if you don't want to change the password.
                </p>

            </div>

            <!-- SUBMIT BUTTON -->
            <button type="submit"
                class="w-full mt-2 bg-noceco-mustard hover:bg-noceco-mustardHover text-white font-semibold py-3 px-4 rounded-xl transition-all shadow-[0_4px_14px_rgba(219,161,17,0.3)]">

                Save Changes

            </button>

        </form>

    </div>

</div>

<!-- PASSWORD TOGGLE SCRIPT -->
<script>

    function togglePassword() {

        const passwordInput = document.getElementById('edit_password');

        if (passwordInput.type === 'password') {

            passwordInput.type = 'text';

        } else {

            passwordInput.type = 'password';

        }
    }

</script>

    <script>
        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
        }

        function openEditModal(accountNo, contact, type) {
            document.getElementById('edit_account_no').value = accountNo;
            document.getElementById('edit_contact').value = contact;
            document.getElementById('edit_type').value = type;
            document.getElementById('editModal').classList.remove('hidden');
        }

        function openHistoryModal(accountNo, clientName) {
            document.getElementById('historyModal').classList.remove('hidden');
            document.getElementById('modalClientName').textContent = clientName;
            
            const tbody = document.getElementById('historyTableBody');
            const loader = document.getElementById('historyLoading');
            
            tbody.innerHTML = '';
            loader.classList.remove('hidden');

            // AJAX Fetch Call
            fetch(`manage-consumers.php?ajax=history&account_no=${accountNo}`)
                .then(response => response.json())
                .then(data => {
                    loader.classList.add('hidden');
                    if(data.status === 'success' && data.data.length > 0) {
                        data.data.forEach(bill => {
                            // Status Badge Logic
                            let badge = '';
                            if(bill.status === 'Paid') badge = '<span class="px-2 py-1 rounded text-xs font-semibold bg-green-50 text-green-600 border border-green-100">PAID</span>';
                            else if(bill.status === 'Overdue') badge = '<span class="px-2 py-1 rounded text-xs font-semibold bg-red-50 text-red-600 border border-red-100">OVERDUE</span>';
                            else badge = '<span class="px-2 py-1 rounded text-xs font-semibold bg-yellow-50 text-yellow-600 border border-yellow-100">UNPAID</span>';

                            const tr = document.createElement('tr');
                            tr.innerHTML = `
                                <td class="py-4">
                                    <span class="font-medium text-gray-900">${bill.billing_month}</span><br>
                                    <span class="text-xs text-gray-400">#${bill.invoice_no} | Due: ${bill.due_date}</span>
                                </td>
                                <td class="py-4 text-right font-bold text-gray-900">₱${parseFloat(bill.amount_due).toLocaleString(undefined, {minimumFractionDigits: 2})}</td>
                                <td class="py-4 text-right">${badge}</td>
                            `;
                            tbody.appendChild(tr);
                        });
                    } else {
                        tbody.innerHTML = '<tr><td colspan="3" class="py-6 text-center text-sm text-gray-500">No billing records found for this account.</td></tr>';
                    }
                })
                .catch(err => {
                    loader.textContent = 'Error loading data.';
                });
        }
    </script>
</body>
</html>