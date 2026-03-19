<?php
session_start();
include 'db_connect.php';

// SECURITY CHECK
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login.php");
    exit();
}

// BACKEND LOGIC: Handle the Basura-Alert Submission
if (isset($_POST['send_basura_alert'])) {
    $purok = $_POST['purok_area'];
    $message = $_POST['alert_message'];
    $admin_id = $_SESSION['user_id'];

    $alert_sql = "INSERT INTO basura_alerts (purok_area, admin_id, message) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($alert_sql);
    $stmt->bind_param("sis", $purok, $admin_id, $message);
    
    if ($stmt->execute()) {
        echo "<script>alert('Basura-Alert successfully broadcasted to " . $purok . "!'); window.location.href='admin_dashboard.php';</script>";
    } else {
        echo "<script>alert('Database error: Failed to send alert.');</script>";
    }
}

// BACKEND LOGIC: Fetch all GPS coordinates for the map
$map_query = $conn->query("SELECT description, latitude, longitude, status FROM waste_reports WHERE latitude IS NOT NULL AND longitude IS NOT NULL");
$map_data = [];
while($row = $map_query->fetch_assoc()) {
    $map_data[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Coastal & Land Watch</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

    <style>
        body { background-color: #f4f7f6; }
        .navbar-brand { font-weight: bold; letter-spacing: 1px; }
        #masterMap { border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark bg-success mb-4 shadow-sm">
        <div class="container-fluid px-4">
            <a class="navbar-brand" href="#">🌊 Coastal & Land Watch - Admin</a>
            <div class="d-flex">
                <span class="navbar-text text-light me-3">Welcome, Barangay Secretary!</span>
                <a href="logout.php" class="btn btn-outline-light btn-sm">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-4">
        
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card bg-danger text-white shadow-sm h-100">
                    <div class="card-body text-center">
                        <h5 class="card-title">🗑️ Pending Reports</h5>
                        <?php
                        $pending = $conn->query("SELECT COUNT(*) as count FROM waste_reports WHERE status='Pending'")->fetch_assoc();
                        echo "<h2 class='display-4 fw-bold'>" . $pending['count'] . "</h2>";
                        ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-success text-white shadow-sm h-100">
                    <div class="card-body text-center">
                        <h5 class="card-title">✅ Cleaned Areas</h5>
                        <?php
                        $cleaned = $conn->query("SELECT COUNT(*) as count FROM waste_reports WHERE status='Cleaned'")->fetch_assoc();
                        echo "<h2 class='display-4 fw-bold'>" . $cleaned['count'] . "</h2>";
                        ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-primary text-white shadow-sm h-100">
                    <div class="card-body text-center">
                        <h5 class="card-title">👥 Active Residents</h5>
                        <?php
                        $users = $conn->query("SELECT COUNT(*) as count FROM users WHERE role='Resident'")->fetch_assoc();
                        echo "<h2 class='display-4 fw-bold'>" . $users['count'] . "</h2>";
                        ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-lg-4 mb-3">
                <div class="card border-warning shadow-sm h-100">
                    <div class="card-header bg-warning text-dark fw-bold">
                        📢 Dispatch Basura-Alert
                    </div>
                    <div class="card-body bg-light">
                        <p class="small text-muted mb-3">Notify residents that the garbage truck is approaching their area (approx 500m radius).</p>
                        <form method="POST" action="">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Truck Location:</label>
                                <select name="purok_area" class="form-select" required>
                                    <option value="Purok Uno">Purok Uno</option>
                                    <option value="Purok Dos">Purok Dos</option>
                                    <option value="Purok Tres">Purok Tres</option>
                                    <option value="All Areas of Tanza">All Areas of Tanza</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Message:</label>
                                <textarea name="alert_message" class="form-control" rows="2" required>The garbage truck is near your area! Please bring out your trash.</textarea>
                            </div>
                            <button type="submit" name="send_basura_alert" class="btn btn-warning w-100 fw-bold shadow-sm">Send Alert 🚚</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-8 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-dark text-white fw-bold">
                        📍 Barangay Tanza GIS Master Map
                    </div>
                    <div class="card-body p-0">
                        <div id="masterMap" style="height: 400px; width: 100%;"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-5">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                <h5 class="mb-0 fw-bold text-dark">Recent Waste Reports</h5>
                <a href="print_report.php" target="_blank" class="btn btn-outline-success btn-sm fw-bold">🖨️ Print Monthly Report</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0 align-middle">
                        <thead class="table-light text-center">
                            <tr>
                                <th>Reporter Name</th>
                                <th>Date Reported</th>
                                <th>Description</th>
                                <th>Location</th>
                                <th>Photo Evidence</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody class="text-center">
                            <?php
                            $reports_query = $conn->query("SELECT waste_reports.*, users.full_name FROM waste_reports JOIN users ON waste_reports.resident_id = users.user_id ORDER BY waste_reports.created_at DESC");
                            
                            if($reports_query->num_rows > 0) {
                                while($row = $reports_query->fetch_assoc()) {
                                    echo "<tr>";
                                    echo "<td class='fw-bold'>" . $row['full_name'] . "</td>";
                                    echo "<td><small class='text-muted'>" . date("M d, Y <br> h:i A", strtotime($row['created_at'])) . "</small></td>";
                                    echo "<td>" . $row['description'] . "</td>";
                                    
                                    // Location Link
                                    $lat = isset($row['latitude']) ? $row['latitude'] : '0';
                                    $lng = isset($row['longitude']) ? $row['longitude'] : '0';
                                    echo "<td><a href='https://www.google.com/maps?q=" . $lat . "," . $lng . "' target='_blank' class='btn btn-sm btn-outline-primary'>Map 📍</a></td>";
                                    
                                    // Photos
                                    echo "<td>";
                                    echo "<a href='uploads/reports/" . $row['before_photo_path'] . "' target='_blank' class='badge bg-secondary text-decoration-none'>Before</a> ";
                                    if ($row['status'] === 'Cleaned' && !empty($row['after_photo_path'])) {
                                        echo "<a href='uploads/reports/" . $row['after_photo_path'] . "' target='_blank' class='badge bg-success text-decoration-none'>After</a>";
                                    }
                                    echo "</td>";
                                    
                                    // Status Badge
                                    $statusColor = ($row['status'] == 'Pending') ? 'danger' : 'success';
                                    echo "<td><span class='badge bg-" . $statusColor . "'>" . $row['status'] . "</span></td>";
                                    
                                    // Resolve Button
                                    echo "<td>";
                                    if ($row['status'] == 'Pending') {
                                        echo "<a href='resolve_report.php?id=" . $row['report_id'] . "' class='btn btn-sm btn-success fw-bold'>Resolve</a>";
                                    } else {
                                        echo "<span class='text-muted small'>Resolved</span>";
                                    }
                                    echo "</td>";
                                    echo "</tr>";
                                }
                            } else {
                                echo "<tr><td colspan='7' class='text-muted py-4'>No waste reports found.</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div> <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        var map = L.map('masterMap').setView([11.45, 123.15], 13); // Centered near Estancia

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap'
        }).addTo(map);

        var locations = <?php echo json_encode($map_data); ?>;
        
        if (locations.length > 0) {
            var bounds = []; 
            locations.forEach(function(loc) {
                var pinColor = (loc.status === 'Pending') ? 'red' : 'green'; 
                var circle = L.circleMarker([loc.latitude, loc.longitude], {
                    color: pinColor, fillColor: pinColor, fillOpacity: 0.8, radius: 8
                }).addTo(map);
                circle.bindPopup("<b>Status: " + loc.status + "</b><br>Desc: " + loc.description);
                bounds.push([loc.latitude, loc.longitude]);
            });
            map.fitBounds(bounds);
        }
    </script>
</body>
</html>
