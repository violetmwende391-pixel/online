<?php
require_once 'config.php';

if (!is_logged_in()) {
    redirect('login.php');
}

try {
    // Get user's meters
    $stmt = $pdo->prepare("SELECT * FROM meters WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $meters = $stmt->fetchAll();

    // Compute accurate balances for each meter
    foreach ($meters as &$meter) {
        $meter_id = $meter['meter_id'];

        // Get total volume
        $stmt = $pdo->prepare("SELECT total_volume FROM flow_data WHERE meter_id = ? ORDER BY recorded_at DESC LIMIT 1");
        $stmt->execute([$meter_id]);
        $volume_data = $stmt->fetch();
        $total_volume = $volume_data['total_volume'] ?? 0;

        // Get total top-up
        $stmt = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE meter_id = ?");
        $stmt->execute([$meter_id]);
        $total_topup = $stmt->fetchColumn() ?? 0;

        // Set accurate balance
        $meter['balance'] = $total_topup - $total_volume;
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
    <title>My Meters - <?= APP_NAME ?></title>
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
                        <a class="nav-link active" href="meters.php"><i class="bi bi-water"></i> My Meters</a>
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
                <h2>My Meters</h2>

                <?php if (empty($meters)): ?>
                    <div class="alert alert-info">
                        No meters assigned to your account.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Meter Name</th>
                                    <th>Serial Number</th>
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
                                    <td><?= $meter['meter_serial'] ?></td>
                                    <td><?= $meter['meter_location'] ?></td>
                                    <td><?= CURRENCY ?> <?= number_format($meter['balance'], 2) ?></td>
                                    <td>
                                        <span class="badge bg-<?= $meter['status'] == 'active' ? 'success' : ($meter['status'] == 'maintenance' ? 'warning' : 'secondary') ?>">
                                            <?= ucfirst($meter['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="meter.php?id=<?= $meter['meter_id'] ?>" class="btn btn-sm btn-primary">
                                            <i class="bi bi-eye"></i> View
                                        </a>
                                        <a href="make_payment.php?meter_id=<?= $meter['meter_id'] ?>" class="btn btn-sm btn-success">
                                            <i class="bi bi-cash-coin"></i> Top-up
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
