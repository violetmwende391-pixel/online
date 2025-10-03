<?php
// config.php

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- Supabase Session Pooler PostgreSQL Configuration ---
// Use the IPv4-compatible session pooler connection
define('DB_HOST', 'aws-0-eu-north-1.pooler.supabase.com');
define('DB_PORT', '5432');
define('DB_NAME', 'postgres');

// This is different from normal user: use full session pooler user
define('DB_USER', 'postgres.kuykrqqtzgsetfodzuxi');
define('DB_PASS', 'Nasiuma.12?');  // ← Replace with your actual Supabase password

// Optional App Settings
define('BASE_URL', 'https://smart-meter-server.onrender.com');
define('APP_NAME', 'Smart Water Metering');
define('CURRENCY', 'KES');

// Create PostgreSQL connection
try {
    $pdo = new PDO("pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Start session
session_start();

// Include any shared functions (optional)
require_once 'functions.php';
?>