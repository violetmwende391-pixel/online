<?php
require_once 'config.php';
isAuthenticated();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_consumer'])) {
        $name = trim($_POST['name']);
        $phone = trim($_POST['phone']);
        $email = trim($_POST['email']);
        $address = trim($_POST['address']);
        $accountType = $_POST['account_type'];
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO consumers 
                (name, phone, email, address, account_type) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$name, $phone, $email, $address, $accountType]);
            
            $_SESSION['success'] = "Consumer added successfully";
            header('Location: consumers.php');
            exit;
        } catch (PDOException $e) {
            $error = "Error adding consumer: " . $e->getMessage();
        }
    }
}

// Get all consumers
try {
    $stmt = $pdo->query("
        SELECT c.*, 
               (SELECT COUNT(*) FROM meters WHERE consumer_id = c.consumer_id) as meter_count,
               (SELECT SUM(amount) FROM payments WHERE consumer_id = c.consumer_id) as total_payments
        FROM consumers c
        ORDER BY c.name
    ");
    $consumers = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Error fetching consumers: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Consumers | <?= SITE_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
    <?php include 'navbar.php'; ?>
    
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5>Consumers List</h5>
                        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addConsumerModal">
                            <i class="bi bi-plus-lg"></i> Add Consumer
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (isset($_SESSION['success'])): ?>
                            <div class="alert alert-success"><?= $_SESSION['success'] ?></div>
                            <?php unset($_SESSION['success']); ?>
                        <?php endif; ?>
                        
                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger"><?= $error ?></div>
                        <?php endif; ?>
                        
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Phone</th>
                                        <th>Account Type</th>
                                        <th>Meters</th>
                                        <th>Total Paid</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($consumers as $consumer): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($consumer['name']) ?></td>
                                        <td><?= htmlspecialchars($consumer['phone']) ?></td>
                                        <td>
                                            <span class="badge bg-<?= $consumer['account_type'] === 'prepaid' ? 'info' : 'warning' ?>">
                                                <?= ucfirst($consumer['account_type']) ?>
                                            </span>
                                        </td>
                                        <td><?= $consumer['meter_count'] ?></td>
                                        <td><?= number_format($consumer['total_payments'] ?? 0, 2) ?> KES</td>
                                        <td>
                                            <a href="consumer_details.php?id=<?= $consumer['consumer_id'] ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-eye-fill"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add Consumer Modal -->
    <div class="modal fade" id="addConsumerModal" tabindex="-1" aria-labelledby="addConsumerModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title" id="addConsumerModalLabel">Add New Consumer</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="name" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="phone" class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" id="phone" name="phone" required>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email (Optional)</label>
                            <input type="email" class="form-control" id="email" name="email">
                        </div>
                        <div class="mb-3">
                            <label for="address" class="form-label">Address</label>
                            <textarea class="form-control" id="address" name="address" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="account_type" class="form-label">Account Type</label>
                            <select class="form-select" id="account_type" name="account_type" required>
                                <option value="prepaid">Prepaid</option>
                                <option value="postpaid">Postpaid</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="add_consumer" class="btn btn-primary">Save Consumer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

























 