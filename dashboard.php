<?php
require_once 'config.php';

// Redirect if not logged in
if (!is_logged_in()) {
    redirect('login.php');
}

// Get user's meters and data
try {
    // Get user details
    $user = get_user($pdo, $_SESSION['user_id']);

    // Get user's meters
    $stmt = $pdo->prepare("SELECT * FROM meters WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $meters = $stmt->fetchAll();

    // Get payment history
    $stmt = $pdo->prepare("SELECT p.*, m.meter_name 
                          FROM payments p 
                          JOIN meters m ON p.meter_id = m.meter_id 
                          WHERE p.user_id = ? 
                          ORDER BY p.payment_date DESC 
                          LIMIT 5");
    $stmt->execute([$_SESSION['user_id']]);
    $payments = $stmt->fetchAll();

    // Calculate total balance (sum of all meter balances using topup - volume)
    $total_balance = 0;
    foreach ($meters as &$meter) {
        $meter_id = $meter['meter_id'];

        // Get latest total volume from flow_data
        $stmt = $pdo->prepare("SELECT total_volume FROM flow_data 
                               WHERE meter_id = ? 
                               ORDER BY recorded_at DESC 
                               LIMIT 1");
        $stmt->execute([$meter_id]);
        $flow_data = $stmt->fetch();
        $total_volume = $flow_data['total_volume'] ?? 0;

        // Get total top-up amount
        $stmt = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE meter_id = ?");
        $stmt->execute([$meter_id]);
        $total_topup = $stmt->fetchColumn() ?? 0;

        // Calculate and store balance
        $balance = $total_topup - $total_volume;
        $meter['balance'] = $balance;
        $total_balance += $balance;
    }

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand" href="dashboard.php"><?= APP_NAME ?></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link active" href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
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
        <div class="col-md-4">
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Account Summary</h5>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-5">Account Type:</dt>
                        <dd class="col-sm-7"><?= ucfirst($_SESSION['account_type']) ?></dd>

                        <dt class="col-sm-5">Total Balance:</dt>
                        <dd class="col-sm-7"><?= CURRENCY ?> <?= number_format($total_balance, 2) ?></dd>

                        <dt class="col-sm-5">Meters:</dt>
                        <dd class="col-sm-7"><?= count($meters) ?></dd>
                    </dl>
                    <a href="make_payment.php" class="btn btn-success w-100 mt-2">
                        <i class="bi bi-cash-coin"></i> Make Payment
                    </a>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Recent Payments</h5>
                </div>
                <div class="card-body">
                    <?php if ($payments): ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($payments as $payment): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <small class="text-muted"><?= date('M j', strtotime($payment['payment_date'])) ?></small><br>
                                    <?= $payment['meter_name'] ?>
                                </div>
                                <span class="badge bg-primary rounded-pill"><?= CURRENCY ?> <?= number_format($payment['amount'], 2) ?></span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <a href="payments.php" class="btn btn-sm btn-outline-primary w-100 mt-2">View All</a>
                    <?php else: ?>
                        <div class="alert alert-info mb-0">No payment history found.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <?php if (count($meters) === 1): ?>
                <a href="usage_stats.php?id=<?= $meters[0]['meter_id'] ?>" class="btn btn-outline-info mb-3">
                    <i class="bi bi-bar-chart"></i> View My Usage Stats
                </a>
            <?php endif; ?>

            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">My Meters</h5>
                </div>
                <div class="card-body">
                    <?php if ($meters): ?>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Meter Name</th>
                                        <th>Location</th>
                                        <th>Balance</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($meters as $meter): ?>
                                    <tr>
                                        <td><?= $meter['meter_name'] ?></td>
                                        <td><?= $meter['meter_location'] ?></td>
                                        <td><span class="fw-bold <?= $meter['balance'] < 100 ? 'text-danger' : 'text-success' ?>">
                                            <?= CURRENCY ?> <?= number_format($meter['balance'], 2) ?>
                                        </span></td>
                                        <td>
                                            <span class="badge bg-<?= $meter['status'] == 'active' ? 'success' : ($meter['status'] == 'maintenance' ? 'warning' : 'secondary') ?>">
                                                <?= ucfirst($meter['status']) ?>
                                            </span>
                                        </td>
                                        <td class="d-flex gap-1">
                                            <a href="meter.php?id=<?= $meter['meter_id'] ?>" class="btn btn-sm btn-primary">
                                                <i class="bi bi-eye"></i> View
                                            </a>
                                            <a href="usage_stats.php?id=<?= $meter['meter_id'] ?>" class="btn btn-sm btn-info">
                                                <i class="bi bi-bar-chart"></i> Stats
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning">No meters assigned to your account.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Monthly Consumption</h5>
                </div>
                <div class="card-body">
                    <div class="text-center py-4">
                        <i class="bi bi-bar-chart" style="font-size: 3rem; opacity: 0.2;"></i>
                        <p class="text-muted mt-2">Consumption chart will be displayed here</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<a href="usage_stats.php" class="btn btn-outline-info mb-3">
    <i class="bi bi-graph-up"></i> View My Usage Stats
</a>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
