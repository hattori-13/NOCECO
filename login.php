<?php
session_start();

// Include Database Connection (Path is direct since index.php is in the root folder)
require_once 'db/dbcon.php';

// If a consumer is already logged in, send them straight to their dashboard
if (isset($_SESSION['client_account']) && $_SESSION['role'] === 'Consumer') {
    header("Location: consumers/home.php");
    exit();
}

$error = '';

// ---------------------------------------------------------------------
// FORM PROCESSING: CONSUMER LOGIN
// ---------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $identifier = trim($_POST['identifier']); // Formatted as XX-XXX-XXXXX
    $password = $_POST['password'];

    if (empty($identifier) || empty($password)) {
        $error = "Please enter both your Account/ID Number and Password.";
    } else {
        try {
            // Strip hyphens to check the raw 10-digit ID column accurately
            $id_only_numbers = str_replace('-', '', $identifier);

            // Check if input matches Account No (with hyphens) OR ID Number (without hyphens)
            $stmt = $pdo->prepare("SELECT * FROM clients WHERE account_no = ? OR id_number = ?");
            $stmt->execute([$identifier, $id_only_numbers]);
            $client = $stmt->fetch();

            if ($client && password_verify($password, $client['password_hash'])) {
                // Success! Set Consumer Session Variables (Allows both Connected and Disconnected users)
                $_SESSION['client_account'] = $client['account_no'];
                $_SESSION['client_id'] = $client['id_number'];
                $_SESSION['client_name'] = $client['first_name'] . ' ' . $client['last_name'];
                $_SESSION['meter_no'] = $client['meter_no'];
                $_SESSION['client_status'] = $client['status']; // Storing status in case dashboard needs it
                $_SESSION['role'] = 'Consumer';
                
                // Redirect to their private portal
                header("Location: consumers/home.php");
                exit();
            } else {
                $error = "Invalid Account credentials or password.";
            }
        } catch (PDOException $e) {
            $error = "System Error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NOCECO Web Billing System | Consumer Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = {
        theme: {
          extend: {
            colors: {
              noceco: {
                bg: '#ffffff',
                text: '#1D1D1F',
                mustard: '#DBA111',
                mustardHover: '#B8860B'
              }
            },
            fontFamily: {
              sans: ['-apple-system', 'BlinkMacSystemFont', '"Segoe UI"', 'Roboto', 'Helvetica', 'Arial', 'sans-serif'],
            },
            boxShadow: {
              'apple': '0 8px 32px rgba(0, 0, 0, 0.08)',
            }
          }
        }
      }
    </script>
    <style>
        /* Creates the beautiful background using your specific wave design */
        body {
            /* Note: Ensure you save your uploaded background image as assets/noceco-bg.png */
            background-image: url('assets/noceco-bg.png');
            background-size: cover;
            background-position: bottom center;
            background-repeat: no-repeat;
            background-attachment: fixed;
        }
        
        /* Frosted glass effect for the login card */
        .glass-card {
            background: rgba(255, 255, 255, 0.90);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.5);
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">

    <div class="w-full max-w-[420px] glass-card rounded-[24px] shadow-apple p-8 sm:p-10 relative z-10">
        
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-yellow-50 mb-4 shadow-sm border border-yellow-100">
                <svg class="w-8 h-8 text-noceco-mustard" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"></path>
                </svg>
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-gray-900">Consumer Portal</h1>
            <p class="text-sm text-gray-500 mt-2">Log in to view your NOCECO billing statements.</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="mb-6 p-4 rounded-xl text-sm font-medium bg-red-50 text-red-600 border border-red-100 flex items-start">
                <svg class="w-5 h-5 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <form action="index.php" method="POST" class="space-y-5">
            
            <div>
                <label for="identifier" class="block text-sm font-semibold text-gray-700 mb-1.5">Account No. or 10-Digit ID</label>
                <input type="text" name="identifier" id="identifier" required autofocus autocomplete="off"
                    class="w-full px-4 py-3.5 rounded-xl bg-gray-50 border border-gray-200 focus:outline-none focus:ring-2 focus:ring-noceco-mustard focus:bg-white transition-all text-gray-900 font-mono tracking-wider"
                    placeholder="26-328-66378" maxlength="12">
            </div>

            <div>
                <div class="flex justify-between items-center mb-1.5">
                    <label for="password" class="block text-sm font-semibold text-gray-700">Password</label>
                </div>
                <div class="relative">
                    <input type="password" name="password" id="password" required
                        class="w-full px-4 py-3.5 pr-12 rounded-xl bg-gray-50 border border-gray-200 focus:outline-none focus:ring-2 focus:ring-noceco-mustard focus:bg-white transition-all text-gray-900"
                        placeholder="••••••••">
                    
                    <button type="button" id="togglePassword" class="absolute inset-y-0 right-0 pr-4 flex items-center text-gray-400 hover:text-noceco-mustard transition-colors">
                        <svg id="eyeIcon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                        <svg id="eyeSlashIcon" class="w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.542-7a10.05 10.05 0 011.52-3.13m2.77-2.77A10.05 10.05 0 0112 5c4.478 0 8.268 2.943 9.542 7a10.05 10.05 0 01-1.52 3.13m-2.77 2.77L4 4m16 16l-3.23-3.23M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    </button>
                </div>
            </div>

            <button type="submit" 
                class="w-full mt-2 bg-noceco-mustard hover:bg-noceco-mustardHover text-white font-bold text-lg py-4 px-4 rounded-xl transition-all duration-200 shadow-[0_4px_14px_rgba(219,161,17,0.3)] hover:shadow-[0_6px_20px_rgba(219,161,17,0.4)] flex justify-center items-center gap-2">
                Sign In to Account
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
            </button>
        </form>

        <div class="mt-8 text-center border-t border-gray-100 pt-6">
            <p class="text-xs text-gray-500">
                NOCECO Personnel? <a href="administrator.php" class="text-noceco-mustard font-bold hover:underline">Access Staff Portal</a>
            </p>
        </div>
    </div>

    <script>
        // --- REAL-TIME ACCOUNT FORMATTER (XX-XXX-XXXXX) ---
        const identifierInput = document.getElementById('identifier');
        
        identifierInput.addEventListener('input', function (e) {
            // Prevent interference if the user is hitting backspace
            if (e.inputType === 'deleteContentBackward') return;
            
            // Strip out any non-numeric characters the user might type
            let val = this.value.replace(/\D/g, ''); 
            let formatted = '';
            
            // Apply the NOCECO Hyphen Format
            if (val.length > 0) {
                formatted += val.substring(0, 2);
            }
            if (val.length > 2) {
                formatted += '-' + val.substring(2, 5);
            }
            if (val.length > 5) {
                formatted += '-' + val.substring(5, 10);
            }
            
            this.value = formatted;
        });

        // --- PASSWORD VISIBILITY TOGGLE ---
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