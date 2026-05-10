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
        /* PAGE BACKGROUND (#ECEFF1) */
        body { font-family: 'Segoe UI', Arial, sans-serif; background-color: #ECEFF1; display: flex; height: 100vh; margin: 0; }

        /* DARKER SIDEBAR (#1B5E20) */
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
        .page-header { margin-bottom: 25px; }
        .page-title { margin:0; font-weight: 800; color: #263238; font-size: 24px; }

        /* SLEEK TABS */
        .tab-menu { display: flex; gap: 12px; margin-bottom: 25px; }
        .tab-btn { background-color: #CFD8DC; border: none; padding: 10px 24px; font-size: 14px; font-weight: 700; color: #455A64; border-radius: 8px; cursor: pointer; transition: 0.2s; }
        .tab-btn:hover { background-color: #B0BEC5; }
        .tab-btn.active { background-color: #2E7D32; color: white; box-shadow: 0px 4px 10px rgba(46,125,50,0.2); }

        /* CONTENT CARDS */
        .content-section { display: none; background: #fff; padding: 30px; border-radius: 16px; box-shadow: 0 8px 24px rgba(0,0,0,0.04); border: 1px solid rgba(0,0,0,0.05); }
        
        /* MODERN TABLES */
        table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 14px; }
        th, td { padding: 16px 12px; border-bottom: 1px solid #ECEFF1; text-align: left; vertical-align: middle; }
        th { background-color: #F8FDFF; color: #546E7A; font-weight: 700; text-transform: uppercase; font-size: 12px; letter-spacing: 0.5px; border-bottom: 2px solid #CFD8DC; }
        tr:hover { background-color: #F5F7F8; }

        /* MODERN BUTTONS */
        .btn-sm { padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 12px; font-weight: 700; display: inline-block; margin-right: 5px; transition: 0.2s; }
        .btn-approve { background-color: #E8F5E9; color: #2E7D32; border: 1px solid #C8E6C9; }
        .btn-approve:hover { background-color: #2E7D32; color: white; }
        .btn-reject { background-color: #FFEBEE; color: #C62828; border: 1px solid #FFCDD2; }
        .btn-reject:hover { background-color: #C62828; color: white; }
        .btn-view { background-color: #E3F2FD; color: #0277BD; border: 1px solid #BBDEFB; }
        .btn-view:hover { background-color: #0277BD; color: white; }

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
            $sidebar_logo = !empty($info['logo_path']) ? 'uploads/logo/' . $info['logo_path'] : 'uploads/default_profile.png';
            $sidebar_bname = !empty($info['barangay_name']) ? 'Brgy. ' . $info['barangay_name'] : 'Barangay System';
            $sidebar_municipal = !empty($info['municipal']) ? $info['municipal'] : 'Estancia';
            $sidebar_city = !empty($info['city']) ? $info['city'] : 'Iloilo';
            ?>
            <img src="<?php echo $sidebar_logo; ?>" id="profile-pic" alt="Admin Profile" onerror="this.src='https://cdn-icons-png.flaticon.com/512/149/149071.png'">
            <div id="admin-name"><?php echo htmlspecialchars($sidebar_bname); ?></div>
            <div id="admin-location"><?php echo htmlspecialchars($sidebar_municipal . ', ' . $sidebar_city); ?></div>
        </div>

        <div class="sidebar-menu-title">Menu</div>
        <div id="nav-menu">
            <a href="admin_dashboard.php?view=dashboard">📊 Dashboard</a>
            <a href="admin_dashboard.php?view=reports">🗑️ Reports</a>
            <a href="admin_dashboard.php?view=alert">📢 Basura Alert</a>
            
            <a href="approve_resident.php" class="active">👥 Residents</a>
            <a href="barangay_info.php">ℹ️ System Info</a>
            <a href="logout.php" id="logout-link">🚪 Logout</a>
        </div>
    </div>

    <div id="main-content">
        <div class="d-md-none mb-4 shadow-sm" style="background:#1B5E20; padding:15px 20px; display:flex; justify-content:space-between; align-items:center; border-radius: 8px;">
            <h5 class="m-0 fw-bold text-white">⚙️ Admin Menu</h5>
            <button onclick="toggleSidebar()" style="background:none; border:none; color:white; font-size:28px; padding:0; cursor:pointer;">☰</button>
        </div>
        <div class="page-header">
            <h2 class="page-title">Resident Management</h2>
            <span class="text-muted small" style="color: #78909C !important; font-weight: 500;">Review and manage app access</span>
        </div>

        <div class="tab-menu">
            <button id="tab-pending" class="tab-btn active" onclick="switchResTab('pending')">Pending Approvals</button>
            <button id="tab-active" class="tab-btn" onclick="switchResTab('active')">Active Residents</button>
        </div>

        <div id="section-pending" class="content-section" style="display: block;">
            <h3 style="margin-top: 0; color: #1B5E20; font-weight: 800; font-size: 18px;">Residents Awaiting Approval</h3>
            <p style="color: #546E7A; font-size: 14px;">Please review the uploaded IDs before granting access to the system.</p>
            
            <table>
                <tr>
                    <th>Full Name</th>
                    <th>Username</th>
                    <th>Phone Number</th>
                    <th>ID Photo</th>
                    <th>Action</th>
                </tr>
                <?php
                $pending_query = $conn->query("SELECT * FROM users WHERE role='Resident' AND account_status='Pending' ORDER BY user_id DESC");
                if ($pending_query->num_rows > 0) {
                    while ($row = $pending_query->fetch_assoc()) {
                        echo "<tr>";
                        echo "<td><strong style='color: #263238;'>" . htmlspecialchars($row['full_name']) . "</strong></td>";
                        echo "<td style='color: #455A64;'>" . htmlspecialchars($row['username']) . "</td>";
                        echo "<td style='color: #455A64;'>" . htmlspecialchars($row['phone_number']) . "</td>";
                        echo "<td><a href='uploads/ids/" . htmlspecialchars($row['id_photo_path']) . "' target='_blank' class='btn-sm btn-view'>🖼️ View ID</a></td>";
                        echo "<td>
                                <a href='approve_resident.php?action=approve&id=" . $row['user_id'] . "' class='btn-sm btn-approve' onclick=\"return confirm('Approve this resident?');\">Approve</a>
                                <a href='approve_resident.php?action=reject&id=" . $row['user_id'] . "' class='btn-sm btn-reject' onclick=\"return confirm('Reject this resident?');\">Reject</a>
                              </td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='5' style='text-align: center; padding: 30px; color: #90A4AE; font-weight: 500;'>No pending approvals at the moment.</td></tr>";
                }
                ?>
            </table>
        </div>

        <div id="section-active" class="content-section">
            <h3 style="margin-top: 0; color: #1B5E20; font-weight: 800; font-size: 18px;">Currently Active Residents</h3>
            <p style="color: #546E7A; font-size: 14px;">These residents have full access to submit waste reports and receive alerts.</p>
            
            <table>
                <tr>
                    <th>Full Name</th>
                    <th>Username</th>
                    <th>Phone Number</th>
                    <th>Status</th>
                </tr>
                <?php
                $active_query = $conn->query("SELECT * FROM users WHERE role='Resident' AND account_status='Approved' ORDER BY full_name ASC");
                if ($active_query->num_rows > 0) {
                    while ($row = $active_query->fetch_assoc()) {
                        echo "<tr>";
                        echo "<td><strong style='color: #263238;'>" . htmlspecialchars($row['full_name']) . "</strong></td>";
                        echo "<td style='color: #455A64;'>" . htmlspecialchars($row['username']) . "</td>";
                        echo "<td style='color: #455A64;'>" . htmlspecialchars($row['phone_number']) . "</td>";
                        echo "<td><span style='background-color: #E8F5E9; color: #2E7D32; padding: 6px 12px; border-radius: 6px; font-weight: 700; font-size: 12px; border: 1px solid #C8E6C9;'>Active</span></td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='4' style='text-align: center; padding: 30px; color: #90A4AE; font-weight: 500;'>No active residents found.</td></tr>";
                }
                ?>
            </table>
        </div>

    </div>

    <script>
        function switchResTab(tabName) {
            // Hide all sections
            document.getElementById('section-pending').style.display = 'none';
            document.getElementById('section-active').style.display = 'none';
            
            // Remove 'active' color from all tab buttons
            document.getElementById('tab-pending').classList.remove('active');
            document.getElementById('tab-active').classList.remove('active');
            
            // Show the selected section and highlight the button
            document.getElementById('section-' + tabName).style.display = 'block';
            document.getElementById('tab-' + tabName).classList.add('active');
        }
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
