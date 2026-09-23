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
    :root {
        --bg-color: #F2EFE9; 
        --card-white: #FFFFFF;
        --card-lime: #D9FA4A; 
        --text-dark: #1A1A1A;
        --text-gray: #6B7280;
    }

    body { background-color: var(--bg-color); color: var(--text-dark); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; padding: 0; }
    .main-wrapper { max-width: 1100px; margin: 0 auto; padding: 40px 20px; }

    /* TOP NAVIGATION */
    .top-nav { display: flex; justify-content: space-between; align-items: center; margin-bottom: 60px; }
    .nav-brand { display: flex; align-items: center; gap: 12px; font-size: 18px; font-weight: 500; color: var(--text-dark); text-decoration: none; }
    .brand-icon { background: var(--text-dark); color: var(--card-lime); width: 36px; height: 36px; border-radius: 8px; display: flex; justify-content: center; align-items: center; font-weight: bold; }
    .nav-links { display: flex; gap: 30px; }
    .nav-links a { color: var(--text-gray); text-decoration: none; font-size: 15px; transition: 0.2s; }
    .nav-links a.active, .nav-links a:hover { color: var(--text-dark); font-weight: 500; }

    /* HEADERS */
    .header-section { margin-bottom: 40px; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 20px; }
    .header-section h1 { font-size: 42px; line-height: 1.2; margin: 0 0 10px 0; font-weight: 500; letter-spacing: -1px; }
    .header-section p { font-size: 16px; color: var(--text-gray); margin: 0; }

    /* CARDS & TABLES */
    .map-card, .bento-card { background: var(--card-white); border-radius: 32px; padding: 32px; box-shadow: 0 4px 20px rgba(0,0,0,0.02); }
    .content-section { display: none; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 14px; }
    th, td { padding: 16px 12px; border-bottom: 1px solid #E5E7EB; text-align: left; }
    th { color: var(--text-gray); font-weight: 500; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; }
    
    /* PILLS & BUTTONS */
    .status-pill { padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 500; display: inline-block; }
    .status-active { background: #E6F8F3; color: #34D399; }
    .status-pending { background: #FCE8E8; color: #F87171; }
    
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
        .top-nav { flex-direction: column; gap: 20px; align-items: flex-start; }
        .nav-links { flex-wrap: wrap; gap: 15px; }
        .header-section { flex-direction: column; align-items: flex-start; }
        .header-section h1 { font-size: 32px; }
        table { display: block; overflow-x: auto; white-space: nowrap; }
    }
</style>
</head>
<body>
<div class="main-wrapper">

    <!-- TOP NAVIGATION -->
    <div class="top-nav">
        <a href="admin_dashboard.php?view=dashboard" class="nav-brand">
            <div class="brand-icon">◻</div> Brgy. Tanza
        </a>
        <div class="nav-links">
            <a href="admin_dashboard.php?view=dashboard">Dashboard</a>
            <a href="admin_dashboard.php?view=reports" class="active">Reports</a>
            <a href="approve_resident.php">Residents</a>
            <a href="barangay_info.php">Settings</a>
            <a href="logout.php" onclick="return confirm('Log out?');">Logout</a>
        </div>
    </div>

    <!-- HEADER -->
    <div class="header-section">
        <div>
            <h1>Resolve Report #<?php echo $report['report_id']; ?></h1>
            <p>Review details and attach proof of cleanup</p>
        </div>
        <a href="admin_dashboard.php?view=reports" class="btn-outline-pill">← Back to Reports</a>
    </div>

    <div class="row">
        <!-- BEFORE PHOTO CARD -->
        <div class="col-md-6 mb-4">
            <div class="bento-card h-100">
                <h4 style="margin: 0 0 20px 0; font-weight: 500;">Original Report</h4>
                <img src="uploads/reports/<?php echo $report['before_photo_path']; ?>" style="height: 300px; width: 100%; object-fit: cover; border-radius: 20px; border: 1px solid #E5E7EB; margin-bottom: 20px;" alt="Before Photo">
                
                <p style="font-size: 14px; margin-bottom: 8px;"><strong style="color: var(--text-dark);">Date Reported:</strong> <span style="color: var(--text-gray);"><?php echo date("M d, Y h:i A", strtotime($report['created_at'])); ?></span></p>
                <p style="font-size: 14px; margin-bottom: 20px;"><strong style="color: var(--text-dark);">Description:</strong> <span style="color: var(--text-gray);"><?php echo htmlspecialchars($report['description']); ?></span></p>
                
                <a href="https://www.google.com/maps?q=<?php echo $report['latitude'].','.$report['longitude']; ?>" target="_blank" class="btn-outline-pill w-100 text-center" style="padding: 12px; color: #3B82F6;">📍 View Exact Location</a>
            </div>
        </div>

        <!-- ACTION REQUIRED CARD -->
        <div class="col-md-6 mb-4">
            <div class="bento-card h-100" style="background: #FAFAFA; border: 1px dashed #E5E7EB;">
                <h4 style="margin: 0 0 15px 0; font-weight: 500;">Action Required</h4>
                <p style="color: var(--text-gray); font-size: 14px; margin-bottom: 30px; line-height: 1.5;">To close this report, upload a photo showing that the area has been successfully cleaned by the barangay personnel.</p>
                
                <form method="POST" action="resolve_report.php?id=<?php echo $report_id; ?>" enctype="multipart/form-data" onsubmit="return confirm('Mark this report as cleaned?');">
                    <input type="hidden" name="report_id" value="<?php echo $report_id; ?>">
                    
                    <div class="mb-4">
                        <label class="form-label">Upload 'After' Photo</label>
                        <input type="file" name="photo_after" class="form-control" accept=".jpg,.jpeg,.png" required style="padding: 12px; background: #fff;">
                    </div>

                    <button type="submit" name="mark_resolved" class="btn-pill w-100" style="padding: 16px; font-size: 16px;">
                        Mark as Cleaned
                    </button>
                </form>
            </div>
        </div>
    </div>

</div>
</body>
</html>
