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
        $pending_users = $conn->query("SELECT * FROM users WHERE role = 'Resident' AND account_status = 'Pending'");
        if ($pending_users->num_rows > 0) {
            while ($row = $pending_users->fetch_assoc()) {
                echo "<tr>";
                echo "<td>" . $row['full_name'] . "</td>";
                echo "<td>" . $row['username'] . "</td>";
                echo "<td>" . $row['phone_number'] . "</td>";
                echo "<td><a href='uploads/ids/" . $row['id_photo_path'] . "' target='_blank'>View ID</a></td>"; 
                echo "<td><a href='admin_dashboard.php?approve_id=" . $row['user_id'] . "'>Approve</a></td>";
                echo "</tr>";
            }
        } else {
            echo "<tr><td colspan='5'>No pending residents.</td></tr>";
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
