<?php
require_once 'config.php';

if (!is_logged_in()) {
    redirect('login.php');
}

// Get user's meters
try {
    $stmt = $pdo->prepare("SELECT * FROM meters WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $meters = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Process payment form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $meter_id = intval($_POST['meter_id']);
    $amount = floatval($_POST['amount']);
    $method = sanitize($_POST['payment_method']);
    $transaction_code = sanitize($_POST['transaction_code']);
    
    // Validate
    if ($amount <= 0) {
        $error = "Amount must be greater than 0";
    } elseif (!in_array($method, ['mpesa', 'cash', 'card', 'bank'])) {
        $error = "Invalid payment method";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Record payment
            $stmt = $pdo->prepare("INSERT INTO payments 
                                  (user_id, meter_id, amount, payment_method, transaction_code) 
                                  VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $_SESSION['user_id'],
                $meter_id,
                $amount,
                $method,
                $transaction_code
            ]);
            
            // Add top-up command for ESP32
            add_command($pdo, $meter_id, 'topup', $amount);
            
            $pdo->commit();
            
            $_SESSION['success'] = "Payment of " . CURRENCY . " $amount processed successfully";
            redirect("dashboard.php");
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Failed to process payment: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Make Payment - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <!-- Header/Navbar -->
    <?php include 'user_header.php'; ?>
    
    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Make Payment</h5>
                    </div>
                    <div class="card-body">
                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger"><?= $error ?></div>
                        <?php endif; ?>
                        
                        <form method="POST">
                            <div class="mb-3">
                                <label for="meter_id" class="form-label">Select Meter</label>
                                <select class="form-select" id="meter_id" name="meter_id" required>
                                    <option value="">-- Select Meter --</option>
                                    <?php foreach ($meters as $meter): ?>
                                    <option value="<?= $meter['meter_id'] ?>">
                                        <?= $meter['meter_name'] ?> (<?= $meter['meter_location'] ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="amount" class="form-label">Amount (<?= CURRENCY ?>)</label>
                                <input type="number" step="0.01" min="0.01" class="form-control" id="amount" name="amount" required>
                            </div>
                            <div class="mb-3">
                                <label for="payment_method" class="form-label">Payment Method</label>
                                <select class="form-select" id="payment_method" name="payment_method" required>
                                    <option value="">-- Select Method --</option>
                                    <option value="mpesa">M-Pesa</option>
                                    <option value="cash">Cash</option>
                                    <option value="card">Card</option>
                                    <option value="bank">Bank Transfer</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="transaction_code" class="form-label">Transaction Code (if applicable)</label>
                                <input type="text" class="form-control" id="transaction_code" name="transaction_code">
                            </div>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-cash-coin"></i> Process Payment
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>