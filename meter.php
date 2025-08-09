<?php
// Show all PHP errors for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

require_once 'config.php';

if (!is_logged_in()) {
    redirect('login.php');
}

$meter_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

try {
    // Get meter details
    $stmt = $pdo->prepare("SELECT m.*, u.username, u.full_name, u.account_type 
                          FROM meters m 
                          LEFT JOIN users u ON m.user_id = u.user_id 
                          WHERE m.meter_id = ? AND (m.user_id = ? OR ? = 1)");
    $stmt->execute([$meter_id, $_SESSION['user_id'], is_admin_logged_in()]);
    $meter = $stmt->fetch();
    
    if (!$meter) {
        $_SESSION['error'] = "Meter not found or not authorized";
        redirect('meters.php');
    }
    
    // Get latest flow data
    $stmt = $pdo->prepare("SELECT * FROM flow_data 
                          WHERE meter_id = ? 
                          ORDER BY recorded_at DESC 
                          LIMIT 1");
    $stmt->execute([$meter_id]);
    $flow_data = $stmt->fetch();
    // Estimate remaining balance if not already stored in DB
$total_volume = $flow_data['total_volume'] ?? 0;

$stmt = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE meter_id = ?");
$stmt->execute([$meter_id]);
$total_topup = $stmt->fetchColumn() ?? 0;

$flow_data['balance'] = $total_topup - $total_volume;

    
    // Get payment history
    $stmt = $pdo->prepare("SELECT * FROM payments 
                          WHERE meter_id = ? 
                          ORDER BY payment_date DESC 
                          LIMIT 10");
    $stmt->execute([$meter_id]);
    $payments = $stmt->fetchAll();
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meter Details - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
    <!-- Header/Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php"><?= APP_NAME ?></a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="meters.php"><i class="bi bi-water"></i> My Meters</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="payments.php"><i class="bi bi-cash-stack"></i> Payments</a>
                    </li>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle"></i> <?= $_SESSION['full_name'] ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person"></i> Profile</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    
    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2>Meter Details</h2>
                    <a href="meters.php" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Meters
                    </a>
                </div>
                
                <!-- Flash Messages -->
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger"><?= $_SESSION['error'] ?></div>
                    <?php unset($_SESSION['error']); ?>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success"><?= $_SESSION['success'] ?></div>
                    <?php unset($_SESSION['success']); ?>
                <?php endif; ?>
                
                <!-- Meter Info -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <dl class="row">
                                    <dt class="col-sm-4">Meter Name:</dt>
                                    <dd class="col-sm-8"><?= $meter['meter_name'] ?></dd>
                                    
                                    <dt class="col-sm-4">Serial Number:</dt>
                                    <dd class="col-sm-8"><?= $meter['meter_serial'] ?></dd>
                                    
                                    <dt class="col-sm-4">Location:</dt>
                                    <dd class="col-sm-8"><?= $meter['meter_location'] ?></dd>
                                </dl>
                            </div>
                            <div class="col-md-6">
                                <dl class="row">
                                    <dt class="col-sm-4">Account Type:</dt>
                                    <dd class="col-sm-8"><?= ucfirst($meter['account_type']) ?></dd>
                                    
                                    <dt class="col-sm-4">Status:</dt>
                                    <dd class="col-sm-8">
                                        <span class="badge bg-<?= $meter['status'] == 'active' ? 'success' : ($meter['status'] == 'maintenance' ? 'warning' : 'secondary') ?>">
                                            <?= ucfirst($meter['status']) ?>
                                        </span>
                                    </dd>
                                    
                                    <?php if ($flow_data): ?>
                                    <dt class="col-sm-4">Current Balance:</dt>
                                    <dd class="col-sm-8"><?= CURRENCY ?> <?= number_format($flow_data['balance'], 2) ?></dd>
                                    <?php endif; ?>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Current Status -->
                <?php if ($flow_data): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Current Status</h5>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-md-3">
                                <div class="card mb-3">
                                    <div class="card-body">
                                        <h6 class="card-title">Flow Rate</h6>
                                        <h3 class="mb-0"><?= number_format($flow_data['flow_rate'] ?? 0, 2) ?> L/min</h3>


                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card mb-3">
                                    <div class="card-body">
                                        <h6 class="card-title">Session Volume</h6>
                                        <h3 class="mb-0"><?= number_format($flow_data['volume'] ?? 0, 2) ?> L</h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card mb-3">
                                    <div class="card-body">
                                        <h6 class="card-title">Total Volume</h6>
                                        <h3 class="mb-0"><?= number_format($flow_data['total_volume'] ?? 0, 2) ?> L</h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card mb-3 <?= $flow_data['balance'] < 100 ? 'bg-warning text-white' : '' ?>">
                                    <div class="card-body">
                                        <h6 class="card-title">Balance</h6>
                                        <h3 class="mb-0"><?= CURRENCY ?> <?= number_format($flow_data['balance'], 2) ?></h3>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Payment History -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Recent Payments</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($payments): ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Amount</th>
                                            <th>Method</th>
                                            <th>Transaction Code</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($payments as $payment): ?>
                                        <tr>
                                            <td><?= date('M j, Y H:i', strtotime($payment['payment_date'])) ?></td>
                                            <td><?= CURRENCY ?> <?= number_format($payment['amount'], 2) ?></td>
                                            <td><?= ucfirst($payment['payment_method']) ?></td>
                                            <td><?= $payment['transaction_code'] ?? 'N/A' ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <a href="payments.php?meter=<?= $meter_id ?>" class="btn btn-sm btn-outline-primary mt-2">View All Payments</a>
                        <?php else: ?>
                            <div class="alert alert-info mb-0">
                                No payment history found for this meter.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>