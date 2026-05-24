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

$page = $_GET['page'] ?? 'accounts';

// ---------------------------------------------------------------------
// HELPERS
// ---------------------------------------------------------------------
if (!function_exists('starts_with_safe')) {
    function starts_with_safe(string $haystack, string $needle): bool
    {
        return substr($haystack, 0, strlen($needle)) === $needle;
    }
}

if (!function_exists('normalize_mobile_number')) {
    function normalize_mobile_number(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        if ($digits === '') {
            return '';
        }

        // Accept 09XXXXXXXXX, 639XXXXXXXXX, or 9XXXXXXXXX
        if (strlen($digits) === 10 && $digits[0] === '9') {
            return '0' . $digits;
        }

        if (strlen($digits) === 12 && starts_with_safe($digits, '63')) {
            return '0' . substr($digits, 2);
        }

        if (strlen($digits) === 11 && starts_with_safe($digits, '09')) {
            return $digits;
        }

        // Fallback: return cleaned digits
        return $digits;
    }
}

if (!function_exists('build_notice_message')) {
    function build_notice_message(string $type, array $row): string
    {
        $firstName = trim((string)($row['first_name'] ?? 'Customer'));
        $accountNo = trim((string)($row['account_no'] ?? ''));
        $billingMonth = trim((string)($row['billing_month'] ?? ''));
        $amountDue = number_format((float)($row['amount_due'] ?? 0), 2);
        $dueDate = !empty($row['due_date']) ? date('F j, Y', strtotime($row['due_date'])) : date('F j, Y');

        if ($type === 'disconnection') {
            return "NOCECO NOTICE: Dear {$firstName}, your account {$accountNo} has an unpaid bill for {$billingMonth} amounting to Php {$amountDue} and is already DISCONNECTED. Please settle immediately for reconnection.";
        }

        return "NOCECO NOTICE: Dear {$firstName}, your bill for {$billingMonth} amounting to Php {$amountDue} is due on {$dueDate}. Please pay on or before the due date to avoid disconnection.";
    }
}

if (!function_exists('notice_message_type')) {
    function notice_message_type(string $type, array $row): string
    {
        $invoiceNo = (string)($row['invoice_no'] ?? $row['account_no'] ?? time());
        return ($type === 'disconnection' ? 'Disconnection Notice - ' : 'Due Date Notice - ') . $invoiceNo;
    }
}

if (!function_exists('send_semaphore_sms')) {
    function send_semaphore_sms(string $number, string $message): array
    {
        $apiKey = 'E20452aa0e1978aa79da33c699b9a5c0';
        $senderName = 'Immunisafe';

        $payload = [
            'apikey' => $apiKey,
            'number' => $number,
            'message' => $message,
            'sendername' => $senderName
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.semaphore.co/api/v4/messages');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $raw = curl_exec($ch);
        $curlErr = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $curlErr) {
            return [
                'success' => false,
                'http_code' => $httpCode,
                'status' => 'Failed',
                'raw' => $curlErr ?: 'Unknown cURL error'
            ];
        }

        $decoded = json_decode($raw, true);

        $status = 'Failed';
        if (is_array($decoded)) {
            $first = $decoded[0] ?? $decoded;
            if (is_array($first) && isset($first['status'])) {
                $status = ucfirst(strtolower((string)$first['status']));
            }
        }

        $success = in_array(strtolower($status), ['queued', 'pending', 'sent'], true) || $httpCode >= 200 && $httpCode < 300;

        return [
            'success' => $success,
            'http_code' => $httpCode,
            'status' => $status,
            'raw' => $raw,
            'decoded' => $decoded
        ];
    }
}

