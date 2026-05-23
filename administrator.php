<?php
session_start();
require_once 'db/dbcon.php';

// ---------------------------------------------------------------------
// SECURITY: ANTI-BRUTE FORCE MECHANISM
// ---------------------------------------------------------------------
$max_attempts = 5;
$lockout_time = 900; // 15 minutes in seconds

if (isset($_SESSION['lockout_time']) && time() < $_SESSION['lockout_time']) {
    $time_left = ceil(($_SESSION['lockout_time'] - time()) / 60);
    $error_msg = "Too many failed attempts. Please try again in $time_left minutes.";
    $is_locked = true;
} else {
    $is_locked = false;
    if (isset($_SESSION['lockout_time']) && time() >= $_SESSION['lockout_time']) {
        unset($_SESSION['login_attempts'], $_SESSION['lockout_time']);
    }
}

// ---------------------------------------------------------------------
// REDIRECT IF ALREADY LOGGED IN
// ---------------------------------------------------------------------
if (isset($_SESSION['staff_id']) && isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'Main Administrator') header("Location: admin/admin-dashboard.php");
    elseif ($_SESSION['role'] === 'Cashier') header("Location: admin/cashier.php");
    elseif ($_SESSION['role'] === 'Meter Reader') header("Location: readerman/readerman.php");
    elseif ($_SESSION['role'] === 'Staff') header("Location: staff/home.php");
    exit();
}

// ---------------------------------------------------------------------
// LOGIN PROCESSING
// ---------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && !$is_locked) {
    if (!isset($_SESSION['login_attempts'])) $_SESSION['login_attempts'] = 0;

    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (empty($username) || empty($password)) {
        $error_msg = "Please enter both username and password.";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT staff_id, password_hash, full_name, role, status FROM system_staff WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && $user['status'] === 'Active' && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);

                $_SESSION['staff_id'] = $user['staff_id'];
                $_SESSION['username'] = $username;
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];

                unset($_SESSION['login_attempts'], $_SESSION['lockout_time']);

                // Route to respective dashboards
                if ($user['role'] === 'Main Administrator') header("Location: admin/admin-dashboard.php");
                elseif ($user['role'] === 'Cashier') header("Location: admin/cashier.php");
                elseif ($user['role'] === 'Meter Reader') header("Location: readerman/dashboard.php");
                elseif ($user['role'] === 'Staff') header("Location: staff/home.php");
                exit();

            } else {
                $_SESSION['login_attempts']++;
                if ($_SESSION['login_attempts'] >= $max_attempts) {
                    $_SESSION['lockout_time'] = time() + $lockout_time;
                    $error_msg = "Too many failed attempts. You are locked out for 15 minutes.";
                    $is_locked = true;
                } else {
                    $attempts_left = $max_attempts - $_SESSION['login_attempts'];
                    $error_msg = "Invalid credentials. You have $attempts_left attempts left.";
                }
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error_msg = "System Error. Please contact IT support."; 
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Login | NOCECO Billing System</title>
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
            }
          }
        }
      }
    </script>
</head>
<body class="bg-noceco-bg text-noceco-text min-h-screen flex flex-col items-center justify-center p-4">

    <div class="max-w-md w-full bg-white rounded-[20px] shadow-apple p-8 border border-gray-100/50">
        
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-noceco-mustard/10 mb-5">
                <svg class="w-7 h-7 text-noceco-mustard" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                </svg>
            </div>
            <h1 class="text-2xl font-semibold tracking-tight">Staff Secure Portal</h1>
            <p class="text-sm text-gray-500 mt-2">NOCECO Internal System Access</p>
        </div>

        <?php if (!empty($error_msg)): ?>
            <div class="mb-6 p-4 rounded-xl text-sm font-medium bg-red-50 text-red-700 border border-red-100/50 flex items-center gap-3">
                <svg class="w-5 h-5 text-red-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                <span><?php echo htmlspecialchars($error_msg); ?></span>
            </div>
        <?php endif; ?>

        <form action="administrator.php" method="POST" class="space-y-5">
            
            <div>
                <label for="username" class="block text-sm font-medium text-gray-700 mb-1.5">Username</label>
                <input type="text" name="username" id="username" required <?php echo $is_locked ? 'disabled' : ''; ?>
                    class="w-full px-4 py-3 rounded-xl bg-noceco-bg/50 border border-gray-200 focus:outline-none focus:ring-2 focus:ring-noceco-mustard focus:bg-white transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed"
                    placeholder="Enter your system username">
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1.5">Password</label>
                <input type="password" name="password" id="password" required <?php echo $is_locked ? 'disabled' : ''; ?>
                    class="w-full px-4 py-3 rounded-xl bg-noceco-bg/50 border border-gray-200 focus:outline-none focus:ring-2 focus:ring-noceco-mustard focus:bg-white transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed"
                    placeholder="••••••••">
            </div>

            <button type="submit" <?php echo $is_locked ? 'disabled' : ''; ?>
                class="w-full mt-4 bg-noceco-mustard hover:bg-noceco-mustardHover text-white font-semibold py-3.5 px-4 rounded-xl transition-all duration-200 shadow-[0_4px_14px_rgba(219,161,17,0.3)] hover:shadow-[0_6px_20px_rgba(219,161,17,0.4)] disabled:bg-gray-400 disabled:shadow-none disabled:cursor-not-allowed flex justify-center items-center gap-2">
                Secure Login
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
            </button>

        </form>
    </div>

    <div class="mt-8 text-center text-xs text-gray-400">
        <p>Protected by NOCECO Security Architecture</p>
        <p class="mt-1">Authorized Personnel Only</p>
    </div>

</body>
</html>