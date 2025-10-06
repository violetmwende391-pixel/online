<?php
require_once 'config.php';

if (!is_admin_logged_in()) {
    redirect('admin_login.php');
}

// Get meter ID from URL
$meter_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

try {
    // Get meter details
    $stmt = $pdo->prepare("SELECT m.*, u.username, u.full_name, u.account_type 
                          FROM meters m 
                          LEFT JOIN users u ON m.user_id = u.user_id 
                          WHERE m.meter_id = ?");
    $stmt->execute([$meter_id]);
    $meter = $stmt->fetch();
    
    if (!$meter) {
        $_SESSION['error'] = "Meter not found";
        redirect('admin_meters.php');
    }
    
    // Get latest flow data
    $stmt = $pdo->prepare("SELECT * FROM flow_data 
                          WHERE meter_id = ? 
                          ORDER BY recorded_at DESC 
                          LIMIT 1");
    $stmt->execute([$meter_id]);
    $flow_data = $stmt->fetch();
    // Estimate remaining balance if not already stored in DB
$total_volume = $flow_data['total_volume'] ?? 0;

$stmt = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE meter_id = ?");
$stmt->execute([$meter_id]);
$total_topup = $stmt->fetchColumn() ?? 0;

if (!isset($flow_data['balance']) || $flow_data['balance'] === null) {
    // Fallback calculation only if balance is missing
    $flow_data['balance'] = $total_topup - $total_volume;
}



// After calculating balance
$api_balance = $total_topup - $total_volume;
if (abs($flow_data['balance'] - $api_balance) > 0.01) {
    error_log("Balance discrepancy detected for meter $meter_id: " .
             "Dashboard=$flow_data[balance] vs API=$api_balance");
}
    
    // Get payment history
    $stmt = $pdo->prepare("SELECT p.*, u.username, u.full_name 
                          FROM payments p 
                          JOIN users u ON p.user_id = u.user_id 
                          WHERE p.meter_id = ? 
                          ORDER BY p.payment_date DESC");
    $stmt->execute([$meter_id]);
    $payments = $stmt->fetchAll();
    
    // Get command history
    $stmt = $pdo->prepare("SELECT c.*, a.username as admin_username 
                          FROM commands c 
                          LEFT JOIN admins a ON c.issued_by = a.admin_id 
                          WHERE c.meter_id = ? 
                          ORDER BY c.issued_at DESC 
                          LIMIT 10");
    $stmt->execute([$meter_id]);
    $commands = $stmt->fetchAll();
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Process valve control form
// Process valve control form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['valve_action'])) {
    $action = sanitize($_POST['valve_action']);
    
    try {
        // Start transaction
        $pdo->beginTransaction();
        
        // First mark any existing pending commands as executed
        $stmt = $pdo->prepare("
            UPDATE commands 
            SET executed = TRUE, 
                executed_at = NOW(),
                notes = 'Overridden by new command'
            WHERE meter_id = ? 
            AND command_type = 'valve' 
            AND executed = FALSE
        ");
        $stmt->execute([$meter_id]);
        
        // Add new command for ESP32 - using PostgreSQL syntax
        $stmt = $pdo->prepare("
            INSERT INTO commands 
            (meter_id, command_type, command_value, issued_by, issued_at, executed) 
            VALUES (?, 'valve', ?, ?, NOW(), FALSE)
            RETURNING command_id
        ");
        $stmt->execute([$meter_id, $action, $_SESSION['admin_id']]);
        $command_id = $stmt->fetchColumn();
        
        // Also update the meter's last command timestamp
        $stmt = $pdo->prepare("
            UPDATE meters 
            SET last_command_at = NOW() 
            WHERE meter_id = ?
        ");
        $stmt->execute([$meter_id]);
        
        $pdo->commit();
        
        $_SESSION['success'] = "Valve command sent successfully (ID: $command_id)";
        redirect("admin_meter.php?id=$meter_id");
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Failed to send command: " . $e->getMessage();
        redirect("admin_meter.php?id=$meter_id");
    }
}

// Process top-up form - Add similar transaction handling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['topup_amount'])) {
    $amount = floatval($_POST['topup_amount']);
    $method = sanitize($_POST['payment_method']);
    $transaction_code = sanitize($_POST['transaction_code']);
    
    if ($amount <= 0) {
        $_SESSION['error'] = "Amount must be greater than 0";
        redirect("admin_meter.php?id=$meter_id");
    }
    
    try {
        $pdo->beginTransaction();
        
        // Record payment
        $stmt = $pdo->prepare("INSERT INTO payments 
                              (user_id, meter_id, amount, payment_method, transaction_code, recorded_by) 
                              VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $meter['user_id'],
            $meter_id,
            $amount,
            $method,
            $transaction_code,
            $_SESSION['admin_id']
        ]);
                // Update latest balance in flow_data (Quick Fix Option A)
// ✅ PostgreSQL-compatible version
$stmt = $pdo->prepare("
    UPDATE flow_data
    SET balance = balance + ?
    WHERE id = (
        SELECT id FROM flow_data 
        WHERE meter_id = ? 
        ORDER BY recorded_at DESC 
        LIMIT 1
    )
");
$stmt->execute([$amount, $meter_id]);


        
        // Mark any existing topup commands as executed
        $stmt = $pdo->prepare("
            UPDATE commands 
            SET executed = TRUE, 
                executed_at = NOW(),
                notes = 'Overridden by new topup'
            WHERE meter_id = ? 
            AND command_type = 'topup' 
            AND executed = FALSE
        ");
        $stmt->execute([$meter_id]);
        
        // Add new topup command
        $stmt = $pdo->prepare("
            INSERT INTO commands 
            (meter_id, command_type, command_value, issued_by, issued_at, executed) 
            VALUES (?, 'topup', ?, ?, NOW(), FALSE)
            RETURNING command_id
        ");
        $stmt->execute([$meter_id, $amount, $_SESSION['admin_id']]);
        $command_id = $stmt->fetchColumn();
        
        $pdo->commit();
        
        $_SESSION['success'] = "Top-up of " . CURRENCY . " $amount processed successfully (Command ID: $command_id)";
        redirect("admin_meter.php?id=$meter_id");
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Failed to process top-up: " . $e->getMessage();
        redirect("admin_meter.php?id=$meter_id");
    }
}

// ... [rest of your HTML remains exactly the same] ...
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meter Details - <?= APP_NAME ?></title>
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
                    <h1 class="h2">Meter Details</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="admin_meters.php" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> Back to Meters
                        </a>
                    </div>
                </div>
                
                <!-- Flash Messages -->
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger"><?= $_SESSION['error'] ?></div>
                    <?php unset($_SESSION['error']); ?>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success"><?= $_SESSION['success'] ?></div>
                    <?php unset($_SESSION['success']); ?>
                <?php endif; ?>
                
                <!-- Meter Info Card -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Meter Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <dl class="row">
                                    <dt class="col-sm-4">Meter Name:</dt>
                                    <dd class="col-sm-8"><?= $meter['meter_name'] ?></dd>
                                    
                                    <dt class="col-sm-4">Serial Number:</dt>
                                    <dd class="col-sm-8"><?= $meter['meter_serial'] ?></dd>
                                    
                                    <dt class="col-sm-4">Location:</dt>
                                    <dd class="col-sm-8"><?= $meter['meter_location'] ?></dd>
                                    
                                    <dt class="col-sm-4">IP Address:</dt>
                                    <dd class="col-sm-8"><?= $meter['ip_address'] ?? 'N/A' ?></dd>
                                </dl>
                            </div>
                            <div class="col-md-6">
                                <dl class="row">
                                    <dt class="col-sm-4">Assigned User:</dt>
                                    <dd class="col-sm-8">
                                        <?php if ($meter['user_id']): ?>
                                            <?= $meter['full_name'] ?> (<?= $meter['username'] ?>)
                                        <?php else: ?>
                                            <span class="text-muted">Unassigned</span>
                                        <?php endif; ?>
                                    </dd>
                                    
                                    <dt class="col-sm-4">Account Type:</dt>
                                    <dd class="col-sm-8">
                                        <?= ucfirst($meter['account_type'] ?? 'prepaid') ?>
                                    </dd>
                                    
                                    <dt class="col-sm-4">Status:</dt>
                                    <dd class="col-sm-8">
                                        <span class="badge bg-<?= $meter['status'] == 'active' ? 'success' : ($meter['status'] == 'maintenance' ? 'warning' : 'secondary') ?>">
                                            <?= ucfirst($meter['status']) ?>
                                        </span>
                                    </dd>
                                    
                                    <dt class="col-sm-4">Last Update:</dt>
                                    <dd class="col-sm-8">
                                        <?php if ($flow_data): ?>
                                            <?= date('M j, Y H:i', strtotime($flow_data['recorded_at'])) ?>
                                        <?php else: ?>
                                            Never
                                        <?php endif; ?>
                                    </dd>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Current Status Card -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Current Status</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($flow_data): ?>
                            <div class="row">
                                <div class="col-md-3 text-center">
                                    <div class="card mb-3">
                                        <div class="card-body">
                                            <h6 class="card-title">Flow Rate</h6>
                                            <h3 id="flow_rate" class="mb-0"><?= number_format($flow_data['flow_rate'], 2) ?> L/min</h3>

                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3 text-center">
                                    <div class="card mb-3">
                                        <div class="card-body">
                                            <h6 class="card-title">Session Volume</h6>
                                            <h3 id="session_volume" class="mb-0"><?= number_format($flow_data['volume'], 2) ?> L</h3>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3 text-center">
                                    <div class="card mb-3">
                                        <div class="card-body">
                                            <h6 class="card-title">Total Volume</h6>
                                            <h3 id="total_volume" class="mb-0"><?= number_format($flow_data['total_volume'], 2) ?> L</h3>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3 text-center">
                                    <div class="card mb-3 <?= $flow_data['balance'] < 100 ? 'bg-warning text-white' : '' ?>">
                                        <div class="card-body">
                                            <h6 class="card-title">Balance</h6>
                                            <h3 id="balance" class="mb-0"><?= CURRENCY ?> <?= number_format($flow_data['balance'], 2) ?></h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-body">
                                            <h6 class="card-title">Valve Status</h6>
                                            <h3 class="mb-0">
                                                <span id="valve_status" class="badge bg-<?= $flow_data['valve_status'] == 'open' ? 'success' : 'danger' ?>">
                                                      <?= ucfirst($flow_data['valve_status']) ?>
                                                </span>

                                                <span class="badge bg-<?= $flow_data['valve_status'] == 'open' ? 'success' : 'danger' ?>">
                                                    <?= ucfirst($flow_data['valve_status']) ?>
                                                </span>
                                            </h3>
                                            
<div class="btn-group" role="group">
    <button type="button" class="btn btn-success" onclick="sendCommand('open')">
        <i class="bi bi-valve-open"></i> Open Valve
    </button>
    <button type="button" class="btn btn-danger" onclick="sendCommand('close')">
        <i class="bi bi-valve-closed"></i> Close Valve
    </button>
</div>

<script>
function sendCommand(action) {
    const formData = new FormData();
    formData.append('meter_id', <?= $meter_id ?>);
    formData.append('valve_action', action);

    fetch('send_command.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            alert('Command sent successfully (ID: ' + data.command_id + ')');
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(err => alert('Network error: ' + err));
}
</script>

                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-body">
                                            <h6 class="card-title">Top-up Account</h6>
                                            
                                            <form method="POST">
                                                <div class="mb-3">
                                                    <label for="topup_amount" class="form-label">Amount (<?= CURRENCY ?>)</label>
                                                    <input type="number" step="0.01" min="0.01" class="form-control" id="topup_amount" name="topup_amount" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label for="payment_method" class="form-label">Payment Method</label>
                                                    <select class="form-select" id="payment_method" name="payment_method" required>
                                                        <option value="mpesa">M-Pesa</option>
                                                        <option value="cash">Cash</option>
                                                        <option value="card">Card</option>
                                                        <option value="bank">Bank Transfer</option>
                                                    </select>
                                                </div>
                                                <div class="mb-3">
                                                    <label for="transaction_code" class="form-label">Transaction Code</label>
                                                    <input type="text" class="form-control" id="transaction_code" name="transaction_code">
                                                </div>
                                                <button type="submit" class="btn btn-primary w-100">
                                                    <i class="bi bi-cash-coin"></i> Process Top-up
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                No data received from this meter yet.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Tabs for History -->
                <ul class="nav nav-tabs" id="meterTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="payments-tab" data-bs-toggle="tab" data-bs-target="#payments" type="button" role="tab">
                            Payment History
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="commands-tab" data-bs-toggle="tab" data-bs-target="#commands" type="button" role="tab">
                            Command History
                        </button>
                    </li>
                </ul>
                
                <div class="tab-content p-3 border border-top-0 rounded-bottom" id="meterTabsContent">
                    <!-- Payments Tab -->
                    <div class="tab-pane fade show active" id="payments" role="tabpanel">
                        <?php if ($payments): ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Amount</th>
                                            <th>Method</th>
                                            <th>Transaction Code</th>
                                            <th>Recorded By</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($payments as $payment): ?>
                                        <tr>
                                            <td><?= date('M j, Y H:i', strtotime($payment['payment_date'])) ?></td>
                                            <td><?= CURRENCY ?> <?= number_format($payment['amount'], 2) ?></td>
                                            <td><?= ucfirst($payment['payment_method']) ?></td>
                                            <td><?= $payment['transaction_code'] ?? 'N/A' ?></td>
                                            <td>Admin</td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                No payment history found for this meter.
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Commands Tab -->
                    <div class="tab-pane fade" id="commands" role="tabpanel">
                        <?php if ($commands): ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Command Type</th>
                                            <th>Value</th>
                                            <th>Issued By</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($commands as $command): ?>
                                        <tr>
                                            <td><?= date('M j, Y H:i', strtotime($command['issued_at'])) ?></td>
                                            <td><?= ucfirst($command['command_type']) ?></td>
                                            <td><?= $command['command_value'] ?></td>
                                            <td><?= $command['admin_username'] ?? 'System' ?></td>
                                            <td>
                                                <?php if ($command['executed']): ?>
                                                    <span class="badge bg-success">Executed</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning">Pending</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                No commands have been issued to this meter.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Initialize tabs
        var meterTabs = new bootstrap.Tab(document.getElementById('payments-tab'));
        meterTabs.show();
    </script>
    <script>
setInterval(() => {
    fetch('fetch_flow_data.php?meter_id=<?= $meter_id ?>')
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                document.getElementById('flow_rate').innerText = data.flow_rate.toFixed(2) + ' L/min';
                document.getElementById('session_volume').innerText = data.volume.toFixed(2) + ' L';
                document.getElementById('total_volume').innerText = data.total_volume.toFixed(2) + ' L';
                document.getElementById('balance').innerText = '<?= CURRENCY ?> ' + data.balance.toFixed(2);

                const valveStatus = document.getElementById('valve_status');
                valveStatus.innerText = data.valve_status.charAt(0).toUpperCase() + data.valve_status.slice(1);
                valveStatus.className = 'badge bg-' + (data.valve_status === 'open' ? 'success' : 'danger');
            }
        })
        .catch(err => console.error('Flow data fetch failed:', err));
}, 5000);
 // every 5 seconds
</script>

</body>
</html>