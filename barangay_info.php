<?php
session_start();
include 'db_connect.php';

// SECURITY CHECK
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login.php");
    exit();
}

$admin_id = $_SESSION['user_id'];
$admin_data = $conn->query("SELECT full_name FROM users WHERE user_id = $admin_id")->fetch_assoc();

// Ensure there is at least one row in the settings table
$check_info = $conn->query("SELECT * FROM barangay_information LIMIT 1");
if ($check_info->num_rows == 0) {
    $conn->query("INSERT INTO barangay_information (barangay_name) VALUES ('Barangay Tanza')");
    $check_info = $conn->query("SELECT * FROM barangay_information LIMIT 1");
}
$info = $check_info->fetch_assoc();
$info_id = $info['id']; // Assuming the primary key is 'id'

$update_success = false;
$update_error = '';

// BACKEND: Handle Form Submission
if (isset($_POST['update_info'])) {
    $b_name = $_POST['barangay_name'];
    $captain = $_POST['captain_name'];
    $contact = $_POST['contact_number'];
    $address = $_POST['full_address'];
    $zip = $_POST['zip_code'];
    $region = $_POST['region'];
    
    // Default to existing logo if no new one is uploaded
    $logo_file_name = $info['logo_path']; 

    // Handle Logo Upload if a new file is selected
    if (!empty($_FILES["barangay_logo"]["name"])) {
        $target_dir = "uploads/logo/";
        if (!is_dir($target_dir)) { mkdir($target_dir, 0777, true); }
        
        $new_logo = time() . "_" . basename($_FILES["barangay_logo"]["name"]);
        $target_file = $target_dir . $new_logo;
        $file_extension = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));

        if (in_array($file_extension, array("jpg", "jpeg", "png"))) {
            if (move_uploaded_file($_FILES["barangay_logo"]["tmp_name"], $target_file)) {
                $logo_file_name = $new_logo; // Update variable to new file name
            }
        } else {
            $update_error = "Only JPG and PNG files are allowed for the logo.";
        }
    }

    if (empty($update_error)) {
        $update_sql = "UPDATE barangay_information SET 
                        barangay_name = ?, 
                        captain_name = ?, 
                        contact_number = ?, 
                        full_address = ?, 
                        zip_code = ?, 
                        region = ?, 
                        logo_path = ? 
                       WHERE id = ?";
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("sssssssi", $b_name, $captain, $contact, $address, $zip, $region, $logo_file_name, $info_id);
        
        if ($stmt->execute()) {
            $update_success = true;
            // Refresh info data after successful update
            $info = $conn->query("SELECT * FROM barangay_information LIMIT 1")->fetch_assoc();
        } else {
            $update_error = "Failed to update information in the database.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Information - Barangay Tanza</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: Arial, sans-serif; background-color: #F5F5F5; display: flex; height: 100vh; margin: 0; }
        
        /* NEW GREEN SIDEBAR */
        #sidebar { width: 260px; background-color: #2E7D32; color: #fff; display: flex; flex-direction: column; padding-top: 20px; box-shadow: 4px 0px 15px rgba(0,0,0,0.15); position: fixed; height: 100%; z-index: 1000; }
        #profile-header { text-align: center; padding-bottom: 20px; border-bottom: 1px solid #4CAF50; margin-bottom: 20px; }
        #profile-pic { width: 80px; height: 80px; border-radius: 50%; border: 3px solid #fff; object-fit: cover; margin-bottom: 10px; background-color: #fff; padding: 2px; }
        #admin-name { font-weight: bold; font-size: 18px; margin-bottom: 2px; }
        
        #nav-menu a { color: #e8f5e9; text-decoration: none; padding: 12px 20px; display: block; font-size: 15px; transition: 0.3s; border-radius: 4px; margin: 0 10px 5px 10px; }
        #nav-menu a:hover, #nav-menu a.active { color: #fff; background-color: #4CAF50; font-weight: bold; box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
        #nav-menu #logout-link { color: #ffcdd2; margin-top: auto; margin-bottom: 20px; }
        #nav-menu #logout-link:hover { background-color: #d32f2f; color: #fff; }
        
        /* MAIN CONTENT */
        #main-content { margin-left: 260px; flex: 1; padding: 30px; overflow-y: auto; }
        .page-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #ddd; padding-bottom: 10px; margin-bottom: 20px; }
        
        /* MATCHING FORM CARD */
        .form-card { background: #fff; padding: 30px; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); border-top: 5px solid #2E7D32; max-width: 900px; margin: 0 auto; }
        .form-label { font-weight: bold; color: #555; }
        #logoPreview { width: 120px; height: 120px; object-fit: cover; border-radius: 50%; border: 3px solid #ddd; display: block; margin: 10px 0; }

        /* CUSTOM GREEN SCROLLBAR */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-thumb { background: #4CAF50; border-radius: 4px; }
        ::-webkit-scrollbar-track { background: #F5F5F5; }
    </style>
</head>
<body>

    <div id="sidebar">
        <div id="profile-header">
            <?php 
            // Dynamically load the logo and name we fetched at the top of the page!
            $sidebar_logo = !empty($info['logo_path']) ? 'uploads/logo/' . $info['logo_path'] : 'uploads/default_profile.png';
            $sidebar_bname = !empty($info['barangay_name']) ? 'Brgy. ' . $info['barangay_name'] : 'Barangay System';
            ?>
            
            <img src="<?php echo $sidebar_logo; ?>" id="profile-pic" alt="Barangay Logo" onerror="this.src='https://cdn-icons-png.flaticon.com/512/149/149071.png'" style="background-color: #fff; padding: 2px;">
            
            <div id="admin-name" style="font-size: 18px; margin-bottom: 2px;"><?php echo htmlspecialchars($sidebar_bname); ?></div>
            <div style="font-size:11px; color:#aaa;">Admin: <?php echo htmlspecialchars($admin_data['full_name']); ?></div>
        </div>
        <div id="nav-menu">
            <a href="admin_dashboard.php?view=dashboard">📊 Main Dashboard</a>
            <a href="admin_dashboard.php?view=reports">🗑️ Waste Reports</a>
            <a href="admin_dashboard.php?view=map">📍 GIS Master Map</a>
            <a href="approve_resident.php">👥 Approve Residents</a>
            <a href="print_report.php" target="_blank">🖨️ Print Monthly Report</a>
            <a href="barangay_info.php" class="active">ℹ️ System Information</a>
            <a href="logout.php" id="logout-link" onclick="return confirm('Are you sure you want to log out?');">🚪 Logout</a>
        </div>
    </div>

    <div id="main-content">
        <div class="page-header">
            <h2 style="margin:0;">ℹ️ System Information</h2>
            <span class="text-muted small">Manage official barangay details</span>
        </div>

        <?php if($update_success): ?>
            <div class="alert alert-success shadow-sm fw-bold text-center mx-auto" style="max-width: 900px;">
                ✅ Barangay Information Successfully Updated!
            </div>
        <?php elseif(!empty($update_error)): ?>
            <div class="alert alert-danger shadow-sm fw-bold text-center mx-auto" style="max-width: 900px;">
                ❌ <?php echo $update_error; ?>
            </div>
        <?php endif; ?>

        <div class="form-card">
            <form method="POST" action="" enctype="multipart/form-data">
                
                <div class="row mb-4">
                    <div class="col-md-4 text-center border-end">
                        <label class="form-label">Official Barangay Logo</label>
                        <div class="d-flex justify-content-center">
                            <?php 
                            $logo_src = !empty($info['logo_path']) ? 'uploads/logo/' . $info['logo_path'] : 'https://via.placeholder.com/150?text=No+Logo';
                            ?>
                            <img id="logoPreview" src="<?php echo $logo_src; ?>" alt="Barangay Logo">
                        </div>
                        <input type="file" name="barangay_logo" class="form-control form-control-sm mt-2" accept=".jpg,.jpeg,.png" id="logoUpload">
                        <small class="text-muted">Leave blank to keep current logo</small>
                    </div>

                    <div class="col-md-8 pl-4">
                        <div class="mb-3">
                            <label class="form-label">Barangay Name</label>
                            <input type="text" name="barangay_name" class="form-control" value="<?php echo htmlspecialchars($info['barangay_name'] ?? ''); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-success">Punong Barangay (Barangay Captain)</label>
                            <input type="text" name="captain_name" class="form-control fw-bold" value="<?php echo htmlspecialchars($info['captain_name'] ?? ''); ?>" required placeholder="e.g. Hon. Juan Dela Cruz">
                        </div>
                    </div>
                </div>

                <hr class="mb-4">

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Contact Number</label>
                        <input type="text" name="contact_number" class="form-control" value="<?php echo htmlspecialchars($info['contact_number'] ?? ''); ?>" placeholder="Landline or Mobile">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Region</label>
                        <input type="text" name="region" class="form-control" value="<?php echo htmlspecialchars($info['region'] ?? ''); ?>" placeholder="e.g. Region VII (Central Visayas)">
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-8 mb-3">
                        <label class="form-label">Barangay Address (Full)</label>
                        <input type="text" name="full_address" class="form-control" value="<?php echo htmlspecialchars($info['full_address'] ?? ''); ?>" placeholder="e.g. Barangay Hall, M.L. Quezon St...">
                    </div>
                    <div class="col-md-4 mb-4">
                        <label class="form-label">Zip Code</label>
                        <input type="text" name="zip_code" class="form-control" value="<?php echo htmlspecialchars($info['zip_code'] ?? ''); ?>" placeholder="e.g. 6014">
                    </div>
                </div>

                <button type="submit" name="update_info" class="btn btn-success w-100 py-2 fw-bold fs-5 shadow-sm" onclick="return confirm('Are you sure you want to update the official Barangay Information?');">
                    💾 Save Changes
                </button>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.getElementById('logoUpload').addEventListener('change', function(event) {
            var reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('logoPreview').src = e.target.result;
            }
            if(event.target.files[0]) {
                reader.readAsDataURL(event.target.files[0]);
            }
        });
    </script>
</body>
</html>
