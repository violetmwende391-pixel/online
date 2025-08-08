<?php
require_once '../config.php';

if (!is_admin_logged_in()) {
    redirect('../admin_login.php');
}

// Get all users and meters
try {
    $stmt = $pdo->query("SELECT user_id, username, full_name FROM users ORDER BY full_name");
    $users = $stmt->fetchAll();
    
    $stmt = $pdo->query("SELECT meter_id, meter_name FROM meters ORDER BY meter_name");
    $meters = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = intval($_POST['user_id']);
    $meter_id = intval($_POST['meter_id']);
    $amount = floatval($_POST['amount']);
    $method = sanitize($_POST['payment_method']);
    $transaction_code = sanitize($_POST['transaction_code']);
    
    if ($amount <= 0) {
        $_SESSION['error'] = "Amount must be greater than 0";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Record payment
            $stmt = $pdo->prepare("INSERT INTO payments 
                                  (user_id, meter_id, amount, payment_method, transaction_code, recorded_by) 
                                  VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $user_id,
                $meter_id,
                $amount,
                $method,
                $transaction_code,
                $_SESSION['admin_id']
            ]);
            
            // Add top-up command for ESP32
            add_command($pdo, $meter_id, 'topup', $amount, $_SESSION['admin_id']);
            
            $pdo->commit();
            
            $_SESSION['success'] = "Payment recorded successfully";
            redirect('admin_payments.php');
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Failed to record payment: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Payment - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <!-- Header/Navbar -->
    <?php include 'admin_header.php'; ?>
    
    <div class="container-fluid mt-3">
        <div class="row">
            <!-- Sidebar -->
            <?php include 'admin_sidebar.php'; ?>
            
            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Add Payment</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="admin_payments.php" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> Back to Payments
                        </a>
                    </div>
                </div>
                
                <!-- Flash Messages -->
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger"><?= $_SESSION['error'] ?></div>
                    <?php unset($_SESSION['error']); ?>
                <?php endif; ?>
                
                <div class="card">
                    <div class="card-body">
                        <form method="POST">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="user_id" class="form-label">User</label>
                                        <select class="form-select" id="user_id" name="user_id" required>
                                            <option value="">-- Select User --</option>
                                            <?php foreach ($users as $user): ?>
                                            <option value="<?= $user['user_id'] ?>">
                                                <?= $user['full_name'] ?> (<?= $user['username'] ?>)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label for="meter_id" class="form-label">Meter</label>
                                        <select class="form-select" id="meter_id" name="meter_id" required>
                                            <option value="">-- Select Meter --</option>
                                            <?php foreach ($meters as $meter): ?>
                                            <option value="<?= $meter['meter_id'] ?>">
                                                <?= $meter['meter_name'] ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="amount" class="form-label">Amount (<?= CURRENCY ?>)</label>
                                        <input type="number" step="0.01" min="0.01" class="form-control" id="amount" name="amount" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="payment_method" class="form-label">Payment Method</label>
                                        <select class="form-select" id="payment_method" name="payment_method" required>
                                            <option value="mpesa">M-Pesa</option>
                                            <option value="cash">Cash</option>
                                            <option value="card">Card</option>
                                            <option value="bank">Bank Transfer</option>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label for="transaction_code" class="form-label">Transaction Code</label>
                                        <input type="text" class="form-control" id="transaction_code" name="transaction_code">
                                    </div>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary">Record Payment</button>
                        </form>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>