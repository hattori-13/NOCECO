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
// SILENT DATABASE PATCH (Auto-Upgrades the table for Monthly Tracking)
// ---------------------------------------------------------------------
try {
    $pdo->exec("ALTER TABLE billing_rates_catalog ADD COLUMN effective_month VARCHAR(50) DEFAULT 'Standard' AFTER charge_description");
} catch (PDOException $e) {
    // Column already exists, ignore
}

// ---------------------------------------------------------------------
// FORM PROCESSING: ADD, EDIT, ARCHIVE, AUTO-POPULATE, & QR UPLOAD
// ---------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? '';

    // UPLOAD PAYMENT QR CODE
    if ($action === 'upload_qr') {
        if (isset($_FILES['qr_image']) && $_FILES['qr_image']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = '../uploads/';
            
            // Create uploads directory if it doesn't exist
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $fileTmpPath = $_FILES['qr_image']['tmp_name'];
            $fileName = $_FILES['qr_image']['name'];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            
            $allowedExtensions = ['jpg', 'jpeg', 'png'];
            
            if (in_array($fileExtension, $allowedExtensions)) {
                // Delete old QR codes to prevent format conflicts (e.g., leaving a .jpg when uploading a .png)
                $existingFiles = glob($uploadDir . 'payment_qr.*');
                if ($existingFiles) {
                    foreach ($existingFiles as $file) {
                        unlink($file);
                    }
                }

                // Standardize the new filename
                $destPath = $uploadDir . 'payment_qr.' . $fileExtension;

                if(move_uploaded_file($fileTmpPath, $destPath)) {
                    $message = "Payment QR Code successfully updated.";
                    $messageType = "success";
                } else {
                    $message = "Error moving the uploaded file.";
                    $messageType = "error";
                }
            } else {
                $message = "Invalid file type. Only JPG and PNG are allowed.";
                $messageType = "error";
            }
        } else {
            $message = "No file uploaded or an upload error occurred.";
            $messageType = "error";
        }
    }

    // AUTO-POPULATE EXACT NOCECO RATES FROM INVOICE
    if ($action === 'auto_populate') {
        $month = date('Y-m'); // Default to current month
        
        // Exact representation of the uploaded NOCECO Receipt
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
            ['Supply Retail Cust. Charge', 'Per_Customer', 0.0000, 0], // Kept at 0 to reflect receipt total
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
            
            // VAT Specific Lines extracted exactly from receipt
            ['Generation VAT', 'Per_KWH', 0.8048, 0],
            ['Transmission VAT', 'Per_KWH', 0.2323, 0], 
            ['DSM VAT', 'Per_KWH', 0.1992, 0]
        ];

        try {
            $stmt = $pdo->prepare("INSERT INTO billing_rates_catalog (charge_description, effective_month, charge_type, current_rate, is_vatable, status) VALUES (?, ?, ?, ?, ?, 'Active')");
            foreach ($standardRates as $rate) {
                $stmt->execute([$rate[0], $month, $rate[1], $rate[2], $rate[3]]);
            }
            $message = "All 31 exact NOCECO fees and VAT lines successfully auto-populated!";
            $messageType = "success";
        } catch (PDOException $e) {
            $message = "Auto-populate failed: " . $e->getMessage();
            $messageType = "error";
        }
    }

    // MANUAL ADD RATE
    if ($action === 'add') {
        $month = trim($_POST['effective_month'] ?? date('Y-m'));
        $description = trim($_POST['charge_description'] ?? '');
        $type = $_POST['charge_type'] ?? 'Per_KWH';
        $rate = $_POST['current_rate'] ?? 0;
        $is_vatable = $_POST['is_vatable'] ?? 0;

        try {
            $stmt = $pdo->prepare("INSERT INTO billing_rates_catalog (charge_description, effective_month, charge_type, current_rate, is_vatable, status) VALUES (?, ?, ?, ?, ?, 'Active')");
            $stmt->execute([$description, $month, $type, $rate, $is_vatable]);
            $message = "New fee '$description' successfully added for $month.";
            $messageType = "success";
        } catch (PDOException $e) {
            $message = "Failed to add rate: " . $e->getMessage();
            $messageType = "error";
        }
    }

    // EDIT RATE
    if ($action === 'edit') {
        $rate_id = $_POST['rate_id'];
        $month = trim($_POST['effective_month'] ?? date('Y-m'));
        $description = trim($_POST['charge_description']);
        $type = $_POST['charge_type'];
        $rate = $_POST['current_rate'];
        $is_vatable = $_POST['is_vatable'];

        try {
            $stmt = $pdo->prepare("UPDATE billing_rates_catalog SET charge_description = ?, effective_month = ?, charge_type = ?, current_rate = ?, is_vatable = ? WHERE rate_id = ?");
            $stmt->execute([$description, $month, $type, $rate, $is_vatable, $rate_id]);
            $message = "'$description' successfully updated.";
            $messageType = "success";
        } catch (PDOException $e) {
            $message = "Update failed: " . $e->getMessage();
            $messageType = "error";
        }
    }

    // TOGGLE ARCHIVE STATUS
    if ($action === 'toggle_status') {
        $rate_id = $_POST['rate_id'];
        $current_status = $_POST['current_status'];
        $new_status = ($current_status === 'Active') ? 'Archived' : 'Active';

        try {
            $stmt = $pdo->prepare("UPDATE billing_rates_catalog SET status = ? WHERE rate_id = ?");
            $stmt->execute([$new_status, $rate_id]);
            $message = "Rate status changed to $new_status.";
            $messageType = "success";
        } catch (PDOException $e) {
            $message = "Status update failed: " . $e->getMessage();
            $messageType = "error";
        }
    }
}

