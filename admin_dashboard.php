<?php
session_start();
include 'db_connect.php';

// SECURITY CHECK: Kick out anyone who is NOT an Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login.php");
    exit();
}

// BACKEND LOGIC: Handle the "Approve Resident" action
if (isset($_GET['approve_id'])) {
    $id_to_approve = $_GET['approve_id'];
    $update_sql = "UPDATE users SET account_status = 'Active' WHERE user_id = ?";
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param("i", $id_to_approve);
    if ($stmt->execute()) {
        echo "<script>alert('Resident Approved Successfully!'); window.location.href='admin_dashboard.php';</script>";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard - Barangay Coastal & Land Watch</title>
</head>
<body>
    <h2>Welcome, Barangay Secretary!</h2>
    <a href="logout.php">Logout</a>
    <hr>

    <?php
    // Query 1: Count Pending Reports
    $pending_query = $conn->query("SELECT COUNT(*) as total FROM waste_reports WHERE status = 'Pending'");
    $pending_count = $pending_query->fetch_assoc()['total'];

    // Query 2: Count Cleaned Reports (Using your exact database vocabulary!)
    $cleaned_query = $conn->query("SELECT COUNT(*) as total FROM waste_reports WHERE status = 'Cleaned'");
    $cleaned_count = $cleaned_query->fetch_assoc()['total'];

    // Query 3: Count Active Residents
    // Query 3: Count All Residents
    $resident_query = $conn->query("SELECT COUNT(*) as total FROM users WHERE role = 'Resident'");  
    $resident_count = $resident_query->fetch_assoc()['total'];
    ?>

    <h3>Barangay Tanza System Overview</h3>
    <div style="display: flex; gap: 20px; margin-bottom: 20px;">
        <div style="border: 1px solid #ccc; padding: 15px; text-align: center; background-color: #f9f9f9; width: 200px; border-radius: 8px;">
            <h4 style="margin: 0; color: #555;">🗑️ Pending Reports</h4>
            <h1 style="color: #d9534f; margin: 10px 0 0 0; font-size: 40px;"><?php echo $pending_count; ?></h1>
        </div>
        
        <div style="border: 1px solid #ccc; padding: 15px; text-align: center; background-color: #f9f9f9; width: 200px; border-radius: 8px;">
            <h4 style="margin: 0; color: #555;">✅ Cleaned Areas</h4>
            <h1 style="color: #5cb85c; margin: 10px 0 0 0; font-size: 40px;"><?php echo $cleaned_count; ?></h1>
        </div>

        <div style="border: 1px solid #ccc; padding: 15px; text-align: center; background-color: #f9f9f9; width: 200px; border-radius: 8px;">
            <h4 style="margin: 0; color: #555;">👥 Active Residents</h4>
            <h1 style="color: #0275d8; margin: 10px 0 0 0; font-size: 40px;"><?php echo $resident_count; ?></h1>
        </div>
    </div>
    <hr>

    <h3>Pending Resident Approvals</h3>
    <table border="1" cellpadding="10">
        <tr>
            <th>Full Name</th>
            <th>Username</th>
            <th>Phone</th>
            <th>ID Photo</th>
            <th>Action</th>
        </tr>
        <?php
        // Fetch users who are Residents AND have a 'Pending' account status
        $pending_query = "SELECT * FROM users WHERE role = 'Resident' AND account_status = 'Pending'";
        $pending_users = $conn->query($pending_query);

        if ($pending_users->num_rows > 0) {
            while ($user = $pending_users->fetch_assoc()) {
                echo "<tr>";
                echo "<td>" . $user['full_name'] . "</td>";
                echo "<td>" . $user['username'] . "</td>";
                echo "<td>" . $user['phone_number'] . "</td>";
                
                // Link to view their uploaded ID photo
                // Make sure your ID upload folder name matches here! (e.g., 'uploads/ids/' or just 'uploads/')
                echo "<td><a href='uploads/ids/" . $user['id_photo_path'] . "' target='_blank'>View ID 🪪</a></td>";
                
                // The Action buttons (we will build the backend for these next!)
                // Make sure your primary key column is 'user_id' based on your previous screenshot
                echo "<td>";
                echo "<a href='approve_resident.php?id=" . $user['user_id'] . "&action=approve' style='color: green; font-weight: bold;'>[Approve]</a> <br><br>";
                echo "<a href='approve_resident.php?id=" . $user['user_id'] . "&action=reject' style='color: red;'>[Reject]</a>";
                echo "</td>";
                echo "</tr>";
            }
        } else {
            echo "<tr><td colspan='5' style='text-align: center;'>No pending residents.</td></tr>";
        }
        ?>
    </table>

    <hr>

    <h3>Recent Waste Reports in Barangay Tanza</h3>
    <table border="1" cellpadding="10">
        <tr>
            <th>Reporter Name</th>
            <th>Description</th>
            <th>Location</th>
            <th>Photo Evidence</th>
            <th>Status</th>
        </tr>
        <?php
        // SQL JOIN: This combines the reports table with the users table so we can get the resident's actual name!
        $report_query = "SELECT waste_reports.*, users.full_name 
                         FROM waste_reports 
                         JOIN users ON waste_reports.resident_id = users.user_id 
                         ORDER BY waste_reports.status DESC"; 
        
        $reports = $conn->query($report_query);

        if ($reports->num_rows > 0) {
            while ($row = $reports->fetch_assoc()) {
                echo "<tr>";
                echo "<td>" . $row['full_name'] . "</td>";
                echo "<td>" . $row['description'] . "</td>";
                
                // Capstone Flex: Turn the Lat/Lng into a Google Maps Link
                // Safe check for coordinates and proper Google Maps Link
                $lat = isset($row['latitude']) ? $row['latitude'] : '0';
                $lng = isset($row['longitude']) ? $row['longitude'] : '0';
                
                echo "<td><a href='https://www.google.com/maps?q=" . $lat . "," . $lng . "' target='_blank'>View on Map 📍</a></td>";
                
                // Show 'Before' photo, and if resolved, show the 'After' photo too!
                echo "<td>";
                echo "<a href='uploads/reports/" . $row['before_photo_path'] . "' target='_blank'>Before 📷</a><br>";
                
                if ($row['status'] === 'Cleaned' && !empty($row['after_photo_path'])) {
                    echo "<a href='uploads/reports/" . $row['after_photo_path'] . "' target='_blank'>After ✅</a>";
                }
                echo "</td>";
                
                // Show status, and if it's Pending, show a Resolve button
                echo "<td>";
                echo "<strong>" . $row['status'] . "</strong><br>";
                if ($row['status'] === 'Pending') {
                    // Make sure 'report_id' matches the primary key column name in your waste_reports table!
                    echo "<a href='resolve_report.php?report_id=" . $row['report_id'] . "'>[Mark as Cleaned]</a>";
                }
                echo "</td>";
                echo "</tr>";
            }
        } else {
            echo "<tr><td colspan='5'>No waste reports submitted yet.</td></tr>";
        }
        ?>
    </table>
</body>
</html>
