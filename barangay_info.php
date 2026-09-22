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
    $secretary   = $_POST['secretary_name']; 
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
    $pnp = $conn->real_escape_string($_POST['pnp_hotline']);
    $bfp = $conn->real_escape_string($_POST['bfp_hotline']);
    $mdrrmo = $conn->real_escape_string($_POST['mdrrmo_hotline']);
    $health = $conn->real_escape_string($_POST['health_center_hotline']);
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
        // 🚨 FIX: Added the missing comma and converted new fields to safer '?' placeholders
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
                        emergency_hotlines = ?,
                        pnp_hotline        = ?,
                        bfp_hotline        = ?,
                        mdrrmo_hotline     = ?,
                        health_center_hotline = ?
                       WHERE id = ?";
                       
        $stmt = $conn->prepare($update_sql);
        
        // 🚨 FIX: Updated bind_param to securely include the 4 new variables
        $stmt->bind_param(
            "ssssssssssssssssssi",
            $b_name, $captain, $secretary, $contact, $address, $zip, $region,
            $logo_file_name, $subtitle, $description,
            $city, $municipal, $office_hrs, $hotlines,
            $pnp, $bfp, $mdrrmo, $health, $info_id
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
    :root {
        --bg-color: #F2EFE9; 
        --card-white: #FFFFFF;
        --card-lime: #D9FA4A; 
        --text-dark: #1A1A1A;
        --text-gray: #6B7280;
    }

    body { background-color: var(--bg-color); color: var(--text-dark); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; padding: 0; }
    .main-wrapper { max-width: 1100px; margin: 0 auto; padding: 40px 20px; }

    /* 🌟 FOOLPROOF CENTERED NAV */
    .top-nav { position: relative; display: flex; justify-content: space-between; align-items: center; margin-bottom: 60px; }
    .nav-brand { display: flex; align-items: center; gap: 12px; font-size: 18px; font-weight: 500; color: var(--text-dark); text-decoration: none; z-index: 10; }
    .brand-icon { background: var(--text-dark); color: var(--card-lime); width: 36px; height: 36px; border-radius: 8px; display: flex; justify-content: center; align-items: center; font-weight: bold; }
    
    .nav-links { position: absolute; left: 50%; transform: translateX(-50%); display: flex; gap: 30px; z-index: 10; }
    .nav-links a { color: var(--text-gray); text-decoration: none; font-size: 15px; transition: 0.2s; }
    .nav-links a.active, .nav-links a:hover { color: var(--text-dark); font-weight: 500; }
    
    .notif-wrapper { position: relative; z-index: 10; }
    .btn-notif { background: var(--text-dark); color: var(--card-lime); border: none; padding: 12px 24px; border-radius: 30px; font-weight: 500; font-size: 14px; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: 0.2s; }
    .btn-notif:hover { transform: scale(0.98); opacity: 0.9; }

    /* HEADERS */
    .header-section { margin-bottom: 40px; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 20px; }
    .header-section h1 { font-size: 42px; line-height: 1.2; margin: 0 0 10px 0; font-weight: 500; letter-spacing: -1px; }
    .header-section p { font-size: 16px; color: var(--text-gray); margin: 0; }

    /* DASHBOARD BENTO GRID */
    .bento-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; margin-bottom: 24px; }
    .bento-card { background: var(--card-white); border-radius: 32px; padding: 32px; display: flex; flex-direction: column; position: relative; box-shadow: 0 4px 20px rgba(0,0,0,0.02); }
    .bento-card.lime { background: var(--card-lime); }
    .icon-box { width: 42px; height: 42px; border-radius: 12px; display: flex; justify-content: center; align-items: center; margin-bottom: 40px; font-size: 20px; }
    .icon-box.pink { background: #FCE8E8; color: #F87171; }
    .icon-box.mint { background: #E6F8F3; color: #34D399; }
    .icon-box.dark-lime { background: #C0E326; color: #4D7C0F; } 
    .bento-card h6 { font-size: 14px; font-weight: 500; color: var(--text-gray); margin: 0 0 8px 0; }
    .bento-card.lime h6 { color: var(--text-dark); }
    .bento-card h2 { font-size: 56px; font-weight: 400; margin: 0; letter-spacing: -2px; color: var(--text-dark); }

    /* CARDS & TABLES */
    .map-card { background: var(--card-white); border-radius: 32px; padding: 32px; box-shadow: 0 4px 20px rgba(0,0,0,0.02); }
    .map-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
    .map-header h3 { margin: 0; font-size: 18px; font-weight: 500; color: var(--text-dark); }
    .map-legend span { font-size: 13px; color: var(--text-gray); margin-left: 16px; }
    .map-container-inner { border-radius: 20px; overflow: hidden; height: 450px; border: 1px solid #E5E7EB; }
    
    .content-section { display: none; }
    
    /* REPORTS SECTION BENTO UI */
    .reports-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px; margin-bottom: 24px; }
    .reports-header h3 { margin: 0; font-size: 20px; font-weight: 500; color: var(--text-dark); }
    .reports-controls { display: flex; gap: 16px; align-items: center; flex-wrap: wrap; }
    .search-pill { background: transparent; border: 1px solid #E5E7EB; border-radius: 30px; padding: 10px 20px; color: var(--text-dark); font-size: 14px; width: 250px; outline: none; transition: 0.2s; }
    .search-pill:focus { border-color: var(--card-lime); }
    .btn-print-pill { background: var(--text-dark); color: var(--card-lime); border: none; padding: 10px 20px; border-radius: 30px; font-size: 14px; font-weight: 500; text-decoration: none; display: flex; align-items: center; gap: 8px; transition: 0.2s; }
    .btn-print-pill:hover { opacity: 0.8; }
    
    .bento-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    .bento-table th { text-align: left; padding: 16px 12px; color: var(--text-gray); font-size: 12px; text-transform: uppercase; letter-spacing: 1px; border-bottom: 1px solid rgba(107, 114, 128, 0.2); }
    .bento-table td { padding: 16px 12px; color: var(--text-dark); font-size: 14px; border-bottom: 1px solid rgba(107, 114, 128, 0.1); vertical-align: middle; }
    .bento-table tbody tr:hover { background: rgba(107, 114, 128, 0.05); }

    /* PILLS & BUTTONS */
    .status-pill { padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 500; display: inline-block; }
    .status-active, .status-cleaned { background: rgba(52, 211, 153, 0.1); color: #34D399; border: 1px solid rgba(52, 211, 153, 0.3); }
    .status-pending { background: rgba(248, 113, 113, 0.1); color: #F87171; border: 1px solid rgba(248, 113, 113, 0.3); }
    
    .action-link { color: var(--card-lime); text-decoration: none; font-weight: 500; }
    .action-link:hover { text-decoration: underline; }
    
    .btn-pill { background: var(--text-dark); color: var(--card-lime); border: none; padding: 12px 24px; border-radius: 30px; font-weight: 500; font-size: 14px; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; transition: 0.2s; }
    .btn-pill:hover { opacity: 0.9; transform: scale(0.98); color: var(--card-lime); }
    
    .btn-outline-pill { background: transparent; border: 1px solid #E5E7EB; color: var(--text-dark); padding: 8px 16px; border-radius: 30px; font-size: 13px; text-decoration: none; font-weight: 500; transition: 0.2s; display: inline-block; }
    .btn-outline-pill:hover { border-color: var(--text-dark); }
    .btn-outline-pill.danger { color: #F87171; border-color: #FCE8E8; background: #FCE8E8; }
    .btn-outline-pill.danger:hover { opacity: 0.8; }

    /* FORMS */
    .form-label { font-weight: 500; color: var(--text-dark); font-size: 14px; margin-bottom: 8px; display: block; }
    .form-control { border-radius: 16px; border: 1px solid #E5E7EB; padding: 14px 16px; background-color: #F9FAFB; color: var(--text-dark); outline: none; width: 100%; transition: 0.2s; box-sizing: border-box; }
    .form-control:focus { border-color: var(--text-dark); background-color: #fff; }
    .section-title { font-size: 18px; font-weight: 500; color: var(--text-dark); margin: 30px 0 20px 0; padding-bottom: 10px; border-bottom: 1px solid #E5E7EB; }

    /* TABS */
    .filter-group { display: inline-flex; background: transparent; border: 1px solid #E5E7EB; border-radius: 30px; overflow: hidden; margin-bottom: 24px; }
    .filter-btn { background: transparent; border: none; padding: 10px 24px; color: var(--text-gray); font-size: 14px; font-weight: 500; cursor: pointer; transition: 0.2s; }
    .filter-btn.active { background: var(--text-dark); color: var(--card-lime); }

    /* RESPONSIVE */
    @media (max-width: 992px) {
        .top-nav { display: flex; flex-direction: column; gap: 20px; align-items: flex-start; }
        .notif-wrapper { align-self: flex-start; }
        .bento-grid { grid-template-columns: 1fr; }
        .nav-links { position: relative; left: 0; transform: none; flex-wrap: wrap; gap: 15px; justify-self: start; }
        .header-section { flex-direction: column; align-items: flex-start; }
        .header-section h1 { font-size: 32px; }
        .bento-table { display: block; overflow-x: auto; white-space: nowrap; }
    }
</style>
</head>

<body>
<div class="main-wrapper">

    <!-- TOP NAVIGATION -->
    <div class="top-nav">
        <?php 
        $sidebar_bname = !empty($info['barangay_name']) ? 'Brgy. ' . $info['barangay_name'] : 'Barangay Tanza';
        $current_file = basename($_SERVER['PHP_SELF']);
        $current_view = isset($_GET['view']) ? $_GET['view'] : '';
        ?>
        <a href="admin_dashboard.php?view=dashboard" class="nav-brand">
            <div class="brand-icon">◻</div>
            <?php echo htmlspecialchars($sidebar_bname); ?>
        </a>
        
        <div class="nav-links">
            <a href="admin_dashboard.php?view=dashboard" class="<?php echo ($current_view == 'dashboard' || ($current_file == 'admin_dashboard.php' && empty($current_view))) ? 'active' : ''; ?>">Dashboard</a>
            <a href="admin_dashboard.php?view=reports" class="<?php echo ($current_view == 'reports') ? 'active' : ''; ?>">Reports</a>
            <a href="approve_resident.php" class="<?php echo ($current_file == 'approve_resident.php') ? 'active' : ''; ?>">Residents</a>
            <a href="barangay_info.php" class="<?php echo ($current_file == 'barangay_info.php') ? 'active' : ''; ?>">Settings</a>
            <a href="logout.php" onclick="return confirm('Log out?');">Logout</a>
        </div>

       <div class="notif-wrapper">
            <?php
            // 1. Get the last seen report ID from the browser cookie (defaults to 0)
            $last_seen_id = isset($_COOKIE['last_seen_notif_id']) ? intval($_COOKIE['last_seen_notif_id']) : 0;

            // 2. Count ONLY the new reports that have an ID higher than what was last seen
            $new_reports_check = $conn->query("SELECT COUNT(*) as c FROM waste_reports WHERE status = 'Pending' AND report_id > $last_seen_id")->fetch_assoc();
            $new_count = $new_reports_check['c'] ? $new_reports_check['c'] : 0;

            // 3. Get the absolute latest report ID so we can save it to the cookie later
            $absolute_max_check = $conn->query("SELECT MAX(report_id) as max_id FROM waste_reports WHERE status = 'Pending'")->fetch_assoc();
            $max_id_to_save = $absolute_max_check['max_id'] ? $absolute_max_check['max_id'] : 0;
            ?>
            
            <?php if ($new_count > 0): ?>
                <!-- Has new reports: Show dark prominent button -->
                <button id="adminNotifBtn" class="btn-notif" data-maxid="<?php echo $max_id_to_save; ?>" style="background: var(--text-dark, #1A1A1A); color: var(--card-lime, #D9FA4A); border: none; padding: 12px 24px; border-radius: 30px; font-weight: 500; font-size: 14px; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: 0.2s;">
                    ◧ <?php echo $new_count; ?> new <?php echo ($new_count == 1) ? 'report' : 'reports'; ?>
                </button>
            <?php else: ?>
                <!-- No new reports: Show clean "Notifications" ghost button -->
                <button id="adminNotifBtn" class="btn-notif" data-maxid="<?php echo $max_id_to_save; ?>" style="background: #F9FAFB; color: var(--text-dark); border: 1px solid #E5E7EB; padding: 12px 24px; border-radius: 30px; font-weight: 500; font-size: 14px; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: 0.2s;">
                    ◧ Notifications
                </button>
            <?php endif; ?>
            
            <div id="adminNotifBox" style="display: none; position: absolute; top: 50px; right: 0; min-width: 350px; z-index: 1050; background: #fff; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border: 1px solid #E5E7EB; overflow: hidden;">
                <div style="background: #1A1A1A; color: #fff; padding: 15px; font-weight: 600;">Needs Action</div>
                
                <div style="max-height: 350px; overflow-y: auto;">
                    <?php
                    $recent_reports = $conn->query("SELECT w.*, u.username, u.full_name FROM waste_reports w JOIN users u ON w.resident_id = u.user_id WHERE w.status = 'Pending' ORDER BY w.created_at DESC LIMIT 5");
                    
                    if ($recent_reports && $recent_reports->num_rows > 0) {
                        while($notif = $recent_reports->fetch_assoc()) {
                            $reporter = !empty($notif['full_name']) ? $notif['full_name'] : $notif['username'];
                            
                            echo "<div onmouseover='this.style.background=\"#F9FAFB\"' onmouseout='this.style.background=\"transparent\"' style='padding: 15px; border-bottom: 1px solid rgba(107,114,128,0.2); transition: 0.2s;'>";
                            echo "<strong style='color: #F87171; display: block; margin-bottom: 4px;'>🚨 New Report from " . htmlspecialchars($reporter) . "</strong>";
                            echo "<span style='font-size: 13px; color: #6B7280;'>\"" . htmlspecialchars($notif['description']) . "\"</span>";
                            echo "</div>";
                        }
                    } else {
                        echo "<div style='padding: 20px; text-align: center; color: #6B7280;'>No pending reports.</div>";
                    }
                    ?>
                </div>
            </div>
        </div>
</div>

    <!-- HEADER -->
    <div class="header-section">
        <div>
            <h1>System Information</h1>
            <p>Manage official barangay details and configuration</p>
        </div>
    </div>

    <?php if ($update_success): ?>
        <div style="background: #E6F8F3; color: #047857; padding: 16px; border-radius: 16px; text-align: center; font-weight: 500; margin-bottom: 24px;">
            ✅ Barangay Information Successfully Updated!
        </div>
    <?php elseif (!empty($update_error)): ?>
        <div style="background: #FCE8E8; color: #B91C1C; padding: 16px; border-radius: 16px; text-align: center; font-weight: 500; margin-bottom: 24px;">
            ❌ <?php echo $update_error; ?>
        </div>
    <?php endif; ?>

    <div class="map-card">
        <form method="POST" action="" enctype="multipart/form-data">

            <div class="section-title" style="margin-top: 0;">Barangay Identity</div>
            <div class="row mb-4">
                <div class="col-md-4 text-center">
                    <label class="form-label">Official Barangay Logo</label>
                    <?php $logo_src = !empty($info['logo_path']) ? 'uploads/logo/' . $info['logo_path'] : 'https://via.placeholder.com/150?text=No+Logo'; ?>
                    <img id="logoPreview" src="<?php echo $logo_src; ?>" alt="Barangay Logo" style="width: 120px; height: 120px; border-radius: 20px; object-fit: cover; margin-bottom: 15px; border: 1px solid #E5E7EB;">
                    <input type="file" name="barangay_logo" class="form-control" accept=".jpg,.jpeg,.png" id="logoUpload">
                </div>
                <div class="col-md-8">
                    <div class="mb-3">
                        <label class="form-label">Barangay Name</label>
                        <input type="text" name="barangay_name" class="form-control" value="<?php echo htmlspecialchars($info['barangay_name'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Punong Barangay (Captain)</label>
                        <input type="text" name="captain_name" class="form-control" value="<?php echo htmlspecialchars($info['captain_name'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Barangay Secretary</label>
                        <input type="text" name="secretary_name" class="form-control" value="<?php echo htmlspecialchars($info['secretary_name'] ?? ''); ?>" required>
                    </div>
                </div>
            </div>

            <div class="section-title">Location Details</div>
            <div class="row mb-4">
                <div class="col-md-4 mb-3"><label class="form-label">City</label><input type="text" name="city" class="form-control" value="<?php echo htmlspecialchars($info['city'] ?? 'Iloilo'); ?>"></div>
                <div class="col-md-4 mb-3"><label class="form-label">Municipality</label><input type="text" name="municipal" class="form-control" value="<?php echo htmlspecialchars($info['municipal'] ?? 'Estancia'); ?>"></div>
                <div class="col-md-4 mb-3"><label class="form-label">Zip Code</label><input type="text" name="zip_code" class="form-control" value="<?php echo htmlspecialchars($info['zip_code'] ?? ''); ?>"></div>
                <div class="col-md-12"><label class="form-label">Full Address</label><input type="text" name="full_address" class="form-control" value="<?php echo htmlspecialchars($info['full_address'] ?? ''); ?>"></div>
            </div>
            
            <div class="section-title">System Branding</div>
            <div class="row mb-4">
                <div class="col-md-12 mb-3"><label class="form-label">System Sub-title</label><input type="text" name="system_subtitle" class="form-control" value="<?php echo htmlspecialchars($info['system_subtitle'] ?? ''); ?>"></div>
                <div class="col-md-12"><label class="form-label">System Description</label><textarea name="system_description" class="form-control" rows="2"><?php echo htmlspecialchars($info['system_description'] ?? ''); ?></textarea></div>
            </div>

            <!-- CONTACT & EMERGENCY SECTION -->
        <div style="margin-top: 30px; margin-bottom: 20px; border-bottom: 1px solid #E5E7EB; padding-bottom: 10px;">
            <h4 style="font-size: 18px; font-weight: 600; color: var(--text-dark); margin: 0;">Contact & Emergency Hotlines</h4>
        </div>
        
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Barangay Contact Number</label>
                <input type="text" name="contact_number" class="form-control" value="<?php echo htmlspecialchars($info['contact_number'] ?? ''); ?>">
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Office Hours</label>
                <input type="text" name="office_hours" class="form-control" value="<?php echo htmlspecialchars($info['office_hours'] ?? ''); ?>" placeholder="e.g. 8:00 AM - 5:00 PM">
            </div>
        </div>

        <h5 style="font-size: 14px; font-weight: 600; margin-top: 15px; margin-bottom: 15px; color: var(--text-gray); text-transform: uppercase; letter-spacing: 1px;">Specific Agency Hotlines</h5>
        
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">🚓 Philippine National Police (PNP)</label>
                <input type="text" name="pnp_hotline" class="form-control" value="<?php echo htmlspecialchars($info['pnp_hotline'] ?? ''); ?>" placeholder="e.g. 0912 345 6789">
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">🚒 Bureau of Fire Protection (BFP)</label>
                <input type="text" name="bfp_hotline" class="form-control" value="<?php echo htmlspecialchars($info['bfp_hotline'] ?? ''); ?>" placeholder="e.g. 0998 765 4321">
            </div>
        </div>

        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">🚑 MDRRMO / Local Rescue</label>
                <input type="text" name="mdrrmo_hotline" class="form-control" value="<?php echo htmlspecialchars($info['mdrrmo_hotline'] ?? ''); ?>" placeholder="Estancia Rescue Hotline">
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">🏥 Barangay Health Center</label>
                <input type="text" name="health_center_hotline" class="form-control" value="<?php echo htmlspecialchars($info['health_center_hotline'] ?? ''); ?>" placeholder="Clinic / Midwife contact">
            </div>
        </div>

        <div class="row">
            <div class="col-md-12 mb-4">
                <label class="form-label">Other Emergency Hotlines (General Notes)</label>
                <textarea name="emergency_hotlines" class="form-control" style="height: 80px; resize: none;" placeholder="Any other important numbers..."><?php echo htmlspecialchars($info['emergency_hotlines'] ?? ''); ?></textarea>
            </div>
        </div>
            
            <input type="hidden" name="region" value="<?php echo htmlspecialchars($info['region'] ?? ''); ?>">

            <button type="submit" name="update_info" class="btn-pill w-100" style="padding: 16px; font-size: 16px; margin-top: 20px;" onclick="return confirm('Save these changes?');">Save Configuration</button>
        </form>
    </div>
</div>

<script>
    document.getElementById('logoUpload').addEventListener('change', function (event) {
        var reader = new FileReader();
        reader.onload = function (e) { document.getElementById('logoPreview').src = e.target.result; };
        if (event.target.files[0]) { reader.readAsDataURL(event.target.files[0]); }
    });

    // Toggle Admin Notification Box
    var adminNotifBtn = document.getElementById("adminNotifBtn");
    if(adminNotifBtn) {
        adminNotifBtn.onclick = function() {
            var box = document.getElementById("adminNotifBox");
            box.style.display = (box.style.display === "none" || box.style.display === "") ? "block" : "none";
        };
    }
</script>

<script>
    var adminNotifBtn = document.getElementById("adminNotifBtn");
    if(adminNotifBtn) {
        adminNotifBtn.onclick = function() {
            var box = document.getElementById("adminNotifBox");
            
            if (box.style.display === "none" || box.style.display === "") {
                box.style.display = "block";
                
                // Save the highest report ID to the cookie
                let maxId = this.getAttribute("data-maxid");
                document.cookie = "last_seen_notif_id=" + maxId + "; path=/; max-age=" + (30*24*60*60);
                
                // Instantly update the button UI
                this.innerHTML = "◧ Notifications";
                this.style.background = "#F9FAFB";
                this.style.color = "#1A1A1A";
                this.style.border = "1px solid #E5E7EB";
                
            } else {
                box.style.display = "none";
            }
        };
    }
</script>

</body>
</html>
