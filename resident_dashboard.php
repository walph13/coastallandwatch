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
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
</head>
<body>
    <h2>Resident Dashboard</h2>
    <p>Welcome! Help keep Barangay Tanza clean by reporting waste areas below.</p>
    
    <div style="position: relative; display: flex; gap: 10px; margin-bottom: 25px;">
        
        <button id="notifBtn" style="background-color: #007bff; color: white; border: none; padding: 10px 15px; border-radius: 5px; cursor: pointer; font-size: 16px; font-weight: bold; box-shadow: 0px 4px 6px rgba(0,0,0,0.1);">
            🔔 Notifications
            <span style="background-color: red; color: white; border-radius: 50%; padding: 2px 7px; font-size: 12px; margin-left: 5px;">New</span>
        </button>

        <button onclick="toggleReportingStation()" style="background-color: #28a745; color: white; padding: 10px 15px; font-size: 16px; border-radius: 5px; cursor: pointer; border: none; font-weight: bold; box-shadow: 0px 4px 6px rgba(0,0,0,0.1);">
            📸 Report New Waste
        </button>

        <div id="notifBox" style="display: none; position: absolute; top: 45px; left: 0; background-color: white; min-width: 350px; box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2); z-index: 100; border-radius: 5px; border: 1px solid #ccc; overflow: hidden;">
            <div style="padding: 10px 15px; background-color: #343a40; color: white; font-weight: bold;">Recent Updates</div>
            
            <?php
            // 1. FETCH ADMIN BASURA-ALERTS (Yellow notifications)
            $alerts = $conn->query("SELECT * FROM basura_alerts ORDER BY sent_at DESC LIMIT 3");
            if ($alerts->num_rows > 0) {
                while($alert = $alerts->fetch_assoc()) {
                    echo "<div style='padding: 12px; border-bottom: 1px solid #eee; background-color: #fff3cd; color: #856404;'>";
                    echo "<strong style='display: block; margin-bottom: 3px;'>📢 Truck Alert: " . $alert['purok_area'] . "</strong>";
                    echo "<span>" . $alert['message'] . "</span>";
                    echo "<br><small style='color: #b58500; font-size: 11px;'>" . date("M d, Y h:i A", strtotime($alert['sent_at'])) . "</small>";
                    echo "</div>";
                }
            }

            // 2. FETCH RESIDENT'S CLEANED REPORTS (Green notifications)
            $res_id = $_SESSION['user_id'];
            $cleaned = $conn->query("SELECT * FROM waste_reports WHERE resident_id = $res_id AND status = 'Cleaned' ORDER BY created_at DESC LIMIT 3");
            if ($cleaned->num_rows > 0) {
                while($clean = $cleaned->fetch_assoc()) {
                    echo "<div style='padding: 12px; border-bottom: 1px solid #eee; background-color: #d4edda; color: #155724;'>";
                    echo "<strong style='display: block; margin-bottom: 3px;'>✅ Report Cleaned!</strong>";
                    echo "<span>Your report at <b>" . $clean['description'] . "</b> has been resolved by the Barangay. Thank you!</span>";
                    echo "</div>";
                }
            }

            // 3. IF NO NOTIFICATIONS EXIST
            if ($alerts->num_rows == 0 && $cleaned->num_rows == 0) {
                echo "<div style='padding: 15px; text-align: center; color: gray;'>You have no new notifications.</div>";
            }
            ?>
        </div>
    </div>

    <a href="logout.php" style="color: #dc3545; text-decoration: none; font-weight: bold;">Log Out</a> 
    <hr>

    <div id="reportingStation" style="display: none; background-color: #f8f9fa; padding: 20px; border: 2px solid #ddd; border-radius: 8px; margin-bottom: 30px;">
        <h3 style="margin-top: 0; color: #333;">📍 Reporting Station</h3>
        <p style="font-size: 14px; color: #666;">Fill out the details below to alert the Barangay.</p>
        
        <form action="resident_dashboard.php" method="POST" enctype="multipart/form-data">
            
            <label>Description of Waste:</label><br>
            <textarea name="description" rows="4" cols="30" required placeholder="E.g., Plastic bottles washed up on the shore..."></textarea><br><br>
            
            <label>Upload 'Before' Photo:</label><br>
            <input type="file" name="photo_before" required><br><br>

            <input type="hidden" name="latitude" id="lat" required>
            <input type="hidden" name="longitude" id="lng" required>

            <button type="button" onclick="getLocation()" style="padding: 8px 12px; cursor: pointer;">📍 Auto-Detect My Location</button>
            <span id="location_status" style="color:red; font-weight: bold; margin-left: 10px;"> Location not pinned yet.</span><br><br>

            <label style="font-weight: bold; margin-top: 15px; display: block;">Or Pin Exact Location Manually:</label>
            <p style="font-size: 12px; color: #555; margin-bottom: 5px;">Tap the map to manually drop a pin where the waste is located.</p>
            
            <div id="pinMap" style="height: 250px; width: 100%; border: 2px solid #ccc; border-radius: 5px; margin-bottom: 15px;"></div>

            <button type="submit" name="submit_report" id="submit_btn" disabled style="padding: 10px 20px; font-size: 16px; background-color: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer;">Submit Report</button>
        </form>
    </div>

    <h3>My Reported Waste History</h3>
    <table border="1" cellpadding="10" cellspacing="0" style="width: 100%; text-align: left;">
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
                
                // FIXED Google Maps Link
                $lat = isset($row['latitude']) ? $row['latitude'] : '0';
                $lng = isset($row['longitude']) ? $row['longitude'] : '0';
                echo "<td><a href='https://www.google.com/maps/search/?api=1&query=" . $lat . "," . $lng . "' target='_blank'>View on Map 📍</a></td>";
                
                // Show 'Before' photo, and if Cleaned, show the 'After' photo
                echo "<td>";
                echo "<a href='uploads/reports/" . $row['before_photo_path'] . "' target='_blank'>Before 📷</a><br>";
                
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
        // 1. Notification Dropdown Toggle
        document.getElementById("notifBtn").onclick = function() {
            var box = document.getElementById("notifBox");
            if (box.style.display === "none" || box.style.display === "") {
                box.style.display = "block";
            } else {
                box.style.display = "none";
            }
        }

        // 2. Reporting Station Toggle
        function toggleReportingStation() {
            var station = document.getElementById("reportingStation");
            
            if (station.style.display === "none" || station.style.display === "") {
                station.style.display = "block"; // Open it
                setTimeout(function(){ submitMap.invalidateSize(); }, 300); // Fix map render
                station.scrollIntoView({ behavior: 'smooth' });
            } else {
                station.style.display = "none"; // Close it
            }
        }

        // 3. Auto-GPS Location Logic
        function getLocation() {
            if (navigator.geolocation) {
                document.getElementById("location_status").innerHTML = "Locating...";
                document.getElementById("location_status").style.color = "orange";
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
            document.getElementById("submit_btn").disabled = false; // UNLOCK SUBMIT BUTTON
        }

        function showError(error) {
            alert("Error getting location. Please allow location access or use the manual map pin.");
            document.getElementById("location_status").innerHTML = "Failed. Please use the map below.";
            document.getElementById("location_status").style.color = "red";
        }

        // 4. Manual Map Pinning Logic
        var submitMap = L.map('pinMap').setView([11.45, 123.15], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap'
        }).addTo(submitMap);

        var currentPin;

        submitMap.on('click', function(e) {
            var click_lat = e.latlng.lat;
            var click_lng = e.latlng.lng;
            
            if (currentPin) {
                submitMap.removeLayer(currentPin);
            }
            
            currentPin = L.marker([click_lat, click_lng]).addTo(submitMap);
            
            // Send coordinates to the hidden form boxes
            document.getElementById('lat').value = click_lat;
            document.getElementById('lng').value = click_lng;
            
            // Update UI to show success
            document.getElementById("location_status").innerHTML = "Map Pinned Manually! ✔️";
            document.getElementById("location_status").style.color = "blue";
            document.getElementById("submit_btn").disabled = false; // UNLOCK SUBMIT BUTTON
        });
    </script>
</body>
</html>
