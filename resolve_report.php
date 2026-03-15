<?php
session_start();
include 'db_connect.php';

// SECURITY CHECK: Only Admin allowed
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login.php");
    exit();
}

// Get the specific report ID from the URL
if (!isset($_GET['report_id'])) {
    echo "No report selected.";
    exit();
}
$report_id = $_GET['report_id'];

// BACKEND LOGIC: Handle the "After" photo upload and status update
if (isset($_POST['resolve_task'])) {
    
    $target_dir = "uploads/reports/";
    $file_name = time() . "_after_" . basename($_FILES["photo_after"]["name"]);
    $target_file = $target_dir . $file_name;

    // SECURITY UPGRADE: Check the file extension
    $allowed_extensions = array("jpg", "jpeg", "png");
    $file_extension = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));

    if (!in_array($file_extension, $allowed_extensions)) {
        echo "<script>alert('Security Alert: Only JPG, JPEG, and PNG image files are allowed!');</script>";
    } else {
        // If the file is valid, upload and update database
        if (move_uploaded_file($_FILES["photo_after"]["tmp_name"], $target_file)) {
            
            // Using 'report_id' and 'Cleaned' based on our previous database fixes
            $sql = "UPDATE waste_reports SET after_photo_path = ?, status = 'Cleaned' WHERE report_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $file_name, $report_id); 
            
            if ($stmt->execute()) {
                echo "<script>alert('Report successfully marked as Cleaned!'); window.location.href='admin_dashboard.php';</script>";
            } else {
                echo "<script>alert('Database error.');</script>";
            }
        } else {
            echo "<script>alert('Failed to upload the photo.');</script>";
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Resolve Report - Coastal & Land Watch</title>
</head>
<body>
    <h2>Resolve Waste Report #<?php echo htmlspecialchars($report_id); ?></h2>
    <p>Upload a photo proving the area has been cleaned up.</p>
    
    <form action="" method="POST" enctype="multipart/form-data">
        <label>Upload 'After' Photo:</label><br>
        <input type="file" name="photo_after" required><br><br>
        
        <button type="submit" name="resolve_task">Submit Clean-up Proof</button>
        <br><br>
        <a href="admin_dashboard.php">Cancel and go back</a>
    </form>
</body>
</html>
