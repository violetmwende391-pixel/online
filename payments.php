<?php
require_once 'config.php';

if (!is_logged_in()) {
    redirect('login.php');
}

// Fetch user meters
try {
    $stmt = $pdo->prepare("SELECT * FROM meters WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $meters = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Process Manual Payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['payment_method'] !== 'mpesa') {
    $meter_id = intval($_POST['meter_id']);
    $amount = floatval($_POST['amount']);
    $method = sanitize($_POST['payment_method']);
    $transaction_code = sanitize($_POST['transaction_code']);

    if ($amount <= 0) {
        $error = "Amount must be greater than 0";
    } elseif (!in_array($method, ['cash', 'card', 'bank'])) {
        $error = "Invalid payment method";
    } else {
        try {
            $pdo->beginTransaction();
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

            add_command($pdo, $meter_id, 'topup', $amount);
            $pdo->commit();

            $_SESSION['success'] = "✅ Payment of " . CURRENCY . " $amount was successful";
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
    <title>Make Payment - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<?php include 'user_header.php'; ?>

<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?= $error ?></div>
            <?php elseif (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><?= $_SESSION['success']; unset($_SESSION['success']); ?></div>
            <?php endif; ?>

            <div class="card shadow">
                <div class="card-header bg-primary text-white">
                    <h5>Make Payment</h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="paymentForm" action="payment.php">
                        <div class="mb-3">
                            <label for="meter_id" class="form-label">Select Meter</label>
                            <select class="form-select" id="meter_id" name="meter_id" required>
                                <option value="">-- Select Meter --</option>
                                <?php foreach ($meters as $meter): ?>
                                    <option value="<?= $meter['meter_id'] ?>" data-serial="<?= $meter['meter_serial'] ?>">
                                        <?= $meter['meter_name'] ?> (<?= $meter['meter_location'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="amount" class="form-label">Amount (<?= CURRENCY ?>)</label>
                            <input type="number" name="amount" id="amount" class="form-control" required min="1">
                        </div>

                        <div class="mb-3">
                            <label for="payment_method" class="form-label">Payment Method</label>
                            <select class="form-select" id="payment_method" name="payment_method" required>
                                <option value="">-- Select Method --</option>
                                <option value="mpesa">M-Pesa (STK Push)</option>
                                <option value="cash">Cash</option>
                                <option value="card">Card</option>
                                <option value="bank">Bank Transfer</option>
                            </select>
                        </div>

                        <!-- Only for manual methods -->
                        <div class="mb-3 manual-field">
                            <label for="transaction_code" class="form-label">Transaction Code</label>
                            <input type="text" name="transaction_code" id="transaction_code" class="form-control">
                        </div>

                        <!-- For MPESA STK -->
                        <div class="mb-3 mpesa-field d-none">
                            <label for="phone" class="form-label">Phone Number (e.g. 254708374149)</label>
                            <input type="text" name="phone" id="phone" class="form-control">
                        </div>

                        <button type="submit" class="btn btn-success w-100" id="payBtn">Pay Now</button>
                    </form>
                </div>
            </div>

            <div id="stkResult" class="mt-4"></div>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const methodSelect = document.getElementById("payment_method");
    const manualField = document.querySelector(".manual-field");
    const mpesaField = document.querySelector(".mpesa-field");
    const payBtn = document.getElementById("payBtn");

    methodSelect.addEventListener("change", function() {
        if (this.value === "mpesa") {
            manualField.classList.add("d-none");
            mpesaField.classList.remove("d-none");
        } else {
            manualField.classList.remove("d-none");
            mpesaField.classList.add("d-none");
        }
    });

    document.getElementById("paymentForm").addEventListener("submit", function(e) {
        if (methodSelect.value === "mpesa") {
            e.preventDefault();
            const meterId = document.getElementById("meter_id").value;
            const amount = document.getElementById("amount").value;
            const phone = document.getElementById("phone").value;
            const serial = document.querySelector(`#meter_id option[value="${meterId}"]`).dataset.serial;

            if (!phone || !serial) {
                alert("Missing phone or meter serial.");
                return;
            }

            payBtn.disabled = true;
            payBtn.textContent = "Sending STK Push...";

            fetch("initiate_stk.php", {
                method: "POST",
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `phone=${phone}&meter_serial=${serial}&amount=${amount}`
            })
            .then(res => res.text())
            .then(data => {
                document.getElementById("stkResult").innerHTML = data;
                payBtn.disabled = false;
                payBtn.textContent = "Pay Now";
            })
            .catch(err => {
                alert("Failed to send STK Push.");
                payBtn.disabled = false;
                payBtn.textContent = "Pay Now";
            });
        }
    });
});
</script>

</body>
</html>
