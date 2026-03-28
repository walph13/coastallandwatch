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

// FETCH BARANGAY INFO FOR THE DYNAMIC SIDEBAR
$check_info = $conn->query("SELECT * FROM barangay_information LIMIT 1");
if ($check_info && $check_info->num_rows > 0) {
    $info = $check_info->fetch_assoc();
} else {
    $info = ['barangay_name' => 'Barangay System', 'logo_path' => ''];
}

// BACKEND: Handle the Basura-Alert Submission
if (isset($_POST['send_basura_alert'])) {
    $purok = $_POST['purok_area'];
    $message = $_POST['alert_message'];

    $alert_sql = "INSERT INTO basura_alerts (purok_area, admin_id, message) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($alert_sql);
    $stmt->bind_param("sis", $purok, $admin_id, $message);
    
    if ($stmt->execute()) {
        echo "<script>alert('Basura-Alert successfully broadcasted to " . $purok . "!'); window.location.href='admin_dashboard.php?view=alert';</script>";
    } else {
        echo "<script>alert('Database error: Failed to send alert.');</script>";
    }
}

// BACKEND: Fetch all data for the GIS Map (Coordinates, Photos, Names, Dates)
$map_query = $conn->query("
    SELECT w.report_id, w.description, w.latitude, w.longitude, w.status, 
           w.before_photo_path, w.created_at, u.full_name 
    FROM waste_reports w 
    JOIN users u ON w.resident_id = u.user_id 
    WHERE w.latitude IS NOT NULL AND w.longitude IS NOT NULL
");
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
        /* ACCENT BACKGROUND */
        body { font-family: Arial, sans-serif; background-color: #F5F5F5; display: flex; height: 100vh; margin: 0; }
        
        /* SIDEBAR: PRIMARY DARK GREEN (#2E7D32) */
        #sidebar { width: 260px; background-color: #2E7D32; color: #fff; display: flex; flex-direction: column; padding-top: 20px; box-shadow: 4px 0px 15px rgba(0,0,0,0.15); position: fixed; height: 100%; z-index: 1000; }
        
        #profile-header { text-align: center; padding-bottom: 20px; border-bottom: 1px solid #4CAF50; margin-bottom: 20px; }
        #profile-pic { width: 80px; height: 80px; border-radius: 50%; border: 3px solid #fff; object-fit: cover; margin-bottom: 10px; background-color: #fff; padding: 2px; }
        #admin-name { font-weight: bold; font-size: 16px; margin-bottom: 2px; }
        
        #nav-menu a { color: #e8f5e9; text-decoration: none; padding: 12px 20px; display: block; font-size: 15px; transition: 0.3s; border-radius: 4px; margin: 0 10px 5px 10px; }
        
        /* HOVER & ACTIVE: SECONDARY LIGHT GREEN (#4CAF50) */
        #nav-menu a:hover, #nav-menu a.active { color: #fff; background-color: #4CAF50; font-weight: bold; box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
        
        /* LOGOUT BUTTON */
        #nav-menu #logout-link { color: #ffcdd2; margin-top: auto; margin-bottom: 20px; }
        #nav-menu #logout-link:hover { background-color: #d32f2f; color: #fff; }
        
        /* MAIN CONTENT STYLES */
        #main-content { margin-left: 260px; flex: 1; padding: 30px; overflow-y: auto; }
        .page-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #ddd; padding-bottom: 10px; margin-bottom: 20px; }
        
        .dashboard-card { background: #fff; padding: 20px; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); margin-bottom: 20px; border-top: 4px solid #4CAF50; transition: 0.3s; }
        .dashboard-card:hover { transform: translateY(-3px); box-shadow: 0 6px 15px rgba(0,0,0,0.1); }
        .table-wrapper { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }

        /* CUSTOM SCROLLBAR */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-thumb { background: #4CAF50; border-radius: 4px; }
        ::-webkit-scrollbar-track { background: #F5F5F5; }
    </style>
</head>
<body>

<div id="sidebar">
        <div id="profile-header">
            <?php 
            $sidebar_logo = !empty($info['logo_path']) ? 'uploads/logo/' . $info['logo_path'] : 'uploads/default_profile.png';
            $sidebar_bname = !empty($info['barangay_name']) ? 'Brgy. ' . $info['barangay_name'] : 'Barangay System';
            
            $current_view = isset($_GET['view']) ? $_GET['view'] : 'dashboard';
            ?>
            <img src="<?php echo $sidebar_logo; ?>" id="profile-pic" alt="Admin Profile" onerror="this.src='https://cdn-icons-png.flaticon.com/512/149/149071.png'">
            <div id="admin-name"><?php echo htmlspecialchars($sidebar_bname); ?></div>
            <div style="font-size:11px; color:#e8f5e9;">Admin: <?php echo htmlspecialchars($admin_data['full_name']); ?></div>
        </div>

        <div id="nav-menu">
            <a href="admin_dashboard.php?view=dashboard" id="tab-dashboard" class="<?php echo ($current_view == 'dashboard' || empty($_GET['view'])) ? 'active' : ''; ?>">📊 Main Dashboard</a>
            <a href="admin_dashboard.php?view=reports" id="tab-reports" class="<?php echo ($current_view == 'reports') ? 'active' : ''; ?>">🗑️ Waste Reports</a>
            <a href="admin_dashboard.php?view=alert" id="tab-alert" class="<?php echo ($current_view == 'alert') ? 'active' : ''; ?>">📢 Basura Dispatch Alert</a>
            
            <a href="approve_resident.php">👥 Approve Residents</a>
            <a href="print_report.php" target="_blank">🖨️ Print Monthly Report</a>
            <a href="barangay_info.php">ℹ️ System Information</a>
            <a href="logout.php" id="logout-link" onclick="return confirm('Are you sure you want to log out?');">🚪 Logout</a>
        </div>
    </div>

    <div id="main-content">
        <div id="dashboard-header" class="page-header">
            <h2 id="page-title" style="margin:0; color: #2E7D32; font-weight: bold;">📊 Main Dashboard</h2>
            <div style="font-size:14px; color:#777; font-weight:bold;">
                📅 <?php echo date('M d, Y'); ?> | 🕒 <span id="liveClock"></span>
            </div>
        </div>

        <div id="section-dashboard" class="content-section" style="display: block;">
            
            <div style="display: flex; gap: 20px; margin-bottom: 20px;">
                <div class="dashboard-card" style="flex:1; border-top: 5px solid #dc3545;">
                    <h3 style="color: #dc3545;">Pending Reports</h3>
                    <?php
                    $pending = $conn->query("SELECT COUNT(*) as count FROM waste_reports WHERE status='Pending'")->fetch_assoc();
                    echo "<h1 style='margin:0; font-size:48px; color: #333;'>" . $pending['count'] . "</h1>";
                    ?>
                </div>
                <div class="dashboard-card" style="flex:1; border-top: 5px solid #28a745;">
                    <h3 style="color: #28a745;">Cleaned Areas</h3>
                    <?php
                    $cleaned = $conn->query("SELECT COUNT(*) as count FROM waste_reports WHERE status='Cleaned'")->fetch_assoc();
                    echo "<h1 style='margin:0; font-size:48px; color: #333;'>" . $cleaned['count'] . "</h1>";
                    ?>
                </div>
                <div class="dashboard-card" style="flex:1; border-top: 5px solid #007bff;">
                    <h3 style="color: #007bff;">Active Residents</h3>
                    <?php
                    $users = $conn->query("SELECT COUNT(*) as count FROM users WHERE role='Resident'")->fetch_assoc();
                    echo "<h1 style='margin:0; font-size:48px; color: #333;'>" . $users['count'] . "</h1>";
                    ?>
                </div>
            </div>

            <div class="dashboard-card" style="border-top: 4px solid #4CAF50; padding: 15px;">
                <h3 style="margin-top: 0; color:#333;">Barangay GIS Master Map 📍</h3>
                <p style="color: #666; font-size: 14px;">Use the layer button (top right of map) to switch to Satellite View!</p>
                <div id="masterMap" style="height: 450px; width: 100%; border-radius: 8px; border: 2px solid #ddd; z-index: 1;"></div>
            </div>
        </div>

        <div id="section-reports" class="content-section" style="display: none;">
            <div class="table-wrapper" style="border-top: 4px solid #4CAF50;">
                
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                    <h3 style="margin: 0; color: #333;">Recent Waste Reports</h3>
                    
                    <div style="display: flex; gap: 15px; align-items: center;">
                        <input type="text" id="searchInput" placeholder="🔍 Search Name or Description..." style="padding: 10px; width: 280px; border: 1px solid #ccc; border-radius: 5px;">
                        
                        <div>
                            <button id="filter-All" class="filter-btn" style="background-color: #007bff; color: white; border: none; padding: 10px 15px; border-radius: 4px; cursor: pointer; font-weight: bold;" onclick="filterTable('All')">All</button>
                            <button id="filter-Pending" class="filter-btn" style="background-color: #e9ecef; color: #333; border: 1px solid #ccc; padding: 10px 15px; border-radius: 4px; cursor: pointer; font-weight: bold; margin-left: 5px;" onclick="filterTable('Pending')">🔴 Pending</button>
                            <button id="filter-Cleaned" class="filter-btn" style="background-color: #e9ecef; color: #333; border: 1px solid #ccc; padding: 10px 15px; border-radius: 4px; cursor: pointer; font-weight: bold; margin-left: 5px;" onclick="filterTable('Cleaned')">✅ Cleaned</button>
                        </div>
                    </div>
                </div>

                <table class="table table-bordered table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Reporter</th>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Location</th>
                            <th>Photos</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="reportsTableBody">
                        <?php
                        $reports_query = $conn->query("SELECT waste_reports.*, users.full_name FROM waste_reports JOIN users ON waste_reports.resident_id = users.user_id ORDER BY waste_reports.created_at DESC");
                        
                        if($reports_query->num_rows > 0) {
                            while($row = $reports_query->fetch_assoc()) {
                                echo "<tr class='report-row' data-status='" . $row['status'] . "'>";
                                echo "<td class='reporter-name fw-bold'>" . htmlspecialchars($row['full_name']) . "</td>";
                                echo "<td>" . date("M d, Y", strtotime($row['created_at'])) . "</td>";
                                echo "<td class='report-desc'>" . htmlspecialchars($row['description']) . "</td>";
                                
                                $lat = isset($row['latitude']) ? $row['latitude'] : '0';
                                $lng = isset($row['longitude']) ? $row['longitude'] : '0';
                                echo "<td><a href='https://www.google.com/maps?q=" . $lat . "," . $lng . "' target='_blank' style='color:#007bff; text-decoration:none; font-weight:bold;'>Map 📍</a></td>";
                                
                                echo "<td><a href='uploads/reports/" . $row['before_photo_path'] . "' target='_blank' class='badge bg-secondary text-decoration-none'>Before</a> ";
                                if ($row['status'] === 'Cleaned' && !empty($row['after_photo_path'])) {
                                    echo "<br><a href='uploads/reports/" . $row['after_photo_path'] . "' target='_blank' class='badge bg-success text-decoration-none mt-1'>After ✅</a>";
                                }
                                echo "</td>";
                                
                                $badgeColor = ($row['status'] == 'Pending') ? 'danger' : 'success';
                                echo "<td><span class='badge bg-" . $badgeColor . " shadow-sm'>" . $row['status'] . "</span></td>";
                                
                                echo "<td>";
                                if ($row['status'] == 'Pending') {
                                    echo "<a href='resolve_report.php?id=" . $row['report_id'] . "' class='btn btn-success btn-sm fw-bold shadow-sm'>Resolve</a>";
                                } else {
                                    echo "<span class='text-muted small fw-bold'>Resolved</span>";
                                }
                                echo "</td>";
                                echo "</tr>";
                            }
                        } else {
                            echo "<tr id='no-data-row'><td colspan='7' style='text-align:center;' class='text-muted py-4'>No waste reports found.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="section-alert" class="content-section" style="display: none;">
            <div style="width: 100%; max-width: 600px; background-color: #fff3cd; border-left: 6px solid #ffc107; padding: 30px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); margin: 0 auto;">
                <h3 style="margin-top: 0; color: #856404; font-weight: bold;">📢 Dispatch Basura-Alert</h3>
                <p style="color: #666; font-size: 15px; margin-bottom: 20px;">Send an official broadcast to notify residents that the garbage truck is approaching their area or to share important waste management updates.</p>
                
                <form method="POST" action="">
                    <div class="mb-3">
                        <label style="font-weight: bold; color: #555;">Select Target Area:</label>
                        <select name="purok_area" class="form-select" required style="padding: 12px; border-radius: 6px;">
                            <option value="Purok Uno">Purok Uno</option>
                            <option value="Purok Dos">Purok Dos</option>
                            <option value="Purok Tres">Purok Tres</option>
                            <option value="All Areas" selected>All Areas (Barangay-wide)</option>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label style="font-weight: bold; color: #555;">Broadcast Message:</label>
                        <textarea name="alert_message" class="form-control" required style="padding: 12px; height: 100px; border-radius: 6px;">The garbage truck is currently near your area! Please prepare and bring out your segregated trash.</textarea>
                    </div>

                    <button type="submit" name="send_basura_alert" class="btn btn-warning w-100 fw-bold fs-5 shadow-sm py-2">
                        Broadcast Alert Now 🚚
                    </button>
                </form>
            </div>
        </div>

    </div> 

    <script>
        // 1. Tab Switching Logic
        window.onload = function() {
            var urlParams = new URLSearchParams(window.location.search);
            var viewToOpen = urlParams.get('view');
            
            if (viewToOpen) {
                switchTab(viewToOpen);
            } else {
                switchTab('dashboard'); 
            }
        };

        function switchTab(tabName) {
            document.querySelectorAll('.content-section').forEach(function(section) {
                section.style.display = 'none';
            });
            
            var tabDash = document.getElementById('tab-dashboard');
            var tabRep = document.getElementById('tab-reports');
            var tabAlert = document.getElementById('tab-alert');
            if(tabDash) tabDash.classList.remove('active');
            if(tabRep) tabRep.classList.remove('active');
            if(tabAlert) tabAlert.classList.remove('active');
            
            var targetSection = document.getElementById('section-' + tabName);
            if(targetSection) targetSection.style.display = 'block';
            
            var targetTab = document.getElementById('tab-' + tabName);
            if(targetTab) targetTab.classList.add('active');

            if (tabName === 'dashboard') {
                document.getElementById('page-title').innerText = "📊 Main Dashboard";
                setTimeout(function() { map.invalidateSize(); }, 200);
            } else if (tabName === 'reports') {
                document.getElementById('page-title').innerText = "🗑️ Waste Reports";
            } else if (tabName === 'alert') {
                document.getElementById('page-title').innerText = "📢 Basura Dispatch Alert";
            }
        }

        // ==========================================
        // 2. UPDATED SATELLITE MAP LOGIC
        // ==========================================
        
        // Setup Map Center
        var map = L.map('masterMap').setView([11.45, 123.15], 13);

        // Define Layer 1: Standard Street View
        var streetView = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap'
        });

        // Define Layer 2: High-Res Satellite View (Esri)
        var satelliteView = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            maxZoom: 19,
            attribution: 'Tiles © Esri'
        });

        // Add Street View as the default when page loads
        streetView.addTo(map);

        // Create the Toggle Button for the top right corner
        var baseMaps = {
            "🗺️ Street View": streetView,
            "🛰️ Satellite View": satelliteView
        };
        L.control.layers(baseMaps).addTo(map);

        // Map Pins...
        var redPin = new L.Icon({
            iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-red.png',
            shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
            iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34], shadowSize: [41, 41]
        });

        var greenPin = new L.Icon({
            iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-green.png',
            shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
            iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34], shadowSize: [41, 41]
        });

        var locations = <?php echo json_encode($map_data); ?>;
        
        locations.forEach(function(loc) {
            var currentIcon = (loc.status === 'Pending') ? redPin : greenPin; 
            var marker = L.marker([loc.latitude, loc.longitude], {icon: currentIcon}).addTo(map);
            var dateObj = new Date(loc.created_at);
            var dateStr = dateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

            var hoverCard = `
                <div style="text-align:center; min-width: 160px; padding: 5px;">
                    <img src="uploads/reports/${loc.before_photo_path}" style="width: 100%; height: 100px; object-fit: cover; border-radius: 5px; margin-bottom: 8px; border: 1px solid #ccc;">
                    <div style="font-size: 14px; color: ${(loc.status === 'Pending') ? '#dc3545' : '#28a745'}; font-weight: bold; margin-bottom: 5px;">
                        ${(loc.status === 'Pending') ? '🔴 Pending Report' : '✅ Cleaned Area'}
                    </div>
                    <div style="font-size: 12px; text-align: left; line-height: 1.4;">
                        <b>Reporter:</b> ${loc.full_name}<br>
                        <b>Date:</b> ${dateStr}<br>
                        <b>Desc:</b> <span style="color:#555;">"${loc.description}"</span>
                    </div>
                </div>
            `;
            
            marker.bindTooltip(hoverCard, { direction: 'top', opacity: 1, className: 'custom-hover-card' });
        });

        // 3. Live Search and Filter Logic
        let currentStatusFilter = 'All';
        document.getElementById('searchInput').addEventListener('keyup', applyFilters);

        function filterTable(status) {
            currentStatusFilter = status;
            document.getElementById('filter-All').style.cssText = "background-color: #e9ecef; color: #333; border: 1px solid #ccc; padding: 10px 15px; border-radius: 4px; cursor: pointer; font-weight: bold; margin-left: 5px;";
            document.getElementById('filter-Pending').style.cssText = "background-color: #e9ecef; color: #333; border: 1px solid #ccc; padding: 10px 15px; border-radius: 4px; cursor: pointer; font-weight: bold; margin-left: 5px;";
            document.getElementById('filter-Cleaned').style.cssText = "background-color: #e9ecef; color: #333; border: 1px solid #ccc; padding: 10px 15px; border-radius: 4px; cursor: pointer; font-weight: bold; margin-left: 5px;";
            document.getElementById('filter-' + status).style.cssText = "background-color: #007bff; color: white; border: none; padding: 10px 15px; border-radius: 4px; cursor: pointer; font-weight: bold; margin-left: 5px;";
            applyFilters();
        }

        function applyFilters() {
            let searchText = document.getElementById('searchInput').value.toLowerCase();
            let rows = document.querySelectorAll('.report-row');
            
            rows.forEach(row => {
                let reporterName = row.querySelector('.reporter-name').innerText.toLowerCase();
                let description = row.querySelector('.report-desc').innerText.toLowerCase();
                let rowStatus = row.getAttribute('data-status');
                
                let matchesSearch = reporterName.includes(searchText) || description.includes(searchText);
                let matchesStatus = (currentStatusFilter === 'All') || (rowStatus === currentStatusFilter);
                
                if (matchesSearch && matchesStatus) {
                    row.style.display = ''; 
                } else {
                    row.style.display = 'none'; 
                }
            });
        }

        // 4. Live Clock
        setInterval(function() {
            var now = new Date();
            document.getElementById('liveClock').innerText = now.toLocaleTimeString();
        }, 1000);
    </script>
</body>
</html>
