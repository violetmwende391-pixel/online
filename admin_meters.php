<?php
require_once 'config.php';

if (!is_admin_logged_in()) {
    redirect('../admin_login.php');
}

try {
    // Get all meters with user info
    $stmt = $pdo->query("SELECT m.*, u.username, u.full_name 
                        FROM meters m 
                        LEFT JOIN users u ON m.user_id = u.user_id 
                        ORDER BY m.meter_name");
    $meters = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Process delete request
if (isset($_GET['delete'])) {
    $meter_id = intval($_GET['delete']);
    
    try {
        $pdo->beginTransaction();
        
        // Delete meter's flow data
        $stmt = $pdo->prepare("DELETE FROM flow_data WHERE meter_id = ?");
        $stmt->execute([$meter_id]);
        
        // Delete meter's payments
        $stmt = $pdo->prepare("DELETE FROM payments WHERE meter_id = ?");
        $stmt->execute([$meter_id]);
        
        // Delete meter's commands
        $stmt = $pdo->prepare("DELETE FROM commands WHERE meter_id = ?");
        $stmt->execute([$meter_id]);
        
        // Delete meter
        $stmt = $pdo->prepare("DELETE FROM meters WHERE meter_id = ?");
        $stmt->execute([$meter_id]);
        
        $pdo->commit();
        
        $_SESSION['success'] = "Meter deleted successfully";
        redirect('admin_meters.php');
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Failed to delete meter: " . $e->getMessage();
        redirect('admin_meters.php');
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Meters - <?= APP_NAME ?></title>
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
                    <h1 class="h2">Manage Meters</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="admin_add_meter.php" class="btn btn-sm btn-primary">
                            <i class="bi bi-plus-circle"></i> Add New Meter
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
                
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Serial</th>
                                        <th>Assigned User</th>
                                        <th>Location</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($meters as $meter): ?>
                                    <tr>
                                        <td><?= $meter['meter_id'] ?></td>
                                        <td><?= $meter['meter_name'] ?></td>
                                        <td><?= $meter['meter_serial'] ?></td>
                                        <td>
                                            <?php if ($meter['user_id']): ?>
                                                <?= $meter['full_name'] ?> (<?= $meter['username'] ?>)
                                            <?php else: ?>
                                                <span class="text-muted">Unassigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $meter['meter_location'] ?></td>
                                        <td>
                                            <span class="badge bg-<?= $meter['status'] == 'active' ? 'success' : ($meter['status'] == 'maintenance' ? 'warning' : 'secondary') ?>">
                                                <?= ucfirst($meter['status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="admin_meter.php?id=<?= $meter['meter_id'] ?>" class="btn btn-sm btn-primary">
                                                <i class="bi bi-eye"></i> View
                                            </a>
                                            <a href="admin_edit_meter.php?id=<?= $meter['meter_id'] ?>" class="btn btn-sm btn-secondary">
                                                <i class="bi bi-pencil"></i> Edit
                                            </a>
                                            <a href="admin_meters.php?delete=<?= $meter['meter_id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this meter?')">
                                                <i class="bi bi-trash"></i> Delete
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