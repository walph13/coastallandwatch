<?php
session_start();
include 'db_connect.php';

// -----------------------------------------------------------------------------
// 1. SECURITY CHECK
// -----------------------------------------------------------------------------
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login.php");
    exit();
}

$admin_id = $_SESSION['user_id'];
$admin_data = $conn->query("SELECT full_name FROM users WHERE user_id = $admin_id")->fetch_assoc();

// -----------------------------------------------------------------------------
// 2. FETCH BARANGAY INFO
// -----------------------------------------------------------------------------
$check_info = $conn->query("SELECT * FROM barangay_information LIMIT 1");
if ($check_info && $check_info->num_rows > 0) {
    $info = $check_info->fetch_assoc();
} else {
    $conn->query("INSERT INTO barangay_information (barangay_name) VALUES ('Barangay Tanza')");
    $check_info = $conn->query("SELECT * FROM barangay_information LIMIT 1");
    $info = $check_info->fetch_assoc();
}
$info_id = $info['id'];

// -----------------------------------------------------------------------------
// 3. BACKEND ACTION HANDLERS
// -----------------------------------------------------------------------------

// ACTION: Basura-Alert Submission
if (isset($_POST['send_basura_alert'])) {
    $purok = $_POST['purok_area'];
    $message = $_POST['alert_message'];

    $alert_sql = "INSERT INTO basura_alerts (purok_area, admin_id, message) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($alert_sql);
    $stmt->bind_param("sis", $purok, $admin_id, $message);
    
    if ($stmt->execute()) {
        echo "<script>alert('Basura-Alert successfully broadcasted to " . $purok . "!'); window.location.href='admin_dashboard.php?view=alert';</script>";
        exit();
    } else {
        echo "<script>alert('Database error: Failed to send alert.');</script>";
    }
}

// ACTION: Approve or Reject Residents
if (isset($_GET['resident_action']) && isset($_GET['id'])) {
    $target_id = intval($_GET['id']);
    $action = $_GET['resident_action'];
    
    if ($action === 'approve') {
        $conn->query("UPDATE users SET account_status='Approved' WHERE user_id=$target_id");
        echo "<script>alert('Resident successfully approved!'); window.location.href='admin_dashboard.php?view=residents';</script>";
        exit();
    } elseif ($action === 'reject') {
        $conn->query("UPDATE users SET account_status='Rejected' WHERE user_id=$target_id");
        echo "<script>alert('Resident application rejected.'); window.location.href='admin_dashboard.php?view=residents';</script>";
        exit();
    }
}

// ACTION: Delete Resident
if (isset($_POST['delete_resident_id'])) {
    $del_id = intval($_POST['delete_resident_id']);
    $conn->query("DELETE FROM users WHERE user_id = $del_id AND role = 'Resident'");
    echo "<script>alert('Resident account permanently deleted.'); window.location.href='admin_dashboard.php?view=residents&status=deleted';</script>";
    exit();
}

// ACTION: Update Barangay Settings
$update_success = false;
$update_error = '';

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
    $pnp         = $conn->real_escape_string($_POST['pnp_hotline']);
    $bfp         = $conn->real_escape_string($_POST['bfp_hotline']);
    $mdrrmo      = $conn->real_escape_string($_POST['mdrrmo_hotline']);
    $health      = $conn->real_escape_string($_POST['health_center_hotline']);
    $logo_file_name = isset($info['logo_path']) ? $info['logo_path'] : '';

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

// -----------------------------------------------------------------------------
// 4. DATA FETCHING FOR VIEWS
// -----------------------------------------------------------------------------

