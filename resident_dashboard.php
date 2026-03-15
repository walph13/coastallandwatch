<?php
session_start();
include 'db_connect.php';

// SECURITY CHECK: Kick out anyone who is NOT a Resident
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Resident') {
    header("Location: login.php");
    exit();
}

$resident_id = $_SESSION['user_id'];

// BACKEND LOGIC: Handle the Waste Report Submission
// BACKEND LOGIC: Handle the Waste Report Submission
if (isset($_POST['submit_report'])) {
    $description = $_POST['description'];
    $lat = $_POST['latitude'];
    $lng = $_POST['longitude'];
    
    // Set up upload directory
    $target_dir = "uploads/reports/";
    if (!is_dir($target_dir)) { mkdir($target_dir, 0777, true); }
    
    $file_name = time() . "_" . basename($_FILES["photo_before"]["name"]);
    $target_file = $target_dir . $file_name;

    // SECURITY UPGRADE: Check the file extension
    $allowed_extensions = array("jpg", "jpeg", "png");
    $file_extension = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));

    if (!in_array($file_extension, $allowed_extensions)) {
        // Block the file and refresh
        echo "<script>alert('Security Alert: Only JPG, JPEG, and PNG image files are allowed!'); window.location.href='resident_dashboard.php';</script>";
    } else {
        // If the file is a valid image, move it and save to database
        if (move_uploaded_file($_FILES["photo_before"]["tmp_name"], $target_file)) {
            $sql = "INSERT INTO waste_reports (resident_id, latitude, longitude, description, before_photo_path, status) 
                    VALUES (?, ?, ?, ?, ?, 'Pending')";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iddss", $resident_id, $lat, $lng, $description, $file_name);
            
            if ($stmt->execute()) {
                echo "<script>alert('Waste report submitted successfully!'); window.location.href='resident_dashboard.php';</script>";
            } else {
                echo "<script>alert('Error submitting report.');</script>";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Resident Dashboard - Coastal & Land Watch</title>
</head>
<body>
    <h2>Resident Dashboard</h2>
    <p>Welcome! Help keep Barangay Tanza clean by reporting waste areas below.</p>
    <a href="logout.php">Logout</a> <hr>

    <h3>Report a New Waste Area</h3>
    <form action="resident_dashboard.php" method="POST" enctype="multipart/form-data">
        
        <label>Description of Waste:</label><br>
        <textarea name="description" rows="4" cols="30" required placeholder="E.g., Plastic bottles washed up on the shore..."></textarea><br><br>
        
        <label>Upload 'Before' Photo:</label><br>
        <input type="file" name="photo_before" required><br><br>

        <input type="hidden" name="latitude" id="lat" required>
        <input type="hidden" name="longitude" id="lng" required>

        <button type="button" onclick="getLocation()">Pin My Location</button>
        <span id="location_status" style="color:red;"> Location not pinned yet.</span><br><br>

        <button type="submit" name="submit_report" id="submit_btn" disabled>Submit Report</button>
    </form>

    <hr>
    
    <h3>My Reported Waste History</h3>
    <table border="1" cellpadding="10">
        <tr>
            <th>Description</th>
            <th>Location</th>
            <th>Photo Evidence</th>
            <th>Status</th>
        </tr>
        <?php
        // Fetch ONLY the reports belonging to the currently logged-in resident
        $history_query = "SELECT * FROM waste_reports WHERE resident_id = $resident_id ORDER BY status ASC, report_id DESC";
        $history = $conn->query($history_query);

        if ($history->num_rows > 0) {
            while ($row = $history->fetch_assoc()) {
                echo "<tr>";
                echo "<td>" . $row['description'] . "</td>";
                
                // Safe Google Maps Link
                $lat = isset($row['latitude']) ? $row['latitude'] : '0';
                $lng = isset($row['longitude']) ? $row['longitude'] : '0';
                echo "<td><a href='https://www.google.com/maps?q=" . $lat . "," . $lng . "' target='_blank'>View on Map 📍</a></td>";
                
                // Show 'Before' photo, and if Cleaned, show the 'After' photo
                echo "<td>";
                echo "<a href='uploads/reports/" . $row['before_photo_path'] . "' target='_blank'>Before 📷</a><br>";
                
                // FIXED: Checking for 'Cleaned' status based on your database ENUM
                if ($row['status'] === 'Cleaned' && !empty($row['after_photo_path'])) {
                    echo "<a href='uploads/reports/" . $row['after_photo_path'] . "' target='_blank'>After ✅</a>";
                }
                echo "</td>";
                
                // Display Status
                echo "<td><strong>" . $row['status'] . "</strong></td>";
                echo "</tr>";
            }
        } else {
            echo "<tr><td colspan='4'>You haven't submitted any waste reports yet.</td></tr>";
        }
        ?>
    </table>

    <script>
        // JavaScript to get the phone/browser GPS location
        function getLocation() {
            if (navigator.geolocation) {
                document.getElementById("location_status").innerHTML = "Locating...";
                navigator.geolocation.getCurrentPosition(showPosition, showError);
            } else { 
                alert("Geolocation is not supported by this browser.");
            }
        }

        function showPosition(position) {
            document.getElementById("lat").value = position.coords.latitude;
            document.getElementById("lng").value = position.coords.longitude;
            document.getElementById("location_status").innerHTML = "Location Pinned! ✔️";
            document.getElementById("location_status").style.color = "green";
            document.getElementById("submit_btn").disabled = false; // Enable the submit button
        }

        function showError(error) {
            alert("Error getting location. Please allow location access.");
        }
    </script>
</body>
</html>