// ---------------------------------------------------------------------
// CHECK FOR EXISTING QR CODE
// ---------------------------------------------------------------------
$qrExists = false;
$qrPath = '';
$possibleExtensions = ['jpg', 'jpeg', 'png'];
foreach ($possibleExtensions as $ext) {
    if (file_exists('../uploads/payment_qr.' . $ext)) {
        $qrExists = true;
        $qrPath = '../uploads/payment_qr.' . $ext . '?v=' . time(); // Cache-busting parameter
        break;
    }
}

// ---------------------------------------------------------------------
// FETCH RATES & CALCULATE TOTALS
// ---------------------------------------------------------------------
try {
    $stmt = $pdo->query("SELECT * FROM billing_rates_catalog ORDER BY status ASC, effective_month DESC, rate_id ASC");
    $rates = $stmt->fetchAll();
    
    $activeRates = array_filter($rates, function($r) { return $r['status'] === 'Active'; });

    $totalPerKWH = 0;
    foreach ($activeRates as $r) {
        if ($r['charge_type'] === 'Per_KWH') {
            $totalPerKWH += (float)$r['current_rate'];
        }
    }

} catch (PDOException $e) {
    $message = "Failed to load rates: " . $e->getMessage();
    $messageType = "error";
    $rates = [];
    $activeRates = [];
    $totalPerKWH = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billing Rates | NOCECO System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = {
        theme: {
          extend: {
            colors: { noceco: { bg: '#F5F5F7', text: '#1D1D1F', mustard: '#DBA111', mustardHover: '#B8860B' } },
            fontFamily: { sans: ['-apple-system', 'BlinkMacSystemFont', '"Segoe UI"', 'Roboto', 'sans-serif'], mono: ['ui-monospace', 'SFMono-Regular', 'Menlo', 'Monaco', 'Consolas', 'monospace'] },
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
                <a href="admin-dashboard.php" class="flex items-center px-4 py-3 text-gray-500 hover:bg-gray-50 hover:text-gray-900 rounded-xl transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                    Dashboard
                </a>
                <a href="register-admin.php" class="flex items-center px-4 py-3 text-gray-500 hover:bg-gray-50 hover:text-gray-900 rounded-xl transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path></svg>
                    Register Staff
                </a>
                <a href="../consumers/add-consumer.php" class="flex items-center px-4 py-3 text-gray-500 hover:bg-gray-50 hover:text-gray-900 rounded-xl transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m-3-3h6m-6 0H9m-3 0h3m-3 0a3 3 0 00-3 3v1m15-4a3 3 0 013 3v1m-15 4a3 3 0 003 3h3a3 3 0 003-3m-6 0v-1m6 1v-1"></path></svg>
                    Add Consumer
                </a>
                <a href="../consumers/manage-consumers.php" class="flex items-center px-4 py-3 text-gray-500 hover:bg-gray-50 hover:text-gray-900 rounded-xl transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                    Manage Consumers
                </a>
                <a href="billing-rates.php" class="flex items-center px-4 py-3 bg-noceco-bg/80 text-noceco-mustard font-medium rounded-xl">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path></svg>
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
                <h2 class="text-xl font-semibold tracking-tight">Pricing Catalog</h2>
                <p class="text-xs text-gray-500">Mathematical Constants & Monthly Billing Rules</p>
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

        <div class="p-8 flex flex-col xl:flex-row gap-8 items-start">
            
            <div class="w-full xl:w-2/3">
                <div class="bg-white rounded-[20px] shadow-apple p-6 border border-gray-100 mb-6 flex justify-between items-center bg-gradient-to-r from-white to-gray-50">
                    <div>
                        <h4 class="text-sm font-bold text-gray-500 uppercase tracking-wider">Total Active Energy Rate</h4>
                        <p class="text-xs text-gray-400 mt-1">Sum of all Active "Per KWH" charges (including VAT).</p>
                    </div>
                    <div class="text-right">
                        <span class="text-3xl font-black text-gray-900">Php <?php echo number_format($totalPerKWH, 4); ?></span>
                        <span class="text-gray-500 font-medium ml-1">/ kWh</span>
                    </div>
                </div>

                <?php if (!empty($message)): ?>
                    <div class="mb-6 p-4 rounded-xl text-sm font-medium <?php echo $messageType === 'success' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900 tracking-tight">Computation Catalog</h3>
                        <p class="text-sm text-gray-500">Manage monthly variables, fixed prices, and specific VAT rates.</p>
                    </div>
                    
                    <div class="flex gap-3">
                        <button onclick="openModal('qrModal')" class="bg-white border border-gray-200 hover:bg-gray-50 text-gray-700 px-4 py-2.5 rounded-xl text-sm font-semibold transition-colors shadow-apple-sm flex items-center">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                            Payment QR
                        </button>
                        
                        <button onclick="openModal('addModal')" class="bg-gray-900 hover:bg-black text-white px-5 py-2.5 rounded-xl text-sm font-semibold transition-colors shadow-apple-sm flex items-center">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                            Add New Fee
                        </button>
                    </div>
                </div>

                <div class="bg-white rounded-[20px] shadow-apple border border-gray-100/50 overflow-hidden">
                    
                    <?php if (empty($rates)): ?>
                        <div class="p-10 text-center bg-gray-50/50">
                            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-blue-100 mb-4">
                                <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                            </div>
                            <h3 class="text-xl font-bold text-gray-900 mb-2">Initialize Database</h3>
                            <p class="text-sm text-gray-500 mb-6 max-w-md mx-auto">Your catalog is empty. Click below to automatically load all 31 exact NOCECO fees, null rates, and taxes from the invoice.</p>
                            <form action="billing-rates.php" method="POST">
                                <input type="hidden" name="action" value="auto_populate">
                                <button type="submit" class="bg-noceco-mustard hover:bg-noceco-mustardHover text-white px-6 py-3 rounded-xl font-bold shadow-apple-sm transition-colors">
                                    Auto-Populate 31 Exact NOCECO Rates
                                </button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto max-h-[800px] overflow-y-auto custom-scrollbar">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="bg-gray-50/50 border-b border-gray-100 text-xs font-semibold text-gray-500 uppercase tracking-wider sticky top-0 backdrop-blur-md">
                                        <th class="p-5">Effective Month</th>
                                        <th class="p-5">Charge Description</th>
                                        <th class="p-5 text-center">Type</th>
                                        <th class="p-5 text-right">Rate</th>
                                        <th class="p-5 text-center">Status</th>
                                        <th class="p-5 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($rates as $r): ?>
                                        <tr class="hover:bg-gray-50/50 transition-colors <?php echo $r['status'] === 'Archived' ? 'opacity-50' : ''; ?>">
                                            <td class="p-5 text-sm font-bold text-noceco-mustard whitespace-nowrap">
                                                <?php echo htmlspecialchars($r['effective_month'] ?? 'Standard'); ?>
                                            </td>
                                            <td class="p-5 font-semibold text-gray-900">
                                                <?php echo htmlspecialchars($r['charge_description']); ?>
                                            </td>
                                            <td class="p-5 text-center"><span class="text-xs font-medium px-2.5 py-1 rounded-md bg-gray-100 text-gray-600"><?php echo str_replace('_', ' ', htmlspecialchars($r['charge_type'])); ?></span></td>
                                            <td class="p-5 text-right font-bold text-gray-900 font-mono"><?php echo number_format($r['current_rate'], 4); ?></td>
                                            <td class="p-5 text-center">
                                                <?php if ($r['status'] === 'Active'): ?>
                                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-green-50 text-green-700 border border-green-100">Active</span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-600 border border-gray-200">Archived</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="p-5 text-right space-x-2 whitespace-nowrap">
                                                <button onclick="openEditModal(<?php echo $r['rate_id']; ?>, '<?php echo addslashes($r['effective_month'] ?? ''); ?>', '<?php echo addslashes($r['charge_description']); ?>', '<?php echo $r['charge_type']; ?>', '<?php echo $r['current_rate']; ?>', <?php echo $r['is_vatable']; ?>)"
                                                        class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-blue-50 text-blue-600 hover:bg-blue-100 transition-colors" title="Edit Rate">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                                                </button>
                                                <form action="billing-rates.php" method="POST" class="inline">
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="rate_id" value="<?php echo $r['rate_id']; ?>">
                                                    <input type="hidden" name="current_status" value="<?php echo $r['status']; ?>">
                                                    <button type="submit" onclick="return confirm('Toggle archive status for this rate?');"
                                                            class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-gray-50 text-gray-600 hover:bg-gray-200 transition-colors">
                                                        <?php if ($r['status'] === 'Active'): ?>
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"></path></svg>
                                                        <?php else: ?>
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"></path></svg>
                                                        <?php endif; ?>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="w-full xl:w-1/3 bg-gray-900 rounded-[20px] shadow-2xl p-1 relative overflow-hidden sticky top-28">
                <div class="bg-[#fcfcfa] rounded-[16px] p-6 shadow-inner relative font-mono text-sm border border-gray-200 h-[750px] flex flex-col">
                    <div class="absolute -top-1 left-0 right-0 h-2 bg-[url('data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMCIgaGVpZ2h0PSIxMCI+PHBvbHlnb24gcG9pbnRzPSIwLDEwIDUsMCAxMCwxMCIgZmlsbD0iIzExMTgxNyIvPjwvc3ZnPg==')] opacity-20"></div>

                    <div class="mb-4 pb-4 border-b-2 border-dashed border-gray-300">
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-2">Simulate KWH Used</label>
                        <input type="number" id="sim_kwh" value="127" oninput="generateReceipt()"
                            class="w-full px-3 py-2 bg-white border border-gray-300 rounded focus:outline-none focus:border-gray-500 font-bold text-lg text-center">
                    </div>

                    <div class="text-center mb-4">
                        <h4 class="font-bold text-[15px] tracking-tight">NOCECO</h4>
                        <p class="text-[10px] text-gray-600">BILLING INVOICE SIMULATOR</p>
                    </div>

                    <div class="flex-1 overflow-y-auto pr-2 custom-scrollbar">
                        <p class="text-[11px] tracking-[0.2em] mb-2 font-bold text-center">C H A R G E S</p>
                        <div class="flex justify-between text-[10px] font-bold border-b border-gray-300 pb-1 mb-2 uppercase text-gray-500">
                            <span class="w-1/2">Description</span>
                            <span class="w-1/4 text-center">Rate</span>
                            <span class="w-1/4 text-right">Amount</span>
                        </div>
                        <div id="receipt_items" class="space-y-1 mb-4"></div>

                        <p class="text-[11px] mb-2 mt-4 font-bold border-t border-dashed border-gray-300 pt-2">Vat Detail:</p>
                        <div id="receipt_vat_items" class="space-y-1 text-gray-600 pl-2"></div>
                    </div>

                    <div class="mt-4 pt-3 border-t-2 border-dashed border-gray-300">
                        <p class="text-center text-xs font-bold mb-3 tracking-widest uppercase">Summary</p>
                        <div class="flex justify-between text-[11px] mb-1 font-bold">
                            <span>TOTAL Value Added TAX 12%</span>
                            <span id="receipt_vat">0.00</span>
                        </div>
                        <div class="flex justify-between font-bold text-sm mb-1 pt-2 border-t border-gray-200">
                            <span>TOTAL SALES</span>
                            <span id="receipt_total_sales">Php 0.00</span>
                        </div>
                        <div class="flex justify-between font-bold text-lg">
                            <span>CURRENT MONTH BILL</span>
                            <span id="receipt_total">Php 0.00</span>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <div id="qrModal" class="fixed inset-0 bg-gray-900/40 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-[24px] shadow-2xl w-full max-w-md overflow-hidden">
            <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50">
                <h3 class="text-lg font-bold text-gray-900">Upload Payment QR</h3>
                <button onclick="closeModal('qrModal')" class="p-2 bg-gray-200 hover:bg-gray-300 rounded-full"><svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
            </div>
            
            <form action="billing-rates.php" method="POST" enctype="multipart/form-data" class="p-6 space-y-4">
                <input type="hidden" name="action" value="upload_qr">
                
                <?php if ($qrExists): ?>
                    <div class="text-center mb-4 bg-gray-50 p-4 rounded-xl border border-gray-100">
                        <p class="text-xs text-gray-500 mb-3 font-semibold uppercase tracking-wider">Current Global QR Code:</p>
                        <img src="<?php echo $qrPath; ?>" alt="Payment QR" class="w-32 h-32 mx-auto rounded-xl border border-gray-200 shadow-sm object-contain bg-white">
                    </div>
                <?php endif; ?>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Select Image (JPG or PNG)</label>
                    <input type="file" name="qr_image" accept=".jpg, .jpeg, .png" required
                        class="w-full px-3 py-2 rounded-xl bg-gray-50 border border-gray-200 focus:ring-2 focus:ring-noceco-mustard focus:bg-white transition-all text-sm text-gray-600
                        file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-noceco-mustard file:text-white hover:file:bg-noceco-mustardHover cursor-pointer">
                </div>

                <button type="submit" class="w-full mt-6 bg-gray-900 hover:bg-black text-white font-bold py-4 px-4 rounded-xl shadow-[0_4px_14px_rgba(0,0,0,0.3)] transition-colors">
                    Upload & Save QR
                </button>
            </form>
        </div>
    </div>

    <div id="addModal" class="fixed inset-0 bg-gray-900/40 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-[24px] shadow-2xl w-full max-w-md overflow-hidden">
            <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50">
                <h3 class="text-lg font-bold text-gray-900">Add New Fee</h3>
                <button onclick="closeModal('addModal')" class="p-2 bg-gray-200 hover:bg-gray-300 rounded-full"><svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
            </div>
            <form action="billing-rates.php" method="POST" class="p-6 space-y-4">
                <input type="hidden" name="action" value="add">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Effective Month</label>
                    <input type="month" name="effective_month" required value="<?php echo date('Y-m'); ?>"
                        class="w-full px-4 py-3 rounded-xl bg-gray-50 border border-gray-200 focus:ring-2 focus:ring-noceco-mustard focus:bg-white transition-all font-bold text-noceco-mustard">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Charge Description</label>
                    <input type="text" name="charge_description" required placeholder="e.g., Generation VAT or System Loss"
                        class="w-full px-4 py-3 rounded-xl bg-gray-50 border border-gray-200 focus:ring-2 focus:ring-noceco-mustard focus:bg-white transition-all">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Computation Type</label>
                    <select name="charge_type" required class="w-full px-4 py-3 rounded-xl bg-gray-50 border border-gray-200 focus:ring-2 focus:ring-noceco-mustard focus:bg-white transition-all appearance-none">
                        <option value="Per_KWH">Per KWH (Calculated by Usage)</option>
                        <option value="Per_Customer">Per Customer (Fixed Fee)</option>
                        <option value="Fixed">Fixed Amount</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Rate Value</label>
                    <input type="number" name="current_rate" required step="0.0001" placeholder="e.g., 1.0158"
                        class="w-full px-4 py-3 rounded-xl bg-gray-50 border border-gray-200 focus:ring-2 focus:ring-noceco-mustard focus:bg-white transition-all font-mono">
                </div>
                <button type="submit" class="w-full mt-4 bg-noceco-mustard hover:bg-noceco-mustardHover text-white font-bold py-4 px-4 rounded-xl shadow-[0_4px_14px_rgba(219,161,17,0.3)]">Save to Catalog</button>
            </form>
        </div>
    </div>

    <div id="editModal" class="fixed inset-0 bg-gray-900/40 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-[24px] shadow-2xl w-full max-w-md overflow-hidden">
            <div class="p-6 border-b border-gray-100 flex justify-between items-center">
                <h3 class="text-lg font-bold text-gray-900">Edit Rate</h3>
                <button onclick="closeModal('editModal')" class="p-2 bg-gray-100 hover:bg-gray-200 rounded-full"><svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
            </div>
            <form action="billing-rates.php" method="POST" class="p-6 space-y-4">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="rate_id" id="edit_id">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Effective Month</label>
                    <input type="month" name="effective_month" id="edit_month" required
                        class="w-full px-4 py-3 rounded-xl bg-gray-50 border border-gray-200 focus:ring-2 focus:ring-noceco-mustard focus:bg-white transition-all font-bold text-noceco-mustard">
                </div>

                <div><label class="block text-sm font-medium text-gray-700 mb-1">Description</label><input type="text" name="charge_description" id="edit_desc" required class="w-full px-4 py-3 rounded-xl bg-gray-50 border border-gray-200 focus:ring-2 focus:ring-noceco-mustard focus:bg-white transition-all"></div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                    <select name="charge_type" id="edit_type" required class="w-full px-4 py-3 rounded-xl bg-gray-50 border border-gray-200 focus:ring-2 focus:ring-noceco-mustard focus:bg-white transition-all appearance-none">
                        <option value="Per_KWH">Per KWH</option><option value="Per_Customer">Per Customer</option><option value="Fixed">Fixed</option>
                    </select>
                </div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Rate</label><input type="number" name="current_rate" id="edit_rate" required step="0.0001" class="w-full px-4 py-3 rounded-xl bg-gray-50 border border-gray-200 focus:ring-2 focus:ring-noceco-mustard focus:bg-white transition-all font-mono"></div>
                <button type="submit" class="w-full mt-4 bg-gray-900 text-white font-semibold py-3 px-4 rounded-xl">Update Catalog</button>
            </form>
        </div>
    </div>

    <style>
        .custom-scrollbar::-webkit-scrollbar { width: 4px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }
    </style>
    
    <script>
        const activeRates = <?php echo json_encode(array_values($activeRates)); ?>;

        function generateReceipt() {
            const kwh = parseFloat(document.getElementById('sim_kwh').value) || 0;
            const chargesContainer = document.getElementById('receipt_items');
            const vatContainer = document.getElementById('receipt_vat_items');
            
            chargesContainer.innerHTML = ''; 
            vatContainer.innerHTML = '';

            let totalSales = 0;
            let explicitVatTotal = 0; 

            if (activeRates.length === 0) {
                chargesContainer.innerHTML = '<div class="text-center py-4 text-gray-400 text-xs italic">Catalog is empty.</div>';
            }

            activeRates.forEach(rate => {
                let amount = 0;
                let rateValue = parseFloat(rate.current_rate);
                let rateSuffix = rate.charge_type === 'Per_KWH' ? '/kwh' : (rate.charge_type === 'Per_Customer' ? '/Cust' : '');

                if (rate.charge_type === 'Per_KWH') amount = kwh * rateValue;
                else if (rate.charge_type === 'Per_Customer') amount = rateValue;
                else amount = rateValue;

                totalSales += amount;

                const row = document.createElement('div');
                row.className = "flex justify-between text-[11px] leading-tight";
                
                // Route VAT items to the specific VAT section at the bottom
                if(rate.charge_description.toUpperCase().includes('VAT')) {
                    explicitVatTotal += amount; 
                    row.innerHTML = `
                        <span class="w-1/2 truncate pr-1">${rate.charge_description}</span>
                        <span class="w-1/4 text-center">x ${rateValue.toFixed(4)}${rateSuffix}</span>
                        <span class="w-1/4 text-right">${amount.toFixed(2)}</span>
                    `;
                    vatContainer.appendChild(row);
                } else {
                    let shortDesc = rate.charge_description.length > 23 ? rate.charge_description.substring(0, 21) + '..' : rate.charge_description;
                    row.innerHTML = `
                        <span class="w-1/2 truncate pr-1">${shortDesc}</span>
                        <span class="w-1/4 text-center">${rateValue.toFixed(4)}${rateSuffix}</span>
                        <span class="w-1/4 text-right">${amount.toFixed(2)}</span>
                    `;
                    chargesContainer.appendChild(row);
                }
            });

            // Update Totals Footer exactly like the NOCECO receipt
            document.getElementById('receipt_vat').textContent = explicitVatTotal.toFixed(2);
            document.getElementById('receipt_total_sales').textContent = 'Php ' + totalSales.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('receipt_total').textContent = 'Php ' + totalSales.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }

        function openModal(modalId) { document.getElementById(modalId).classList.remove('hidden'); }
        function closeModal(modalId) { document.getElementById(modalId).classList.add('hidden'); }
        function openEditModal(id, month, desc, type, rate, vat) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_month').value = month || '<?php echo date('Y-m'); ?>';
            document.getElementById('edit_desc').value = desc;
            document.getElementById('edit_type').value = type;
            document.getElementById('edit_rate').value = rate;
            openModal('editModal');
        }

        document.addEventListener('DOMContentLoaded', generateReceipt);
    </script>
</body>
</html>