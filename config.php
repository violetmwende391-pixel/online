<?php
// config.php

// Enable error reporting (for debugging)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- Supabase PostgreSQL Configuration ---
// You MUST replace these values with the actual connection info

define('DB_HOST', 'db.kuykrqqtzgsetfodzuxi.supabase.co');
define('DB_PORT', '5432');
define('DB_NAME', 'postgres');
define('DB_USER', 'postgres');
define('DB_PASS', 'Nasiuma.12?'); // ← REPLACE this with your Supabase password

// Optional App Info
define('BASE_URL', 'https://smart-meter-server.onrender.com');  // your live backend URL
define('APP_NAME', 'Smart Water Metering');
define('CURRENCY', 'KES');

// --- Create PostgreSQL PDO connection ---
try {
    $pdo = new PDO("pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// --- Start session ---
session_start();

// --- Load shared functions (optional) ---
require_once 'functions.php';
?>
