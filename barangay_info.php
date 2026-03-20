<?php
session_start();
include 'db_connect.php';

// SECURITY CHECK
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login.php");
    exit();
}

// BACKEND: Get current admin data for the sidebar profile
$admin_id = $_SESSION['user_id'];
$admin_data = $conn->query("SELECT full_name FROM users WHERE user_id = $admin_id")->fetch_assoc();

// BACKEND: Handle the update form
$update_success = false;
$error_msg = '';

if (isset($_POST['update_info'])) {
    $b_name = $_POST['barangay_name'];
    $m_name = $_POST['municipality'];
    $p_name = $_POST['province'];
    $c_name = $_POST['captain_name'];
    $email = $_POST['contact_email'];

    $sql = "UPDATE barangay_information SET barangay_name=?, municipality=?, province=?, captain_name=?, contact_email=? WHERE id=1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssss", $b_name, $m_name, $p_name, $c_name, $email);
    
    if ($stmt->execute()) {
        $update_success = true;
    } else {
        $error_msg = 'Database error: Failed to update information.';
    }
}

// FETCH: Get the current info to fill the form
$b_info = $conn->query("SELECT * FROM barangay_information WHERE id=1")->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Information - Barangay Tanza</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <style>
        /* BASE STYLES & SIDEBAR (Matches Admin Dashboard perfectly) */
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; display: flex; height: 100vh; background-color: #f4f7f6; }
        
        #sidebar { width: 260px; background-color: #343a40; color: #fff; display: flex; flex-direction: column; padding-top: 20px; box-shadow: 2px 0px 10px rgba(0,0,0,0.1); position: fixed; height: 100%; z-index: 1000; }
        #profile-header { text-align: center; padding-bottom: 20px; border-bottom: 1px solid #4b545c; margin-bottom: 20px; }
        #profile-pic { width: 80px; height: 80px; border-radius: 50%; border: 3px solid #fff; object-fit: cover; margin-bottom: 10px; }
        #admin-name { font-weight: bold; font-size: 16px; }
        
        #nav-menu a { color: #c2c7d0; text-decoration: none; padding: 12px 20px; display: block; font-size: 15px; transition: 0.3s; border-radius: 4px; margin: 0 10px 5px 10px; cursor: pointer; }
        #nav-menu a:hover, #nav-menu a.active { color: #fff; background-color: #28a745; font-weight: bold; }
        #nav-menu #logout-link { color: #dc3545; margin-top: auto; margin-bottom: 20px; }

        #main-content { margin-left: 260px; flex: 1; padding: 30px; overflow-y: auto; }
        #dashboard-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; border-bottom: 2px solid #ddd; padding-bottom: 10px; }
    </style>
</head>
<body>

    <div id="sidebar">
        <div id="profile-header">
            <img src="uploads/default_profile.png" id="profile-pic" alt="Admin Profile" onerror="this.src='https://cdn-icons-png.flaticon.com/512/149/149071.png'">
            <div id="admin-name"><?php echo htmlspecialchars($admin_data['full_name']); ?></div>
            <div style="font-size:12px; color:#aaa; margin-top:3px;">Barangay Secretary</div>
        </div>

        <div id="nav-menu">
            <a href="admin_dashboard.php?view=dashboard">📊 Main Dashboard</a>
            <a href="admin_dashboard.php?view=reports">🗑️ Waste Reports</a>
            <a href="admin_dashboard.php?view=map">📍 GIS Master Map</a>
            
            <a href="approve_resident.php">👥 Approve Residents</a>
            <a href="print_report.php" target="_blank">🖨️ Print Monthly Report</a>
            <a href="barangay_info.php" class="active">ℹ️ System Information</a>
            <a href="logout.php" id="logout-link">🚪 Logout</a>
        </div>
    </div>

    <div id="main-content">
        <div id="dashboard-header">
            <h2 style="margin:0;">ℹ️ System Information</h2>
            <div style="font-size:14px; color:#777;">Manage official barangay details</div>
        </div>

        <?php if($update_success): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
                <strong>Success!</strong> Barangay information has been updated.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php elseif(!empty($error_msg)): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                <strong>Error!</strong> <?php echo $error_msg; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm border-0" style="max-width: 800px;">
            <div class="card-header bg-dark text-white fw-bold py-3">
                Update Barangay Details
            </div>
            <div class="card-body bg-white p-4">
                <p class="text-muted small mb-4">This information is used to generate official headers on your printable reports and system alerts.</p>
                
                <form method="POST" action="">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Barangay Name</label>
                            <input type="text" name="barangay_name" class="form-control" value="<?php echo htmlspecialchars($b_info['barangay_name']); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Municipality / City</label>
                            <input type="text" name="municipality" class="form-control" value="<?php echo htmlspecialchars($b_info['municipality']); ?>" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Province</label>
                        <input type="text" name="province" class="form-control" value="<?php echo htmlspecialchars($b_info['province']); ?>" required>
                    </div>

                    <hr class="my-4">

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Barangay Captain Full Name</label>
                            <input type="text" name="captain_name" class="form-control" value="<?php echo htmlspecialchars($b_info['captain_name']); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Official Contact Email</label>
                            <input type="email" name="contact_email" class="form-control" value="<?php echo htmlspecialchars($b_info['contact_email']); ?>">
                        </div>
                    </div>
                    
                    <div class="mt-4 text-end">
                        <button type="submit" name="update_info" class="btn btn-success fw-bold px-4 py-2 shadow-sm">💾 Save Changes</button>
                    </div>
                </form>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
