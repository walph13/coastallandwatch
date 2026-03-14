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
    
    // Handle the "After" Photo Upload
    $target_dir = "uploads/reports/";
    $file_name = time() . "_after_" . basename($_FILES["photo_after"]["name"]);
    $target_file = $target_dir . $file_name;

    if (move_uploaded_file($_FILES["photo_after"]["tmp_name"], $target_file)) {
        
        // Update the database to save the new photo and change status to Cleaned
        $sql = "UPDATE waste_reports SET after_photo_path = ?, status = 'Cleaned' WHERE report_id = ?";
        $stmt = $conn->prepare($sql);
        
        // "si" means String (the photo text) and Integer (the report ID number)
        $stmt->bind_param("si", $file_name, $report_id); 
        
        if ($stmt->execute()) {
            echo "<script>alert('Report successfully marked as Resolved!'); window.location.href='admin_dashboard.php';</script>";
        } else {
            echo "<script>alert('Database error.');</script>";
        }
    } else {
        echo "<script>alert('Failed to upload the photo.');</script>";
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
