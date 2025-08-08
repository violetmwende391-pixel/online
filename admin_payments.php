<?php
require_once 'config.php';

if (!is_admin_logged_in()) {
    redirect('../admin_login.php');
}

// Get filter parameters
$filter_user = isset($_GET['user']) ? intval($_GET['user']) : 0;
$filter_meter = isset($_GET['meter']) ? intval($_GET['meter']) : 0;
$filter_method = isset($_GET['method']) ? sanitize($_GET['method']) : '';
$filter_date_from = isset($_GET['date_from']) ? sanitize($_GET['date_from']) : '';
$filter_date_to = isset($_GET['date_to']) ? sanitize($_GET['date_to']) : '';

// Build query
$query = "SELECT p.*, u.username, u.full_name, m.meter_name 
          FROM payments p 
          JOIN users u ON p.user_id = u.user_id 
          JOIN meters m ON p.meter_id = m.meter_id 
          WHERE 1=1";
$params = [];

if ($filter_user > 0) {
    $query .= " AND p.user_id = ?";
    $params[] = $filter_user;
}

if ($filter_meter > 0) {
    $query .= " AND p.meter_id = ?";
    $params[] = $filter_meter;
}

if (!empty($filter_method)) {
    $query .= " AND p.payment_method = ?";
    $params[] = $filter_method;
}

if (!empty($filter_date_from)) {
    $query .= " AND p.payment_date >= ?";
    $params[] = $filter_date_from;
}

if (!empty($filter_date_to)) {
    $query .= " AND p.payment_date <= ?";
    $params[] = $filter_date_to . ' 23:59:59';
}

$query .= " ORDER BY p.payment_date DESC";

try {
    // Get payments
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $payments = $stmt->fetchAll();
    
    // Get all users for filter dropdown
    $stmt = $pdo->query("SELECT user_id, username, full_name FROM users ORDER BY full_name");
    $users = $stmt->fetchAll();
    
    // Get all meters for filter dropdown
    $stmt = $pdo->query("SELECT meter_id, meter_name FROM meters ORDER BY meter_name");
    $meters = $stmt->fetchAll();
    
    // Calculate total
    $stmt = $pdo->prepare(str_replace('p.*, u.username, u.full_name, m.meter_name', 'SUM(p.amount) as total', $query));
    $stmt->execute($params);
    $total = $stmt->fetch()['total'] ?? 0;
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment History - <?= APP_NAME ?></title>
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
                    <h1 class="h2">Payment History</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="admin_add_payment.php" class="btn btn-sm btn-primary">
                            <i class="bi bi-plus-circle"></i> Add Payment
                        </a>
                    </div>
                </div>
                
                <!-- Filters -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label for="user" class="form-label">User</label>
                                <select id="user" name="user" class="form-select">
                                    <option value="0">All Users</option>
                                    <?php foreach ($users as $user): ?>
                                    <option value="<?= $user['user_id'] ?>" <?= $filter_user == $user['user_id'] ? 'selected' : '' ?>>
                                        <?= $user['full_name'] ?> (<?= $user['username'] ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="meter" class="form-label">Meter</label>
                                <select id="meter" name="meter" class="form-select">
                                    <option value="0">All Meters</option>
                                    <?php foreach ($meters as $meter): ?>
                                    <option value="<?= $meter['meter_id'] ?>" <?= $filter_meter == $meter['meter_id'] ? 'selected' : '' ?>>
                                        <?= $meter['meter_name'] ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="method" class="form-label">Method</label>
                                <select id="method" name="method" class="form-select">
                                    <option value="">All Methods</option>
                                    <option value="mpesa" <?= $filter_method == 'mpesa' ? 'selected' : '' ?>>M-Pesa</option>
                                    <option value="cash" <?= $filter_method == 'cash' ? 'selected' : '' ?>>Cash</option>
                                    <option value="card" <?= $filter_method == 'card' ? 'selected' : '' ?>>Card</option>
                                    <option value="bank" <?= $filter_method == 'bank' ? 'selected' : '' ?>>Bank Transfer</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="date_from" class="form-label">From Date</label>
                                <input type="date" id="date_from" name="date_from" class="form-control" value="<?= $filter_date_from ?>">
                            </div>
                            <div class="col-md-2">
                                <label for="date_to" class="form-label">To Date</label>
                                <input type="date" id="date_to" name="date_to" class="form-control" value="<?= $filter_date_to ?>">
                            </div>
                            <div class="col-md-12">
                                <button type="submit" class="btn btn-primary">Apply Filters</button>
                                <a href="admin_payments.php" class="btn btn-secondary">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Summary -->
                <div class="alert alert-info mb-4">
                    <strong>Total Payments:</strong> <?= CURRENCY ?> <?= number_format($total, 2) ?>
                    <span class="float-end">
                        <strong>Count:</strong> <?= count($payments) ?> records
                    </span>
                </div>
                
                <!-- Payments Table -->
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>User</th>
                                        <th>Meter</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Transaction Code</th>
                                        <th>Recorded By</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td><?= date('M j, Y H:i', strtotime($payment['payment_date'])) ?></td>
                                        <td><?= $payment['full_name'] ?> (<?= $payment['username'] ?>)</td>
                                        <td><?= $payment['meter_name'] ?></td>
                                        <td><?= CURRENCY ?> <?= number_format($payment['amount'], 2) ?></td>
                                        <td><?= ucfirst($payment['payment_method']) ?></td>
                                        <td><?= $payment['transaction_code'] ?? 'N/A' ?></td>
                                        <td>Admin</td>
                                        <td>
                                            <a href="admin_payment_receipt.php?id=<?= $payment['payment_id'] ?>" class="btn btn-sm btn-info">
                                                <i class="bi bi-receipt"></i> Receipt
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