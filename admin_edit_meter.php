<?php
require_once 'config.php';

if (!is_admin_logged_in()) {
    redirect('../admin_login.php');
}

$meter_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

try {
    // Get meter details
    $stmt = $pdo->prepare("SELECT * FROM meters WHERE meter_id = ?");
    $stmt->execute([$meter_id]);
    $meter = $stmt->fetch();
    
    if (!$meter) {
        $_SESSION['error'] = "Meter not found";
        redirect('admin_meters.php');
    }
    
    // Get all users for dropdown
    $stmt = $pdo->query("SELECT user_id, username, full_name FROM users ORDER BY full_name");
    $users = $stmt->fetchAll();
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $meter_name = sanitize($_POST['meter_name']);
    $meter_serial = sanitize($_POST['meter_serial']);
    $meter_location = sanitize($_POST['meter_location']);
    $ip_address = sanitize($_POST['ip_address']);
    $user_id = !empty($_POST['user_id']) ? intval($_POST['user_id']) : null;
    $status = sanitize($_POST['status']);
    
    try {
        $stmt = $pdo->prepare("UPDATE meters SET 
                              meter_name = ?,
                              meter_serial = ?,
                              meter_location = ?,
                              ip_address = ?,
                              user_id = ?,
                              status = ?
                              WHERE meter_id = ?");
        $stmt->execute([
            $meter_name,
            $meter_serial,
            $meter_location,
            $ip_address,
            $user_id,
            $status,
            $meter_id
        ]);
        
        $_SESSION['success'] = "Meter updated successfully";
        redirect("admin_meter.php?id=$meter_id");
        
    } catch (PDOException $e) {
        $_SESSION['error'] = "Failed to update meter: " . $e->getMessage();
        redirect("admin_edit_meter.php?id=$meter_id");
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Meter - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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
                    <h1 class="h2">Edit Meter</h1>
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
                
                <div class="card">
                    <div class="card-body">
                        <form method="POST">
                            <div class="mb-3">
                                <label for="meter_name" class="form-label">Meter Name</label>
                                <input type="text" class="form-control" id="meter_name" name="meter_name" value="<?= $meter['meter_name'] ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="meter_serial" class="form-label">Serial Number</label>
                                <input type="text" class="form-control" id="meter_serial" name="meter_serial" value="<?= $meter['meter_serial'] ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="meter_location" class="form-label">Location</label>
                                <input type="text" class="form-control" id="meter_location" name="meter_location" value="<?= $meter['meter_location'] ?>">
                            </div>
                            <div class="mb-3">
                                <label for="ip_address" class="form-label">IP Address</label>
                                <input type="text" class="form-control" id="ip_address" name="ip_address" value="<?= $meter['ip_address'] ?>">
                            </div>
                            <div class="mb-3">
                                <label for="user_id" class="form-label">Assigned User</label>
                                <select class="form-select" id="user_id" name="user_id">
                                    <option value="">-- Unassigned --</option>
                                    <?php foreach ($users as $user): ?>
                                    <option value="<?= $user['user_id'] ?>" <?= $meter['user_id'] == $user['user_id'] ? 'selected' : '' ?>>
                                        <?= $user['full_name'] ?> (<?= $user['username'] ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status" required>
                                    <option value="active" <?= $meter['status'] == 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="inactive" <?= $meter['status'] == 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                    <option value="maintenance" <?= $meter['status'] == 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary">Update Meter</button>
                        </form>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>