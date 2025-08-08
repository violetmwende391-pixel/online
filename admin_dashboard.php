<?php
require_once 'config.php';

// Redirect if not logged in
if (!is_admin_logged_in()) {
    redirect('admin_login.php');
}

// Get statistics
try {
    // Total users
    $stmt = $pdo->query("SELECT COUNT(*) as total_users FROM users");
    $total_users = $stmt->fetch()['total_users'];
    
    // Total meters
    $stmt = $pdo->query("SELECT COUNT(*) as total_meters FROM meters");
    $total_meters = $stmt->fetch()['total_meters'];
    
    // Total payments
    $stmt = $pdo->query("SELECT SUM(amount) as total_payments FROM payments");
    $total_payments = $stmt->fetch()['total_payments'] ?? 0;
    
    // Recent payments
    $stmt = $pdo->query("SELECT p.*, u.full_name, u.username 
                        FROM payments p 
                        JOIN users u ON p.user_id = u.user_id 
                        ORDER BY payment_date DESC 
                        LIMIT 5");
    $recent_payments = $stmt->fetchAll();
    
    // Meters needing attention (low balance)
    $stmt = $pdo->query("SELECT m.*, u.full_name, fd.balance 
                        FROM meters m 
                        LEFT JOIN users u ON m.user_id = u.user_id 
                        LEFT JOIN (SELECT meter_id, balance FROM flow_data WHERE (meter_id, recorded_at) IN 
                                  (SELECT meter_id, MAX(recorded_at) FROM flow_data GROUP BY meter_id)) fd 
                        ON m.meter_id = fd.meter_id 
                        WHERE fd.balance < 100 
                        ORDER BY fd.balance ASC 
                        LIMIT 5");
    $low_balance_meters = $stmt->fetchAll();
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
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
                    <h1 class="h2">Dashboard Overview</h1>
                    <a href="admin_leakage.php" class="btn btn-outline-danger">
                         <i class="bi bi-droplet-half"></i> Check Leakage
                        </a>

                </div>
                
                <!-- Stats Cards -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card text-white bg-primary mb-3">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h5 class="card-title">Total Users</h5>
                                        <h2 class="mb-0"><?= $total_users ?></h2>
                                    </div>
                                    <i class="bi bi-people-fill" style="font-size: 2rem;"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card text-white bg-success mb-3">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h5 class="card-title">Total Meters</h5>
                                        <h2 class="mb-0"><?= $total_meters ?></h2>
                                    </div>
                                    <i class="bi bi-speedometer2" style="font-size: 2rem;"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card text-white bg-info mb-3">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h5 class="card-title">Total Payments</h5>
                                        <h2 class="mb-0"><?= CURRENCY ?> <?= number_format($total_payments, 2) ?></h2>
                                    </div>
                                    <i class="bi bi-cash-stack" style="font-size: 2rem;"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Payments -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Recent Payments</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>User</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Transaction Code</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_payments as $payment): ?>
                                    <tr>
                                        <td><?= date('M j, Y H:i', strtotime($payment['payment_date'])) ?></td>
                                        <td><?= $payment['full_name'] ?> (<?= $payment['username'] ?>)</td>
                                        <td><?= CURRENCY ?> <?= number_format($payment['amount'], 2) ?></td>
                                        <td><?= ucfirst($payment['payment_method']) ?></td>
                                        <td><?= $payment['transaction_code'] ?? 'N/A' ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Low Balance Meters -->
                <div class="card">
                    <div class="card-header bg-warning">
                        <h5 class="mb-0">Meters with Low Balance</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Meter Name</th>
                                        <th>User</th>
                                        <th>Location</th>
                                        <th>Balance</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($low_balance_meters as $meter): ?>
                                    <tr>
                                        <td><?= $meter['meter_name'] ?></td>
                                        <td><?= $meter['full_name'] ?? 'Unassigned' ?></td>
                                        <td><?= $meter['meter_location'] ?></td>
                                        <td><?= CURRENCY ?> <?= number_format($meter['balance'], 2) ?></td>
                                        <td>
                                            <a href="admin_meter.php?id=<?= $meter['meter_id'] ?>" class="btn btn-sm btn-primary">
                                                <i class="bi bi-eye"></i> View
                                            </a>
                                            <a href="admin_topup.php?meter_id=<?= $meter['meter_id'] ?>" class="btn btn-sm btn-success">
                                                <i class="bi bi-cash-coin"></i> Top-up
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>











 