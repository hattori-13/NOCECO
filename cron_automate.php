<?php
// cron_automate.php - Run this via your server's Cron Job (e.g., daily at 1:00 AM)
require_once 'db/dbcon.php';

// A. AUTO-DISCONNECT ACCOUNTS PAST DUE
try {
    // Disconnect clients whose Unpaid bill is > 7 days past due
    $pdo->query("
        UPDATE clients c
        JOIN billing_invoices b ON c.account_no = b.account_no
        SET c.status = 'Disconnected'
        WHERE b.status = 'Unpaid' 
        AND CURDATE() > DATE_ADD(b.due_date, INTERVAL 7 DAY)
        AND c.status = 'Connected'
    ");
} catch (Exception $e) { error_log($e->getMessage()); }

// B. AUTOMATED SMS SENDER (Once per month check)
try {
    $stmtTargets = $pdo->query("
        SELECT c.account_no, c.contact_number, c.first_name, b.amount_due, b.billing_month 
        FROM clients c
        JOIN billing_invoices b ON c.account_no = b.account_no
        WHERE (c.status = 'Disconnected' OR b.due_date = CURDATE())
        AND b.status = 'Unpaid'
    ");
    $targets = $stmtTargets->fetchAll(PDO::FETCH_ASSOC);

    foreach ($targets as $t) {
        $msgType = "Auto-Notice " . $t['billing_month'];
        
        // Only send if not already sent for this month
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM sms_logs WHERE account_no = ? AND message_type = ?");
        $stmtCheck->execute([$t['account_no'], $msgType]);
        
        if ($stmtCheck->fetchColumn() == 0) {
            $smsMessage = "NOCECO NOTICE: Your account " . $t['account_no'] . " has a balance of Php " . number_format($t['amount_due'], 2) . ". Please settle to avoid service interruption.";
            $stmtInsert = $pdo->prepare("INSERT INTO sms_logs (account_no, contact_number, message_type, message_content, sent_status) VALUES (?, ?, ?, ?, 'Pending')");
            $stmtInsert->execute([$t['account_no'], $t['contact_number'], $msgType, $smsMessage]);
        }
    }
} catch (Exception $e) { error_log($e->getMessage()); }
?>