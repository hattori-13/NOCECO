<?php
session_start();
require_once '../db/dbcon.php';

if (!isset($_SESSION['client_account']) || $_SESSION['role'] !== 'Consumer') {
    header("Location: ../index.php");
    exit();
}

$account_no = $_SESSION['client_account'];
$message = '';
$messageType = '';

// 1. FETCH LATEST UNPAID BILL
try {
    $stmt = $pdo->prepare("SELECT * FROM billing_invoices WHERE account_no = ? AND status = 'Unpaid' ORDER BY reading_date DESC LIMIT 1");
    $stmt->execute([$account_no]);
    $bill = $stmt->fetch();
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

// ---------------------------------------------------------------------
// 2. PROCESS PAYMENT SUBMISSION (STORES TO DATABASE)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_payment'])) {
    $ref_no = trim($_POST['reference_no']);
    $invoice_no = $_POST['invoice_no'];
    $amount = $_POST['amount'];

    if (empty($ref_no)) {
        $message = "Please enter the GCash Reference Number.";
        $messageType = "error";
    } else {
        try {
            // Store payment for Admin verification
            $stmt = $pdo->prepare("INSERT INTO payments (account_no, invoice_no, amount_paid, reference_no, payment_method, status, payment_date) VALUES (?, ?, ?, ?, 'QRPH', 'Pending', NOW())");
            
            if ($stmt->execute([$account_no, $invoice_no, $amount, $ref_no])) {
                // Option: Auto-update invoice status to 'Processing'
                $update = $pdo->prepare("UPDATE billing_invoices SET status = 'Processing' WHERE invoice_no = ?");
                $update->execute([$invoice_no]);

                $message = "Payment submitted! Please wait for admin verification.";
                $messageType = "success";
                $bill = false; // Hide the bill form
            }
        } catch (PDOException $e) {
            $message = "Error saving payment: " . $e->getMessage();
            $messageType = "error";
        }
    }
}

// 3. GET YOUR QR IMAGE (Uploaded by Admin)
// NOTE: Change 'admin_qr.png' to the actual filename used by your admin upload system, 
// or fetch the dynamic filename from your database if you store it there.
$qr_filename = "payment_qr.jpg";
$qr_image_path = "../uploads/" . $qr_filename;

// Check if the admin's QR file exists, otherwise use a placeholder to prevent broken images
if (file_exists($qr_image_path)) {
    $qr_image_url = $qr_image_path;
} else {
    $qr_image_url = "https://via.placeholder.com/250?text=No+QR+Code+Available"; 
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NOCECO | Secure Payment</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = {
        theme: { extend: { colors: { noceco: { bg: '#F5F5F7', mustard: '#DBA111' } } } }
      }
    </script>
</head>
<body class="bg-noceco-bg min-h-screen flex flex-col items-center p-6">

    <div class="w-full max-w-md">
        <header class="flex justify-between items-center mb-8">
            <a href="home.php" class="text-sm font-bold text-gray-400 hover:text-noceco-mustard transition-colors">← Dashboard</a>
            <span class="px-3 py-1 bg-blue-100 text-blue-700 text-[10px] font-black rounded-full uppercase tracking-widest animate-pulse">Live QR Ph</span>
        </header>

        <?php if ($messageType === 'success'): ?>
            <div class="bg-white rounded-[32px] p-10 text-center shadow-xl border border-green-100">
                <div class="w-20 h-20 bg-green-50 rounded-full flex items-center justify-center mx-auto mb-6 text-green-500 text-4xl">✓</div>
                <h1 class="text-2xl font-bold text-gray-900"><?php echo $message; ?></h1>
                <p class="text-gray-500 mt-4 text-sm">Your reference number <strong>#<?php echo htmlspecialchars($ref_no); ?></strong> has been logged. You will receive an SMS once verified.</p>
                <a href="home.php" class="inline-block mt-8 bg-gray-900 text-white px-10 py-3 rounded-xl font-bold">Back to Home</a>
            </div>
        <?php elseif (!$bill): ?>
            <div class="bg-white rounded-[32px] p-10 text-center shadow-xl border border-gray-100">
                <h1 class="text-2xl font-bold text-gray-900">No Balance Due</h1>
                <p class="text-gray-500 mt-2">You have no outstanding bills to pay.</p>
                <a href="home.php" class="inline-block mt-8 bg-gray-900 text-white px-8 py-3 rounded-xl font-bold">Return Home</a>
            </div>
        <?php else: ?>
            
            <div class="bg-white rounded-[32px] shadow-2xl border border-gray-100 overflow-hidden">
                <div class="bg-gray-900 p-8 text-white text-center">
                    <p class="text-[10px] font-bold text-noceco-mustard uppercase tracking-widest mb-1">Payable Amount</p>
                    <h2 class="text-4xl font-black tracking-tighter">₱<?php echo number_format($bill['amount_due'], 2); ?></h2>
                    <p class="text-[10px] text-gray-400 mt-2 uppercase tracking-widest font-bold">Invoice #<?php echo $bill['invoice_no']; ?></p>
                </div>

                <div class="p-8">
                    <?php if ($messageType === 'error'): ?>
                        <div class="bg-red-50 text-red-600 p-4 rounded-xl text-xs font-bold mb-6"><?php echo $message; ?></div>
                    <?php endif; ?>

                    <div class="text-center">
                        <p class="text-sm font-bold text-gray-900 mb-4">Step 1: Scan and Pay</p>
                        <div class="bg-white p-3 rounded-2xl border-2 border-noceco-mustard inline-block mb-6 shadow-inner">
                            <img src="<?php echo htmlspecialchars($qr_image_url); ?>" class="w-56 h-56 object-contain" alt="NOCECO Admin QR">
                        </div>
                    </div>

                    <form action="mybill.php" method="POST" class="mt-4 pt-6 border-t border-dashed border-gray-200">
                        <input type="hidden" name="invoice_no" value="<?php echo $bill['invoice_no']; ?>">
                        <input type="hidden" name="amount" value="<?php echo $bill['amount_due']; ?>">
                        
                        <p class="text-sm font-bold text-gray-900 mb-4 text-center">Step 2: Enter Receipt Info</p>
                        
                        <div class="mb-6">
                            <label class="block text-[10px] font-black text-gray-400 uppercase mb-1">GCash Reference No.</label>
                            <input type="text" name="reference_no" required placeholder="13-digit number from GCash"
                                class="w-full px-4 py-4 rounded-xl bg-gray-50 border border-gray-200 focus:ring-2 focus:ring-noceco-mustard focus:bg-white transition-all font-mono text-lg">
                        </div>

                        <button type="submit" name="submit_payment" class="w-full bg-[#007DFE] hover:bg-blue-700 text-white font-black py-5 rounded-2xl shadow-lg transition-all flex items-center justify-center gap-3">
                            Confirm Payment Submission
                        </button>
                        
                        <p class="text-[9px] text-gray-400 mt-6 text-center leading-relaxed">
                            Ensure you have completed the GCash transfer before submitting. False reporting may lead to account penalties.
                        </p>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

</body>
</html>