<!DOCTYPE html>
<html>
<head>
    <title>Paystack M-Pesa Payment</title>
</head>
<body>
    <h2>Pay with M-Pesa (Paystack)</h2>
    <form action="pay.php" method="POST">
        <label>Customer Name:</label><br>
        <input type="text" name="name" required><br><br>

        <label>Email Address:</label><br>
        <input type="email" name="email" required><br><br>

        <label>Amount (KES):</label><br>
        <input type="number" name="amount" step="0.01" required><br><br>

        <label>Account Number:</label><br>
        <input type="text" name="account_number" required><br><br>

        <button type="submit">Pay Now</button>
    </form>
</body>
</html>
