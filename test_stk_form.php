<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>STK Push Test - Smart Meter</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">Test STK Push Payment</h4>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="initiate_stk.php">
                            <div class="mb-3">
                                <label for="phone" class="form-label">Phone Number (e.g. 254708374149)</label>
                                <input type="text" name="phone" id="phone" class="form-control" required>
                            </div>

                            <div class="mb-3">
                                <label for="meter_serial" class="form-label">Meter Serial</label>
                                <input type="text" name="meter_serial" id="meter_serial" class="form-control" required>
                            </div>

                            <div class="mb-3">
                                <label for="amount" class="form-label">Amount (KES)</label>
                                <input type="number" name="amount" id="amount" class="form-control" required min="1">
                            </div>

                            <button type="submit" class="btn btn-success w-100">
                                <i class="bi bi-phone"></i> Send STK Push
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap icons (optional) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
</body>
</html>
