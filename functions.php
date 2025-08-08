<?php
// functions.php

// Redirect function
function redirect($url) {
    header("Location: $url");
    exit();
}

// Check if user is logged in
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

// Check if admin is logged in
function is_admin_logged_in() {
    return isset($_SESSION['admin_id']);
}

// Sanitize input data
function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

// Password hashing
function hash_password($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}

// Verify password
function verify_password($password, $hash) {
    return password_verify($password, $hash);
}

// Get user by ID
function get_user($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetch();
}

// Get meter by ID
function get_meter($pdo, $meter_id) {
    $stmt = $pdo->prepare("SELECT m.*, u.username, u.full_name 
                          FROM meters m 
                          LEFT JOIN users u ON m.user_id = u.user_id 
                          WHERE m.meter_id = ?");
    $stmt->execute([$meter_id]);
    return $stmt->fetch();
}

// Get latest flow data for a meter
function get_latest_flow_data($pdo, $meter_id) {
    $stmt = $pdo->prepare("SELECT * FROM flow_data 
                          WHERE meter_id = ? 
                          ORDER BY recorded_at DESC 
                          LIMIT 1");
    $stmt->execute([$meter_id]);
    return $stmt->fetch();
}

// Get user's balance
function get_user_balance($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT SUM(amount) as total_payments FROM payments WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $payments = $stmt->fetch()['total_payments'] ?? 0;

    // For postpaid, we might calculate differently
    // This is simplified - adjust according to your billing logic
    return $payments;
}

// Add a new command for ESP32
function add_command($pdo, $meter_id, $type, $value, $admin_id = null) {
    $sql = "INSERT INTO commands (meter_id, command_type, command_value, issued_by) 
            VALUES (?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    return $stmt->execute([$meter_id, $type, $value, $admin_id]);
}
?>