<?php
require_once 'config.php';

if (!is_logged_in()) {
    redirect('login.php');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Simulate M-Pesa Payment</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<?php include 'user_header.php'; ?>

<div class="container mt-5">
    <div class="card">
        <div class="card-header bg-success text-white">
            <h4>Simulate M-Pesa Payment</h4>
        </div>
        <div class="card-body">
            <form method="POST" action="mpesa_callback.php">
                <div class="mb-3">
                    <label for="TransAmount" class="form-label">Amount</label>
                    <input type="number" step="0.01" name="TransAmount" id="TransAmount" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label for="TransID" class="form-label">Transaction Code</label>
                    <input type="text" name="TransID" id="TransID" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label for="BillRefNumber" class="form-label">Meter Serial</label>
                    <input type="text" name="BillRefNumber" id="BillRefNumber" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-success w-100">Simulate Payment</button>
            </form>
        </div>
    </div>
</div>

</body>
</html>
