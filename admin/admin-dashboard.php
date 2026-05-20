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
// AJAX ENDPOINT: REAL-TIME DATA TRANSMITTAL (Top Cards)
// ---------------------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] == 'true') {
    header('Content-Type: application/json');
    try {
        $stmt1 = $pdo->query("SELECT COUNT(*) as total FROM clients WHERE status = 'Connected'");
        $activeConsumers = $stmt1->fetch()['total'];

        $stmt2 = $pdo->query("SELECT SUM(amount_paid) as revenue FROM payments WHERE DATE(payment_date) = CURDATE() AND status IN ('Success', 'Verified')");
        $revenueToday = $stmt2->fetch()['revenue'] ?? 0.00;

        $stmt3 = $pdo->query("SELECT COUNT(*) as count, SUM(amount_due) as total FROM billing_invoices WHERE status = 'Unpaid'");
        $pendingBills = $stmt3->fetch();

        echo json_encode([
            'status' => 'success',
            'active_consumers' => number_format($activeConsumers),
            'revenue_today' => 'Php ' . number_format($revenueToday, 2),
            'pending_count' => number_format($pendingBills['count']),
            'pending_total' => 'Php ' . number_format($pendingBills['total'], 2)
        ]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit();
}

// ---------------------------------------------------------------------
// ANALYTICS PRE-LOAD (For Charts & Dashboards)
// ---------------------------------------------------------------------
try {
    // 1. Real 7-Day Revenue Trend
    $stmtGraph = $pdo->query("SELECT DATE(payment_date) as date, SUM(amount_paid) as total 
                              FROM payments 
                              WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) 
                              AND status IN ('Success', 'Verified') 
                              GROUP BY DATE(payment_date) 
                              ORDER BY date ASC");
    $revenueData = $stmtGraph->fetchAll();
    
    // Normalize data (Fill missing days with 0)
    $dates = [];
    $totals = [];
    for ($i = 6; $i >= 0; $i--) {
        $targetDate = date('Y-m-d', strtotime("-$i days"));
        $dates[] = date('M d', strtotime($targetDate));
        
        $foundTotal = 0;
        foreach($revenueData as $row) {
            if($row['date'] === $targetDate) {
                $foundTotal = (float)$row['total'];
                break;
            }
        }
        $totals[] = $foundTotal;
    }

    // 2. Active vs Deactivated Consumers
    $stmtStatus = $pdo->query("SELECT status, COUNT(*) as count FROM clients GROUP BY status");
    $statusData = $stmtStatus->fetchAll();
    $activeCount = 0;
    $disconnectedCount = 0;
    foreach($statusData as $s) {
        if(strtolower($s['status']) == 'connected') $activeCount = $s['count'];
        else $disconnectedCount = $s['count'];
    }

    // 3. City / Municipality Distribution Extraction
    $stmtCities = $pdo->query("SELECT address FROM clients");
    $addresses = $stmtCities->fetchAll(PDO::FETCH_COLUMN);
    $cityCounts = [];
    foreach($addresses as $address) {
        $parts = explode(',', $address);
        // Extract the last part after the comma (usually the city/municipality)
        $rawCity = trim(end($parts)); 
        // Clean up common prefixes for a cleaner graph
        $city = str_ireplace(['City Of ', 'City'], '', $rawCity); 
        $city = ucwords(strtolower(trim($city)));
        
        if(!empty($city)) {
            if(!isset($cityCounts[$city])) $cityCounts[$city] = 0;
            $cityCounts[$city]++;
        }
    }
    arsort($cityCounts); // Sort highest population first
    $cityLabels = array_keys($cityCounts);
    $cityData = array_values($cityCounts);

} catch (PDOException $e) {
    die("Analytics Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Command Center | NOCECO</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
    <style>
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #E5E7EB; border-radius: 10px; }
    </style>
</head>
<body class="bg-noceco-bg text-noceco-text flex h-screen overflow-hidden">

    <aside class="w-64 bg-white border-r border-gray-200 flex flex-col justify-between hidden md:flex z-20 relative">
        <div>
            <div class="h-20 flex items-center px-8 border-b border-gray-100">
                <div class="w-8 h-8 rounded-full bg-noceco-mustard flex items-center justify-center mr-3 shadow-apple-sm">
                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                </div>
                <span class="font-bold text-lg tracking-tight">NOCECO</span>
            </div>

            <nav class="p-4 space-y-1.5">
                <a href="admin-dashboard.php" class="flex items-center px-4 py-3 bg-noceco-bg/80 text-noceco-mustard font-medium rounded-xl shadow-sm">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                    Command Center
                </a>
                
                <a href="announcements.php" class="flex items-center px-4 py-3 text-gray-500 hover:bg-gray-50 hover:text-gray-900 rounded-xl transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path></svg>
                    Web Content (CMS)
                </a>

                <a href="register-admin.php" class="flex items-center px-4 py-3 text-gray-500 hover:bg-gray-50 hover:text-gray-900 rounded-xl transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path></svg>
                    Register Staff
                </a>
                <a href="../consumers/add-consumer.php" class="flex items-center px-4 py-3 text-gray-500 hover:bg-gray-50 hover:text-gray-900 rounded-xl transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                    Register Client
                </a>
                <a href="../consumers/manage-consumers.php" class="flex items-center px-4 py-3 text-gray-500 hover:bg-gray-50 hover:text-gray-900 rounded-xl transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                    Manage Consumers
                </a>
                <a href="billing-rates.php" class="flex items-center px-4 py-3 text-gray-500 hover:bg-gray-50 hover:text-gray-900 rounded-xl transition-colors">
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

    <main class="flex-1 flex flex-col h-screen overflow-y-auto custom-scrollbar">
       
        <header class="h-20 bg-white/80 backdrop-blur-md border-b border-gray-200 flex items-center justify-between px-8 sticky top-0 z-10">
            <div>
                <h2 class="text-xl font-semibold tracking-tight">Analytics Overview</h2>
                <p class="text-xs text-gray-500" id="live-time">Real-time Data Synchronized</p>
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
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-white rounded-2xl p-6 shadow-apple border border-gray-100">
                    <p class="text-sm font-medium text-gray-500 mb-1">Active Connected Users</p>
                    <h3 class="text-3xl font-bold tracking-tight text-gray-900" id="ui-consumers"><?php echo number_format($activeCount); ?></h3>
                    <p class="text-xs text-green-500 font-bold mt-2 flex items-center"><span class="w-2 h-2 rounded-full bg-green-500 mr-2 animate-pulse"></span> Network Stable</p>
                </div>
                <div class="bg-white rounded-2xl p-6 shadow-apple border border-gray-100 relative overflow-hidden group">
                    <div class="absolute -right-6 -top-6 w-24 h-24 bg-noceco-mustard/10 rounded-full group-hover:scale-150 transition-transform duration-500"></div>
                    <p class="text-sm font-medium text-gray-500 mb-1">Today's Revenue</p>
                    <h3 class="text-3xl font-bold tracking-tight text-gray-900" id="ui-revenue">Php 0.00</h3>
                    <p class="text-xs text-gray-400 mt-2">Walk-in & Online verified</p>
                </div>
                <div class="bg-white rounded-2xl p-6 shadow-apple border border-gray-100">
                    <p class="text-sm font-medium text-gray-500 mb-1">Pending Receivables</p>
                    <h3 class="text-3xl font-bold tracking-tight text-red-500" id="ui-pending-total">Php 0.00</h3>
                    <p class="text-xs text-gray-400 mt-2"><span id="ui-pending-count">0</span> unpaid invoices</p>
                </div>
                <div class="bg-white rounded-2xl p-6 shadow-apple border border-gray-100">
                    <p class="text-sm font-medium text-gray-500 mb-1">Municipalities Served</p>
                    <h3 class="text-3xl font-bold tracking-tight text-blue-600"><?php echo count($cityCounts); ?></h3>
                    <p class="text-xs text-gray-400 mt-2">Active service areas</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                <div class="lg:col-span-2 bg-white rounded-2xl shadow-apple border border-gray-100 p-6">
                    <div class="flex justify-between items-center mb-6">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 tracking-tight">7-Day Revenue Trend</h3>
                            <p class="text-xs text-gray-500">Official collected payments across all gateways</p>
                        </div>
                    </div>
                    <div class="relative h-[280px] w-full">
                        <canvas id="revenueChart"></canvas>
                    </div>
                </div>

                <div class="bg-white rounded-2xl shadow-apple border border-gray-100 p-6 flex flex-col items-center justify-center">
                    <h3 class="text-lg font-semibold text-gray-900 tracking-tight w-full mb-4">Network Health</h3>
                    <div class="relative h-[220px] w-full flex justify-center">
                        <canvas id="statusChart"></canvas>
                    </div>
                    <div class="w-full flex justify-between mt-6 px-4">
                        <div class="text-center">
                            <p class="text-xl font-black text-green-500"><?php echo $activeCount; ?></p>
                            <p class="text-[10px] text-gray-500 uppercase font-bold tracking-widest">Connected</p>
                        </div>
                        <div class="text-center">
                            <p class="text-xl font-black text-red-500"><?php echo $disconnectedCount; ?></p>
                            <p class="text-[10px] text-gray-500 uppercase font-bold tracking-widest">Deactivated</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-white rounded-2xl shadow-apple border border-gray-100 p-6">
                    <div class="mb-6">
                        <h3 class="text-lg font-semibold text-gray-900 tracking-tight">Consumers per Municipality</h3>
                        <p class="text-xs text-gray-500">Demographic distribution of active users</p>
                    </div>
                    <div class="relative h-[250px] w-full">
                        <canvas id="cityChart"></canvas>
                    </div>
                </div>
                
                <div class="bg-white rounded-2xl shadow-apple border border-gray-100 overflow-hidden flex flex-col">
                    <div class="p-6 border-b border-gray-100 bg-gray-50/50">
                        <h3 class="text-lg font-semibold text-gray-900 tracking-tight">Top Service Areas</h3>
                    </div>
                    <div class="p-0 overflow-y-auto max-h-[250px] custom-scrollbar">
                        <table class="w-full text-left text-sm">
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach(array_slice($cityCounts, 0, 5) as $city => $count): ?>
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="p-4 font-bold text-gray-700"><?php echo htmlspecialchars($city); ?></td>
                                    <td class="p-4 text-right">
                                        <span class="bg-blue-50 text-blue-600 px-3 py-1 rounded-full font-bold text-xs">
                                            <?php echo number_format($count); ?> users
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <script>
        // -----------------------------------------------------
        // AJAX Real-Time Data Fetching (Counters Only)
        // -----------------------------------------------------
        function fetchDashboardData() {
            fetch('admin-dashboard.php?ajax=true')
                .then(response => response.json())
                .then(data => {
                    if(data.status === 'success') {
                        document.getElementById('ui-consumers').textContent = data.active_consumers;
                        document.getElementById('ui-revenue').textContent = data.revenue_today;
                        document.getElementById('ui-pending-count').textContent = data.pending_count;
                        document.getElementById('ui-pending-total').textContent = data.pending_total;
                    }
                })
                .catch(error => console.error('Data sync failed:', error));
        }
        fetchDashboardData();
        setInterval(fetchDashboardData, 5000); // Check every 5 seconds

        setInterval(() => {
            const now = new Date();
            document.getElementById('live-time').textContent = now.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit', second:'2-digit'}) + ' - Sync Active';
        }, 1000);

        // -----------------------------------------------------
        // CHART 1: 7-Day Revenue (Line Chart)
        // -----------------------------------------------------
        const ctxRev = document.getElementById('revenueChart').getContext('2d');
        const revGradient = ctxRev.createLinearGradient(0, 0, 0, 300);
        revGradient.addColorStop(0, 'rgba(219, 161, 17, 0.4)');
        revGradient.addColorStop(1, 'rgba(219, 161, 17, 0.0)');

        new Chart(ctxRev, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($dates); ?>,
                datasets: [{
                    label: 'Revenue (Php)',
                    data: <?php echo json_encode($totals); ?>,
                    borderColor: '#DBA111', // Mustard
                    backgroundColor: revGradient,
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4, // Smooth Apple-style curves
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: '#DBA111',
                    pointBorderWidth: 2,
                    pointRadius: 4
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, grid: { color: '#f3f4f6', drawBorder: false }, ticks: { color: '#9ca3af' } },
                    x: { grid: { display: false, drawBorder: false }, ticks: { color: '#9ca3af' } }
                }
            }
        });

        // -----------------------------------------------------
        // CHART 2: Network Health (Doughnut Chart)
        // -----------------------------------------------------
        const ctxStatus = document.getElementById('statusChart').getContext('2d');
        new Chart(ctxStatus, {
            type: 'doughnut',
            data: {
                labels: ['Connected', 'Deactivated'],
                datasets: [{
                    data: [<?php echo $activeCount; ?>, <?php echo $disconnectedCount; ?>],
                    backgroundColor: ['#22c55e', '#ef4444'], // Tailwind green-500, red-500
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '75%',
                plugins: { legend: { display: false } }
            }
        });

        // -----------------------------------------------------
        // CHART 3: City Demographics (Bar Chart)
        // -----------------------------------------------------
        const ctxCity = document.getElementById('cityChart').getContext('2d');
        new Chart(ctxCity, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_slice($cityLabels, 0, 8)); ?>, // Top 8 cities to fit graph
                datasets: [{
                    label: 'Consumers',
                    data: <?php echo json_encode(array_slice($cityData, 0, 8)); ?>,
                    backgroundColor: '#3b82f6', // Tailwind blue-500
                    borderRadius: 6,
                    barThickness: 24
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, grid: { display: false }, ticks: { color: '#9ca3af' } },
                    x: { grid: { display: false }, ticks: { color: '#9ca3af', font: { size: 10 } } }
                }
            }
        });
    </script>
</body>
</html>