if (!function_exists('fetch_notice_recipients')) {
    function fetch_notice_recipients(PDO $pdo, string $type, ?string $dueDate = null): array
    {
        if ($type === 'disconnection') {
            $sql = "
                SELECT
                    c.account_no,
                    c.first_name,
                    c.last_name,
                    c.contact_number,
                    c.address,
                    c.status AS account_status,
                    b.invoice_no,
                    b.billing_month,
                    b.amount_due,
                    b.due_date
                FROM clients c
                INNER JOIN billing_invoices b ON b.account_no = c.account_no
                WHERE c.status = 'Disconnected'
                  AND b.status = 'Unpaid'
                  AND b.invoice_no = (
                        SELECT bi.invoice_no
                        FROM billing_invoices bi
                        WHERE bi.account_no = c.account_no
                          AND bi.status = 'Unpaid'
                        ORDER BY bi.due_date DESC, bi.created_at DESC
                        LIMIT 1
                  )
                ORDER BY b.due_date ASC, c.last_name ASC, c.first_name ASC
            ";

            $stmt = $pdo->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($type === 'due_date') {
            $dueDate = $dueDate ?: date('Y-m-d');

            $sql = "
                SELECT
                    c.account_no,
                    c.first_name,
                    c.last_name,
                    c.contact_number,
                    c.address,
                    c.status AS account_status,
                    b.invoice_no,
                    b.billing_month,
                    b.amount_due,
                    b.due_date
                FROM clients c
                INNER JOIN billing_invoices b ON b.account_no = c.account_no
                WHERE c.status <> 'Disconnected'
                  AND b.status = 'Unpaid'
                  AND b.due_date = ?
                  AND b.invoice_no = (
                        SELECT bi.invoice_no
                        FROM billing_invoices bi
                        WHERE bi.account_no = c.account_no
                          AND bi.status = 'Unpaid'
                          AND bi.due_date = ?
                        ORDER BY bi.created_at DESC
                        LIMIT 1
                  )
                ORDER BY c.last_name ASC, c.first_name ASC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$dueDate, $dueDate]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return [];
    }
}

// ---------------------------------------------------------------------
// WEBHOOK / AJAX ENDPOINTS
// ---------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'preview_notice') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $type = $_GET['type'] ?? '';
        $dueDate = $_GET['due_date'] ?? date('Y-m-d');

        $targets = fetch_notice_recipients($pdo, $type, $dueDate);

        $items = [];
        foreach ($targets as $row) {
            $items[] = [
                'account_no' => $row['account_no'] ?? '',
                'full_name' => trim(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '')),
                'contact_number' => $row['contact_number'] ?? '',
                'normalized_number' => normalize_mobile_number((string)($row['contact_number'] ?? '')),
                'invoice_no' => $row['invoice_no'] ?? '',
                'billing_month' => $row['billing_month'] ?? '',
                'amount_due' => number_format((float)($row['amount_due'] ?? 0), 2),
                'due_date' => !empty($row['due_date']) ? date('Y-m-d', strtotime($row['due_date'])) : '',
                'message' => build_notice_message($type, $row),
                'message_type' => notice_message_type($type, $row),
                'account_status' => $row['account_status'] ?? ''
            ];
        }

        echo json_encode([
            'success' => true,
            'count' => count($items),
            'items' => $items
        ]);
    } catch (Throwable $e) {
        echo json_encode([
            'success' => false,
            'count' => 0,
            'items' => [],
            'message' => 'Unable to fetch recipients.'
        ]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_notice') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $type = $_POST['notice_type'] ?? '';
        $dueDate = $_POST['due_date'] ?? date('Y-m-d');
        $targets = fetch_notice_recipients($pdo, $type, $dueDate);

        if (empty($targets)) {
            echo json_encode([
                'success' => false,
                'message' => 'No recipients found for this notice.',
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0
            ]);
            exit;
        }

        $sent = 0;
        $failed = 0;
        $skipped = 0;
        $results = [];

        foreach ($targets as $row) {
            $accountNo = (string)($row['account_no'] ?? '');
            $contactNumberRaw = (string)($row['contact_number'] ?? '');
            $normalizedNumber = normalize_mobile_number($contactNumberRaw);
            $messageType = notice_message_type($type, $row);
            $messageContent = build_notice_message($type, $row);

            if ($accountNo === '' || $normalizedNumber === '' || $messageContent === '') {
                $failed++;
                $results[] = [
                    'account_no' => $accountNo,
                    'status' => 'Failed',
                    'reason' => 'Missing account or phone data.'
                ];
                continue;
            }

            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM sms_logs WHERE account_no = ? AND message_type = ?");
            $stmtCheck->execute([$accountNo, $messageType]);

            if ((int)$stmtCheck->fetchColumn() > 0) {
                $skipped++;
                $results[] = [
                    'account_no' => $accountNo,
                    'status' => 'Skipped',
                    'reason' => 'Already sent.'
                ];
                continue;
            }

            $api = send_semaphore_sms($normalizedNumber, $messageContent);
            $statusForLog = $api['success'] ? 'Sent' : 'Failed';

            $stmtInsert = $pdo->prepare("
                INSERT INTO sms_logs (account_no, contact_number, message_type, message_content, sent_status)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmtInsert->execute([
                $accountNo,
                $normalizedNumber,
                $messageType,
                $messageContent,
                $statusForLog
            ]);

            if ($api['success']) {
                $sent++;
            } else {
                $failed++;
            }

            $results[] = [
                'account_no' => $accountNo,
                'status' => $statusForLog,
                'api_status' => $api['status'] ?? 'Failed'
            ];
        }

        echo json_encode([
            'success' => true,
            'message' => 'Notice sending completed.',
            'total' => count($targets),
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
            'results' => $results
        ]);
    } catch (Throwable $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Unable to send notice.',
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0
        ]);
    }
    exit;
}

