<?php
session_start();
require_once '../db/dbcon.php';

// ---------------------------------------------------------------------
// SECURITY: ROLE VERIFICATION
// ---------------------------------------------------------------------
if (!isset($_SESSION['staff_id']) || $_SESSION['role'] !== 'Main Administrator') {
    header("Location: ../administrator.php");
    exit();
}

// ---------------------------------------------------------------------
// DATE RANGE FILTER LOGIC
// ---------------------------------------------------------------------
// Default to the current month if no dates are selected
$startDate = $_GET['start_date'] ?? date('Y-m-01'); 
$endDate = $_GET['end_date'] ?? date('Y-m-t');

try {
    // 1. CLIENT DEMOGRAPHICS (Current Snapshot)
    $stmtClients = $pdo->query("SELECT status, COUNT(*) as count FROM clients GROUP BY status");
    $clientData = $stmtClients->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $connectedClients = $clientData['Connected'] ?? 0;
    $disconnectedClients = $clientData['Disconnected'] ?? 0;
    $totalClients = $connectedClients + $disconnectedClients;

    // 2. TOTAL INCOME (Filtered by Date Range)
    $stmtIncome = $pdo->prepare("SELECT SUM(amount_paid) FROM payments WHERE status IN ('Success', 'Verified') AND DATE(payment_date) BETWEEN ? AND ?");
    $stmtIncome->execute([$startDate, $endDate]);
    $totalIncome = $stmtIncome->fetchColumn() ?: 0.00;

    // 3. OVERALL KWH CONSUMPTION (Filtered by Date Range)
    $stmtKwh = $pdo->prepare("SELECT SUM(kwh_used) FROM billing_invoices WHERE DATE(reading_date) BETWEEN ? AND ?");
    $stmtKwh->execute([$startDate, $endDate]);
    $totalKwh = $stmtKwh->fetchColumn() ?: 0;

    // 4. CHART DATA: MONTHLY INCOME GENERATION (Last 12 Months)
    $stmtChart = $pdo->query("
        SELECT DATE_FORMAT(payment_date, '%b %Y') as month_label, SUM(amount_paid) as total 
        FROM payments 
        WHERE status IN ('Success', 'Verified') AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(payment_date, '%Y-%m'), month_label 
        ORDER BY MAX(payment_date) ASC
    ");
    $monthlyData = $stmtChart->fetchAll();
    
    $chartLabels = [];
    $chartTotals = [];
    foreach ($monthlyData as $row) {
        $chartLabels[] = $row['month_label'];
        $chartTotals[] = $row['total'];
    }

} catch (PDOException $e) {
    error_log("Report Generation Error: " . $e->getMessage());
    die("A database error occurred while generating the report.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Executive Report | NOCECO</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
      tailwind.config = {
        theme: {
          extend: {
            colors: { noceco: { bg: '#F5F5F7', mustard: '#DBA111', mustardHover: '#B8860B' } },
            fontFamily: { sans: ['-apple-system', 'BlinkMacSystemFont', '"Segoe UI"', 'Roboto', 'sans-serif'] },
            boxShadow: { 'apple': '0 4px 24px rgba(0, 0, 0, 0.04)', 'apple-sm': '0 2px 8px rgba(0, 0, 0, 0.04)' }
          }
        }
      }
    </script>
    <style>
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #E5E7EB; border-radius: 10px; }
        
        /* STRICT PRINT STYLES FOR PDF EXPORT */
        @media print {
            @page { size: A4 portrait; margin: 15mm; }
            body { background: white !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            
            /* Hide UI elements */
            .print-hide { display: none !important; }
            aside { display: none !important; }
            
            /* Force content to expand */
            main { padding: 0 !important; margin: 0 !important; width: 100% !important; overflow: visible !important; height: auto !important; }
            .content-wrapper { height: auto !important; overflow: visible !important; }
            
            /* Show Letterhead */
            .print-header { display: block !important; border-bottom: 2px solid #1D1D1F; padding-bottom: 10px; margin-bottom: 20px; }
            
            /* Box Styling for Print */
            .print-box { border: 1px solid #E5E7EB !important; box-shadow: none !important; break-inside: avoid; }
            
            /* Adjust Grid for paper */
            .print-grid { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; gap: 1rem !important; }
            .print-grid-3 { grid-template-columns: repeat(3, minmax(0, 1fr)) !important; }
        }
    </style>
</head>
<body class="bg-noceco-bg text-gray-900 flex h-screen overflow-hidden">

    <aside class="w-64 bg-white border-r border-gray-200 flex flex-col justify-between hidden md:flex z-30 shrink-0 print-hide">
        <div>
            <div class="h-20 flex items-center px-8 border-b border-gray-100">
                <div class="w-8 h-8 rounded-full bg-noceco-mustard flex items-center justify-center mr-3 shadow-apple-sm">
                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                </div>
                <span class="font-bold text-lg tracking-tight">NOCECO</span>
            </div>
            <nav class="p-4 space-y-1.5">
                <a href="admin-dashboard.php" class="flex items-center px-4 py-3 text-gray-500 hover:bg-gray-50 rounded-xl transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                    Command Center
                </a>
                <a href="report.php" class="flex items-center px-4 py-3 bg-noceco-bg/80 text-noceco-mustard font-bold rounded-xl shadow-sm">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    Data Reports
                </a>
                <a href="announcements.php" class="flex items-center px-4 py-3 text-gray-500 hover:bg-gray-50 rounded-xl transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path></svg>
                    Web Content (CMS)
                </a>
                <a href="register-admin.php" class="flex items-center px-4 py-3 text-gray-500 hover:bg-gray-50 rounded-xl transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path></svg>
                    Register Staff
                </a>
            </nav>
        </div>
        <div class="p-4 border-t border-gray-100">
            <a href="../logout.php" class="flex items-center px-4 py-3 text-red-500 hover:bg-red-50 rounded-xl font-medium">Logout</a>
        </div>
    </aside>

    <div class="flex-1 flex flex-col h-screen overflow-hidden content-wrapper w-full">
        
        <header class="h-20 bg-white border-b border-gray-200 flex items-center justify-between px-8 shrink-0 z-20 print-hide">
            <div>
                <h2 class="text-xl font-bold tracking-tight">Executive Data Report</h2>
                <p class="text-xs text-gray-500">Analytics and revenue generation statistics.</p>
            </div>
            <div class="flex gap-3">
                <button onclick="window.print()" class="bg-noceco-mustard hover:bg-noceco-mustardHover text-white px-6 py-2 rounded-lg font-bold text-sm transition-all shadow-sm flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                    Print / Export PDF
                </button>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto p-8 custom-scrollbar bg-noceco-bg">
            <div class="max-w-6xl mx-auto">

                <div class="hidden print-header text-center w-full">
                    <div class="flex justify-center items-center gap-4 mb-2">
                        <img src="../images/NOCECO.png" style="width:60px; height:60px; object-fit:contain;" alt="Logo">
                        <div class="text-left">
                            <h1 class="text-2xl font-black text-gray-900 leading-none">NOCECO</h1>
                            <p class="text-[10px] font-bold text-gray-500 uppercase tracking-widest">Negros Occidental Electric Cooperative</p>
                        </div>
                    </div>
                    <h2 class="text-lg font-bold mt-4 uppercase border-t pt-4">Executive Financial & Operations Report</h2>
                    <p class="text-xs text-gray-500 font-medium">Reporting Period: <?php echo date('F d, Y', strtotime($startDate)); ?> to <?php echo date('F d, Y', strtotime($endDate)); ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Generated by: <?php echo htmlspecialchars($_SESSION['full_name']); ?> on <?php echo date('M d, Y h:i A'); ?></p>
                </div>

                <div class="bg-white rounded-2xl shadow-apple border border-gray-100 p-6 mb-8 print-hide">
                    <form action="report.php" method="GET" class="flex flex-col sm:flex-row items-end gap-4">
                        <div class="w-full sm:w-1/3">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-2">Date From</label>
                            <input type="date" name="start_date" value="<?php echo $startDate; ?>" class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-noceco-mustard outline-none">
                        </div>
                        <div class="w-full sm:w-1/3">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-2">Date To</label>
                            <input type="date" name="end_date" value="<?php echo $endDate; ?>" class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-noceco-mustard outline-none">
                        </div>
                        <div class="w-full sm:w-1/3">
                            <button type="submit" class="w-full bg-gray-900 hover:bg-black text-white font-bold py-2 rounded-lg transition-colors">
                                Generate Data
                            </button>
                        </div>
                    </form>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8 print-grid">
                    
                    <div class="bg-white rounded-[24px] p-8 shadow-apple border border-gray-100 print-box">
                        <div class="flex items-center gap-3 mb-2">
                            <div class="w-8 h-8 rounded-full bg-green-50 text-green-600 flex items-center justify-center">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            </div>
                            <h3 class="text-sm font-bold text-gray-500 uppercase tracking-widest">Total Income Generated</h3>
                        </div>
                        <p class="text-4xl font-black text-gray-900 tracking-tight mb-1">₱<?php echo number_format($totalIncome, 2); ?></p>
                        <p class="text-xs text-gray-400 font-medium">Collected between <?php echo date('M d', strtotime($startDate)); ?> - <?php echo date('M d, Y', strtotime($endDate)); ?></p>
                    </div>

                    <div class="bg-white rounded-[24px] p-8 shadow-apple border border-gray-100 print-box">
                        <div class="flex items-center gap-3 mb-2">
                            <div class="w-8 h-8 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                            </div>
                            <h3 class="text-sm font-bold text-gray-500 uppercase tracking-widest">Overall Consumption</h3>
                        </div>
                        <p class="text-4xl font-black text-gray-900 tracking-tight mb-1"><?php echo number_format($totalKwh); ?> <span class="text-xl text-gray-400 font-bold">kWh</span></p>
                        <p class="text-xs text-gray-400 font-medium">Billed between <?php echo date('M d', strtotime($startDate)); ?> - <?php echo date('M d, Y', strtotime($endDate)); ?></p>
                    </div>

                </div>

                <div class="mb-8">
                    <h3 class="text-lg font-bold text-gray-900 mb-4 print-hide">Network Demographics</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 print-grid-3">
                        <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 text-center print-box">
                            <p class="text-3xl font-black text-gray-900"><?php echo number_format($totalClients); ?></p>
                            <p class="text-xs font-bold text-gray-400 uppercase tracking-widest mt-1">Total Network Clients</p>
                        </div>
                        <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 text-center print-box border-b-4 border-b-green-500">
                            <p class="text-3xl font-black text-green-600"><?php echo number_format($connectedClients); ?></p>
                            <p class="text-xs font-bold text-gray-400 uppercase tracking-widest mt-1">Active / Connected</p>
                        </div>
                        <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 text-center print-box border-b-4 border-b-red-500">
                            <p class="text-3xl font-black text-red-500"><?php echo number_format($disconnectedClients); ?></p>
                            <p class="text-xs font-bold text-gray-400 uppercase tracking-widest mt-1">Disconnected</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-[24px] shadow-apple border border-gray-100 p-8 print-box">
                    <div class="mb-6">
                        <h3 class="text-lg font-bold text-gray-900 tracking-tight">12-Month Income Generation Trend</h3>
                        <p class="text-xs text-gray-500">Historical performance showing revenue collected per month.</p>
                    </div>
                    <div class="relative h-[350px] w-full">
                        <canvas id="monthlyTrendChart"></canvas>
                    </div>
                </div>

                <div class="hidden print-header border-none mt-10 pt-10 text-center">
                    <p class="text-[10px] text-gray-500 font-bold uppercase tracking-widest">*** END OF REPORT ***</p>
                </div>

            </div>
        </main>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('monthlyTrendChart').getContext('2d');
            
            // Gradient fill
            const gradient = ctx.createLinearGradient(0, 0, 0, 350);
            gradient.addColorStop(0, 'rgba(219, 161, 17, 0.5)'); // Mustard
            gradient.addColorStop(1, 'rgba(219, 161, 17, 0.0)');

            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($chartLabels); ?>,
                    datasets: [{
                        label: 'Total Income (₱)',
                        data: <?php echo json_encode($chartTotals); ?>,
                        borderColor: '#DBA111',
                        backgroundColor: gradient,
                        borderWidth: 3,
                        pointBackgroundColor: '#ffffff',
                        pointBorderColor: '#DBA111',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        fill: true,
                        tension: 0.3 // Smooth curves
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { 
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return '₱ ' + context.parsed.y.toLocaleString(undefined, {minimumFractionDigits: 2});
                                }
                            }
                        }
                    },
                    scales: {
                        y: { 
                            beginAtZero: true, 
                            grid: { color: '#f3f4f6', drawBorder: false },
                            ticks: { 
                                color: '#9ca3af',
                                callback: function(value) {
                                    if(value >= 1000000) return '₱' + (value / 1000000).toFixed(1) + 'M';
                                    if(value >= 1000) return '₱' + (value / 1000).toFixed(0) + 'k';
                                    return '₱' + value;
                                }
                            }
                        },
                        x: { 
                            grid: { display: false, drawBorder: false }, 
                            ticks: { color: '#6b7280', font: { weight: 'bold' } } 
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>