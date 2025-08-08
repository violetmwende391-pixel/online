<?php
require_once 'config.php';

if (!is_admin_logged_in()) {
    redirect('admin_login.php');
}

// Get meter ID from URL
$meter_id = isset($_GET['meter_id']) ? intval($_GET['meter_id']) : 0;

try {
    // Get meter details
    $stmt = $pdo->prepare("SELECT m.*, u.username, u.full_name 
                          FROM meters m 
                          LEFT JOIN users u ON m.user_id = u.user_id 
                          WHERE m.meter_id = ?");
    $stmt->execute([$meter_id]);
    $meter = $stmt->fetch();
    
    if (!$meter) {
        $_SESSION['error'] = "Meter not found";
        redirect('admin_meters.php');
    }
    
    // Get latest flow data
    $stmt = $pdo->prepare("SELECT * FROM flow_data 
                          WHERE meter_id = ? 
                          ORDER BY recorded_at DESC 
                          LIMIT 1");
    $stmt->execute([$meter_id]);
    $flow_data = $stmt->fetch();
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Process top-up form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = floatval($_POST['amount']);
    $method = sanitize($_POST['payment_method']);
    $transaction_code = sanitize($_POST['transaction_code']);
    
    if ($amount <= 0) {
        $_SESSION['error'] = "Amount must be greater than 0";
        redirect("admin_topup.php?meter_id=$meter_id");
    }
    
    try {
        $pdo->beginTransaction();
        
        // Record payment
        $stmt = $pdo->prepare("INSERT INTO payments 
                              (user_id, meter_id, amount, payment_method, transaction_code, recorded_by) 
                              VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $meter['user_id'],
            $meter_id,
            $amount,
            $method,
            $transaction_code,
            $_SESSION['admin_id']
        ]);
        
        // Add top-up command for ESP32
        add_command($pdo, $meter_id, 'topup', $amount, $_SESSION['admin_id']);
        
        $pdo->commit();
        
        $_SESSION['success'] = "Top-up of " . CURRENCY . " $amount processed successfully";
        redirect("admin_meter.php?id=$meter_id");
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Failed to process top-up: " . $e->getMessage();
        redirect("admin_topup.php?meter_id=$meter_id");
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Top-up Meter - <?= APP_NAME ?></title>
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
                    <h1 class="h2">Top-up Meter</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="admin_meter.php?id=<?= $meter_id ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> Back to Meter
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
                        <h5 class="card-title">Meter Information</h5>
                        <dl class="row">
                            <dt class="col-sm-3">Meter Name:</dt>
                            <dd class="col-sm-9"><?= $meter['meter_name'] ?></dd>
                            
                            <dt class="col-sm-3">Assigned User:</dt>
                            <dd class="col-sm-9">
                                <?php if ($meter['user_id']): ?>
                                    <?= $meter['full_name'] ?> (<?= $meter['username'] ?>)
                                <?php else: ?>
                                    <span class="text-muted">Unassigned</span>
                                <?php endif; ?>
                            </dd>
                            
                            <?php if ($flow_data): ?>
                            <dt class="col-sm-3">Current Balance:</dt>
                            <dd class="col-sm-9"><?= CURRENCY ?> <?= number_format($flow_data['balance'], 2) ?></dd>
                            <?php endif; ?>
                        </dl>
                        
                        <hr>
                        
                        <h5 class="card-title">Top-up Form</h5>
                        <form method="POST">
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
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-cash-coin"></i> Process Top-up
                            </button>
                        </form>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>