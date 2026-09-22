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

// FETCH BARANGAY INFO FOR THE DYNAMIC SIDEBAR
$check_info = $conn->query("SELECT * FROM barangay_information LIMIT 1");
$info = $check_info->fetch_assoc();

// BACKEND: Handle Approve or Reject Actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $target_id = intval($_GET['id']);
    $action = $_GET['action'];
    
    if ($action === 'approve') {
        $conn->query("UPDATE users SET account_status='Approved' WHERE user_id=$target_id");
        echo "<script>alert('Resident successfully approved!'); window.location.href='approve_resident.php';</script>";
    } elseif ($action === 'reject') {
        $conn->query("UPDATE users SET account_status='Rejected' WHERE user_id=$target_id");
        echo "<script>alert('Resident application rejected.'); window.location.href='approve_resident.php';</script>";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Residents - Barangay Tanza</title>

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

    /* 🌟 1. FOOLPROOF CENTERED NAV */
    .top-nav { position: relative; display: flex; justify-content: space-between; align-items: center; margin-bottom: 60px; }
    .nav-brand { display: flex; align-items: center; gap: 12px; font-size: 18px; font-weight: 500; color: var(--text-dark); text-decoration: none; z-index: 10; }
    .brand-icon { background: var(--text-dark); color: var(--card-lime); width: 36px; height: 36px; border-radius: 8px; display: flex; justify-content: center; align-items: center; font-weight: bold; }
    
    /* This mathematically locks the links dead center */
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

    /* CARDS & TABLES */
    .map-card, .bento-card { background: var(--card-white); border-radius: 32px; padding: 32px; box-shadow: 0 4px 20px rgba(0,0,0,0.02); }
    .content-section { display: none; }
    
    /* 🚨 BENTO TABLE (This aligns your tables perfectly!) */
    .bento-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    .bento-table th { text-align: left; padding: 16px 12px; color: var(--text-gray); font-size: 12px; text-transform: uppercase; letter-spacing: 1px; border-bottom: 1px solid rgba(107, 114, 128, 0.2); }
    .bento-table td { padding: 16px 12px; color: var(--text-dark); font-size: 14px; border-bottom: 1px solid rgba(107, 114, 128, 0.1); vertical-align: middle; }
    .bento-table tbody tr:hover { background: rgba(107, 114, 128, 0.05); }
    
    /* PILLS & BUTTONS */
    .status-pill { padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 500; display: inline-block; }
    .status-active, .status-cleaned { background: rgba(52, 211, 153, 0.1); color: #34D399; border: 1px solid rgba(52, 211, 153, 0.3); }
    .status-pending { background: rgba(248, 113, 113, 0.1); color: #F87171; border: 1px solid rgba(248, 113, 113, 0.3); }
    
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
        /* Resets the absolute centering for mobile screens */
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
        // Auto-detect the current page to set the active link
        $current_file = basename($_SERVER['PHP_SELF']);
        $current_view = isset($_GET['view']) ? $_GET['view'] : '';
        ?>
        <a href="admin_dashboard.php?view=dashboard" class="nav-brand">
            <div class="brand-icon">◻</div>
            <?php echo htmlspecialchars($sidebar_bname); ?>
        </a>
        
        <!-- Center Links -->
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
            <h1>Resident Management</h1>
            <p>Review uploaded IDs and manage community app access</p>
        </div>
    </div>

    <!-- WRAP EVERYTHING IN ONE CARD TO MATCH REPORTS DASHBOARD -->
    <div class="map-card">
        
    <?php if (isset($_GET['status']) && $_GET['status'] === 'deleted'): ?>
            <div style="background: #D1FAE5; color: #065F46; padding: 16px; border-radius: 16px; margin-bottom: 20px; border: 1px solid #A7F3D0; font-weight: 500;">
                Resident account successfully deleted!
            </div>
        <?php endif; ?>
        <!-- CARD HEADER WITH INTEGRATED TABS -->
        <div class="reports-header">
            <h3>Community Residents</h3>
            
            <div class="reports-controls">
                <div class="filter-group" style="margin-bottom: 0;">
                    <button id="tab-pending" class="filter-btn active" onclick="switchResTab('pending')">Pending Approvals</button>
                    <button id="tab-active" class="filter-btn" onclick="switchResTab('active')">Active Residents</button>
                </div>
            </div>
        </div>

        <!-- PENDING SECTION -->
        <div id="section-pending" class="content-section" style="display: block;">
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
                    if ($pending_query->num_rows > 0) {
                        while ($row = $pending_query->fetch_assoc()) {
                            echo "<tr>";
                            echo "<td><strong>" . htmlspecialchars($row['full_name']) . "</strong></td>";
                            echo "<td><span style='color: var(--text-gray);'>" . htmlspecialchars($row['username']) . "</span></td>";
                            echo "<td><span style='color: var(--text-gray);'>" . htmlspecialchars($row['phone_number']) . "</span></td>";
                            // 🚨 UPDATED: High-Contrast Action Buttons
// 🚨 ID PHOTO COLUMN (Column 4)
                            echo "<td>";
                            echo "<a href='uploads/ids/" . $row['id_photo_path'] . "' target='_blank' style='background: #F3F4F6; color: var(--text-dark); border: 1px solid #E5E7EB; padding: 6px 14px; border-radius: 20px; font-size: 12px; text-decoration: none; font-weight: 600; display: inline-block;'>View ID</a>";
                            echo "</td>";

                            // 🚨 ACTION COLUMN (Column 5)
                            echo "<td>";
                            echo "<a href='approve_resident.php?action=approve&id=" . $row['user_id'] . "' style='background: var(--text-dark); color: #fff; padding: 6px 16px; border-radius: 20px; font-size: 12px; text-decoration: none; font-weight: 600; display: inline-block; box-shadow: 0 4px 10px rgba(0,0,0,0.1); margin-right: 6px;'>Approve</a>";
                            
                            echo "<a href='approve_resident.php?action=reject&id=" . $row['user_id'] . "' onclick=\"return confirm('Are you sure you want to reject and delete this user?');\" style='background: #FEE2E2; color: #DC2626; padding: 6px 16px; border-radius: 20px; font-size: 12px; text-decoration: none; font-weight: 600; display: inline-block; border: 1px solid #FECACA;'>Reject</a>";
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

       <!-- ACTIVE SECTION -->
        <div id="section-active" class="content-section" style="display: none;">
            <div style="overflow-x: auto;">
                <table class="bento-table">
                    <thead>
                        <tr>
                            <th>Full Name</th>
                            <th>Username</th>
                            <th>Phone Number</th>
                            <th>Status</th>
                            <!-- ADDED: Action Header -->
                            <th>Action</th> 
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $active_query = $conn->query("SELECT * FROM users WHERE role='Resident' AND account_status='Approved' ORDER BY full_name ASC");
                    if ($active_query->num_rows > 0) {
                        while ($row = $active_query->fetch_assoc()) {
                            echo "<tr>";
                            echo "<td><strong>" . htmlspecialchars($row['full_name']) . "</strong></td>";
                            echo "<td><span style='color: var(--text-gray);'>" . htmlspecialchars($row['username']) . "</span></td>";
                            echo "<td><span style='color: var(--text-gray);'>" . htmlspecialchars($row['phone_number']) . "</span></td>";
                            echo "<td><span class='status-pill status-active'>Active</span></td>";
                            
                            // ADDED: Delete Button Form
                            echo "<td>";
                            echo "<form action='delete_resident.php' method='POST' onsubmit=\"return confirm('Are you sure you want to permanently delete " . htmlspecialchars($row['full_name']) . " from the system?');\" style='margin:0;'>";
                            echo "<input type='hidden' name='user_id' value='" . $row['user_id'] . "'>";
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

<script>
    function switchResTab(tabName) {
        document.getElementById('section-pending').style.display = 'none';
        document.getElementById('section-active').style.display = 'none';
        
        document.getElementById('tab-pending').classList.remove('active');
        document.getElementById('tab-active').classList.remove('active');
        
        document.getElementById('section-' + tabName).style.display = 'block';
        document.getElementById('tab-' + tabName).classList.add('active');
    }
</script>

<script>
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
