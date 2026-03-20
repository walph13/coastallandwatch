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

// 1. HANDLE THE FORM SUBMISSION (When Admin uploads the 'After' photo)
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
            // Update database: Change status to Cleaned and save the new photo
            $sql = "UPDATE waste_reports SET status='Cleaned', after_photo_path=? WHERE report_id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $file_name, $r_id);
            
            if ($stmt->execute()) {
                // Redirect back to the reports tab on the dashboard!
                echo "<script>alert('Report successfully marked as Cleaned!'); window.location.href='admin_dashboard.php?view=reports';</script>";
            } else {
                echo "<script>alert('Database error.');</script>";
            }
        }
    }
}

// 2. FETCH THE REPORT DATA (To show the Admin what they are resolving)
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
    // If they somehow get here without an ID, send them back
    header("Location: admin_dashboard.php?view=reports");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resolve Report - Barangay Tanza</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <style>
        /* BASE STYLES & SIDEBAR */
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; display: flex; height: 100vh; background-color: #f4f7f6; }
        #sidebar { width: 260px; background-color: #343a40; color: #fff; display: flex; flex-direction: column; padding-top: 20px; box-shadow: 2px 0px 10px rgba(0,0,0,0.1); position: fixed; height: 100%; z-index: 1000; }
        #profile-header { text-align: center; padding-bottom: 20px; border-bottom: 1px solid #4b545c; margin-bottom: 20px; }
        #profile-pic { width: 80px; height: 80px; border-radius: 50%; border: 3px solid #fff; object-fit: cover; margin-bottom: 10px; }
        #admin-name { font-weight: bold; font-size: 16px; }
        #nav-menu a { color: #c2c7d0; text-decoration: none; padding: 12px 20px; display: block; font-size: 15px; transition: 0.3s; border-radius: 4px; margin: 0 10px 5px 10px; cursor: pointer; }
        #nav-menu a:hover, #nav-menu a.active { color: #fff; background-color: #28a745; font-weight: bold; }
        #nav-menu #logout-link { color: #dc3545; margin-top: auto; margin-bottom: 20px; }
        #main-content { margin-left: 260px; flex: 1; padding: 30px; overflow-y: auto; }
        #dashboard-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; border-bottom: 2px solid #ddd; padding-bottom: 10px; }
    </style>
</head>
<body>

    <div id="sidebar">
        <div id="profile-header">
            <img src="uploads/default_profile.png" id="profile-pic" alt="Admin Profile" onerror="this.src='https://cdn-icons-png.flaticon.com/512/149/149071.png'">
            <div id="admin-name"><?php echo htmlspecialchars($admin_data['full_name']); ?></div>
            <div style="font-size:12px; color:#aaa; margin-top:3px;">Barangay Secretary</div>
        </div>

        <div id="nav-menu">
            <a href="admin_dashboard.php?view=dashboard">📊 Main Dashboard</a>
            <a href="admin_dashboard.php?view=reports" class="active">🗑️ Waste Reports</a>
            <a href="admin_dashboard.php?view=map">📍 GIS Master Map</a>
            <a href="approve_resident.php">👥 Approve Residents</a>
            <a href="print_report.php" target="_blank">🖨️ Print Monthly Report</a>
            <a href="barangay_info.php">ℹ️ System Information</a>
            <a href="logout.php" id="logout-link">🚪 Logout</a>
        </div>
    </div>

    <div id="main-content">
        <div id="dashboard-header">
            <h2 style="margin:0;">✅ Resolve Waste Report #<?php echo $report['report_id']; ?></h2>
            <a href="admin_dashboard.php?view=reports" class="btn btn-outline-secondary btn-sm">← Back to Reports</a>
        </div>

        <div class="row">
            <div class="col-md-6 mb-4">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-danger text-white fw-bold">Original Report (Before)</div>
                    <div class="card-body">
                        <img src="uploads/reports/<?php echo $report['before_photo_path']; ?>" class="img-fluid rounded mb-3" style="max-height: 250px; width: 100%; object-fit: cover;" alt="Before Photo">
                        <p><strong>Date Reported:</strong> <?php echo date("M d, Y h:i A", strtotime($report['created_at'])); ?></p>
                        <p><strong>Description:</strong> <?php echo htmlspecialchars($report['description']); ?></p>
                        <a href="https://www.google.com/maps?q=<?php echo $report['latitude'].','.$report['longitude']; ?>" target="_blank" class="btn btn-primary btn-sm">📍 View Exact Location on Map</a>
                    </div>
                </div>
            </div>

            <div class="col-md-6 mb-4">
                <div class="card shadow-sm h-100 border-success">
                    <div class="card-header bg-success text-white fw-bold">Action Required: Upload Proof of Cleanup</div>
                    <div class="card-body bg-light">
                        <p class="text-muted small">To close this report, upload a photo showing that the area has been successfully cleaned by the barangay personnel.</p>
                        
                        <form method="POST" action="resolve_report.php?id=<?php echo $report_id; ?>" enctype="multipart/form-data">
                            
                            <input type="hidden" name="report_id" value="<?php echo $report_id; ?>">
                            
                            <div class="mb-4">
                                <label class="form-label fw-bold">Upload 'After' Photo:</label>
                                <input type="file" name="photo_after" class="form-control" accept=".jpg,.jpeg,.png" required>
                            </div>

                            <button type="submit" name="mark_resolved" class="btn btn-success w-100 fw-bold py-2 shadow-sm">✅ Mark as Cleaned & Notify Resident</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

    </div>

</body>
</html>
