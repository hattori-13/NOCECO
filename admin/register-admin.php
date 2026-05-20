<?php
// 1. START THE SESSION TO ACCESS LOGGED-IN USER DATA
session_start();

// 2. INCLUDE DATABASE CONNECTION
require_once '../db/dbcon.php';

// ---------------------------------------------------------------------
// SECURITY: STRICT ROLE VERIFICATION (MAIN ADMIN ONLY)
// ---------------------------------------------------------------------
if (!isset($_SESSION['staff_id']) || $_SESSION['role'] !== 'Main Administrator') {
    // If they are not logged in, or if they are just a Cashier/Meter Reader, kick them out.
    header("Location: ../administrator.php");
    exit();
}

$message = '';
$messageType = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $fullName = trim($_POST['full_name']);
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $role = $_POST['role'];

    if (empty($fullName) || empty($username) || empty($password) || empty($role)) {
        $message = "All fields are required.";
        $messageType = "error";
    } else {
        try {
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM system_staff WHERE username = ?");
            $checkStmt->execute([$username]);
            $userExists = $checkStmt->fetchColumn();

            if ($userExists) {
                $message = "Username already exists. Please choose another.";
                $messageType = "error";
            } else {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $sql = "INSERT INTO system_staff (full_name, username, password_hash, role) VALUES (?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                
                if ($stmt->execute([$fullName, $username, $hashedPassword, $role])) {
                    $message = "System Staff account successfully created!";
                    $messageType = "success";
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
    <title>Register Staff | NOCECO System</title>
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
            fontFamily: {
              sans: ['-apple-system', 'BlinkMacSystemFont', '"Segoe UI"', 'Roboto', 'Helvetica', 'Arial', 'sans-serif'],
            },
            boxShadow: {
              'apple': '0 4px 24px rgba(0, 0, 0, 0.04)',
              'apple-sm': '0 2px 8px rgba(0, 0, 0, 0.04)',
            }
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
                <a href="#" class="flex items-center px-4 py-3 text-gray-500 hover:bg-gray-50 hover:text-gray-900 rounded-xl transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                    Manage Consumers
                </a>
                <a href="billing-rates.php" class="flex items-center px-4 py-3 text-gray-500 hover:bg-gray-50 hover:text-gray-900 rounded-xl transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path></svg>
                    Billing Rates
                </a>
                <a href="#" class="flex items-center px-4 py-3 text-gray-500 hover:bg-gray-50 hover:text-gray-900 rounded-xl transition-colors">
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
                <h2 class="text-xl font-semibold tracking-tight">Staff Management</h2>
                <p class="text-xs text-gray-500">Secure Access Control</p>
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

        <div class="flex-1 p-8 flex items-start justify-center mt-10">
            <div class="max-w-md w-full bg-white rounded-[20px] shadow-apple p-8 border border-gray-100/50">
                
                <div class="text-center mb-8">
                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-noceco-mustard/10 mb-4">
                        <svg class="w-6 h-6 text-noceco-mustard" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>
                        </svg>
                    </div>
                    <h1 class="text-2xl font-semibold tracking-tight">Add System User</h1>
                    <p class="text-sm text-gray-500 mt-2">Create credentials for NOCECO personnel</p>
                </div>

                <?php if (!empty($message)): ?>
                    <div class="mb-6 p-4 rounded-xl text-sm font-medium <?php echo $messageType === 'success' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <form action="register-admin.php" method="POST" class="space-y-5">
                    
                    <div>
                        <label for="full_name" class="block text-sm font-medium text-gray-700 mb-1.5">Full Name</label>
                        <input type="text" name="full_name" id="full_name" required
                            class="w-full px-4 py-3 rounded-xl bg-noceco-bg/50 border border-gray-200 focus:outline-none focus:ring-2 focus:ring-noceco-mustard focus:bg-white transition-all duration-200"
                            placeholder="e.g., John Doe">
                    </div>

                    <div>
                        <label for="username" class="block text-sm font-medium text-gray-700 mb-1.5">Username</label>
                        <input type="text" name="username" id="username" required
                            class="w-full px-4 py-3 rounded-xl bg-noceco-bg/50 border border-gray-200 focus:outline-none focus:ring-2 focus:ring-noceco-mustard focus:bg-white transition-all duration-200"
                            placeholder="Create a unique username">
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-1.5">Password</label>
                        <div class="relative">
                            <input type="password" name="password" id="password" required
                                class="w-full px-4 py-3 pr-12 rounded-xl bg-noceco-bg/50 border border-gray-200 focus:outline-none focus:ring-2 focus:ring-noceco-mustard focus:bg-white transition-all duration-200"
                                placeholder="••••••••">
                            
                            <button type="button" id="togglePassword" class="absolute inset-y-0 right-0 pr-4 flex items-center text-gray-400 hover:text-noceco-mustard transition-colors">
                                <svg id="eyeIcon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                </svg>
                                <svg id="eyeSlashIcon" class="w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.542-7a10.05 10.05 0 011.52-3.13m2.77-2.77A10.05 10.05 0 0112 5c4.478 0 8.268 2.943 9.542 7a10.05 10.05 0 01-1.52 3.13m-2.77 2.77L4 4m16 16l-3.23-3.23M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div>
                        <label for="role" class="block text-sm font-medium text-gray-700 mb-1.5">System Role</label>
                        <select name="role" id="role" required
                            class="w-full px-4 py-3 rounded-xl bg-noceco-bg/50 border border-gray-200 focus:outline-none focus:ring-2 focus:ring-noceco-mustard focus:bg-white transition-all duration-200 appearance-none">
                            <option value="" disabled selected>Select access level</option>
                            <option value="Main Administrator">Main Administrator</option>
                            <option value="Cashier">Cashier</option>
                            <option value="Meter Reader">Meter Reader</option>
                        </select>
                    </div>

                    <button type="submit" 
                        class="w-full mt-4 bg-noceco-mustard hover:bg-noceco-mustardHover text-white font-semibold py-3.5 px-4 rounded-xl transition-all duration-200 shadow-[0_4px_14px_rgba(219,161,17,0.3)] hover:shadow-[0_6px_20px_rgba(219,161,17,0.4)]">
                        Register Personnel
                    </button>

                </form>
            </div>
        </div>
    </main>

    <script>
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
    </script>
</body>
</html>