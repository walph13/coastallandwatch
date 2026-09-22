<?php
session_start();
include 'db_connect.php';

// SECURITY CHECK
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login.php");
    exit();
}

// BACKEND: Get current admin data for profile
$admin_id = $_SESSION['user_id'];
$admin_data = $conn->query("SELECT full_name FROM users WHERE user_id = $admin_id")->fetch_assoc();

// FETCH BARANGAY INFO FOR THE DYNAMIC SIDEBAR
$check_info = $conn->query("SELECT * FROM barangay_information LIMIT 1");
if ($check_info && $check_info->num_rows > 0) {
    $info = $check_info->fetch_assoc();
} else {
    $info = ['barangay_name' => 'Barangay System', 'logo_path' => ''];
}

// BACKEND: Handle the Basura-Alert Submission
if (isset($_POST['send_basura_alert'])) {
    $purok = $_POST['purok_area'];
    $message = $_POST['alert_message'];

    $alert_sql = "INSERT INTO basura_alerts (purok_area, admin_id, message) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($alert_sql);
    $stmt->bind_param("sis", $purok, $admin_id, $message);
    
    if ($stmt->execute()) {
        echo "<script>alert('Basura-Alert successfully broadcasted to " . $purok . "!'); window.location.href='admin_dashboard.php?view=alert';</script>";
    } else {
        echo "<script>alert('Database error: Failed to send alert.');</script>";
    }
}

