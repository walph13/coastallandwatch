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

// FETCH BARANGAY INFO FOR THE DYNAMIC SIDEBAR
$check_info = $conn->query("SELECT * FROM barangay_information LIMIT 1");
if ($check_info && $check_info->num_rows > 0) {
    $info = $check_info->fetch_assoc();
} else {
    $info = ['barangay_name' => 'Barangay System', 'logo_path' => ''];
}

// 1. HANDLE THE FORM SUBMISSION
if (isset($_POST['mark_resolved'])) {
    $r_id = $_POST['report_id'];
    
    // Set up upload directory
    $target_dir = "uploads/reports/";
    $file_name = time() . "_after_" . basename($_FILES["photo_after"]["name"]);
    $target_file = $target_dir . $file_name;

    $allowed_extensions = array("jpg", "jpeg", "png");
    $file_extension = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));

    if (!in_array($file_extension, $allowed_extensions)) {
        echo "<script>alert('Only JPG, JPEG, and PNG files are allowed.');</script>";
    } else {
        if (move_uploaded_file($_FILES["photo_after"]["tmp_name"], $target_file)) {
            $sql = "UPDATE waste_reports SET status='Cleaned', after_photo_path=? WHERE report_id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $file_name, $r_id);
            
            if ($stmt->execute()) {
                echo "<script>alert('Report successfully marked as Cleaned!'); window.location.href='admin_dashboard.php?view=reports';</script>";
            } else {
                echo "<script>alert('Database error.');</script>";
            }
        }
    }
}

