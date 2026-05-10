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

// BACKEND: Fetch all data for the GIS Map
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Barangay Tanza GIS</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
   <style>
        /* PAGE BACKGROUND (#ECEFF1) */
        body { font-family: 'Segoe UI', Arial, sans-serif; background-color: #ECEFF1; display: flex; height: 100vh; margin: 0; }

        /* DARKER SIDEBAR (#1B5E20) */
        #sidebar { width: 260px; background-color: #1B5E20; color: #fff; display: flex; flex-direction: column; padding-top: 30px; box-shadow: 4px 0px 15px rgba(0,0,0,0.1); position: fixed; height: 100%; z-index: 1000; }
        #profile-header { text-align: center; padding-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); margin-bottom: 15px; }
        
        #profile-pic { width: 80px; height: 80px; border-radius: 50%; border: 3px solid #fff; object-fit: cover; margin-bottom: 15px; background-color: #fff; padding: 2px; }
        #admin-name { font-weight: 800; font-size: 18px; margin-bottom: 2px; letter-spacing: 0.5px; }
        #admin-location { font-size: 12px; color: #A5D6A7; margin-bottom: 5px; }
        
        .sidebar-menu-title { font-size: 11px; color: #81C784; font-weight: 700; letter-spacing: 1px; padding: 0 20px; margin-bottom: 10px; margin-top: 10px; text-transform: uppercase; }

        #nav-menu a { color: #C8E6C9; text-decoration: none; padding: 12px 20px; display: block; font-size: 15px; transition: 0.3s; border-left: 4px solid transparent; }
        #nav-menu a:hover, #nav-menu a.active { color: #fff; background-color: rgba(255,255,255,0.1); border-left: 4px solid #81C784; font-weight: 700; }
        #nav-menu #logout-link { color: #FFCDD2; margin-top: auto; margin-bottom: 30px; border-left: 4px solid transparent; }
        #nav-menu #logout-link:hover { background-color: #D32F2F; color: #fff; border-left: 4px solid #FF5252; }

        /* MAIN CONTENT STYLES */
        #main-content { margin-left: 260px; flex: 1; padding: 40px; overflow-y: auto; }
        .page-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #CFD8DC; padding-bottom: 15px; margin-bottom: 25px; }
        .page-title { margin:0; font-weight: 800; color: #263238; font-size: 24px; }

        /* MODERN DASHBOARD CARDS */
        .content-section { display: none; }
        .dashboard-card { background: #fff; padding: 40px; border-radius: 16px; box-shadow: 0 8px 24px rgba(0,0,0,0.04); border: 1px solid rgba(0,0,0,0.05); margin-bottom: 25px; width: 100%; }
        
        /* SUMMARY BOXES */
        .summary-box { flex: 1; background: #fff; padding: 25px; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid rgba(0,0,0,0.05); transition: 0.3s; }
        .summary-box:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.08); }
        .summary-box h6 { font-size: 14px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px; color: #78909C; }
        .summary-box h2 { font-size: 48px; font-weight: 800; margin: 0; }

        .border-left-red { border-left: 6px solid #E53935 !important; }
        .border-left-green { border-left: 6px solid #43A047 !important; }
        .border-left-blue { border-left: 6px solid #1E88E5 !important; }
        
        .text-red { color: #E53935; }
        .text-green { color: #43A047; }
        .text-blue { color: #1E88E5; }

        /* MODERN TABLES */
        table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 14px; }
        th, td { padding: 16px 12px; border-bottom: 1px solid #ECEFF1; text-align: left; vertical-align: middle; }
        th { background-color: #F8FDFF; color: #546E7A; font-weight: 700; text-transform: uppercase; font-size: 12px; letter-spacing: 0.5px; border-bottom: 2px solid #CFD8DC; }
        tr:hover { background-color: #F5F7F8; }

        /* BUTTONS & INPUTS */
        .form-label { font-weight: 600; color: #546E7A; font-size: 13px; margin-bottom: 6px; display: block; }
        .form-control, .search-input { border-radius: 8px; border: 1px solid #CFD8DC; padding: 10px 14px; background-color: #F8FDFF; color: #37474F; transition: 0.2s; outline: none; width: 100%; }
        .form-control:focus, .search-input:focus { border-color: #81C784; box-shadow: 0 0 0 0.2rem rgba(76, 175, 80, 0.15); }
        
        .btn-resolve { background-color: #E8F5E9; color: #2E7D32; border: 1px solid #C8E6C9; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 12px; transition: 0.2s; display: inline-block; }
        .btn-resolve:hover { background-color: #2E7D32; color: #fff; }

        /* NEW PRINT BUTTON STYLE */
        .btn-print { background-color: #2E7D32; color: #fff; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; box-shadow: 0 4px 10px rgba(46,125,50,0.2); transition: 0.2s; font-size: 14px; }
        .btn-print:hover { background-color: #1B5E20; color: #fff; transform: translateY(-2px); box-shadow: 0 6px 15px rgba(27,94,32,0.3); }

        /* SECTION TITLES */
        .section-title {
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 0.08em;
            color: #1B5E20;
            background-color: #E8F5E9;
            border-left: 4px solid #2E7D32;
            padding: 8px 16px;
            border-radius: 0 8px 8px 0;
            margin: 0 0 20px 0;
            display: block;
        }

        /* CUSTOM SCROLLBAR */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-thumb { background: #90A4AE; border-radius: 4px; }
        ::-webkit-scrollbar-track { background: #ECEFF1; }

        @media (max-width: 768px) {
            /* Hide sidebar off-screen */
            #sidebar { left: -260px; position: fixed; z-index: 1000; }
            /* Slide it in when active */
            #sidebar.active { left: 0; box-shadow: 5px 0 20px rgba(0,0,0,0.5); }
            
            /* Expand main content to full width and prevent side-scrolling */
            #main-content { margin-left: 0 !important; padding: 15px !important; width: 100%; overflow-x: hidden; }
            
            /* Stack header elements neatly */
            .page-header { flex-direction: column; gap: 15px; align-items: flex-start !important; }
            .page-header > div { width: 100%; }
            
            /* Make summary boxes full width */
            .summary-box { min-width: 100%; margin-bottom: 15px; }
            
            /* Ensure tables scroll sideways instead of breaking the screen */
            .dashboard-card { padding: 15px; }
            table { display: block; overflow-x: auto; white-space: nowrap; }

            /* 🔥 NEW FIX FOR SYSTEM INFO SQUISHING 🔥 */
            /* Force side-by-side columns to stack neatly on phones */
            #section-info .d-flex { flex-direction: column !important; }
            #section-info .row { display: flex; flex-direction: column !important; margin: 0; }
            #section-info .col-md-6, #section-info .col-md-4, #section-info .col-md-8 { 
                width: 100% !important; 
                max-width: 100% !important;
                padding: 0 !important; 
            }
            #section-info .dashboard-card { width: 100% !important; margin-bottom: 20px; }
            
            /* Make sure the logo preview image shrinks properly */
            #section-info img { max-width: 100% !important; height: auto !important; }
        }

    </style>
</head>
<body>

<div id="sidebar-overlay" onclick="toggleSidebar()"></div>

<div id="sidebar">
        <div id="profile-header">
            <?php 
            $sidebar_logo = !empty($info['logo_path']) ? 'uploads/logo/' . $info['logo_path'] : 'uploads/default_profile.png';
            $sidebar_bname = !empty($info['barangay_name']) ? 'Brgy. ' . $info['barangay_name'] : 'Barangay System';
            $sidebar_municipal = !empty($info['municipal']) ? $info['municipal'] : 'Estancia';
            $sidebar_city = !empty($info['city']) ? $info['city'] : 'Iloilo';
            
            $current_view = isset($_GET['view']) ? $_GET['view'] : 'dashboard';
            ?>
            <img src="<?php echo $sidebar_logo; ?>" id="profile-pic" alt="Admin Profile" onerror="this.src='https://cdn-icons-png.flaticon.com/512/149/149071.png'">
            <div id="admin-name"><?php echo htmlspecialchars($sidebar_bname); ?></div>
            <div id="admin-location"><?php echo htmlspecialchars($sidebar_municipal . ', ' . $sidebar_city); ?></div>
        </div>

        <div class="sidebar-menu-title">Menu</div>
        <div id="nav-menu">
            <a href="admin_dashboard.php?view=dashboard" id="tab-dashboard" class="<?php echo ($current_view == 'dashboard' || empty($_GET['view'])) ? 'active' : ''; ?>">📊 Dashboard</a>
            <a href="admin_dashboard.php?view=reports" id="tab-reports" class="<?php echo ($current_view == 'reports') ? 'active' : ''; ?>">🗑️ Reports</a>
            <a href="admin_dashboard.php?view=alert" id="tab-alert" class="<?php echo ($current_view == 'alert') ? 'active' : ''; ?>">📢 Basura Alert</a>
            <a href="approve_resident.php">👥 Residents</a>
            <a href="barangay_info.php">ℹ️ System Info</a>
            <a href="logout.php" id="logout-link" onclick="return confirm('Are you sure you want to log out?');">🚪 Logout</a>
        </div>
    </div>

    <div id="main-content">
        <div class="d-md-none mb-4 rounded shadow-sm" style="background:#1B5E20; padding:12px 20px; display:flex; justify-content:space-between; align-items:center;">
            <h5 class="m-0 fw-bold text-white">⚙️ Admin Menu</h5>
            <button onclick="toggleSidebar()" style="background:none; border:none; color:white; font-size:28px; padding:0; cursor:pointer;">☰</button>
        </div>
        <div class="page-header">
            <h2 id="page-title" class="page-title">📊 Dashboard</h2>
            <div style="font-size:14px; color:#546E7A; font-weight:700;">
                📅 <?php echo date('M d, Y'); ?>  |  🕒 <span id="liveClock"></span>
            </div>
        </div>

        <div id="section-dashboard" class="content-section" style="display: block;">
            
            <div style="display: flex; gap: 25px; margin-bottom: 25px;">
                <div class="summary-box border-left-red">
                    <h6>Pending Reports</h6>
                    <?php
                    $pending = $conn->query("SELECT COUNT(*) as count FROM waste_reports WHERE status='Pending'")->fetch_assoc();
                    echo "<h2 class='text-red'>" . $pending['count'] . "</h2>";
                    ?>
                </div>
                <div class="summary-box border-left-green">
                    <h6>Cleaned Areas</h6>
                    <?php
                    $cleaned = $conn->query("SELECT COUNT(*) as count FROM waste_reports WHERE status='Cleaned'")->fetch_assoc();
                    echo "<h2 class='text-green'>" . $cleaned['count'] . "</h2>";
                    ?>
                </div>
                <div class="summary-box border-left-blue">
                    <h6>Active Residents</h6>
                    <?php
                    $users = $conn->query("SELECT COUNT(*) as count FROM users WHERE role='Resident'")->fetch_assoc();
                    echo "<h2 class='text-blue'>" . $users['count'] . "</h2>";
                    ?>
                </div>
            </div>

            <div class="dashboard-card" style="padding: 25px;">
                <h3 style="margin-top: 0; color:#1B5E20; font-weight: 800; font-size: 18px;">Barangay GIS Master Map</h3>
                <p style="color: #546E7A; font-size: 14px;">Use the layer button (top right of map) to switch to Satellite View!</p>
                <div id="masterMap" style="height: 500px; width: 100%; border-radius: 12px; border: 1px solid #CFD8DC; z-index: 1;"></div>
            </div>
        </div>

        <div id="section-reports" class="content-section" style="display: none;">
            <div class="dashboard-card">
                
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;">
                    <h3 style="margin: 0; color: #1B5E20; font-weight: 800; font-size: 18px;">Recent Waste Reports</h3>
                    
                    <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                        <input type="text" id="searchInput" class="search-input" placeholder="🔍 Search Name or Description" style="width: 250px;">
                        
                        <div style="display: flex;">
                            <button id="filter-All" class="filter-btn" style="background-color: #1E88E5; color: white; border: none; padding: 10px 15px; border-radius: 4px; cursor: pointer; font-weight: bold; margin-left: 8px; box-shadow: 0 4px 10px rgba(30,136,229,0.2);" onclick="filterTable('All')">All</button>
                            <button id="filter-Pending" class="filter-btn" style="background-color: #ECEFF1; color: #546E7A; border: 1px solid #ccc; padding: 10px 15px; border-radius: 4px; cursor: pointer; font-weight: bold; margin-left: 8px;" onclick="filterTable('Pending')">Pending</button>
                            <button id="filter-Cleaned" class="filter-btn" style="background-color: #ECEFF1; color: #546E7A; border: 1px solid #ccc; padding: 10px 15px; border-radius: 4px; cursor: pointer; font-weight: bold; margin-left: 8px;" onclick="filterTable('Cleaned')">Cleaned</button>
                        </div>
                        

                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
            

            <div class="d-flex flex-wrap gap-2 align-items-center">
                
                
                <div class="vr d-none d-md-block mx-1" style="height: 30px; background-color: #CFD8DC;"></div>
                
                <a href="print_report.php" target="_blank" class="btn btn-custom-success shadow-sm flex-grow-1 flex-md-grow-0 d-flex justify-content-center align-items-center gap-2" style="background-color: #2E7D32; border: none; padding: 8px 16px; text-decoration: none; color: white;">
                    🖨️ Print Report
                </a>
                
            </div>
        </div>

                    </div>
                </div>

                <table>
                    <thead>
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
                                echo "<td class='reporter-name'><strong style='color: #263238;'>" . htmlspecialchars($row['full_name']) . "</strong></td>";
                                echo "<td style='color: #546E7A;'>" . date("M d, Y", strtotime($row['created_at'])) . "</td>";
                                echo "<td class='report-desc' style='color: #546E7A;'>" . htmlspecialchars($row['description']) . "</td>";
                                
                                $lat = isset($row['latitude']) ? $row['latitude'] : '0';
                                $lng = isset($row['longitude']) ? $row['longitude'] : '0';
                                echo "<td><a href='https://www.google.com/maps?q=" . $lat . "," . $lng . "' target='_blank' style='color:#1E88E5; text-decoration:none; font-weight:700;'>Map 📍</a></td>";
                                
                                echo "<td><a href='uploads/reports/" . $row['before_photo_path'] . "' target='_blank' class='badge bg-secondary text-decoration-none'>Before</a> ";
                                if ($row['status'] === 'Cleaned' && !empty($row['after_photo_path'])) {
                                    echo "<br><a href='uploads/reports/" . $row['after_photo_path'] . "' target='_blank' class='badge bg-success text-decoration-none mt-1'>After</a>";
                                }
                                echo "</td>";
                                
                                if ($row['status'] == 'Pending') {
                                    echo "<td><span style='background-color: #FFEBEE; color: #C62828; padding: 6px 12px; border-radius: 6px; font-weight: 700; font-size: 12px; border: 1px solid #FFCDD2;'>Pending</span></td>";
                                } else {
                                    echo "<td><span style='background-color: #E8F5E9; color: #2E7D32; padding: 6px 12px; border-radius: 6px; font-weight: 700; font-size: 12px; border: 1px solid #C8E6C9;'>Cleaned</span></td>";
                                }
                                
                                echo "<td>";
                                if ($row['status'] == 'Pending') {
                                    echo "<a href='resolve_report.php?id=" . $row['report_id'] . "' class='btn-resolve'>Resolve</a>";
                                } else {
                                    echo "<span style='color: #90A4AE; font-weight: 700; font-size: 12px;'>Resolved</span>";
                                }
                                echo "</td>";
                                echo "</tr>";
                            }
                        } else {
                            echo "<tr id='no-data-row'><td colspan='7' style='text-align:center; padding: 30px; color: #90A4AE; font-weight: 500;'>No waste reports found.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="section-alert" class="content-section" style="display: none;">
            <div class="dashboard-card">
                
                <div class="section-title">Dispatch Alert</div>
                
                <p style="color: #546E7A; font-size: 14px; margin-bottom: 25px;">Send an official broadcast to notify residents that the garbage truck is approaching their area or to share important waste management updates.</p>
                
                <form method="POST" action="">
                    <div class="row">
                        <div class="col-md-12 mb-4">
                            <label class="form-label">Select Target Area</label>
                            <select name="purok_area" class="form-control" required>
                                <option value="Purok Uno">Purok Uno</option>
                                <option value="Purok Dos">Purok Dos</option>
                                <option value="Purok Tres">Purok Tres</option>
                                <option value="All Areas" selected>All Areas (Barangay-wide)</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-12 mb-4">
                            <label class="form-label">Broadcast Message</label>
                            <textarea name="alert_message" class="form-control" required style="height: 120px; resize: none;">The garbage truck is currently near your area! Please prepare and bring out your segregated trash.</textarea>
                        </div>
                    </div>

                    <button type="submit" name="send_basura_alert" class="btn btn-success w-100 mt-2 py-3 fw-bold fs-5 shadow-sm" style="background-color: #2E7D32; border: none; border-radius: 8px;">
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
                document.getElementById('page-title').innerText = "Dashboard";
                setTimeout(function() { mapDash.invalidateSize(); }, 200);
            } else if (tabName === 'reports') {
                document.getElementById('page-title').innerText = "Reports";
            } else if (tabName === 'alert') {
                document.getElementById('page-title').innerText = "Basura Alert";
            }
        }

        // ==========================================
        // 2. UPDATED SATELLITE MAP LOGIC
        // ==========================================
        
        var locations = <?php echo json_encode($map_data); ?>;
        
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

        function setupMap(mapId) {
            var m = L.map(mapId).setView([11.45, 123.15], 13);
            var streetView = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 });
            var satelliteView = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19 });
            streetView.addTo(m);
            L.control.layers({"🗺️ Street View": streetView, "🛰️ Satellite View": satelliteView}).addTo(m);

            locations.forEach(function(loc) {
                var currentIcon = (loc.status === 'Pending') ? redPin : greenPin; 
                var marker = L.marker([loc.latitude, loc.longitude], {icon: currentIcon}).addTo(m);
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
            return m;
        }

        // Initialize ONLY the Dashboard map
        var mapDash = setupMap('masterMap');

        // 3. Live Search and Filter Logic
        let currentStatusFilter = 'All';
        document.getElementById('searchInput').addEventListener('keyup', applyFilters);

        function filterTable(status) {
            currentStatusFilter = status;
            
            // Reset styling for all buttons
            document.getElementById('filter-All').style.cssText = "background-color: #ECEFF1; color: #546E7A; border: 1px solid #ccc; padding: 10px 15px; border-radius: 4px; cursor: pointer; font-weight: bold; margin-left: 8px;";
            document.getElementById('filter-Pending').style.cssText = "background-color: #ECEFF1; color: #546E7A; border: 1px solid #ccc; padding: 10px 15px; border-radius: 4px; cursor: pointer; font-weight: bold; margin-left: 8px;";
            document.getElementById('filter-Cleaned').style.cssText = "background-color: #ECEFF1; color: #546E7A; border: 1px solid #ccc; padding: 10px 15px; border-radius: 4px; cursor: pointer; font-weight: bold; margin-left: 8px;";
            
            // Apply active styling to clicked button
            document.getElementById('filter-' + status).style.cssText = "background-color: #1E88E5; color: white; border: none; padding: 10px 15px; border-radius: 4px; cursor: pointer; font-weight: bold; margin-left: 8px; box-shadow: 0 4px 10px rgba(30,136,229,0.2);";
            
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

    <script>
        // Toggle Sidebar for Mobile
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('active');
            document.getElementById('sidebar-overlay').classList.toggle('active');
        }
    </script>
</body>
</html>
