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
    <title>Manage Residents - Barangay Tanza</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        /* BASE STYLES & GREEN THEME */
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; display: flex; height: 100vh; background-color: #F5F5F5; }
        
        /* SIDEBAR: PRIMARY DARK GREEN (#2E7D32) */
        #sidebar { width: 260px; background-color: #2E7D32; color: #fff; display: flex; flex-direction: column; padding-top: 20px; box-shadow: 4px 0px 15px rgba(0,0,0,0.15); position: fixed; height: 100%; z-index: 1000; }
        #profile-header { text-align: center; padding-bottom: 20px; border-bottom: 1px solid #4CAF50; margin-bottom: 20px; }
        #profile-pic { width: 80px; height: 80px; border-radius: 50%; border: 3px solid #fff; object-fit: cover; margin-bottom: 10px; background-color: #fff; padding: 2px; }
        #admin-name { font-weight: bold; font-size: 18px; margin-bottom: 2px; }
        
        #nav-menu a { color: #e8f5e9; text-decoration: none; padding: 12px 20px; display: block; font-size: 15px; transition: 0.3s; border-radius: 4px; margin: 0 10px 5px 10px; cursor: pointer; }
        #nav-menu a:hover, #nav-menu a.active { color: #fff; background-color: #4CAF50; font-weight: bold; box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
        #nav-menu #logout-link { color: #ffcdd2; margin-top: auto; margin-bottom: 20px; }
        #nav-menu #logout-link:hover { background-color: #d32f2f; color: #fff; }

        #main-content { margin-left: 260px; flex: 1; padding: 30px; overflow-y: auto; }
        #dashboard-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 2px solid #ddd; padding-bottom: 10px; }
        #dashboard-header h2 { color: #2E7D32; font-weight: bold; }

        /* TAB MENU STYLES FOR THIS PAGE */
        .tab-menu { display: flex; gap: 10px; margin-bottom: 20px; }
        .tab-btn { background-color: #e9ecef; border: none; padding: 10px 20px; font-size: 15px; font-weight: bold; color: #555; border-radius: 5px; cursor: pointer; transition: 0.2s; }
        .tab-btn:hover { background-color: #d3d9df; }
        
        /* ACTIVE TAB: DARK GREEN */
        .tab-btn.active { background-color: #2E7D32; color: white; box-shadow: 0px 4px 6px rgba(0,0,0,0.1); }
        
        /* CONTENT CARDS: MATCHING THE GREEN TOP BORDER */
        .content-section { display: none; background: #fff; padding: 20px; border-radius: 8px; border: none; box-shadow: 0 4px 10px rgba(0,0,0,0.05); border-top: 4px solid #4CAF50; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 12px; border: 1px solid #ddd; text-align: left; }
        th { background-color: #f8f9fa; color: #333; }
        
        .btn-sm { padding: 6px 12px; border-radius: 4px; text-decoration: none; font-size: 13px; font-weight: bold; display: inline-block; margin-right: 5px;}
        .btn-approve { background-color: #2E7D32; color: white; }
        .btn-reject { background-color: #dc3545; color: white; }
        .btn-view { background-color: #17a2b8; color: white; }

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
            // Pulls the logo and name from the database!
            $sidebar_logo = !empty($info['logo_path']) ? 'uploads/logo/' . $info['logo_path'] : 'uploads/default_profile.png';
            $sidebar_bname = !empty($info['barangay_name']) ? 'Brgy. ' . $info['barangay_name'] : 'Barangay System';
            ?>
            <img src="<?php echo $sidebar_logo; ?>" id="profile-pic" alt="Admin Profile" onerror="this.src='https://cdn-icons-png.flaticon.com/512/149/149071.png'">
            <div id="admin-name"><?php echo htmlspecialchars($sidebar_bname); ?></div>
            <div style="font-size:11px; color:#aaa;">Admin: <?php echo htmlspecialchars($admin_data['full_name']); ?></div>
        </div>

        <div id="nav-menu">
            <a href="admin_dashboard.php?view=dashboard">📊 Main Dashboard</a>
            <a href="admin_dashboard.php?view=reports">🗑️ Waste Reports</a>
            <a href="admin_dashboard.php?view=map">📍 GIS Master Map</a>
            
            <a href="approve_resident.php" class="active">👥 Approve Residents</a>
            <a href="print_report.php" target="_blank">🖨️ Print Monthly Report</a>
            <a href="barangay_info.php">ℹ️ System Information</a>
            <a href="logout.php" id="logout-link">🚪 Logout</a>
        </div>
    </div>

    <div id="main-content">
        <div id="dashboard-header">
            <h2 style="margin:0;">👥 Resident Management</h2>
            <div style="font-size:14px; color:#777;">Review and manage app access</div>
        </div>

        <div class="tab-menu">
            <button id="tab-pending" class="tab-btn active" onclick="switchResTab('pending')">⏳ Pending Approvals</button>
            <button id="tab-active" class="tab-btn" onclick="switchResTab('active')">✅ Active Residents</button>
        </div>

        <div id="section-pending" class="content-section" style="display: block;">
            <h3 style="margin-top: 0; color: #333;">Residents Awaiting Approval</h3>
            <p style="color: #666; font-size: 14px;">Please review the uploaded IDs before granting access to the system.</p>
            
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
                        echo "<td><strong>" . htmlspecialchars($row['full_name']) . "</strong></td>";
                        echo "<td>" . htmlspecialchars($row['username']) . "</td>";
                        echo "<td>" . htmlspecialchars($row['phone_number']) . "</td>";
                        echo "<td><a href='uploads/ids/" . htmlspecialchars($row['id_photo_path']) . "' target='_blank' class='btn-sm btn-view'>🖼️ View ID</a></td>";
                        echo "<td>
                                <a href='approve_resident.php?action=approve&id=" . $row['user_id'] . "' class='btn-sm btn-approve' onclick=\"return confirm('Approve this resident?');\">Approve</a>
                                <a href='approve_resident.php?action=reject&id=" . $row['user_id'] . "' class='btn-sm btn-reject' onclick=\"return confirm('Reject this resident?');\">Reject</a>
                              </td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='5' style='text-align: center; padding: 20px; color: #777;'>No pending approvals at the moment.</td></tr>";
                }
                ?>
            </table>
        </div>

        <div id="section-active" class="content-section">
            <h3 style="margin-top: 0; color: #333;">Currently Active Residents</h3>
            <p style="color: #666; font-size: 14px;">These residents have full access to submit waste reports and receive alerts.</p>
            
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
                        echo "<td><strong>" . htmlspecialchars($row['full_name']) . "</strong></td>";
                        echo "<td>" . htmlspecialchars($row['username']) . "</td>";
                        echo "<td>" . htmlspecialchars($row['phone_number']) . "</td>";
                        echo "<td><span style='color: #2E7D32; font-weight: bold;'>Approved</span></td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='4' style='text-align: center; padding: 20px; color: #777;'>No active residents found.</td></tr>";
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
</body>
</html>
