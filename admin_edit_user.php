<?php
require_once 'config.php';

if (!is_admin_logged_in()) {
    redirect('admin_login.php');
}

$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

try {
    // Get user details
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    if (!$user) {
        $_SESSION['error'] = "User not found";
        redirect('admin_users.php');
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize($_POST['username']);
    $full_name = sanitize($_POST['full_name']);
    $email = sanitize($_POST['email']);
    $phone = sanitize($_POST['phone']);
    $address = sanitize($_POST['address']);
    $account_type = sanitize($_POST['account_type']);
    $change_password = isset($_POST['change_password']) && $_POST['change_password'] == '1';
    
    // Validate
    try {
        $pdo->beginTransaction();
        
        // Update basic info
        $sql = "UPDATE users SET 
                username = ?,
                full_name = ?,
                email = ?,
                phone = ?,
                address = ?,
                account_type = ?";
        
        $params = [
            $username,
            $full_name,
            $email,
            $phone,
            $address,
            $account_type
        ];
        
        // Add password update if requested
        if ($change_password) {
            $new_password = $_POST['new_password'];
            $confirm_password = $_POST['confirm_password'];
            
            if ($new_password !== $confirm_password) {
                throw new Exception("Passwords do not match");
            }
            
            $sql .= ", password = ?";
            $params[] = hash_password($new_password);
        }
        
        $sql .= " WHERE user_id = ?";
        $params[] = $user_id;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        $pdo->commit();
        
        $_SESSION['success'] = "User updated successfully";
        redirect("admin_users.php");
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Failed to update user: " . $e->getMessage();
        redirect("admin_edit_user.php?id=$user_id");
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User - <?= APP_NAME ?></title>
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
                    <h1 class="h2">Edit User</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="admin_users.php" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> Back to Users
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
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="username" class="form-label">Username</label>
                                        <input type="text" class="form-control" id="username" name="username" 
                                               value="<?= htmlspecialchars($user['username']) ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="full_name" class="form-label">Full Name</label>
                                        <input type="text" class="form-control" id="full_name" name="full_name" 
                                               value="<?= htmlspecialchars($user['full_name']) ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="email" class="form-label">Email</label>
                                        <input type="email" class="form-control" id="email" name="email" 
                                               value="<?= htmlspecialchars($user['email']) ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="phone" class="form-label">Phone</label>
                                        <input type="text" class="form-control" id="phone" name="phone" 
                                               value="<?= htmlspecialchars($user['phone']) ?>">
                                    </div>
                                    <div class="mb-3">
                                        <label for="address" class="form-label">Address</label>
                                        <textarea class="form-control" id="address" name="address" 
                                                  rows="2"><?= htmlspecialchars($user['address']) ?></textarea>
                                    </div>
                                    <div class="mb-3">
                                        <label for="account_type" class="form-label">Account Type</label>
                                        <select class="form-select" id="account_type" name="account_type" required>
                                            <option value="prepaid" <?= $user['account_type'] == 'prepaid' ? 'selected' : '' ?>>Prepaid</option>
                                            <option value="postpaid" <?= $user['account_type'] == 'postpaid' ? 'selected' : '' ?>>Postpaid</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="card mt-3">
                                <div class="card-header">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" 
                                               id="change_password_switch" name="change_password" value="1">
                                        <label class="form-check-label" for="change_password_switch">
                                            Change Password
                                        </label>
                                    </div>
                                </div>
                                <div class="card-body" id="password_fields" style="display: none;">
                                    <div class="mb-3">
                                        <label for="new_password" class="form-label">New Password</label>
                                        <input type="password" class="form-control" id="new_password" name="new_password">
                                    </div>
                                    <div class="mb-3">
                                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password">
                                    </div>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary mt-3">Update User</button>
                        </form>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle password fields visibility
        document.getElementById('change_password_switch').addEventListener('change', function() {
            const passwordFields = document.getElementById('password_fields');
            passwordFields.style.display = this.checked ? 'block' : 'none';
            
            // Make password fields required when visible
            const passwordInputs = passwordFields.querySelectorAll('input[type="password"]');
            passwordInputs.forEach(input => {
                input.required = this.checked;
            });
        });
    </script>
</body>
</html>