// ---------------------------------------------------------------------
// AUTOMATED SMS SENDER (Runs instantly on page load)
// Condition: Disconnected + Unpaid + Due Date is TODAY
// ---------------------------------------------------------------------
$autoSmsCount = 0;
$smsLogs = [];
$allClients = [];
$viewAccount = null;
$clientBills = [];
$clientDetails = null;

if ($page === 'accounts') {
    try {
        $stmtTargets = $pdo->query("
            SELECT c.account_no, c.contact_number, c.first_name, c.last_name, b.amount_due, b.billing_month
            FROM clients c
            JOIN billing_invoices b ON c.account_no = b.account_no
            WHERE c.status = 'Disconnected'
            AND b.status = 'Unpaid'
            AND b.due_date = CURDATE()
        ");
        $targets = $stmtTargets->fetchAll(PDO::FETCH_ASSOC);

        foreach ($targets as $t) {
            $msgType = "Auto-Notice " . $t['billing_month'];

            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM sms_logs WHERE account_no = ? AND message_type = ?");
            $stmtCheck->execute([$t['account_no'], $msgType]);

            if ($stmtCheck->fetchColumn() == 0) {
                $smsMessage = "NOCECO ALERT: Hello " . trim($t['first_name']) . ". Your disconnected account has a bill DUE TODAY (Php " . number_format($t['amount_due'], 2) . " for " . $t['billing_month'] . "). Please settle immediately to avoid further penalties.";

                $stmtInsert = $pdo->prepare("INSERT INTO sms_logs (account_no, contact_number, message_type, message_content, sent_status) VALUES (?, ?, ?, ?, ?)");
                $stmtInsert->execute([$t['account_no'], $t['contact_number'], $msgType, $smsMessage, 'Sent']);
                $autoSmsCount++;
            }
        }
    } catch (PDOException $e) {
        error_log("Auto-SMS Error: " . $e->getMessage());
    }

    $stmtLogs = $pdo->query("
        SELECT s.account_no, s.contact_number, s.message_content, c.first_name, c.last_name
        FROM sms_logs s
        JOIN clients c ON s.account_no = c.account_no
        WHERE s.message_type LIKE 'Auto-Notice%'
        ORDER BY s.sent_at DESC
    ");
    $smsLogsRaw = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);
    $smsLogs = array_reverse($smsLogsRaw);

    $viewAccount = $_GET['view_account'] ?? null;

    if ($viewAccount) {
        $stmtClient = $pdo->prepare("SELECT * FROM clients WHERE account_no = ?");
        $stmtClient->execute([$viewAccount]);
        $clientDetails = $stmtClient->fetch(PDO::FETCH_ASSOC);

        $stmtBills = $pdo->prepare("SELECT * FROM billing_invoices WHERE account_no = ? ORDER BY due_date DESC");
        $stmtBills->execute([$viewAccount]);
        $clientBills = $stmtBills->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmtAll = $pdo->query("SELECT * FROM clients ORDER BY created_at DESC");
        $allClients = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
    }
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

        /* STRICT PRINT CSS ENGINEERED FOR XP-58 THERMAL PRINTER (58mm / 57x40) */
        @media print {
            body * { visibility: hidden; }
            .print-hide { display: none !important; }
            #print-sticker-area, #print-sticker-area * { visibility: visible; }

            @page {
                size: 58mm auto;
                margin: 0mm;
            }

            #print-sticker-area {
                position: absolute;
                left: 0;
                top: 0;
                width: 58mm;
                margin: 0;
                padding: 4px;
                background: white;
                color: black;
                font-family: 'Courier New', Courier, monospace !important;
                display: flex;
                flex-direction: column;
                align-items: center;
                text-align: center;
                border: none;
            }
        }
    </style>
</head>
<body class="bg-noceco-bg text-noceco-text flex flex-col md:flex-row h-screen overflow-hidden antialiased">

    <div id="print-sticker-area" class="hidden print:flex flex-col items-center justify-center bg-white mx-auto">
        <h1 class="text-2xl font-black uppercase tracking-widest text-black leading-none mb-1">NOCECO</h1>
        <p class="text-[10px] font-bold text-black uppercase tracking-widest border-b border-black pb-1 mb-3 w-full text-center">Meter Tag ID</p>

        <p id="sticker-name" class="font-bold text-sm text-black leading-tight mb-1 truncate w-full px-1 text-center">CONSUMER NAME</p>
        <p id="sticker-account" class="font-mono text-xs font-bold text-black mb-3">00-000-00000</p>

        <img id="sticker-qr" src="" alt="QR Code" style="width: 130px; height: 130px;" class="mb-2">

        <p class="text-[8px] text-black font-bold uppercase tracking-widest mb-1">Scan for E-Billing</p>
        <div style="margin-bottom: 5mm; color: white;">.</div>
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
                <a href="home.php?page=accounts" class="flex items-center px-4 py-3 <?php echo $page === 'accounts' ? 'bg-noceco-bg text-noceco-mustard font-bold rounded-xl shadow-sm border border-gray-100' : 'text-gray-700 hover:bg-gray-50 rounded-xl'; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                    Manage Accounts
                </a>
                <a href="home.php?page=send_notice" class="flex items-center px-4 py-3 <?php echo $page === 'send_notice' ? 'bg-noceco-bg text-noceco-mustard font-bold rounded-xl shadow-sm border border-gray-100' : 'text-gray-700 hover:bg-gray-50 rounded-xl'; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h6m-9 8l2.5-2.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    Send Notice
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
                    <h2 class="text-lg md:text-xl font-black tracking-tight text-gray-900">
                        <?php echo $page === 'send_notice' ? 'Send Notice' : 'Customer Support'; ?>
                    </h2>
                    <p class="text-[10px] md:text-xs text-gray-500 font-bold uppercase tracking-widest hidden sm:block">
                        <?php echo $page === 'send_notice' ? 'Webhook SMS Sender' : 'Profiles & Meter Stickers'; ?>
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <div class="text-right hidden sm:block">
                    <p class="text-[10px] font-bold text-gray-400 uppercase">Active Desk</p>
                    <p class="text-sm font-black text-gray-900"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Staff'); ?></p>
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

                <?php if ($page === 'send_notice'): ?>
                    <div class="mb-6">
                        <h3 class="font-bold text-gray-900 text-lg">Send SMS Notice</h3>
                        <p class="text-xs text-gray-500">Use the two forms below to fetch recipients through the webhook preview and send messages through Semaphore.</p>
                    </div>

                    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                        <div class="bg-white rounded-[24px] shadow-apple border border-gray-200 overflow-hidden">
                            <div class="p-6 border-b border-gray-100 bg-gray-50">
                                <div class="flex items-center justify-between gap-3">
                                    <div>
                                        <h3 class="text-lg font-black text-gray-900">Disconnection Notice</h3>
                                        <p class="text-xs text-gray-500">Disconnected accounts with unpaid bills.</p>
                                    </div>
                                    <span class="text-[10px] font-black uppercase tracking-widest px-3 py-1 rounded bg-red-50 text-red-600 border border-red-100">Disconnected Clients</span>
                                </div>
                            </div>

                            <form id="disconnectionForm" class="p-6 space-y-4">
                                <input type="hidden" name="notice_type" value="disconnection">
                                <div class="bg-red-50 border border-red-100 p-4 rounded-xl text-sm text-red-800">
                                    This will target all accounts with status <strong>Disconnected</strong> and the latest unpaid invoice.
                                </div>

                                <div class="flex gap-3">
                                    <button type="button" onclick="loadNoticePreview('disconnection')" class="w-1/2 bg-white hover:bg-gray-50 text-gray-700 border border-gray-200 font-bold py-3 rounded-xl shadow-sm transition-all text-sm">
                                        Fetch Recipients
                                    </button>
                                    <button type="button" onclick="sendNotice('disconnection')" class="w-1/2 bg-gray-900 hover:bg-black text-white font-bold py-3 rounded-xl shadow-md transition-all text-sm">
                                        Send SMS
                                    </button>
                                </div>
                            </form>

                            <div class="px-6 pb-6">
                                <div id="disconnectionStatus" class="text-xs font-bold text-gray-500 mb-3">No data loaded yet.</div>
                                <div id="disconnectionPreview" class="max-h-[360px] overflow-y-auto custom-scrollbar border border-gray-100 rounded-xl"></div>
                            </div>
                        </div>

                        <div class="bg-white rounded-[24px] shadow-apple border border-gray-200 overflow-hidden">
                            <div class="p-6 border-b border-gray-100 bg-gray-50">
                                <div class="flex items-center justify-between gap-3">
                                    <div>
                                        <h3 class="text-lg font-black text-gray-900">Due Date Notice</h3>
                                        <p class="text-xs text-gray-500">Unpaid bills on the chosen due date.</p>
                                    </div>
                                    <span class="text-[10px] font-black uppercase tracking-widest px-3 py-1 rounded bg-blue-50 text-blue-600 border border-blue-100">DueDate Clients</span>
                                </div>
                            </div>

                            <form id="dueDateForm" class="p-6 space-y-4">
                                <input type="hidden" name="notice_type" value="due_date">
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-2">Due Date</label>
                                    <input type="date" id="dueDateValue" name="due_date" value="<?php echo date('Y-m-d'); ?>"
                                           class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-noceco-mustard outline-none transition-all font-bold text-gray-900">
                                </div>

                                <div class="bg-blue-50 border border-blue-100 p-4 rounded-xl text-sm text-blue-800">
                                    This will target connected accounts with unpaid bills that match the selected due date.
                                </div>

                                <div class="flex gap-3">
                                    <button type="button" onclick="loadNoticePreview('due_date')" class="w-1/2 bg-white hover:bg-gray-50 text-gray-700 border border-gray-200 font-bold py-3 rounded-xl shadow-sm transition-all text-sm">
                                        Fetch Recipients
                                    </button>
                                    <button type="button" onclick="sendNotice('due_date')" class="w-1/2 bg-gray-900 hover:bg-black text-white font-bold py-3 rounded-xl shadow-md transition-all text-sm">
                                        Send SMS
                                    </button>
                                </div>
                            </form>

                            <div class="px-6 pb-6">
                                <div id="dueDateStatus" class="text-xs font-bold text-gray-500 mb-3">No data loaded yet.</div>
                                <div id="dueDatePreview" class="max-h-[360px] overflow-y-auto custom-scrollbar border border-gray-100 rounded-xl"></div>
                            </div>
                        </div>
                    </div>

                <?php else: ?>
                    <?php if ($viewAccount && $clientDetails): ?>
                        <div class="mb-6">
                            <a href="home.php?page=accounts" class="text-noceco-mustard font-bold text-sm hover:underline flex items-center gap-1">
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
                                        Print Sticker (Thermal)
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
                        <div class="mb-8">
                            <div class="flex justify-between items-end mb-4">
                                <div>
                                    <h3 class="font-bold text-gray-900 text-lg flex items-center gap-2">
                                        <svg class="w-5 h-5 text-noceco-mustard" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path></svg>
                                        Automated Notice System
                                    </h3>
                                    <p class="text-xs text-gray-500">Notices sent to disconnected clients with bills due today.</p>
                                </div>
                                <?php if($autoSmsCount > 0): ?>
                                    <span class="bg-green-100 text-green-700 text-[10px] font-bold px-3 py-1 rounded border border-green-200">+<?php echo $autoSmsCount; ?> Sent Today</span>
                                <?php endif; ?>
                            </div>

                            <div class="bg-white rounded-[20px] shadow-apple border border-gray-200 overflow-hidden">
                                <div class="max-h-48 overflow-y-auto custom-scrollbar p-1">
                                    <?php if(empty($smsLogs)): ?>
                                        <div class="p-8 text-center">
                                            <p class="text-sm font-bold text-gray-400">No automated notices have been dispatched yet.</p>
                                        </div>
                                    <?php else: ?>
                                        <table class="w-full text-left text-sm whitespace-nowrap">
                                            <tbody class="divide-y divide-gray-100">
                                                <?php foreach (array_slice($smsLogs, 0, 15) as $log): ?>
                                                    <tr class="hover:bg-gray-50">
                                                        <td class="p-3 w-1/4">
                                                            <p class="font-bold text-gray-900"><?php echo htmlspecialchars($log['last_name'].', '.$log['first_name']); ?></p>
                                                            <p class="text-[10px] text-gray-500 font-mono"><?php echo htmlspecialchars($log['contact_number']); ?></p>
                                                        </td>
                                                        <td class="p-3 w-3/4">
                                                            <p class="text-xs text-gray-600 truncate max-w-lg" title="<?php echo htmlspecialchars($log['message_content']); ?>">
                                                                <?php echo htmlspecialchars($log['message_content']); ?>
                                                            </p>
                                                        </td>
                                                        <td class="p-3 text-right">
                                                            <span class="bg-blue-50 text-blue-600 px-2 py-1 rounded text-[10px] font-bold uppercase tracking-widest border border-blue-100">Dispatched</span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white p-2 rounded-xl shadow-sm border border-gray-200 mb-6 flex items-center sticky top-0 z-20">
                            <svg class="w-5 h-5 text-gray-400 ml-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
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
                                        <a href="home.php?page=accounts&view_account=<?php echo htmlspecialchars($c['account_no']); ?>" class="bg-gray-50 hover:bg-gray-100 text-gray-700 text-center py-2 rounded-lg font-bold text-[11px] transition-colors border border-gray-200 uppercase tracking-wide">
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
                <?php endif; ?>
            </div>
        </main>
    </div>

    <div id="passwordModal" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4 print-hide">
        <div class="bg-white w-full max-w-sm rounded-[24px] shadow-2xl overflow-hidden transform transition-all">
            <div class="p-5 border-b border-gray-100 bg-gray-50 flex justify-between items-center">
                <h3 class="font-black text-gray-900 text-lg">Reset Password</h3>
                <button type="button" onclick="closePasswordModal()" class="text-gray-400 hover:text-gray-900 bg-white border border-gray-200 rounded-full p-1 shadow-sm">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <form action="home.php?page=accounts" method="POST" class="p-6 space-y-4">
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

    <div id="noticeToast" class="fixed bottom-5 right-5 z-[60] hidden print-hide">
        <div class="bg-gray-900 text-white rounded-xl shadow-2xl px-4 py-3 text-sm font-bold max-w-sm"></div>
    </div>

    <script>
        function showNoticeToast(text) {
            const toast = document.getElementById('noticeToast');
            if (!toast) return;
            toast.querySelector('div').textContent = text;
            toast.classList.remove('hidden');
            clearTimeout(window.__noticeToastTimer);
            window.__noticeToastTimer = setTimeout(() => toast.classList.add('hidden'), 3000);
        }

        document.getElementById('domSearchInput')?.addEventListener('input', function(e) {
            const filter = e.target.value.toUpperCase();
            const cards = document.querySelectorAll('.client-card');

            cards.forEach(card => {
                const text = card.innerText.toUpperCase();
                card.style.display = text.includes(filter) ? '' : 'none';
            });
        });

        function openPasswordModal(accountNo, consumerName) {
            document.getElementById('modal_account_no').value = accountNo;
            document.getElementById('modal_consumer_name').innerText = consumerName;
            document.getElementById('passwordModal').classList.remove('hidden');
        }

        function closePasswordModal() {
            document.getElementById('passwordModal').classList.add('hidden');
        }

        function triggerPrintSticker(name, account) {
            document.getElementById('sticker-name').innerText = name;
            document.getElementById('sticker-account').innerText = account;
            document.getElementById('sticker-qr').src = 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' + encodeURIComponent(account);

            setTimeout(() => {
                window.print();
            }, 500);
        }

        function getNoticeForm(type) {
            return document.getElementById(type === 'disconnection' ? 'disconnectionForm' : 'dueDateForm');
        }

        function getNoticePreviewBox(type) {
            return document.getElementById(type === 'disconnection' ? 'disconnectionPreview' : 'dueDatePreview');
        }

        function getNoticeStatusBox(type) {
            return document.getElementById(type === 'disconnection' ? 'disconnectionStatus' : 'dueDateStatus');
        }

        function getDueDateValue() {
            const input = document.getElementById('dueDateValue');
            return input ? input.value : '';
        }

        async function loadNoticePreview(type) {
            const statusBox = getNoticeStatusBox(type);
            const previewBox = getNoticePreviewBox(type);
            const dueDate = type === 'due_date' ? getDueDateValue() : '';

            if (statusBox) statusBox.textContent = 'Fetching recipients...';
            if (previewBox) previewBox.innerHTML = '<div class="p-4 text-sm text-gray-500">Loading...</div>';

            const url = new URL(window.location.href);
            url.searchParams.set('action', 'preview_notice');
            url.searchParams.set('type', type);

            if (dueDate) {
                url.searchParams.set('due_date', dueDate);
            }

            try {
                const response = await fetch(url.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await response.json();

                renderNoticePreview(type, data);
            } catch (err) {
                if (statusBox) statusBox.textContent = 'Failed to fetch recipients.';
                if (previewBox) previewBox.innerHTML = '<div class="p-4 text-sm text-red-600">Unable to load data.</div>';
            }
        }

        function renderNoticePreview(type, data) {
            const statusBox = getNoticeStatusBox(type);
            const previewBox = getNoticePreviewBox(type);

            if (!data || !data.success) {
                if (statusBox) statusBox.textContent = data?.message || 'Unable to load recipients.';
                if (previewBox) previewBox.innerHTML = '<div class="p-4 text-sm text-red-600">No data available.</div>';
                return;
            }

            if (statusBox) {
                statusBox.textContent = `${data.count} recipient(s) ready for sending.`;
            }

            if (!data.items || data.items.length === 0) {
                if (previewBox) {
                    previewBox.innerHTML = '<div class="p-4 text-sm text-gray-500">No recipients found.</div>';
                }
                return;
            }

            let html = `
                <table class="w-full text-left text-xs">
                    <thead class="bg-gray-50 sticky top-0">
                        <tr class="text-[10px] font-black uppercase tracking-widest text-gray-500">
                            <th class="p-3">Name</th>
                            <th class="p-3">Account</th>
                            <th class="p-3">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
            `;

            data.items.forEach(item => {
                html += `
                    <tr class="hover:bg-gray-50">
                        <td class="p-3">
                            <p class="font-bold text-gray-900">${escapeHtml(item.full_name || '')}</p>
                            <p class="text-[10px] text-gray-500">${escapeHtml(item.contact_number || '')}</p>
                        </td>
                        <td class="p-3">
                            <p class="font-mono font-bold text-gray-700">${escapeHtml(item.account_no || '')}</p>
                            <p class="text-[10px] text-gray-500">${escapeHtml(item.invoice_no || '')}</p>
                        </td>
                        <td class="p-3">
                            <p class="font-black text-gray-900">₱${escapeHtml(item.amount_due || '0.00')}</p>
                            <p class="text-[10px] text-gray-500">${escapeHtml(item.billing_month || '')}</p>
                        </td>
                    </tr>
                `;
            });

            html += `</tbody></table>`;
            if (previewBox) previewBox.innerHTML = html;
        }

        async function sendNotice(type) {
            const form = getNoticeForm(type);
            if (!form) return;

            const dueDate = type === 'due_date' ? getDueDateValue() : '';
            const fd = new FormData(form);
            fd.append('action', 'send_notice');

            if (dueDate) {
                fd.set('due_date', dueDate);
            }

            if (!confirm('Send this notice now?')) {
                return;
            }

            try {
                const response = await fetch('home.php?page=send_notice', {
                    method: 'POST',
                    body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                const data = await response.json();

                if (data.success) {
                    showNoticeToast(`Done: ${data.sent} sent, ${data.failed} failed, ${data.skipped} skipped.`);
                    await loadNoticePreview(type);
                } else {
                    showNoticeToast(data.message || 'Sending failed.');
                }
            } catch (err) {
                showNoticeToast('Unable to send notice.');
            }
        }

        function escapeHtml(value) {
            return String(value)
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }
    </script>
</body>
</html>