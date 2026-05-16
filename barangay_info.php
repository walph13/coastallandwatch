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
$info_id = $info['id'];

$update_success = false;
$update_error = '';

// BACKEND: Handle Form Submission
if (isset($_POST['update_info'])) {
    $b_name      = $_POST['barangay_name'];
    $captain     = $_POST['captain_name'];
    $secretary   = $_POST['secretary_name']; // NEW ADDITION
    $contact     = $_POST['contact_number'];
    $address     = $_POST['full_address'];
    $zip         = $_POST['zip_code'];
    $region      = $_POST['region'];
    $subtitle    = $_POST['system_subtitle'];
    $description = $_POST['system_description'];
    $city        = $_POST['city'];
    $municipal   = $_POST['municipal'];
    $office_hrs  = $_POST['office_hours'];
    $hotlines    = $_POST['emergency_hotlines'];

    $logo_file_name = $info['logo_path'];

    if (!empty($_FILES["barangay_logo"]["name"])) {
        $target_dir = "uploads/logo/";
        if (!is_dir($target_dir)) { mkdir($target_dir, 0777, true); }
        $new_logo = time() . "_" . basename($_FILES["barangay_logo"]["name"]);
        $target_file = $target_dir . $new_logo;
        $file_extension = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
        if (in_array($file_extension, array("jpg", "jpeg", "png"))) {
            if (move_uploaded_file($_FILES["barangay_logo"]["tmp_name"], $target_file)) {
                $logo_file_name = $new_logo;
            }
        } else {
            $update_error = "Only JPG and PNG files are allowed for the logo.";
        }
    }

    if (empty($update_error)) {
        // UPDATED QUERY to include secretary_name
        $update_sql = "UPDATE barangay_information SET 
                        barangay_name      = ?, 
                        captain_name       = ?, 
                        secretary_name     = ?, 
                        contact_number     = ?, 
                        full_address       = ?, 
                        zip_code           = ?, 
                        region             = ?, 
                        logo_path          = ?,
                        system_subtitle    = ?,
                        system_description = ?,
                        city               = ?,
                        municipal          = ?,
                        office_hours       = ?,
                        emergency_hotlines = ?
                       WHERE id = ?";
        $stmt = $conn->prepare($update_sql);
        // Added an extra 's' for the new string
        $stmt->bind_param(
            "ssssssssssssssi",
            $b_name, $captain, $secretary, $contact, $address, $zip, $region,
            $logo_file_name, $subtitle, $description,
            $city, $municipal, $office_hrs, $hotlines, $info_id
        );
        if ($stmt->execute()) {
            $update_success = true;
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
        /* PAGE BACKGROUND */
        body { font-family: 'Segoe UI', Arial, sans-serif; background-color: #ECEFF1; display: flex; height: 100vh; margin: 0; }

        /* DARKER SIDEBAR */
        #sidebar { width: 260px; background-color: #1B5E20; color: #fff; display: flex; flex-direction: column; padding-top: 30px; box-shadow: 4px 0px 15px rgba(0,0,0,0.1); position: fixed; height: 100%; z-index: 1000; }
        #profile-header { text-align: center; padding-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); margin-bottom: 15px; }
        
        #profile-pic { width: 80px; height: 80px; border-radius: 50%; border: 3px solid #fff; object-fit: cover; margin-bottom: 15px; background-color: #fff; padding: 2px; }
        
        #admin-name { font-weight: 800; font-size: 18px; margin-bottom: 2px; letter-spacing: 0.5px; }
        #admin-location { font-size: 12px; color: #A5D6A7; margin-bottom: 5px; }
        
        .sidebar-menu-title { font-size: 11px; color: #81C784; font-weight: 700; letter-spacing: 1px; padding: 0 20px; margin-bottom: 10px; margin-top: 10px; text-transform: uppercase; }

        #nav-menu a { color: #C8E6C9; text-decoration: none; padding: 12px 20px; display: block; font-size: 15px; transition: 0.3s; border-left: 4px solid transparent; }
        #nav-menu a:hover, #nav-menu a.active { color: #fff; background-color: rgba(255,255,255,0.1); border-left: 4px solid #81C784; font-weight: 700; }
        #nav-menu #logout-link { color: #FFCDD2; margin-top: auto; margin-bottom: 30px; border-left: 4px solid transparent; }
        #nav-menu #logout-link:hover { background-color: #D32F2F; color: #fff; border-left: 4px solid #FF5252; }

        /* MAIN CONTENT */
        #main-content { margin-left: 260px; flex: 1; padding: 40px; overflow-y: auto; }
        
        /* HEADER */
        .page-header { margin-bottom: 25px; }
        .page-title { margin:0; font-weight: 800; color: #263238; font-size: 24px; }

        /* FORM CARD - FULL WIDTH */
        .form-card { background: #fff; padding: 40px; border-radius: 16px; box-shadow: 0 8px 24px rgba(0,0,0,0.04); max-width: 100%; border: 1px solid rgba(0,0,0,0.05); }
        
        .form-label { font-weight: 600; color: #546E7A; font-size: 13px; margin-bottom: 6px; }
        .form-control { border-radius: 8px; border: 1px solid #CFD8DC; padding: 10px 14px; background-color: #F8FDFF; color: #37474F; transition: 0.2s; }
        .form-control:focus { border-color: #81C784; box-shadow: 0 0 0 0.2rem rgba(76, 175, 80, 0.15); }
        
        #logoPreview { width: 120px; height: 120px; object-fit: cover; border-radius: 50%; border: 3px solid #E0E0E0; display: block; margin: 10px auto; }

        /* SECTION TITLES */
        .section-title {
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 0.08em;
            color: #1B5E20;
            background-color: #E8F5E9;
            border-left: 4px solid #2E7D32;
            padding: 8px 16px;
            border-radius: 0 8px 8px 0;
            margin: 30px 0 20px 0;
            display: block;
        }

        /* SCROLLBAR */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-thumb { background: #90A4AE; border-radius: 4px; }
        ::-webkit-scrollbar-track { background: #ECEFF1; }

        /* ========================================= */
        /* 📱 MOBILE RESPONSIVENESS                  */
        /* ========================================= */
        #sidebar { transition: 0.3s ease-in-out; }
        #sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999; }
        #sidebar-overlay.active { display: block; }

        @media (max-width: 768px) {
            #sidebar { left: -260px; position: fixed; z-index: 1000; }
            #sidebar.active { left: 0; box-shadow: 5px 0 20px rgba(0,0,0,0.5); }
            #main-content { margin-left: 0 !important; padding: 15px !important; width: 100%; }
            .page-header { flex-direction: column; gap: 15px; align-items: flex-start !important; }
            .page-header > div { width: 100%; }
            .dashboard-card { padding: 15px; }
            table { display: block; overflow-x: auto; white-space: nowrap; }
        }

    </style>
    
</head>
<body>
<div id="sidebar-overlay" onclick="toggleSidebar()"></div>
<div id="sidebar">
    <div id="profile-header">
        <?php
        $sidebar_logo  = !empty($info['logo_path']) ? 'uploads/logo/' . $info['logo_path'] : 'uploads/default_profile.png';
        $sidebar_bname = !empty($info['barangay_name']) ? 'Brgy. ' . $info['barangay_name'] : 'Barangay System';
        $sidebar_municipal = !empty($info['municipal']) ? $info['municipal'] : 'Estancia';
        $sidebar_city = !empty($info['city']) ? $info['city'] : 'Iloilo';
        ?>
        <img src="<?php echo $sidebar_logo; ?>" id="profile-pic" alt="Barangay Logo"
             onerror="this.src='https://cdn-icons-png.flaticon.com/512/149/149071.png'">
        <div id="admin-name"><?php echo htmlspecialchars($sidebar_bname); ?></div>
        <div id="admin-location"><?php echo htmlspecialchars($sidebar_municipal . ', ' . $sidebar_city); ?></div>
    </div>
    
    <div class="sidebar-menu-title">Menu</div>
    <div id="nav-menu">
        <a href="admin_dashboard.php?view=dashboard">📊 Dashboard</a>
        <a href="admin_dashboard.php?view=reports">🗑️ Reports</a>  
        <a href="approve_resident.php">👥 Residents</a>
        <a href="barangay_info.php" class="active">ℹ️ System Info</a>
        <a href="logout.php" id="logout-link" onclick="return confirm('Are you sure you want to log out?');">🚪 Logout</a>
    </div>
</div>

<div id="main-content">
    <div class="d-md-none mb-4 shadow-sm" style="background:#1B5E20; padding:15px 20px; display:flex; justify-content:space-between; align-items:center; border-radius: 8px;">
            <h5 class="m-0 fw-bold text-white">⚙️ Admin Menu</h5>
            <button onclick="toggleSidebar()" style="background:none; border:none; color:white; font-size:28px; padding:0; cursor:pointer;">☰</button>
        </div>
    <div class="page-header">
        <h2 class="page-title">System Information</h2>
        <span class="text-muted small" style="color: #78909C !important; font-weight: 500;">Manage official barangay details</span>
    </div>

    <?php if ($update_success): ?>
        <div class="alert alert-success shadow-sm fw-bold text-center mx-auto" style="border-radius: 10px; background-color: #D4EDDA; border-color: #C3E6CB; color: #155724;">
            ✅ Barangay Information Successfully Updated!
        </div>
    <?php elseif (!empty($update_error)): ?>
        <div class="alert alert-danger shadow-sm fw-bold text-center mx-auto" style="border-radius: 10px;">
            ❌ <?php echo $update_error; ?>
        </div>
    <?php endif; ?>

    <div class="form-card">
        <form method="POST" action="" enctype="multipart/form-data">

            <div class="section-title" style="margin-top: 0;">Barangay Identity</div>

            <div class="row mb-4">
                <div class="col-md-4 text-center border-end">
                    <label class="form-label">Official Barangay Logo</label>
                    <div class="d-flex justify-content-center">
                        <?php $logo_src = !empty($info['logo_path']) ? 'uploads/logo/' . $info['logo_path'] : 'https://via.placeholder.com/150?text=No+Logo'; ?>
                        <img id="logoPreview" src="<?php echo $logo_src; ?>" alt="Barangay Logo">
                    </div>
                    <input type="file" name="barangay_logo" class="form-control form-control-sm mt-3 mx-auto" style="max-width: 200px;" accept=".jpg,.jpeg,.png" id="logoUpload">
                    <small class="text-muted d-block mt-1">Leave blank to keep current logo</small>
                </div>

                <div class="col-md-8 ps-4">
                    <div class="mb-4">
                        <label class="form-label">Barangay Name</label>
                        <input type="text" name="barangay_name" class="form-control"
                               value="<?php echo htmlspecialchars($info['barangay_name'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" style="color: #2E7D32;">Punong Barangay (Barangay Captain)</label>
                        <input type="text" name="captain_name" class="form-control fw-bold"
                               value="<?php echo htmlspecialchars($info['captain_name'] ?? ''); ?>"
                               placeholder="e.g. Hon. Juan Dela Cruz" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" style="color: #2E7D32;">Barangay Secretary</label>
                        <input type="text" name="secretary_name" class="form-control fw-bold"
                               value="<?php echo htmlspecialchars($info['secretary_name'] ?? ''); ?>"
                               placeholder="e.g. Maria Clara" required>
                    </div>
                </div>
            </div>

            <div class="section-title">System Branding</div>

            <div class="row">
                <div class="col-md-12 mb-3">
                    <label class="form-label">System Sub-title</label>
                    <input type="text" name="system_subtitle" class="form-control"
                           value="<?php echo htmlspecialchars($info['system_subtitle'] ?? ''); ?>"
                           placeholder="e.g. Coastal Land & Waste Management System">
                </div>
                <div class="col-md-12 mb-4">
                    <label class="form-label">System Description</label>
                    <textarea name="system_description" class="form-control" rows="2"
                              placeholder="Brief description of this system's purpose..."><?php echo htmlspecialchars($info['system_description'] ?? ''); ?></textarea>
                </div>
            </div>

            <div class="section-title">Location Details</div>

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">City</label>
                    <input type="text" name="city" class="form-control"
                           value="<?php echo htmlspecialchars($info['city'] ?? 'Iloilo'); ?>"
                           placeholder="e.g. Iloilo City">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Municipality</label>
                    <input type="text" name="municipal" class="form-control"
                           value="<?php echo htmlspecialchars($info['municipal'] ?? 'Estancia'); ?>"
                           placeholder="e.g. Estancia">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Zip Code</label>
                    <input type="text" name="zip_code" class="form-control"
                           value="<?php echo htmlspecialchars($info['zip_code'] ?? ''); ?>"
                           placeholder="e.g. 5017">
                </div>
            </div>

            <div class="row mb-2">
                <div class="col-md-12 mb-3">
                    <label class="form-label">Barangay Address (Full)</label>
                    <input type="text" name="full_address" class="form-control"
                           value="<?php echo htmlspecialchars($info['full_address'] ?? ''); ?>"
                           placeholder="e.g. Barangay Hall, M.L. Quezon St...">
                </div>
                <input type="hidden" name="region" value="<?php echo htmlspecialchars($info['region'] ?? ''); ?>">
            </div>

            <div class="section-title">Contact & Hours</div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Contact Number</label>
                    <input type="text" name="contact_number" class="form-control"
                           value="<?php echo htmlspecialchars($info['contact_number'] ?? ''); ?>"
                           placeholder="Landline or Mobile">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Office Hours</label>
                    <input type="text" name="office_hours" class="form-control"
                           value="<?php echo htmlspecialchars($info['office_hours'] ?? ''); ?>"
                           placeholder="e.g. Mon–Fri, 8:00 AM – 5:00 PM">
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label">Emergency Hotlines</label>
                <textarea name="emergency_hotlines" class="form-control" rows="3"
                          placeholder="e.g. BFP: 09XX-XXX-XXXX&#10;PNP: 09XX-XXX-XXXX"><?php echo htmlspecialchars($info['emergency_hotlines'] ?? ''); ?></textarea>
                <small class="text-muted d-block mt-1">Enter each hotline on a new line.</small>
            </div>

            <button type="submit" name="update_info" class="btn btn-success w-100 mt-4 py-3 fw-bold fs-5 shadow-sm" style="background-color: #2E7D32; border: none; border-radius: 8px;" onclick="return confirm('Are you sure you want to save these changes to the System Information?');">
                Save Changes
            </button>

        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Live Logo Preview
    document.getElementById('logoUpload').addEventListener('change', function (event) {
        var reader = new FileReader();
        reader.onload = function (e) {
            document.getElementById('logoPreview').src = e.target.result;
        };
        if (event.target.files[0]) {
            reader.readAsDataURL(event.target.files[0]);
        }
    });
</script>

<script>
        // Toggle Sidebar for Mobile
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('active');
            document.getElementById('sidebar-overlay').classList.toggle('active');
        }
    </script>
</body>
</html>