// BACKEND: Fetch all data for the GIS Map
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
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Barangay Tanza GIS</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
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
                <button id="adminNotifBtn" class="btn-notif" data-maxid="<?php echo $max_id_to_save; ?>">
                    ◧ <?php echo $new_count; ?> new <?php echo ($new_count == 1) ? 'report' : 'reports'; ?>
                </button>
            <?php else: ?>
                <!-- No new reports: Show clean "Notifications" ghost button -->
                <button id="adminNotifBtn" class="btn-notif" data-maxid="<?php echo $max_id_to_save; ?>" style="background: #F9FAFB; color: var(--text-dark); border: 1px solid #E5E7EB;">
                    ◧ Notifications
                </button>
            <?php endif; ?>
            
            <!-- 🚨 RESTORED WRAPPER: This makes it float properly without breaking the nav! -->
            <div id="adminNotifBox" style="display: none; position: absolute; top: 50px; right: 0; min-width: 350px; z-index: 1050; background: var(--card-white); border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border: 1px solid #E5E7EB; overflow: hidden;">
                <div style="background: var(--text-dark); color: #fff; padding: 15px; font-weight: 600;">Needs Action</div>
                
                <div style="max-height: 350px; overflow-y: auto;">
                    <?php
                    // Fetches full_name and created_at for the popup
                    $recent_reports = $conn->query("SELECT w.*, u.username, u.full_name FROM waste_reports w JOIN users u ON w.resident_id = u.user_id WHERE w.status = 'Pending' ORDER BY w.created_at DESC LIMIT 5");
                    
                    if ($recent_reports && $recent_reports->num_rows > 0) {
                        while($notif = $recent_reports->fetch_assoc()) {
                            // Prepare clean data for the popup
                            $reporter = !empty($notif['full_name']) ? $notif['full_name'] : $notif['username'];
                            $desc = addslashes(htmlspecialchars($notif['description']));
                            $img = addslashes(htmlspecialchars($notif['before_photo_path']));
                            $date = date("M d, Y - h:i A", strtotime($notif['created_at']));
                            
                            // Clickable item with hover effects
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
    
    <!-- DASHBOARD VIEW -->
    <div id="section-dashboard" class="content-section" style="display: block;">
        
        <!-- 🚨 MOVED THE HEADER INSIDE THE DASHBOARD SECTION 🚨 -->
        <div class="header-section">
            <div>
                <h1>Keep the coastline<br>clean, together.</h1>
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

    <!-- REPORTS SECTION -->
    <div id="section-reports" class="content-section" style="display: none;">
        
        <!-- 🚨 UNIQUE HEADER FOR REPORTS TAB 🚨 -->
        <div class="header-section">
            <div>
                <h1>Official Waste Reports<br>and Cleanup Logs.</h1>
                <p>Review submitted issues, update statuses, and generate official printable records.</p>
            </div>
        </div>

        <div class="map-card">
            <!-- UPDATED 2-ROW REPORTS HEADER -->
            <div class="reports-header" style="display: flex; flex-direction: column; gap: 20px; margin-bottom: 24px; align-items: stretch;">
                
                <!-- TOP ROW: Title & Print Actions -->
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                    <h3 style="margin: 0; font-size: 20px; font-weight: 600; color: var(--text-dark);">Recent Waste Reports</h3>
                    
                    <!-- Print Controls -->
                    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                        <div style="display: flex; align-items: center; gap: 8px; background: #F9FAFB; border: 1px solid var(--border-light); border-radius: 30px; padding: 6px 16px;">
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

                <!-- BOTTOM ROW: Search & Filters -->
                <div class="reports-controls" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; width: 100%;">
                    <input type="text" id="searchInput" class="search-pill" placeholder="🔍 Search Name or Description" style="flex: 1; max-width: 350px;">
                    
                    <div class="filter-group" style="margin-bottom: 0;">
                        <button id="filter-All" class="filter-btn active" onclick="filterTable('All')">All</button>
                        <button id="filter-Pending" class="filter-btn" onclick="filterTable('Pending')">Pending</button>
                        <button id="filter-Cleaned" class="filter-btn" onclick="filterTable('Cleaned')">Cleaned</button>
                    </div>
                </div>

            </div> <!-- /End of reports-header -->
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
                        
                        if($reports_query->num_rows > 0) {
                            while($row = $reports_query->fetch_assoc()) {
                                echo "<tr class='report-row' data-status='" . $row['status'] . "'>";
                                echo "<td><strong>" . htmlspecialchars($row['full_name']) . "</strong></td>";
                                echo "<td>
        <span style='color: var(--text-gray); display: block; font-weight: 500;'>" . date("M d, Y", strtotime($row['created_at'])) . "</span>
        <span style='color: var(--text-gray); font-size: 12px; opacity: 0.7;'>" . date("h:i A", strtotime($row['created_at'])) . "</span>
      </td>";
                                echo "<td>" . htmlspecialchars($row['description']) . "</td>";
                                
                                // Map Column
                                $lat = isset($row['latitude']) ? $row['latitude'] : '0';
                                $lng = isset($row['longitude']) ? $row['longitude'] : '0';
                                echo "<td><a href='https://www.google.com/maps?q=" . $lat . "," . $lng . "' target='_blank' style='color: #3B82F6; text-decoration:none; font-weight:600;'>Map 📍</a></td>";
                                
                                // 🚨 FIX: "Before" and "After" Photo Pill Buttons
                                echo "<td><a href='uploads/reports/" . $row['before_photo_path'] . "' target='_blank' style='background: #F3F4F6; color: var(--text-dark); border: 1px solid #E5E7EB; padding: 4px 12px; border-radius: 20px; font-size: 11px; text-decoration: none; display: inline-block; margin-bottom: 6px; font-weight: 600;'>Before</a> ";
                                if ($row['status'] === 'Cleaned' && !empty($row['after_photo_path'])) {
                                    echo "<br><a href='uploads/reports/" . $row['after_photo_path'] . "' target='_blank' style='background: var(--card-lime); color: var(--text-dark); padding: 4px 12px; border-radius: 20px; font-size: 11px; text-decoration: none; display: inline-block; font-weight: 600; box-shadow: 0 2px 5px rgba(0,0,0,0.05);'>After</a>";
                                }
                                echo "</td>";
                                
                                // Status Column
                                if ($row['status'] == 'Pending') {
                                    echo "<td><span class='status-pill status-pending'>Pending</span></td>";
                                } else {
                                    echo "<td><span class='status-pill status-cleaned'>Cleaned</span></td>";
                                }
                                
                                // 🚨 FIX: High-Contrast "Resolve" Dark Pill Button
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

    <!-- ALERT SECTION -->
    <div id="section-alert" class="content-section" style="display: none;">
        <div class="map-card">
            <div class="section-title" style="margin-top: 0;">Dispatch Alert</div>
            <p style="color: var(--text-gray); font-size: 14px; margin-bottom: 25px;">Send an official broadcast to notify residents that the garbage truck is approaching their area or to share important waste management updates.</p>
            
            <form method="POST" action="">
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

</div> 

<script>
    // Tab Switching Logic
    window.onload = function() {
        var urlParams = new URLSearchParams(window.location.search);
        var viewToOpen = urlParams.get('view');
        if (viewToOpen) { switchTab(viewToOpen); } else { switchTab('dashboard'); }
    };

    function switchTab(tabName) {
        document.querySelectorAll('.content-section').forEach(function(section) {
            section.style.display = 'none';
        });
        
        var tabDash = document.getElementById('tab-dashboard');
        var tabRep = document.getElementById('tab-reports');
        if(tabDash) tabDash.classList.remove('active');
        if(tabRep) tabRep.classList.remove('active');
        
        var targetSection = document.getElementById('section-' + tabName);
        if(targetSection) targetSection.style.display = 'block';
        
        var targetTab = document.getElementById('tab-' + tabName);
        if(targetTab) targetTab.classList.add('active');

        if (tabName === 'dashboard') {
            setTimeout(function() { mapDash.invalidateSize(); }, 200);
        }
    }

    // MAP LOGIC
    var locations = <?php echo json_encode($map_data); ?>;
    
    var redPin = new L.Icon({ iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-red.png', shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png', iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34], shadowSize: [41, 41] });
    var greenPin = new L.Icon({ iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-green.png', shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png', iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34], shadowSize: [41, 41] });

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
            marker.bindTooltip(hoverCard, { direction: 'top', opacity: 1, className: 'custom-hover-card' });
        });
        return m;
    }

    var mapDash = setupMap('masterMap');

    // Filter Logic
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

    // 🚨 SMART NOTIFICATION TOGGLE & RESET
    var adminNotifBtn = document.getElementById("adminNotifBtn");
    if(adminNotifBtn) {
        adminNotifBtn.onclick = function() {
            var box = document.getElementById("adminNotifBox");
            
            // Toggle the dropdown box
            if (box.style.display === "none" || box.style.display === "") {
                box.style.display = "block";
                
                // Save the highest report ID to a browser cookie (Expires in 30 days)
                let maxId = this.getAttribute("data-maxid");
                document.cookie = "last_seen_notif_id=" + maxId + "; path=/; max-age=" + (30*24*60*60);
                
                // Instantly update the button UI to show it has been read
                this.innerHTML = "◧ Notifications";
                this.style.background = "#F9FAFB";
                this.style.color = "var(--text-dark)";
                this.style.border = "1px solid #E5E7EB";
                
            } else {
                box.style.display = "none";
            }
        };
    }