// 2. FETCH THE REPORT DATA
if (isset($_GET['id'])) {
    $report_id = $_GET['id'];
    $query = $conn->query("SELECT * FROM waste_reports WHERE report_id = $report_id");
    
    if ($query->num_rows > 0) {
        $report = $query->fetch_assoc();
    } else {
        echo "<script>alert('Report not found!'); window.location.href='admin_dashboard.php?view=reports';</script>";
        exit();
    }
} else {
    header("Location: admin_dashboard.php?view=reports");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resolve Report - Barangay Tanza GIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <style>
        /* YOUR EXACT DASHBOARD CSS */
        body { font-family: 'Segoe UI', Arial, sans-serif; background-color: #ECEFF1; display: flex; height: 100vh; margin: 0; }

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

        #main-content { margin-left: 260px; flex: 1; padding: 40px; overflow-y: auto; }
        .page-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #CFD8DC; padding-bottom: 15px; margin-bottom: 25px; }
        .page-title { margin:0; font-weight: 800; color: #263238; font-size: 24px; }

        .dashboard-card { background: #fff; padding: 40px; border-radius: 16px; box-shadow: 0 8px 24px rgba(0,0,0,0.04); border: 1px solid rgba(0,0,0,0.05); margin-bottom: 25px; width: 100%; }

        .form-label { font-weight: 600; color: #546E7A; font-size: 13px; margin-bottom: 6px; display: block; }
        .form-control { border-radius: 8px; border: 1px solid #CFD8DC; padding: 10px 14px; background-color: #F8FDFF; color: #37474F; transition: 0.2s; outline: none; width: 100%; }
        .form-control:focus { border-color: #81C784; box-shadow: 0 0 0 0.2rem rgba(76, 175, 80, 0.15); }

        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-thumb { background: #90A4AE; border-radius: 4px; }
        ::-webkit-scrollbar-track { background: #ECEFF1; }

        @media (max-width: 768px) {
            #sidebar { left: -260px; position: fixed; z-index: 1000; }
            #sidebar.active { left: 0; box-shadow: 5px 0 20px rgba(0,0,0,0.5); }
            #main-content { margin-left: 0 !important; padding: 15px !important; width: 100%; overflow-x: hidden; }
            .page-header { flex-direction: column; gap: 15px; align-items: flex-start !important; }
            .dashboard-card { padding: 20px; }
        }
    </style>
</head>
<body>

    <div id="sidebar-overlay" onclick="toggleSidebar()" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:999;"></div>

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
            <a href="admin_dashboard.php?view=reports" class="active">🗑️ Reports</a>
            <a href="approve_resident.php">👥 Residents</a>
            <a href="barangay_info.php">ℹ️ System Info</a>
            <a href="logout.php" id="logout-link" onclick="return confirm('Are you sure you want to log out?');">🚪 Logout</a>
        </div>
    </div>

    <div id="main-content">
        
        <div class="d-md-none mb-4 rounded shadow-sm" style="background:#1B5E20; padding:12px 20px; display:flex; justify-content:space-between; align-items:center;">
            <h5 class="m-0 fw-bold text-white">⚙️ Admin Menu</h5>
            <button onclick="toggleSidebar()" style="background:none; border:none; color:white; font-size:28px; padding:0; cursor:pointer;">☰</button>
        </div>

        <div class="page-header">
            <div>
                <h2 class="page-title">✅ Resolve Report #<?php echo $report['report_id']; ?></h2>
                <p class="text-muted fw-bold mb-0" style="color: #78909C !important;">Review details and attach proof of cleanup</p>
            </div>
            <div>
                <a href="admin_dashboard.php?view=reports" class="btn fw-bold" style="background-color: #ECEFF1; color: #546E7A; border: 1px solid #CFD8DC; border-radius: 8px; padding: 10px 20px;">← Back to Reports</a>
            </div>
        </div>

        <div class="row">
            
            <div class="col-md-6 mb-4">
    <div class="dashboard-card h-100" style="padding: 25px; border-top: 5px solid #43A047;">
        <h4 style="color: #43A047; font-weight: 800; margin-top: 0; margin-bottom: 20px;">Original Report (Before)</h4>
                    
                    <img src="uploads/reports/<?php echo $report['before_photo_path']; ?>" class="img-fluid rounded mb-3 shadow-sm" style="height: 300px; width: 100%; object-fit: cover; border: 1px solid #CFD8DC;" alt="Before Photo">
                    
                    <p style="font-size: 14px; color: #546E7A;"><strong style="color: #263238;">Date Reported:</strong> <?php echo date("M d, Y h:i A", strtotime($report['created_at'])); ?></p>
                    <p style="font-size: 14px; color: #546E7A;"><strong style="color: #263238;">Description:</strong> <?php echo htmlspecialchars($report['description']); ?></p>
                    
                    <a href="https://www.google.com/maps?q=<?php echo $report['latitude'].','.$report['longitude']; ?>" target="_blank" class="btn fw-bold mt-2" style="background-color: #E3F2FD; color: #1E88E5; border: 1px solid #BBDEFB; border-radius: 8px;">📍 View Exact Location on Map</a>
                </div>
            </div>

            <div class="col-md-6 mb-4">
                <div class="dashboard-card h-100" style="padding: 25px; border-top: 5px solid #43A047;">
                    <h4 style="color: #43A047; font-weight: 800; margin-top: 0; margin-bottom: 15px;">Action Required</h4>
                    
                    <p style="color: #546E7A; font-size: 14px; margin-bottom: 25px;">To close this report, upload a photo showing that the area has been successfully cleaned by the barangay personnel.</p>
                    
                    <form method="POST" action="resolve_report.php?id=<?php echo $report_id; ?>" enctype="multipart/form-data" onsubmit="return confirm('Are you sure you want to mark this report as cleaned?');">
                        <input type="hidden" name="report_id" value="<?php echo $report_id; ?>">
                        
                        <div class="mb-4">
                            <label class="form-label">Upload 'After' Photo:</label>
                            <input type="file" name="photo_after" class="form-control" accept=".jpg,.jpeg,.png" required style="padding: 12px;">
                        </div>

                        <button type="submit" name="mark_resolved" class="btn w-100 py-3 mt-4 shadow-sm" style="background-color: #2E7D32; color: #fff; border: none; border-radius: 8px; font-weight: 700; font-size: 16px; transition: 0.2s;">
                            Mark as Cleaned
                        </button>
                    </form>
                </div>
            </div>

        </div>
    </div>

    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('active');
            var overlay = document.getElementById('sidebar-overlay');
            if (overlay.style.display === 'none' || overlay.style.display === '') {
                overlay.style.display = 'block';
            } else {
                overlay.style.display = 'none';
            }
        }
    </script>

</body>
</html>
