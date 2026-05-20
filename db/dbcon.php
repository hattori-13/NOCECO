<?php
// =====================================================================
// NOCECO BILLING SYSTEM - DATABASE CONNECTION (PDO)
// =====================================================================

$host = '127.0.0.1'; // Localhost
$db   = 'noceco_db'; // The exact database name we created
$user = 'root';      // Default XAMPP/WAMP user (Change for production)
$pass = '';          // Default XAMPP/WAMP password (Change for production)
$charset = 'utf8mb4'; // Supports all characters, including special symbols

// DSN (Data Source Name) specifies the host, db, and charset
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

// PDO Configuration Options
$options = [
    // Throw an exception whenever a database error occurs (Crucial for debugging)
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    
    // Always return results as an associative array (e.g., $row['account_no'])
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    
    // Force MySQL to do the actual prepared statements (Better security and performance)
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    // Instantiate the PDO connection object
    $pdo = new PDO($dsn, $user, $pass, $options);
    
    // Uncomment the line below ONLY for testing if the connection works, then remove it.
    // echo "Database Connection Successful!"; 
    
} catch (\PDOException $e) {
    // If the connection fails, catch the error gracefully
    // In production, we would log this to a file instead of showing it to the user
    die("Database Connection Failed: " . $e->getMessage());
}
?>