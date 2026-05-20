<?php
// 1. START SESSION
session_start();

// 2. INCLUDE DATABASE CONNECTION
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

// Generate automated unique identifiers
$auto_account_no = date('y') . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT) . '-' . rand(10000, 99999);
$auto_id_number = date('ymd') . rand(1000, 9999); // Exactly 10 digits: 6 for date + 4 random
$auto_meter_no = date('ymd') . rand(100, 999);

// ---------------------------------------------------------------------
// FORM PROCESSING: ADD NEW CONSUMER
// ---------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitize Inputs
    $account_no = trim($_POST['account_no']); // From readonly field
    $id_number = trim($_POST['id_number']);   // From readonly field
    $first_name = trim($_POST['first_name']);
    $middle_name = trim($_POST['middle_name']);
    $last_name = trim($_POST['last_name']);
    $date_of_birth = $_POST['date_of_birth'];
    $contact_number = trim($_POST['contact_number']); 
    $meter_no = trim($_POST['meter_no']);     // From readonly field
    $consumer_type = $_POST['consumer_type'];
    $password = $_POST['password'];

    // Concatenate Address safely
    $purok = trim($_POST['purok'] ?? '');
    $barangay = trim($_POST['barangay'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $address = $purok . ', Brgy. ' . $barangay . ', ' . $city;

    // Basic Validation
    if (empty($account_no) || empty($id_number) || empty($first_name) || empty($last_name) || empty($password) || empty($city) || empty($barangay)) {
        $message = "Please fill in all required fields.";
        $messageType = "error";
    } elseif (strlen($id_number) !== 10) {
        $message = "ID Number must be exactly 10 digits.";
        $messageType = "error";
    } else {
        try {
            // Check for duplicate Account Number or ID Number just in case of a rare random collision
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE account_no = ? OR id_number = ?");
            $checkStmt->execute([$account_no, $id_number]);
            $exists = $checkStmt->fetchColumn();

            if ($exists) {
                $message = "System Error: A collision occurred with the generated ID. Please refresh to generate a new one.";
                $messageType = "error";
            } else {
                // Securely hash the consumer's web portal password
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                $sql = "INSERT INTO clients (account_no, id_number, password_hash, first_name, middle_name, last_name, date_of_birth, address, contact_number, meter_no, consumer_type, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Connected')";
                
                $stmt = $pdo->prepare($sql);
                
                if ($stmt->execute([$account_no, $id_number, $hashedPassword, $first_name, $middle_name, $last_name, $date_of_birth, $address, $contact_number, $meter_no, $consumer_type])) {
                    $message = "Consumer successfully registered to NOCECO!";
                    $messageType = "success";
                    
                    // Regenerate all unique identifiers for the next entry
                    $auto_account_no = date('y') . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT) . '-' . rand(10000, 99999);
                    $auto_id_number = date('ymd') . rand(1000, 9999);
                    $auto_meter_no = date('ymd') . rand(100, 999);
                }
            }
        } catch (PDOException $e) {
            $message = "Database error: " . $e->getMessage();
            $messageType = "error";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Consumer | NOCECO System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = {
        theme: {
          extend: {
            colors: {
              noceco: {
                bg: '#F5F5F7',
                text: '#1D1D1F',
                mustard: '#DBA111',
                mustardHover: '#B8860B'
              }
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
                <a href="../consumers/manage-consumers.php" class="flex items-center px-4 py-3 text-gray-500 hover:bg-gray-50 hover:text-gray-900 rounded-xl transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                    Manage Consumers
                </a>

                <a href="billing-rates.php" class="flex items-center px-4 py-3 text-gray-500 hover:bg-gray-50 hover:text-gray-900 rounded-xl transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path></svg>
                    Billing Rates
                </a>
                <a href="../admin/report.php" class="flex items-center px-4 py-3 text-gray-500 hover:bg-gray-50 hover:text-gray-900 rounded-xl transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    Reports
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

    <main class="flex-1 flex flex-col h-screen overflow-y-auto">
       
        <header class="h-20 bg-white/80 backdrop-blur-md border-b border-gray-200 flex items-center justify-between px-8 sticky top-0 z-10">
            <div>
                <h2 class="text-xl font-semibold tracking-tight">Consumer Management</h2>
                <p class="text-xs text-gray-500">Database Registration</p>
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

        <div class="flex-1 p-8 flex items-start justify-center">
            <div class="max-w-4xl w-full bg-white rounded-[20px] shadow-apple p-8 border border-gray-100/50">
               
                <div class="mb-8 border-b border-gray-100 pb-5">
                    <h1 class="text-2xl font-semibold tracking-tight">Register New Consumer</h1>
                    <p class="text-sm text-gray-500 mt-1">Enter the client's official details to generate a NOCECO account.</p>
                </div>

                <?php if (!empty($message)): ?>
                    <div class="mb-6 p-4 rounded-xl text-sm font-medium <?php echo $messageType === 'success' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <form action="add-consumer.php" method="POST">
                   
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-5">
                       
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5 flex items-center justify-between">
                                NOCECO Account No.
                                <span class="text-xs text-noceco-mustard bg-yellow-50 px-2 py-0.5 rounded-full border border-yellow-100">Auto-Generated</span>
                            </label>
                            <input type="text" name="account_no" required readonly value="<?php echo htmlspecialchars($auto_account_no); ?>"
                                class="w-full px-4 py-3 rounded-xl bg-gray-50 border border-gray-200 text-gray-500 font-medium focus:outline-none cursor-not-allowed">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5 flex items-center justify-between">
                                10-Digit ID Number
                                <span class="text-xs text-noceco-mustard bg-yellow-50 px-2 py-0.5 rounded-full border border-yellow-100">Auto-Generated</span>
                            </label>
                            <input type="text" name="id_number" required readonly value="<?php echo htmlspecialchars($auto_id_number); ?>"
                                class="w-full px-4 py-3 rounded-xl bg-gray-50 border border-gray-200 text-gray-500 font-medium focus:outline-none cursor-not-allowed">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">First Name</label>
                            <input type="text" name="first_name" required
                                class="w-full px-4 py-3 rounded-xl bg-noceco-bg/50 border border-gray-200 focus:outline-none focus:ring-2 focus:ring-noceco-mustard focus:bg-white transition-all"
                                placeholder="Juan">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">Middle Name (Optional)</label>
                            <input type="text" name="middle_name"
                                class="w-full px-4 py-3 rounded-xl bg-noceco-bg/50 border border-gray-200 focus:outline-none focus:ring-2 focus:ring-noceco-mustard focus:bg-white transition-all"
                                placeholder="Dela">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">Last Name</label>
                            <input type="text" name="last_name" required
                                class="w-full px-4 py-3 rounded-xl bg-noceco-bg/50 border border-gray-200 focus:outline-none focus:ring-2 focus:ring-noceco-mustard focus:bg-white transition-all"
                                placeholder="Cruz">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">Date of Birth</label>
                            <input type="date" name="date_of_birth" required
                                class="w-full px-4 py-3 rounded-xl bg-noceco-bg/50 border border-gray-200 focus:outline-none focus:ring-2 focus:ring-noceco-mustard focus:bg-white transition-all">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">Contact Number</label>
                            <input type="text" name="contact_number" required
                                class="w-full px-4 py-3 rounded-xl bg-noceco-bg/50 border border-gray-200 focus:outline-none focus:ring-2 focus:ring-noceco-mustard focus:bg-white transition-all"
                                placeholder="0912 345 6789">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5 flex items-center justify-between">
                                Meter Number
                                <span class="text-xs text-noceco-mustard bg-yellow-50 px-2 py-0.5 rounded-full border border-yellow-100">Auto-Generated</span>
                            </label>
                            <input type="text" name="meter_no" required readonly value="<?php echo htmlspecialchars($auto_meter_no); ?>"
                                class="w-full px-4 py-3 rounded-xl bg-gray-50 border border-gray-200 text-gray-500 font-medium focus:outline-none cursor-not-allowed">
                        </div>

                        <div class="md:col-span-2 bg-gray-50/50 p-5 rounded-xl border border-gray-100">
                            <label class="block text-sm font-semibold text-gray-900 mb-3 border-b border-gray-200 pb-2">Consumer Address</label>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1">City / Municipality</label>
                                    <select name="city" id="city" required
                                        class="w-full px-4 py-2.5 rounded-lg bg-white border border-gray-200 focus:outline-none focus:ring-2 focus:ring-noceco-mustard transition-all text-sm appearance-none">
                                        <option value="" disabled selected>Loading Cities...</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1">Barangay</label>
                                    <select name="barangay" id="barangay" required disabled
                                        class="w-full px-4 py-2.5 rounded-lg bg-white border border-gray-200 focus:outline-none focus:ring-2 focus:ring-noceco-mustard transition-all text-sm disabled:bg-gray-100 disabled:cursor-not-allowed appearance-none">
                                        <option value="" disabled selected>Select City First</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1">Purok / Street / Blk</label>
                                    <input type="text" name="purok" required
                                        class="w-full px-4 py-2.5 rounded-lg bg-white border border-gray-200 focus:outline-none focus:ring-2 focus:ring-noceco-mustard transition-all text-sm"
                                        placeholder="e.g., Prk 4">
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">Consumer Type</label>
                            <select name="consumer_type" required
                                class="w-full px-4 py-3 rounded-xl bg-noceco-bg/50 border border-gray-200 focus:outline-none focus:ring-2 focus:ring-noceco-mustard focus:bg-white transition-all appearance-none">
                                <option value="RESIDENTIAL" selected>Residential</option>
                                <option value="COMMERCIAL">Commercial</option>
                                <option value="INDUSTRIAL">Industrial</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">Web Portal Password</label>
                            <div class="relative">
                                <input type="password" name="password" id="password" required
                                    class="w-full px-4 py-3 pr-12 rounded-xl bg-noceco-bg/50 border border-gray-200 focus:outline-none focus:ring-2 focus:ring-noceco-mustard focus:bg-white transition-all"
                                    placeholder="Assign an initial password">
                                <button type="button" id="togglePassword" class="absolute inset-y-0 right-0 pr-4 flex items-center text-gray-400 hover:text-noceco-mustard transition-colors">
                                    <svg id="eyeIcon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                    <svg id="eyeSlashIcon" class="w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.542-7a10.05 10.05 0 011.52-3.13m2.77-2.77A10.05 10.05 0 0112 5c4.478 0 8.268 2.943 9.542 7a10.05 10.05 0 01-1.52 3.13m-2.77 2.77L4 4m16 16l-3.23-3.23M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                                </button>
                            </div>
                        </div>

                    </div>
                    
                    <div class="mt-8 flex justify-end">
                        <button type="submit"
                            class="bg-noceco-mustard hover:bg-noceco-mustardHover text-white font-semibold py-3.5 px-8 rounded-xl transition-all duration-200 shadow-[0_4px_14px_rgba(219,161,17,0.3)] hover:shadow-[0_6px_20px_rgba(219,161,17,0.4)]">
                            Save Consumer Data
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </main>

    <script>
        // --- Show/Hide Password Logic ---
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        const eyeIcon = document.getElementById('eyeIcon');
        const eyeSlashIcon = document.getElementById('eyeSlashIcon');

        togglePassword.addEventListener('click', function () {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            eyeIcon.classList.toggle('hidden');
            eyeSlashIcon.classList.toggle('hidden');
        });

        // --- Address Population Logic ---
        let brgyData = [];

        document.addEventListener("DOMContentLoaded", function() {
            const citySelect = document.getElementById('city');
            const brgySelect = document.getElementById('barangay');

            Promise.all([
                fetch('../ph_address/city.json').then(res => res.json()),
                fetch('../ph_address/barangay.json').then(res => res.json())
            ])
            .then(([cities, barangays]) => {
                brgyData = barangays;
                
                citySelect.innerHTML = '<option value="" disabled selected>Select City/Municipality</option>';
                
                // CRITICAL UPDATE: Filter strictly for Negros Occidental (Code: 0645)
                const negrosCities = cities.filter(city => city.province_code === '0645');

                negrosCities.sort((a, b) => a.city_name.localeCompare(b.city_name)).forEach(city => {
                    let opt = document.createElement('option');
                    opt.value = city.city_name;
                    opt.dataset.code = city.city_code; 
                    opt.textContent = city.city_name;
                    citySelect.appendChild(opt);
                });
            })
            .catch(error => {
                console.error("Error loading address JSON:", error);
                citySelect.innerHTML = '<option value="" disabled>Error loading geographic data</option>';
            });

            citySelect.addEventListener('change', function() {
                brgySelect.innerHTML = '<option value="" disabled selected>Select Barangay</option>';
                brgySelect.disabled = false;
                
                const selectedCityCode = this.options[this.selectedIndex].dataset.code;
                
                const filteredBrgys = brgyData.filter(b => b.city_code === selectedCityCode);
                
                filteredBrgys.sort((a, b) => a.brgy_name.localeCompare(b.brgy_name)).forEach(brgy => {
                    let opt = document.createElement('option');
                    opt.value = brgy.brgy_name;
                    opt.textContent = brgy.brgy_name;
                    brgySelect.appendChild(opt);
                });
            });
        });
    </script>
</body>
</html>