<?php
session_start();
include 'db_connect.php';

// SECURITY CHECK
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login.php");
    exit();
}

// BACKEND: Get current admin data for profile
$admin_id = $_SESSION['user_id'];
$admin_data = $conn->query("SELECT full_name FROM users WHERE user_id = $admin_id")->fetch_assoc();

// BACKEND: Handle the Basura-Alert Submission
if (isset($_POST['send_basura_alert'])) {
    $purok = $_POST['purok_area'];
    $message = $_POST['alert_message'];

    $alert_sql = "INSERT INTO basura_alerts (purok_area, admin_id, message) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($alert_sql);
    $stmt->bind_param("sis", $purok, $admin_id, $message);
    
    if ($stmt->execute()) {
        echo "<script>alert('Basura-Alert successfully broadcasted to " . $purok . "!'); window.location.href='admin_dashboard.php';</script>";
    } else {
        echo "<script>alert('Database error: Failed to send alert.');</script>";
    }
}

// BACKEND: Fetch all GPS coordinates for the map
$map_query = $conn->query("SELECT description, latitude, longitude, status FROM waste_reports WHERE latitude IS NOT NULL AND longitude IS NOT NULL");
$map_data = [];
while($row = $map_query->fetch_assoc()) {
    $map_data[] = $row;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard - Barangay Tanza GIS</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            display: flex; 
            height: 100vh; 
            background-color: #f4f7f6;
        }

        /* --- THE LEFT SIDEBAR --- */
        #sidebar {
            width: 260px;
            background-color: #343a40; 
            color: #fff;
            display: flex;
            flex-direction: column; 
            padding-top: 20px;
            box-shadow: 2px 0px 10px rgba(0,0,0,0.1);
            position: fixed; 
            height: 100%;
            z-index: 1000;
        }

        #profile-header {
            text-align: center;
            padding-bottom: 20px;
            border-bottom: 1px solid #4b545c;
            margin-bottom: 20px;
        }

        #profile-pic {
            width: 80px;
            height: 80px;
            border-radius: 50%; 
            border: 3px solid #fff;
            object-fit: cover;
            margin-bottom: 10px;
        }

        #admin-name { font-weight: bold; font-size: 16px; }

        /* Sidebar Buttons */
        #nav-menu a {
            color: #c2c7d0;
            text-decoration: none;
            padding: 12px 20px;
            display: block; 
            font-size: 15px;
            transition: 0.3s;
            border-radius: 4px;
            margin: 0 10px 5px 10px;
            cursor: pointer;
        }

        #nav-menu a:hover, #nav-menu a.active {
            color: #fff;
            background-color: #28a745; 
            font-weight: bold;
        }

        #nav-menu #logout-link {
            color: #dc3545; 
            margin-top: auto; 
            margin-bottom: 20px;
        }

        /* --- THE MAIN CONTENT AREA --- */
        #main-content {
            margin-left: 260px; 
            flex: 1; 
            padding: 30px;
            overflow-y: auto; 
        }

        #dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #ddd;
            padding-bottom: 10px;
        }
        
        /* Hides sections by default */
        .content-section { display: none; }
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
            <a id="tab-dashboard" class="active" onclick="switchTab('dashboard')">📊 Main Dashboard</a>
            <a id="tab-reports" onclick="switchTab('reports')">🗑️ Waste Reports</a>
            <a id="tab-map" onclick="switchTab('map')">📍 GIS Master Map</a>
            
            <a href="approve_resident.php">👥 Approve Residents</a>
            <a href="print_report.php" target="_blank">🖨️ Print Monthly Report</a>
            <a href="barangay_info.php">ℹ️ System Information</a>
            <a href="logout.php" id="logout-link">🚪 Logout</a>
        </div>
    </div>

    <div id="main-content">
        <div id="dashboard-header">
            <h2 id="page-title" style="margin:0;">🌊 Coastal & Land Watch</h2>
            <div style="font-size:14px; color:#777;">Date: <?php echo date('M d, Y'); ?></div>
        </div>

        <div id="section-dashboard" class="content-section" style="display: block;">
            
            <div style="display: flex; gap: 20px; margin-bottom: 30px;">
                <div style="flex:1; background:#dc3545; color:#fff; padding:20px; border-radius:8px;">
                    <h3>Pending Reports</h3>
                    <?php
                    $pending = $conn->query("SELECT COUNT(*) as count FROM waste_reports WHERE status='Pending'")->fetch_assoc();
                    echo "<h1 style='margin:0; font-size:48px;'>" . $pending['count'] . "</h1>";
                    ?>
                </div>
                <div style="flex:1; background:#28a745; color:#fff; padding:20px; border-radius:8px;">
                    <h3>Cleaned Areas</h3>
                    <?php
                    $cleaned = $conn->query("SELECT COUNT(*) as count FROM waste_reports WHERE status='Cleaned'")->fetch_assoc();
                    echo "<h1 style='margin:0; font-size:48px;'>" . $cleaned['count'] . "</h1>";
                    ?>
                </div>
                <div style="flex:1; background:#007bff; color:#fff; padding:20px; border-radius:8px;">
                    <h3>Active Residents</h3>
                    <?php
                    $users = $conn->query("SELECT COUNT(*) as count FROM users WHERE role='Resident'")->fetch_assoc();
                    echo "<h1 style='margin:0; font-size:48px;'>" . $users['count'] . "</h1>";
                    ?>
                </div>
            </div>

            <div style="width: 50%; background-color: #fff3cd; border-left: 6px solid #ffc107; padding: 20px; border-radius: 5px;">
                <h3 style="margin-top: 0; color: #856404;">📢 Dispatch Basura-Alert</h3>
                <p style="color: #666; font-size: 14px;">Notify residents that the garbage truck is approaching their area.</p>
                <form method="POST" action="">
                    <label style="font-weight: bold;">Select Area:</label>
                    <select name="purok_area" required style="width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ccc; border-radius: 4px;">
                        <option value="Purok Uno">Purok Uno</option>
                        <option value="Purok Dos">Purok Dos</option>
                        <option value="Purok Tres">Purok Tres</option>
                        <option value="All Areas">All Areas</option>
                    </select>
                    <label style="font-weight: bold;">Message:</label>
                    <textarea name="alert_message" required style="width: 100%; padding: 10px; margin: 10px 0; height: 70px; border: 1px solid #ccc; border-radius: 4px;">The garbage truck is near your area! Please bring out your trash.</textarea>
                    <button type="submit" name="send_basura_alert" style="width: 100%; background-color: #ffc107; border: none; padding: 12px; font-size: 16px; font-weight: bold; cursor: pointer; border-radius: 4px; color: #333;">Send Alert 🚚</button>
                </form>
            </div>
        </div>

        <div id="section-reports" class="content-section">
            <div style="background:#fff; padding: 20px; border-radius: 5px; border: 2px solid #ddd;">
                <h3 style="margin-top: 0;">Recent Waste Reports</h3>
                <table border="1" cellpadding="10" style="width: 100%; text-align: left; border-collapse: collapse; border: 1px solid #ddd;">
                    <tr style="background-color:#eee;">
                        <th>Reporter</th>
                        <th>Date</th>
                        <th>Description</th>
                        <th>Location</th>
                        <th>Photos</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                    <?php
                    $reports_query = $conn->query("SELECT waste_reports.*, users.full_name FROM waste_reports JOIN users ON waste_reports.resident_id = users.user_id ORDER BY waste_reports.created_at DESC");
                    
                    if($reports_query->num_rows > 0) {
                        while($row = $reports_query->fetch_assoc()) {
                            echo "<tr>";
                            echo "<td>" . $row['full_name'] . "</td>";
                            echo "<td>" . date("M d, Y", strtotime($row['created_at'])) . "</td>";
                            echo "<td>" . $row['description'] . "</td>";
                            
                            $lat = isset($row['latitude']) ? $row['latitude'] : '0';
                            $lng = isset($row['longitude']) ? $row['longitude'] : '0';
                            echo "<td><a href='https://www.google.com/maps?q=" . $lat . "," . $lng . "' target='_blank' style='color:#007bff; text-decoration:none; font-weight:bold;'>Map 📍</a></td>";
                            
                            echo "<td><a href='uploads/reports/" . $row['before_photo_path'] . "' target='_blank' style='color:#6c757d; text-decoration:none;'>Before</a> ";
                            if ($row['status'] === 'Cleaned' && !empty($row['after_photo_path'])) {
                                echo "<br><a href='uploads/reports/" . $row['after_photo_path'] . "' target='_blank' style='color:#28a745; text-decoration:none;'>After ✅</a>";
                            }
                            echo "</td>";
                            
                            $color = ($row['status'] == 'Pending') ? 'red' : 'green';
                            echo "<td style='color:$color; font-weight:bold;'>" . $row['status'] . "</td>";
                            
                            echo "<td>";
                            if ($row['status'] == 'Pending') {
                                echo "<a href='resolve_report.php?id=" . $row['report_id'] . "' style='background:#28a745; color:#fff; padding:5px 10px; text-decoration:none; border-radius:3px;'>Resolve</a>";
                            } else {
                                echo "<span style='color:#aaa;'>Resolved</span>";
                            }
                            echo "</td>";
                            echo "</tr>";
                        }
                    } else {
                        echo "<tr><td colspan='7'>No waste reports found.</td></tr>";
                    }
                    ?>
                </table>
            </div>
        </div>

        <div id="section-map" class="content-section">
            <div style="border: 2px solid #ddd; border-radius: 5px; background:#fff; padding: 15px;">
                <h3 style="margin-top: 0; color:#333;">Barangay Tanza GIS Master Map 📍</h3>
                <p style="color: #666; font-size: 14px;">Red pins are Pending reports. Green pins are Cleaned areas.</p>
                <div id="masterMap" style="height: 500px; width: 100%; border-radius: 4px;"></div>
            </div>
        </div>

    </div> <script>
        // 1. Tab Switching Logic
        // NEW CODE: This reads the VIP pass in the URL and opens the right tab
        window.onload = function() {
            var urlParams = new URLSearchParams(window.location.search);
            var viewToOpen = urlParams.get('view');
            
            // If the link says ?view=map, it automatically opens the map!
            if (viewToOpen) {
                switchTab(viewToOpen);
            }
        };
        function switchTab(tabName) {
            // Hide all sections
            document.querySelectorAll('.content-section').forEach(function(section) {
                section.style.display = 'none';
            });
            
            // Remove 'active' color from all sidebar links
            document.getElementById('tab-dashboard').classList.remove('active');
            document.getElementById('tab-reports').classList.remove('active');
            document.getElementById('tab-map').classList.remove('active');
            
            // Show the selected section
            document.getElementById('section-' + tabName).style.display = 'block';
            
            // Add 'active' color to the clicked link
            document.getElementById('tab-' + tabName).classList.add('active');

            // Change the Header Title dynamically
            if (tabName === 'dashboard') {
                document.getElementById('page-title').innerText = "📊 Main Dashboard";
            } else if (tabName === 'reports') {
                document.getElementById('page-title').innerText = "🗑️ Waste Reports";
            } else if (tabName === 'map') {
                document.getElementById('page-title').innerText = "📍 GIS Master Map";
                
                // PANEL DEFENSE TRICK: Maps glitch if loaded while hidden. 
                // This forces the map to resize perfectly when the tab is clicked.
                setTimeout(function() { map.invalidateSize(); }, 200);
            }
        }

        // 2. Map Initialization Logic
        var map = L.map('masterMap').setView([11.45, 123.15], 13);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap'
        }).addTo(map);

        var locations = <?php echo json_encode($map_data); ?>;
        locations.forEach(function(loc) {
            // Make Pending pins red, Cleaned pins green
            var pinColor = (loc.status === 'Pending') ? 'red' : 'green'; 
            
            var circle = L.circleMarker([loc.latitude, loc.longitude], {
                color: pinColor, fillColor: pinColor, fillOpacity: 0.8, radius: 8
            }).addTo(map);
            
            circle.bindPopup("<b>Status: " + loc.status + "</b><br>" + loc.description);
        });
    </script>
</body>
</html>