</script>

<script>




    function generatePrintReport() {
        let fromDate = document.getElementById('printFromDate').value;
        let toDate = document.getElementById('printToDate').value;
        
        let printUrl = 'print_report.php';
        
        // If both dates are filled, add them to the URL
        if (fromDate && toDate) {
            printUrl += `?from=${fromDate}&to=${toDate}`;
        } 
        // Prevent user from only filling out one date
        else if (fromDate || toDate) {
            alert("Please select both 'From' and 'To' dates to print a specific range. Leave both blank to print all records.");
            return;
        }
        
        // Open the print page in a new tab
        window.open(printUrl, '_blank');
    }

// 🚨 NEW: Notification Modal Logic
    function openNotifModal(img, name, date, desc) {
        // 1. Hide the dropdown menu so it gets out of the way
        document.getElementById("adminNotifBox").style.display = "none";
        
        // 2. Inject the data into the popup
        let imagePath = img ? 'uploads/reports/' + img : 'https://placehold.co/400x300/e0e0e0/a0a0a0?text=No+Photo';
        document.getElementById('notifModalImg').src = imagePath;
        document.getElementById('notifModalName').innerText = name;
        document.getElementById('notifModalDate').innerText = date;
        document.getElementById('notifModalDesc').innerText = desc;
        
        // 3. Show the popup (using flexbox to center it)
        document.getElementById('notifModal').style.display = 'flex';
    }

    function closeNotifModal() {
        document.getElementById('notifModal').style.display = 'none';
    }

</script>
<!-- 🚨 NEW: NOTIFICATION PREVIEW MODAL -->
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
            
            <!-- Quick action button to take you directly to the Reports table -->
            <button onclick="window.location.href='admin_dashboard.php?view=reports';" style="width:100%; background:var(--text-dark); color:var(--card-lime); border:none; padding:14px; border-radius:24px; font-weight:600; font-size:14px; margin-top:16px; cursor:pointer; transition: 0.2s;">
                View in Reports Tab
            </button>
        </div>
    </div>
</body>
</html>