// Fetch GIS Map Data
$map_query = $conn->query("
    SELECT w.report_id, w.description, w.latitude, w.longitude, w.status, 
           w.before_photo_path, w.created_at, u.full_name 
    FROM waste_reports w 
    JOIN users u ON w.resident_id = u.user_id 
    WHERE w.latitude IS NOT NULL AND w.longitude IS NOT NULL
");
$map_data = [];
while($row = $map_query->fetch_assoc()) {
    $map_data[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Barangay GIS System</title>
    
    <!-- CSS Dependencies -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- JS Dependencies -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

    <style>
        :root {
            --bg-color: #F2EFE9; 
            --card-white: #FFFFFF;
            --card-lime: #D9FA4A; 
            --text-dark: #1A1A1A;
            --text-gray: #6B7280;
        }

        body { 
            background-color: var(--bg-color); 
            color: var(--text-dark); 
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; 
            margin: 0; 
            padding: 0; 
        }
        
        .main-wrapper { max-width: 1100px; margin: 0 auto; padding: 40px 20px; }

        /* CENTERED NAV */
        .top-nav { position: relative; display: flex; justify-content: space-between; align-items: center; margin-bottom: 60px; }
        .nav-brand { display: flex; align-items: center; gap: 12px; font-size: 18px; font-weight: 500; color: var(--text-dark); text-decoration: none; z-index: 10; }
        .brand-icon { background: var(--text-dark); color: var(--card-lime); width: 36px; height: 36px; border-radius: 8px; display: flex; justify-content: center; align-items: center; font-weight: bold; }
        
        .nav-links { position: absolute; left: 50%; transform: translateX(-50%); display: flex; gap: 30px; z-index: 10; }
        .nav-links a { color: var(--text-gray); text-decoration: none; font-size: 15px; transition: 0.2s; cursor: pointer; }
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

        /* Reserves space for the scrollbar across all views to stop layout shifting */
html {
  scrollbar-gutter: stable;
  overflow-y: scroll; /* Fallback for older browser compatibility */
}
    </style>
</head>

<body>
<div class="main-wrapper">

    <!-- TOP NAVIGATION -->
    <div class="top-nav">
        <?php $sidebar_bname = !empty($info['barangay_name']) ? 'Brgy. ' . $info['barangay_name'] : 'Barangay Tanza'; ?>
        <a href="javascript:void(0)" onclick="switchTab('dashboard')" class="nav-brand">
            <div class="brand-icon">◻</div>
            <?php echo htmlspecialchars($sidebar_bname); ?>
        </a>
        
        <div class="nav-links">
            <a id="nav-dashboard" onclick="switchTab('dashboard')">Dashboard</a>
            <a id="nav-reports" onclick="switchTab('reports')">Reports</a>
            <a id="nav-residents" onclick="switchTab('residents')">Residents</a>
            <a id="nav-alert" onclick="switchTab('alert')">Alerts</a>
            <a id="nav-settings" onclick="switchTab('settings')">Settings</a>
            <a href="logout.php" onclick="return confirm('Log out?');">Logout</a>
        </div>

        <div class="notif-wrapper">
            <?php
            $last_seen_id = isset($_COOKIE['last_seen_notif_id']) ? intval($_COOKIE['last_seen_notif_id']) : 0;
            $new_reports_check = $conn->query("SELECT COUNT(*) as c FROM waste_reports WHERE status = 'Pending' AND report_id > $last_seen_id")->fetch_assoc();
            $new_count = $new_reports_check['c'] ? $new_reports_check['c'] : 0;

            $absolute_max_check = $conn->query("SELECT MAX(report_id) as max_id FROM waste_reports WHERE status = 'Pending'")->fetch_assoc();
            $max_id_to_save = $absolute_max_check['max_id'] ? $absolute_max_check['max_id'] : 0;
            ?>
            
            <?php if ($new_count > 0): ?>
                <button id="adminNotifBtn" class="btn-notif" data-maxid="<?php echo $max_id_to_save; ?>">
                    ◧ <?php echo $new_count; ?> new <?php echo ($new_count == 1) ? 'report' : 'reports'; ?>
                </button>
            <?php else: ?>
                <button id="adminNotifBtn" class="btn-notif" data-maxid="<?php echo $max_id_to_save; ?>" style="background: #F9FAFB; color: var(--text-dark); border: 1px solid #E5E7EB;">
                    ◧ Notifications
                </button>
            <?php endif; ?>
            
            <!-- NOTIFICATION DROPDOWN -->
            <div id="adminNotifBox" style="display: none; position: absolute; top: 50px; right: 0; min-width: 350px; z-index: 1050; background: var(--card-white); border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border: 1px solid #E5E7EB; overflow: hidden;">
                <div style="background: var(--text-dark); color: #fff; padding: 15px; font-weight: 600;">Needs Action</div>
                
                <div style="max-height: 350px; overflow-y: auto;">
                    <?php
                    $recent_reports = $conn->query("SELECT w.*, u.username, u.full_name FROM waste_reports w JOIN users u ON w.resident_id = u.user_id WHERE w.status = 'Pending' ORDER BY w.created_at DESC LIMIT 5");
                    
                    if ($recent_reports && $recent_reports->num_rows > 0) {
                        while($notif = $recent_reports->fetch_assoc()) {
                            $reporter = !empty($notif['full_name']) ? $notif['full_name'] : $notif['username'];
                            $desc = addslashes(htmlspecialchars($notif['description']));
                            $img = addslashes(htmlspecialchars($notif['before_photo_path']));
                            $date = date("M d, Y - h:i A", strtotime($notif['created_at']));
                            
                            echo "<div onclick='openNotifModal(\"$img\", \"$reporter\", \"$date\", \"$desc\")' onmouseover='this.style.background=\"#F9FAFB\"' onmouseout='this.style.background=\"transparent\"' style='padding: 15px; border-bottom: 1px solid rgba(107,114,128,0.2); cursor: pointer; transition: 0.2s;'>";
                            echo "<strong style='color: #F87171; display: block; margin-bottom: 4px;'>🚨 New Report from " . htmlspecialchars($reporter) . "</strong>";
                            echo "<span style='font-size: 13px; color: var(--text-gray);'>\"" . htmlspecialchars($notif['description']) . "\"</span>";
                            echo "</div>";
                        }
                    } else {
                        echo "<div style='padding: 20px; text-align: center; color: var(--text-gray);'>No pending reports.</div>";
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- =================================================================== -->
    <!-- VIEW 1: DASHBOARD                                                   -->
    <!-- =================================================================== -->
    <div id="section-dashboard" class="content-section">
        <div class="header-section">
            <div>
                <h1>Keep the coastline clean, together.</h1>
                <?php 
                $sidebar_municipal = !empty($info['municipal']) ? $info['municipal'] : 'Estancia';
                $sidebar_city = !empty($info['city']) ? $info['city'] : 'Iloilo';
                ?>
                <p><?php echo htmlspecialchars($sidebar_municipal . ', ' . $sidebar_city); ?> — live report and cleanup tracking</p>
            </div>
        </div>

        <div class="bento-grid">
            <div class="bento-card">
                <div class="icon-box pink">📄</div>
                <h6>Pending reports</h6>
                <?php
                $pending = $conn->query("SELECT COUNT(*) as count FROM waste_reports WHERE status='Pending'")->fetch_assoc();
                echo "<h2>" . $pending['count'] . "</h2>";
                ?>
            </div>
            
            <div class="bento-card">
                <div class="icon-box mint">✓</div>
                <h6>Cleaned areas</h6>
                <?php
                $cleaned = $conn->query("SELECT COUNT(*) as count FROM waste_reports WHERE status='Cleaned'")->fetch_assoc();
                echo "<h2>" . $cleaned['count'] . "</h2>";
                ?>
            </div>
            
            <div class="bento-card lime">
                <div class="icon-box dark-lime">👤</div>
                <h6>Active residents</h6>
                <?php
                $users = $conn->query("SELECT COUNT(*) as count FROM users WHERE role='Resident'")->fetch_assoc();
                echo "<h2>" . $users['count'] . "</h2>";
                ?>
            </div>
        </div>

        <div class="map-card">
            <div class="map-header">
                <h3>Barangay GIS master map</h3>
                <div class="map-legend">
                    <span>🔴 Pending</span>
                    <span>🟢 Cleaned</span>
                </div>
            </div>
            <div class="map-container-inner">
                <div id="masterMap" style="height: 100%; width: 100%; z-index: 1;"></div>
            </div>
        </div>
    </div>

    <!-- =================================================================== -->
    <!-- VIEW 2: REPORTS                                                     -->
    <!-- =================================================================== -->
    <div id="section-reports" class="content-section">
        <div class="header-section">
            <div>
                <h1>Official Waste Reports and Cleanup Logs.</h1>
                <p>Review submitted issues, update statuses, and generate official printable records.</p>
            </div>
        </div>

        <div class="map-card">
            <div class="reports-header" style="display: flex; flex-direction: column; gap: 20px; margin-bottom: 24px; align-items: stretch;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                    <h3 style="margin: 0; font-size: 20px; font-weight: 600; color: var(--text-dark);">Recent Waste Reports</h3>
                    
                    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                        <div style="display: flex; align-items: center; gap: 8px; background: #F9FAFB; border: 1px solid #E5E7EB; border-radius: 30px; padding: 6px 16px;">
                            <span style="font-size: 13px; color: var(--text-gray); font-weight: 500;">Print Range:</span>
                            <input type="date" id="printFromDate" style="border: none; background: transparent; color: var(--text-dark); font-size: 13px; outline: none; cursor: pointer;">
                            <span style="color: var(--text-gray); font-size: 13px;">to</span>
                            <input type="date" id="printToDate" style="border: none; background: transparent; color: var(--text-dark); font-size: 13px; outline: none; cursor: pointer;">
                        </div>

                        <button onclick="generatePrintReport()" class="btn-print-pill" style="cursor: pointer; margin: 0; padding: 10px 24px;">
                            🖨️ Print
                        </button>
                    </div>
                </div>

                <div class="reports-controls" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; width: 100%;">
                    <input type="text" id="searchInput" class="search-pill" placeholder="🔍 Search Name or Description" style="flex: 1; max-width: 350px;">
                    
                    <div class="filter-group" style="margin-bottom: 0;">
                        <button id="filter-All" class="filter-btn active" onclick="filterTable('All')">All</button>
                        <button id="filter-Pending" class="filter-btn" onclick="filterTable('Pending')">Pending</button>
                        <button id="filter-Cleaned" class="filter-btn" onclick="filterTable('Cleaned')">Cleaned</button>
                    </div>
                </div>
            </div>

            <div style="overflow-x: auto;">
                <table class="bento-table">
                    <thead>
                        <tr>
                            <th>Reporter</th>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Location</th>
                            <th>Photos</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="reportsTableBody">
                        <?php
                        $reports_query = $conn->query("SELECT waste_reports.*, users.full_name FROM waste_reports JOIN users ON waste_reports.resident_id = users.user_id ORDER BY waste_reports.created_at DESC");
                        
                        if($reports_query && $reports_query->num_rows > 0) {
                            while($row = $reports_query->fetch_assoc()) {
                                echo "<tr class='report-row' data-status='" . $row['status'] . "'>";
                                echo "<td><strong>" . htmlspecialchars($row['full_name']) . "</strong></td>";
                                echo "<td>
                                        <span style='color: var(--text-gray); display: block; font-weight: 500;'>" . date("M d, Y", strtotime($row['created_at'])) . "</span>
                                        <span style='color: var(--text-gray); font-size: 12px; opacity: 0.7;'>" . date("h:i A", strtotime($row['created_at'])) . "</span>
                                      </td>";
                                echo "<td>" . htmlspecialchars($row['description']) . "</td>";
                                
                                $lat = isset($row['latitude']) ? $row['latitude'] : '0';
                                $lng = isset($row['longitude']) ? $row['longitude'] : '0';
                                echo "<td><a href='https://www.google.com/maps?q=" . $lat . "," . $lng . "' target='_blank' style='color: #3B82F6; text-decoration:none; font-weight:600;'>Map 📍</a></td>";
                                
                                echo "<td><a href='uploads/reports/" . $row['before_photo_path'] . "' target='_blank' style='background: #F3F4F6; color: var(--text-dark); border: 1px solid #E5E7EB; padding: 4px 12px; border-radius: 20px; font-size: 11px; text-decoration: none; display: inline-block; margin-bottom: 6px; font-weight: 600;'>Before</a> ";
                                if ($row['status'] === 'Cleaned' && !empty($row['after_photo_path'])) {
                                    echo "<br><a href='uploads/reports/" . $row['after_photo_path'] . "' target='_blank' style='background: var(--card-lime); color: var(--text-dark); padding: 4px 12px; border-radius: 20px; font-size: 11px; text-decoration: none; display: inline-block; font-weight: 600; box-shadow: 0 2px 5px rgba(0,0,0,0.05);'>After</a>";
                                }
                                echo "</td>";
                                
                                if ($row['status'] == 'Pending') {
                                    echo "<td><span class='status-pill status-pending'>Pending</span></td>";
                                } else {
                                    echo "<td><span class='status-pill status-cleaned'>Cleaned</span></td>";
                                }
                                
                                echo "<td>";
                                if ($row['status'] == 'Pending') {
                                    echo "<a href='resolve_report.php?id=" . $row['report_id'] . "' style='background: var(--text-dark); color: #fff; padding: 8px 16px; border-radius: 20px; font-size: 12px; text-decoration: none; font-weight: 600; display: inline-block; box-shadow: 0 4px 10px rgba(0,0,0,0.1);'>Resolve</a>";
                                } else {
                                    echo "<span style='color: var(--text-gray); font-weight: 500; font-size: 13px;'>Resolved</span>";
                                }
                                echo "</td>";
                                echo "</tr>";
                            }
                        } else {
                            echo "<tr id='no-data-row'><td colspan='7' style='text-align:center; padding: 30px; color: var(--text-gray); font-weight: 500;'>No waste reports found.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- =================================================================== -->
    <!-- VIEW 3: RESIDENTS MANAGEMENT                                        -->
    <!-- =================================================================== -->
    <div id="section-residents" class="content-section">
        <div class="header-section">
            <div>
                <h1>Resident Management</h1>
                <p>Review uploaded IDs and manage community app access</p>
            </div>
        </div>

        <div class="map-card">
            <?php if (isset($_GET['status']) && $_GET['status'] === 'deleted'): ?>
                <div style="background: #D1FAE5; color: #065F46; padding: 16px; border-radius: 16px; margin-bottom: 20px; border: 1px solid #A7F3D0; font-weight: 500;">
                    Resident account successfully deleted!
                </div>
            <?php endif; ?>

            <div class="reports-header">
                <h3>Community Residents</h3>
                <div class="reports-controls">
                    <div class="filter-group" style="margin-bottom: 0;">
                        <button id="tab-pending" class="filter-btn active" onclick="switchResTab('pending')">Pending Approvals</button>
                        <button id="tab-active" class="filter-btn" onclick="switchResTab('active')">Active Residents</button>
                    </div>
                </div>
            </div>

            <!-- SUB TAB: Pending Approvals -->
            <div id="sub-section-pending" style="display: block;">
                <div style="overflow-x: auto;">
                    <table class="bento-table">
                        <thead>
                            <tr>
                                <th>Full Name</th>
                                <th>Username</th>
                                <th>Phone Number</th>
                                <th>ID Photo</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $pending_query = $conn->query("SELECT * FROM users WHERE role='Resident' AND account_status='Pending' ORDER BY user_id DESC");
                        if ($pending_query && $pending_query->num_rows > 0) {
                            while ($row = $pending_query->fetch_assoc()) {
                                echo "<tr>";
                                echo "<td><strong>" . htmlspecialchars($row['full_name']) . "</strong></td>";
                                echo "<td><span style='color: var(--text-gray);'>" . htmlspecialchars($row['username']) . "</span></td>";
                                echo "<td><span style='color: var(--text-gray);'>" . htmlspecialchars($row['phone_number']) . "</span></td>";
                                echo "<td><a href='uploads/ids/" . $row['id_photo_path'] . "' target='_blank' style='background: #F3F4F6; color: var(--text-dark); border: 1px solid #E5E7EB; padding: 6px 14px; border-radius: 20px; font-size: 12px; text-decoration: none; font-weight: 600; display: inline-block;'>View ID</a></td>";
                                echo "<td>";
                                echo "<a href='admin_dashboard.php?resident_action=approve&id=" . $row['user_id'] . "' style='background: var(--text-dark); color: #fff; padding: 6px 16px; border-radius: 20px; font-size: 12px; text-decoration: none; font-weight: 600; display: inline-block; box-shadow: 0 4px 10px rgba(0,0,0,0.1); margin-right: 6px;'>Approve</a>";
                                echo "<a href='admin_dashboard.php?resident_action=reject&id=" . $row['user_id'] . "' onclick=\"return confirm('Are you sure you want to reject and delete this user?');\" style='background: #FEE2E2; color: #DC2626; padding: 6px 16px; border-radius: 20px; font-size: 12px; text-decoration: none; font-weight: 600; display: inline-block; border: 1px solid #FECACA;'>Reject</a>";
                                echo "</td>";
                                echo "</tr>";
                            }
                        } else {
                            echo "<tr><td colspan='5' style='text-align: center; padding: 30px; color: var(--text-gray);'>No pending approvals at the moment.</td></tr>";
                        }
                        ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- SUB TAB: Active Residents -->
            <div id="sub-section-active" style="display: none;">
                <div style="overflow-x: auto;">
                    <table class="bento-table">
                        <thead>
                            <tr>
                                <th>Full Name</th>
                                <th>Username</th>
                                <th>Phone Number</th>
                                <th>Status</th>
                                <th>Action</th> 
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $active_query = $conn->query("SELECT * FROM users WHERE role='Resident' AND account_status='Approved' ORDER BY full_name ASC");
                        if ($active_query && $active_query->num_rows > 0) {
                            while ($row = $active_query->fetch_assoc()) {
                                echo "<tr>";
                                echo "<td><strong>" . htmlspecialchars($row['full_name']) . "</strong></td>";
                                echo "<td><span style='color: var(--text-gray);'>" . htmlspecialchars($row['username']) . "</span></td>";
                                echo "<td><span style='color: var(--text-gray);'>" . htmlspecialchars($row['phone_number']) . "</span></td>";
                                echo "<td><span class='status-pill status-active'>Active</span></td>";
                                echo "<td>";
                                echo "<form action='admin_dashboard.php' method='POST' onsubmit=\"return confirm('Are you sure you want to permanently delete " . htmlspecialchars($row['full_name']) . " from the system?');\" style='margin:0;'>";
                                echo "<input type='hidden' name='delete_resident_id' value='" . $row['user_id'] . "'>";
                                echo "<button type='submit' class='btn-outline-pill danger' style='cursor: pointer; padding: 6px 14px; font-size: 12px;'>Delete</button>";
                                echo "</form>";
                                echo "</td>";
                                echo "</tr>";
                            }
                        } else {
                            echo "<tr><td colspan='5' style='text-align: center; padding: 30px; color: var(--text-gray);'>No active residents found.</td></tr>";
                        }
                        ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- =================================================================== -->
    <!-- VIEW 4: DISPATCH BASURA ALERT                                       -->
    <!-- =================================================================== -->
    <div id="section-alert" class="content-section">
        <div class="header-section">
            <div>
                <h1>Basura-Alert Broadcast</h1>
                <p>Send real-time alerts to residents across all barangay puroks</p>
            </div>
        </div>

        <div class="map-card">
            <div class="section-title" style="margin-top: 0;">Dispatch Alert</div>
            <p style="color: var(--text-gray); font-size: 14px; margin-bottom: 25px;">Send an official broadcast to notify residents that the garbage truck is approaching their area or to share important waste management updates.</p>
            
            <form method="POST" action="admin_dashboard.php">
                <div class="row">
                    <div class="col-md-12 mb-4">
                        <label class="form-label">Select Target Area</label>
                        <select name="purok_area" class="form-control" required>
                            <option value="Purok Uno">Purok Uno</option>
                            <option value="Purok Dos">Purok Dos</option>
                            <option value="Purok Tres">Purok Tres</option>
                            <option value="All Areas" selected>All Areas (Barangay-wide)</option>
                        </select>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-12 mb-4">
                        <label class="form-label">Broadcast Message</label>
                        <textarea name="alert_message" class="form-control" required style="height: 120px; resize: none;">The garbage truck is currently near your area! Please prepare and bring out your segregated trash.</textarea>
                    </div>
                </div>

                <button type="submit" name="send_basura_alert" class="btn-pill w-100" style="padding: 16px; font-size: 16px;">
                    Broadcast Alert Now 🚚
                </button>
            </form>
        </div>
    </div>

    <!-- =================================================================== -->
    <!-- VIEW 5: SYSTEM SETTINGS                                             -->
    <!-- =================================================================== -->
    <div id="section-settings" class="content-section">
        <div class="header-section">
            <div>
                <h1>System Information</h1>
                <p>Manage official barangay details and configuration</p>
            </div>
        </div>

        <?php if ($update_success): ?>
            <div id="settingsStatusMsg" style="background: #E6F8F3; color: #047857; padding: 16px; border-radius: 16px; text-align: center; font-weight: 500; margin-bottom: 24px;">
                ✅ Barangay Information Successfully Updated!
            </div>
        <?php elseif (!empty($update_error)): ?>
            <div id="settingsStatusMsg" style="background: #FCE8E8; color: #B91C1C; padding: 16px; border-radius: 16px; text-align: center; font-weight: 500; margin-bottom: 24px;">
                ❌ <?php echo $update_error; ?>
            </div>
        <?php endif; ?>

        <div class="map-card">
            <form method="POST" action="admin_dashboard.php?view=settings" enctype="multipart/form-data">
                <div class="section-title" style="margin-top: 0;">Barangay Details</div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Barangay Name</label>
                        <input type="text" name="barangay_name" class="form-control" value="<?php echo htmlspecialchars(isset($info['barangay_name']) ? $info['barangay_name'] : ''); ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Barangay Captain</label>
                        <input type="text" name="captain_name" class="form-control" value="<?php echo htmlspecialchars(isset($info['captain_name']) ? $info['captain_name'] : ''); ?>">
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Barangay Secretary</label>
                        <input type="text" name="secretary_name" class="form-control" value="<?php echo htmlspecialchars(isset($info['secretary_name']) ? $info['secretary_name'] : ''); ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Contact Number</label>
                        <input type="text" name="contact_number" class="form-control" value="<?php echo htmlspecialchars(isset($info['contact_number']) ? $info['contact_number'] : ''); ?>">
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Municipality / Town</label>
                        <input type="text" name="municipal" class="form-control" value="<?php echo htmlspecialchars(isset($info['municipal']) ? $info['municipal'] : ''); ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Province / City</label>
                        <input type="text" name="city" class="form-control" value="<?php echo htmlspecialchars(isset($info['city']) ? $info['city'] : ''); ?>">
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-8 mb-3">
                        <label class="form-label">Full Address</label>
                        <input type="text" name="full_address" class="form-control" value="<?php echo htmlspecialchars(isset($info['full_address']) ? $info['full_address'] : ''); ?>">
                    </div>
                    <div class="col-md-2 mb-3">
                        <label class="form-label">ZIP Code</label>
                        <input type="text" name="zip_code" class="form-control" value="<?php echo htmlspecialchars(isset($info['zip_code']) ? $info['zip_code'] : ''); ?>">
                    </div>
                    <div class="col-md-2 mb-3">
                        <label class="form-label">Region</label>
                        <input type="text" name="region" class="form-control" value="<?php echo htmlspecialchars(isset($info['region']) ? $info['region'] : ''); ?>">
                    </div>
                </div>

                <div class="section-title">System & Operational Information</div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">System Subtitle</label>
                        <input type="text" name="system_subtitle" class="form-control" value="<?php echo htmlspecialchars(isset($info['system_subtitle']) ? $info['system_subtitle'] : ''); ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Office Hours</label>
                        <input type="text" name="office_hours" class="form-control" value="<?php echo htmlspecialchars(isset($info['office_hours']) ? $info['office_hours'] : ''); ?>">
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-12 mb-3">
                        <label class="form-label">System Description</label>
                        <textarea name="system_description" class="form-control" style="height: 90px; resize: none;"><?php echo htmlspecialchars(isset($info['system_description']) ? $info['system_description'] : ''); ?></textarea>
                    </div>
                </div>

                <div class="section-title">Emergency Hotlines</div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">PNP Station Hotline</label>
                        <input type="text" name="pnp_hotline" class="form-control" value="<?php echo htmlspecialchars(isset($info['pnp_hotline']) ? $info['pnp_hotline'] : ''); ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">BFP Fire Station Hotline</label>
                        <input type="text" name="bfp_hotline" class="form-control" value="<?php echo htmlspecialchars(isset($info['bfp_hotline']) ? $info['bfp_hotline'] : ''); ?>">
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">MDRRMO / Disaster Hotline</label>
                        <input type="text" name="mdrrmo_hotline" class="form-control" value="<?php echo htmlspecialchars(isset($info['mdrrmo_hotline']) ? $info['mdrrmo_hotline'] : ''); ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Rural Health Unit / Clinic Hotline</label>
                        <input type="text" name="health_center_hotline" class="form-control" value="<?php echo htmlspecialchars(isset($info['health_center_hotline']) ? $info['health_center_hotline'] : ''); ?>">
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-12 mb-4">
                        <label class="form-label">General Hotline / Remarks</label>
                        <input type="text" name="emergency_hotlines" class="form-control" value="<?php echo htmlspecialchars(isset($info['emergency_hotlines']) ? $info['emergency_hotlines'] : ''); ?>">
                    </div>
                </div>

                <div class="section-title">Barangay Branding</div>
                <div class="row">
                    <div class="col-md-12 mb-4">
                        <label class="form-label">Barangay Logo (PNG / JPG)</label>
                        <input type="file" name="barangay_logo" class="form-control">
                        <?php if(!empty($info['logo_path'])): ?>
                            <div style="margin-top: 10px;">
                                <span style="font-size: 13px; color: var(--text-gray);">Current Logo:</span>
                                <img src="uploads/logo/<?php echo htmlspecialchars($info['logo_path']); ?>" alt="Barangay Logo" style="height: 40px; margin-left: 10px; border-radius: 6px;">
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <button type="submit" name="update_info" class="btn-pill w-100" style="padding: 16px; font-size: 16px;">
                    Save System Settings 💾
                </button>
            </form>
        </div>
    </div>

</div>

<!-- =================================================================== -->
<!-- NOTIFICATION PREVIEW MODAL                                          -->
<!-- =================================================================== -->
<div id="notifModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); backdrop-filter:blur(5px); z-index:2000; align-items:center; justify-content:center; padding:20px;">
    <div style="background:var(--card-white); width:100%; max-width:420px; border-radius:28px; padding:24px; position:relative; box-shadow:0 10px 40px rgba(0,0,0,0.15);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 16px;">
            <h3 style="margin:0; font-size:18px; font-weight:600; color:var(--text-dark);">Report Quick Preview</h3>
            <button onclick="closeNotifModal()" style="background:#F3F4F6; border:none; border-radius:50%; width:36px; height:36px; cursor:pointer; color:var(--text-dark); display:flex; align-items:center; justify-content:center; font-size: 16px;">✖</button>
        </div>
        
        <img id="notifModalImg" src="" style="width:100%; height:200px; object-fit:cover; border-radius:16px; margin-bottom:16px; border:1px solid #E5E7EB; background: #F9FAFB;">
        
        <div style="background:#F9FAFB; padding:16px; border-radius:16px; border:1px solid #E5E7EB;">
            <p style="margin:0 0 10px; font-size:14px;"><strong style="color:var(--text-dark);">Reporter:</strong> <span id="notifModalName" style="color:var(--text-gray); float:right; font-weight:500;"></span></p>
            <p style="margin:0 0 10px; font-size:14px;"><strong style="color:var(--text-dark);">Date:</strong> <span id="notifModalDate" style="color:var(--text-gray); float:right; font-weight:500;"></span></p>
            <hr style="border:none; border-top:1px solid #E5E7EB; margin:12px 0;">
            <p style="margin:0 0 6px; font-size:14px; font-weight:600; color:var(--text-dark);">Description:</p>
            <p id="notifModalDesc" style="margin:0; font-size:14px; color:var(--text-gray); line-height:1.5;"></p>
        </div>
        
        <button onclick="switchTab('reports'); closeNotifModal();" style="width:100%; background:var(--text-dark); color:var(--card-lime); border:none; padding:14px; border-radius:24px; font-weight:600; font-size:14px; margin-top:16px; cursor:pointer; transition: 0.2s;">
            View in Reports Tab
        </button>
    </div>
</div>

<!-- =================================================================== -->
<!-- JAVASCRIPT SYSTEM LOGIC                                             -->
<!-- =================================================================== -->
<script>
    var mapDash = null;

    window.onload = function() {
        var urlParams = new URLSearchParams(window.location.search);
        var viewToOpen = urlParams.get('view');
        if (viewToOpen) { 
            switchTab(viewToOpen); 
        } else { 
            switchTab('dashboard'); 
        }
    };

    // Tab Controller
    function switchTab(tabName) {
        // Dismiss the settings success/error banner for good once the admin
        // leaves the Settings tab, so it doesn't reappear if they come back.
        if (tabName !== 'settings') {
            var statusMsg = document.getElementById('settingsStatusMsg');
            if (statusMsg) {
                statusMsg.style.display = 'none';
            }
        }

        document.querySelectorAll('.content-section').forEach(function(section) {
            section.style.display = 'none';
        });

        document.querySelectorAll('.nav-links a').forEach(function(link) {
            link.classList.remove('active');
        });

        var targetSection = document.getElementById('section-' + tabName);
        if (targetSection) {
            targetSection.style.display = 'block';
        }

        var targetNav = document.getElementById('nav-' + tabName);
        if (targetNav) {
            targetNav.classList.add('active');
        }

        if (tabName === 'dashboard') {
            if (!mapDash) {
                mapDash = setupMap('masterMap');
            } else {
                setTimeout(function() { mapDash.invalidateSize(); }, 200);
            }
        }
    }

    // Leaflet GIS Map Initialization
    var locations = <?php echo json_encode($map_data); ?>;
    
    var redPin = new L.Icon({ 
        iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-red.png', 
        shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png', 
        iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34], shadowSize: [41, 41] 
    });
    
    var greenPin = new L.Icon({ 
        iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-green.png', 
        shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png', 
        iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34], shadowSize: [41, 41] 
    });

    function setupMap(mapId) {
        var m = L.map(mapId).setView([11.45, 123.15], 13);
        var streetView = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 });
        var satelliteView = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19 });
        
        streetView.addTo(m);
        L.control.layers({"🗺️ Street View": streetView, "🛰️ Satellite View": satelliteView}).addTo(m);

        locations.forEach(function(loc) {
            var currentIcon = (loc.status === 'Pending') ? redPin : greenPin; 
            var marker = L.marker([loc.latitude, loc.longitude], {icon: currentIcon}).addTo(m);
            var dateObj = new Date(loc.created_at);
            var dateStr = dateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

            var hoverCard = `
                <div style="text-align:center; min-width: 160px; padding: 5px;">
                    <img src="uploads/reports/${loc.before_photo_path}" style="width: 100%; height: 100px; object-fit: cover; border-radius: 5px; margin-bottom: 8px; border: 1px solid #ccc;">
                    <div style="font-size: 14px; color: ${(loc.status === 'Pending') ? '#F87171' : '#34D399'}; font-weight: bold; margin-bottom: 5px;">
                        ${(loc.status === 'Pending') ? '🔴 Pending Report' : '✅ Cleaned Area'}
                    </div>
                    <div style="font-size: 12px; text-align: left; line-height: 1.4;">
                        <b>Reporter:</b> ${loc.full_name}<br>
                        <b>Date:</b> ${dateStr}<br>
                        <b>Desc:</b> <span style="color:#555;">"${loc.description}"</span>
                    </div>
                </div>
            `;
            marker.bindTooltip(hoverCard, { direction: 'top', opacity: 1 });
        });
        return m;
    }

    // Reports Table Search & Filter
    let currentStatusFilter = 'All';
    let searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keyup', applyFilters);
    }

    function filterTable(status) {
        currentStatusFilter = status;
        document.getElementById('filter-All').classList.remove('active');
        document.getElementById('filter-Pending').classList.remove('active');
        document.getElementById('filter-Cleaned').classList.remove('active');
        document.getElementById('filter-' + status).classList.add('active');
        applyFilters();
    }

    function applyFilters() {
        let searchText = document.getElementById('searchInput').value.toLowerCase();
        let rows = document.querySelectorAll('.report-row');
        
        rows.forEach(row => {
            let rowText = row.innerText.toLowerCase();
            let rowStatus = row.getAttribute('data-status');
            let matchesSearch = rowText.includes(searchText);
            let matchesStatus = (currentStatusFilter === 'All') || (rowStatus === currentStatusFilter);
            
            if (matchesSearch && matchesStatus) {
                row.style.display = ''; 
            } else {
                row.style.display = 'none'; 
            }
        });
    }

    // Resident Sub-Tabs Toggle
    function switchResTab(tabName) {
        document.getElementById('sub-section-pending').style.display = 'none';
        document.getElementById('sub-section-active').style.display = 'none';
        
        document.getElementById('tab-pending').classList.remove('active');
        document.getElementById('tab-active').classList.remove('active');
        
        document.getElementById('sub-section-' + tabName).style.display = 'block';
        document.getElementById('tab-' + tabName).classList.add('active');
    }

    // Notification Dropdown Toggle
    var adminNotifBtn = document.getElementById("adminNotifBtn");
    if(adminNotifBtn) {
        adminNotifBtn.onclick = function() {
            var box = document.getElementById("adminNotifBox");
            if (box.style.display === "none" || box.style.display === "") {
                box.style.display = "block";
                
                let maxId = this.getAttribute("data-maxid");
                document.cookie = "last_seen_notif_id=" + maxId + "; path=/; max-age=" + (30*24*60*60);
                
                this.innerHTML = "◧ Notifications";
                this.style.background = "#F9FAFB";
                this.style.color = "var(--text-dark)";
                this.style.border = "1px solid #E5E7EB";
            } else {
                box.style.display = "none";
            }
        };
    }

    // Notification Modal Logic
    function openNotifModal(img, name, date, desc) {
        document.getElementById("adminNotifBox").style.display = "none";
        
        let imagePath = img ? 'uploads/reports/' + img : 'https://via.placeholder.com/400x300?text=No+Photo';
        document.getElementById('notifModalImg').src = imagePath;
        document.getElementById('notifModalName').innerText = name;
        document.getElementById('notifModalDate').innerText = date;
        document.getElementById('notifModalDesc').innerText = desc;
        
        document.getElementById('notifModal').style.display = 'flex';
    }

    function closeNotifModal() {
        document.getElementById('notifModal').style.display = 'none';
    }

    // Print Report Generation
    function generatePrintReport() {
        let fromDate = document.getElementById('printFromDate').value;
        let toDate = document.getElementById('printToDate').value;
        let printUrl = 'print_report.php';
        
        if (fromDate && toDate) {
            printUrl += `?from=${fromDate}&to=${toDate}`;
        } else if (fromDate || toDate) {
            alert("Please select both 'From' and 'To' dates to print a specific range. Leave both blank to print all records.");
            return;
        }
        
        window.open(printUrl, '_blank');
    }
</script>
</body>
</html>
