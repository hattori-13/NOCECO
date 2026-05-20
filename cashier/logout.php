<?php
// Initialize the session
session_start();

// Unset all of the session variables securely
$_SESSION = array();

// To completely kill the session, we must also delete the session cookie.
// This ensures that a hijacked cookie cannot be reused.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Finally, destroy the session on the server side
session_destroy();

// Redirect back to the main secure login portal
header("Location: ../administrator.php");
exit();
?>