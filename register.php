<?php
session_start();
include 'db_connect.php';

// SECURITY CHECK
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Resident') {
    header("Location: login.php");
    exit();
}

$resident_id = $_SESSION['user_id'];
// Fetch all resident data, including their account_status!
$resident_data = $conn->query("SELECT * FROM users WHERE user_id = $resident_id")->fetch_assoc();
$account_status = $resident_data['account_status']; 

// BACKEND LOGIC: Handle the Waste Report Submission (Only if Approved)
if (isset($_POST['submit_report']) && $account_status === 'Approved') {
    $description = $_POST['description'];
    $lat = $_POST['latitude'];
    $lng = $_POST['longitude'];
    
    $target_dir = "uploads/reports/";
    if (!is_dir($target_dir)) { mkdir($target_dir, 0777, true); }
    
    $file_name = time() . "_" . basename($_FILES["photo_before"]["name"]);
    $target_file = $target_dir . $file_name;

    $allowed_extensions = array("jpg", "jpeg", "png");
    $file_extension = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));

    if (!in_array($file_extension, $allowed_extensions)) {
        echo "<script>alert('Security Alert: Only JPG, JPEG, and PNG image files are allowed!'); window.location.href='resident_dashboard.php';</script>";
    } else {
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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resident Dashboard - Coastal & Land Watch</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

    <style>
        body { background-color: #f8f9fa; }
        .navbar-brand { font-weight: bold; letter-spacing: 1px; }
        #pinMap { border-radius: 8px; border: 2px solid #dee2e6; z-index: 1; }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark bg-success mb-4 shadow-sm">
        <div class="container">
            <a class="navbar-brand" href="#">🌊 Coastal Watch</a>
            <div class="d-flex align-items-center">
                <span class="navbar-text text-light me-3 d-none d-sm-inline">Hello, <?php echo htmlspecialchars($resident_data['username']); ?></span>
                <a href="logout.php" class="btn btn-outline-light btn-sm fw-bold" onclick="return confirm('Are you sure you want to log out?');"> Logout</a>
            </div>
        </div>
    </nav>

    <div class="container pb-5">
        
        <div class="row mb-4">
            <div class="col-12">
                <h2 class="fw-bold text-dark mb-1">Hello, Resident! 👋</h2>
                <p class="text-muted">Help keep Barangay Tanza clean by reporting waste hotspots.</p>
            </div>
        </div>

        <?php if ($account_status === 'Pending'): ?>
            <div class="alert alert-warning shadow-sm border-warning p-4 mb-5 text-center rounded-3">
                <h4 class="fw-bold text-dark mb-2">⏳ Account Pending Approval</h4>
                <p class="mb-0 text-muted" style="font-size: 15px;">Your registration is currently being reviewed by the Barangay Admin. Once approved, you will unlock the ability to report waste hotspots and view your history. While you wait, check out the recent community clean-ups below!</p>
            </div>

        <?php else: ?>
            <div class="position-relative d-flex gap-2 flex-wrap mb-4">
                <button id="notifBtn" class="btn btn-primary shadow-sm fw-bold position-relative">
                    🔔 Notifications
                    <?php
                    $alerts_check = $conn->query("SELECT COUNT(*) as c FROM basura_alerts")->fetch_assoc();
                    if($alerts_check['c'] > 0) {
                        echo '<span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">New</span>';
                    }
                    ?>
                </button>

                <button onclick="toggleReportingStation()" class="btn btn-success shadow-sm fw-bold">
                    📸 Report New Waste
                </button>

                <div id="notifBox" class="card shadow" style="display: none; position: absolute; top: 45px; left: 0; min-width: 320px; z-index: 1050;">
                    <div class="card-header bg-dark text-white fw-bold py-2">Recent Updates</div>
                    <div class="list-group list-group-flush max-height-300" style="max-height: 300px; overflow-y: auto;">
                        <?php
                        $alerts = $conn->query("SELECT * FROM basura_alerts ORDER BY sent_at DESC LIMIT 3");
                        if ($alerts->num_rows > 0) {
                            while($alert = $alerts->fetch_assoc()) {
                                echo "<div class='list-group-item list-group-item-warning'>";
                                echo "<strong class='d-block mb-1'>📢 Truck Alert: " . $alert['purok_area'] . "</strong>";
                                echo "<span class='small'>" . $alert['message'] . "</span>";
                                echo "<br><small class='text-muted' style='font-size: 11px;'>" . date("M d, Y h:i A", strtotime($alert['sent_at'])) . "</small>";
                                echo "</div>";
                            }
                        }

                        $cleaned = $conn->query("SELECT * FROM waste_reports WHERE resident_id = $resident_id AND status = 'Cleaned' ORDER BY created_at DESC LIMIT 3");
                        if ($cleaned->num_rows > 0) {
                            while($clean = $cleaned->fetch_assoc()) {
                                echo "<div class='list-group-item list-group-item-success'>";
                                echo "<strong class='d-block mb-1'>✅ Report Cleaned!</strong>";
                                echo "<span class='small'>Your report at <b>" . htmlspecialchars($clean['description']) . "</b> has been resolved.</span>";
                                echo "</div>";
                            }
                        }

                        if ($alerts->num_rows == 0 && $cleaned->num_rows == 0) {
                            echo "<div class='list-group-item text-center text-muted'>No new notifications.</div>";
                        }
                        ?>
                    </div>
                </div>
            </div>

            <div id="reportingStation" class="card shadow-sm border-0 mb-4" style="display: none;">
                <div class="card-header bg-success text-white fw-bold">📍 Reporting Station</div>
                <div class="card-body bg-light">
                    <form action="resident_dashboard.php" method="POST" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Description of Waste:</label>
                            <textarea name="description" class="form-control" rows="3" required placeholder="E.g., Plastic bottles washed up on the shore..."></textarea>
                        </div>
                        <div class="mb-4">
                            <label class="form-label fw-bold">Upload 'Before' Photo:</label>
                            <input class="form-control" type="file" name="photo_before" accept=".jpg,.jpeg,.png" required>
                            <img id="photoPreview" src="" style="display:none; width:100%; max-height:250px; object-fit:cover; border-radius:8px; border:2px solid #ddd; margin-top:15px;">
                        </div>
                        <hr>
                        <h5 class="fw-bold mb-3">Set Location</h5>
                        <input type="hidden" name="latitude" id="lat" required>
                        <input type="hidden" name="longitude" id="lng" required>
                        <div class="mb-3 d-flex align-items-center">
                            <button type="button" onclick="getLocation()" class="btn btn-outline-primary fw-bold me-3">📍 Auto-Detect My Location</button>
                            <span id="location_status" class="text-danger fw-bold small">Not pinned yet.</span>
                        </div>
                        <p class="text-muted small fw-bold mb-1">Or Pin Location Manually on the Map (Use the layers button for Satellite View!):</p>
                        <div id="pinMap" style="height: 300px; width: 100%; margin-bottom: 20px;"></div>
                        <button type="submit" name="submit_report" id="submit_btn" class="btn btn-success w-100 fw-bold py-2 shadow-sm" disabled>Submit Report to Barangay</button>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 fw-bold text-dark">My Reported Waste History</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped mb-0 align-middle">
                            <thead class="table-light text-center">
                                <tr>
                                    <th>Description</th>
                                    <th>Location</th>
                                    <th>Photo Evidence</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody class="text-center">
                                <?php
                                $history_query = "SELECT * FROM waste_reports WHERE resident_id = $resident_id ORDER BY status ASC, report_id DESC";
                                $history = $conn->query($history_query);

                                if ($history->num_rows > 0) {
                                    while ($row = $history->fetch_assoc()) {
                                        echo "<tr>";
                                        echo "<td class='text-start'>" . htmlspecialchars($row['description']) . "</td>";
                                        $l_lat = isset($row['latitude']) ? $row['latitude'] : '0';
                                        $l_lng = isset($row['longitude']) ? $row['longitude'] : '0';
                                        echo "<td><a href='https://www.google.com/maps?q=" . $l_lat . "," . $l_lng . "' target='_blank' class='btn btn-sm btn-outline-primary'>Map 📍</a></td>";
                                        echo "<td><a href='uploads/reports/" . $row['before_photo_path'] . "' target='_blank' class='badge bg-secondary text-decoration-none'>Before</a> ";
                                        if ($row['status'] === 'Cleaned' && !empty($row['after_photo_path'])) {
                                            echo "<br><a href='uploads/reports/" . $row['after_photo_path'] . "' target='_blank' class='badge bg-success text-decoration-none mt-1'>After</a>";
                                        }
                                        echo "</td>";
                                        $badgeColor = ($row['status'] == 'Pending') ? 'danger' : 'success';
                                        echo "<td><span class='badge bg-" . $badgeColor . " shadow-sm'>" . $row['status'] . "</span></td>";
                                        echo "</tr>";
                                    }
                                } else {
                                    echo "<tr><td colspan='4' class='text-muted py-4'>You haven't submitted any waste reports yet.</td></tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="mt-4">
            <h4 class="fw-bold mb-3 text-success">🌟 Community Clean-ups</h4>
            <p class="text-muted small mb-4">See how Barangay Tanza is taking action to keep our community clean!</p>

            <div class="row">
                <?php
                $feed_query = $conn->query("SELECT description, before_photo_path, after_photo_path, created_at FROM waste_reports WHERE status = 'Cleaned' AND after_photo_path IS NOT NULL AND after_photo_path != '' ORDER BY created_at DESC LIMIT 10");
                
                if ($feed_query->num_rows > 0) {
                    while ($feed = $feed_query->fetch_assoc()) {
                        ?>
                        <div class="col-md-6 mb-4">
                            <div class="card h-100 p-3 shadow-sm border-success" style="border-width: 2px;">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="badge bg-success py-1 px-2"><i class="fw-bold">✅ Area Cleaned</i></span>
                                    <small class="text-muted fw-bold"><?php echo date("M d, Y", strtotime($feed['created_at'])); ?></small>
                                </div>
                                <p class="small text-muted mb-2">"<?php echo htmlspecialchars($feed['description']); ?>"<br><i>— Reported by a concerned resident</i></p>
                                <div class="row g-2 mt-auto">
                                    <div class="col-6">
                                        <img src="uploads/reports/<?php echo $feed['before_photo_path']; ?>" class="img-fluid rounded border" style="height: 150px; width: 100%; object-fit: cover;">
                                        <div class="text-center fw-bold text-danger mt-1" style="font-size: 12px;">🔴 BEFORE</div>
                                    </div>
                                    <div class="col-6">
                                        <img src="uploads/reports/<?php echo $feed['after_photo_path']; ?>" class="img-fluid rounded border" style="height: 150px; width: 100%; object-fit: cover;">
                                        <div class="text-center fw-bold text-success mt-1" style="font-size: 12px;">🟢 AFTER</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php
                    }
                } else {
                    echo '<div class="col-12"><div class="alert alert-light border text-center text-muted">No clean-ups posted yet. Be the first to report an area!</div></div>';
                }
                ?>
            </div>
        </div>

    </div> 
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Notification Toggle
        var notifBtn = document.getElementById("notifBtn");
        if(notifBtn) {
            notifBtn.onclick = function() {
                var box = document.getElementById("notifBox");
                box.style.display = (box.style.display === "none" || box.style.display === "") ? "block" : "none";
            }
        }

        // Open Reporting Station
        function toggleReportingStation() {
            var station = document.getElementById("reportingStation");
            if (station.style.display === "none" || station.style.display === "") {
                station.style.display = "block"; 
                setTimeout(function(){ if(submitMap) submitMap.invalidateSize(); }, 300); 
                station.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } else {
                station.style.display = "none"; 
            }
        }

        // Geolocation
        function getLocation() {
            if (navigator.geolocation) {
                document.getElementById("location_status").innerHTML = "Locating...";
                document.getElementById("location_status").className = "text-warning fw-bold small ms-2";
                navigator.geolocation.getCurrentPosition(showPosition, showError);
            } else { alert("Geolocation is not supported."); }
        }

        function showPosition(position) {
            document.getElementById("lat").value = position.coords.latitude;
            document.getElementById("lng").value = position.coords.longitude;
            document.getElementById("location_status").innerHTML = "Location Pinned! ✔️";
            document.getElementById("location_status").className = "text-success fw-bold small ms-2";
            document.getElementById("submit_btn").disabled = false;
            
            // Move map pin automatically if auto-detected
            if (submitMap) {
                var click_lat = position.coords.latitude;
                var click_lng = position.coords.longitude;
                submitMap.setView([click_lat, click_lng], 16);
                if (currentPin) { submitMap.removeLayer(currentPin); }
                currentPin = L.marker([click_lat, click_lng]).addTo(submitMap);
            }
        }

        function showError(error) {
            alert("Error getting location. Please use the map.");
            document.getElementById("location_status").innerHTML = "Failed. Please use the map.";
            document.getElementById("location_status").className = "text-danger fw-bold small ms-2";
        }

        // ==========================================
        // MAP LOGIC WITH SATELLITE VIEW
        // ==========================================
        var mapContainer = document.getElementById('pinMap');
        var submitMap;
        var currentPin;
        
        if(mapContainer) {
            submitMap = L.map('pinMap').setView([11.45, 123.15], 13);
            
            // Layer 1: Street View
            var streetView = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '© OpenStreetMap'
            });

            // Layer 2: Satellite View (Esri)
            var satelliteView = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
                maxZoom: 19,
                attribution: 'Tiles © Esri'
            });

            // Add Street View as the default when page loads
            streetView.addTo(submitMap);

            // Create the Toggle Button for the top right corner
            var baseMaps = {
                "🗺️ Street View": streetView,
                "🛰️ Satellite View": satelliteView
            };
            L.control.layers(baseMaps).addTo(submitMap);

            // Manual Pin Dropping
            submitMap.on('click', function(e) {
                var click_lat = e.latlng.lat;
                var click_lng = e.latlng.lng;
                if (currentPin) { submitMap.removeLayer(currentPin); }
                currentPin = L.marker([click_lat, click_lng]).addTo(submitMap);
                document.getElementById('lat').value = click_lat;
                document.getElementById('lng').value = click_lng;
                document.getElementById("location_status").innerHTML = "Map Pinned Manually! ✔️";
                document.getElementById("location_status").className = "text-primary fw-bold small ms-2";
                document.getElementById("submit_btn").disabled = false; 
            });
        }

        // Photo Preview
        var photoInput = document.querySelector('input[name="photo_before"]');
        if(photoInput) {
            photoInput.addEventListener('change', function(event) {
                var file = event.target.files[0];
                if (!file) return;
                if (file.size > 5242880) {
                    alert("⚠️ Image is too large (over 5MB).");
                    this.value = ""; 
                    document.getElementById('photoPreview').style.display = 'none'; 
                    return; 
                }
                var reader = new FileReader();
                reader.onload = function(e) {
                    var img = document.getElementById('photoPreview');
                    img.src = e.target.result;
                    img.style.display = 'block'; 
                }
                reader.readAsDataURL(file);
            });
        }
    </script>
</body>
</html>
