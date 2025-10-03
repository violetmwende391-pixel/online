<!DOCTYPE html>
<html>
<head>
    <title>M-Pesa STK Push Test</title>
</head>
<body>
    <h3>Test M-Pesa STK Push</h3>
    <form action="initiate_stk_push.php" method="POST">
        <label>Phone Number (e.g. 254708374149):</label><br>
        <input type="text" name="phone" required><br><br>

        <label>Meter Serial Number:</label><br>
        <input type="text" name="meter_serial" required><br><br>

        <label>Amount (KES):</label><br>
        <input type="number" name="amount" required><br><br>

        <button type="submit">Initiate Payment</button>
    </form>
</body>
</html>
