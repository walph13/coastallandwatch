<?php
session_start();
include 'db_connect.php';

// SECURITY CHECK
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Resident') {
    header("Location: login.php");
    exit();
}

$resident_id = $_SESSION['user_id'];
$resident_data = $conn->query("SELECT * FROM users WHERE user_id = $resident_id")->fetch_assoc();
$account_status = $resident_data['account_status']; 

// FETCH BARANGAY INFO FOR THE DYNAMIC SIDEBAR & HOTLINES
$check_info = $conn->query("SELECT * FROM barangay_information LIMIT 1");
if ($check_info && $check_info->num_rows > 0) {
    $info = $check_info->fetch_assoc();
} else {
    $info = ['barangay_name' => 'Barangay System', 'logo_path' => '', 'emergency_hotlines' => 'Not set', 'office_hours' => 'Not set', 'captain_name' => '', 'secretary_name' => '', 'full_address' => ''];
}

// BACKEND: Fetch all data for the Community GIS Map
$map_query = $conn->query("
    SELECT report_id, description, latitude, longitude, status, before_photo_path, created_at 
    FROM waste_reports 
    WHERE latitude IS NOT NULL AND longitude IS NOT NULL
");
$map_data = [];
while($row = $map_query->fetch_assoc()) {
    $map_data[] = $row;
}

// BACKEND LOGIC: Handle Waste Report Submission
if (isset($_POST['submit_report'])) {
    $description = $conn->real_escape_string($_POST['description']);
    $lat = $conn->real_escape_string($_POST['latitude']);
    $lng = $conn->real_escape_string($_POST['longitude']);
    $status = 'Pending'; // All new reports start as Pending
    
    // Photo Upload Logic for the 'Before' picture
    $photo_before = "";
    if (!empty($_FILES['photo_before']['name'])) {
        $target_dir = "uploads/reports/";
        if (!is_dir($target_dir)) { mkdir($target_dir, 0777, true); } // Create folder if it doesn't exist
        
        $file_name = time() . "_" . basename($_FILES["photo_before"]["name"]);
        $target_file = $target_dir . $file_name;
        
        $allowed_extensions = array("jpg", "jpeg", "png");
        $file_extension = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
        
        if (in_array($file_extension, $allowed_extensions)) {
            if (move_uploaded_file($_FILES["photo_before"]["tmp_name"], $target_file)) {
                $photo_before = $file_name;
            }
        }
    }

    // Ensure a photo was actually uploaded before saving to the database
    if (!empty($photo_before)) {
        $insert_query = "INSERT INTO waste_reports (resident_id, description, latitude, longitude, before_photo_path, status) 
                         VALUES ('$resident_id', '$description', '$lat', '$lng', '$photo_before', '$status')";
        
        if ($conn->query($insert_query)) {
            // Success! Send them to the 'My Reports' tab to see it
            echo "<script>alert('Waste report submitted successfully!'); window.location.href='resident_dashboard.php?view=history';</script>";
        } else {
            echo "<script>alert('Database Error: Could not save the report.');</script>";
        }
    } else {
        echo "<script>alert('Error: Please upload a valid image file (JPG, JPEG, or PNG).');</script>";
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
        /* PAGE BACKGROUND */
        body { font-family: 'Segoe UI', Arial, sans-serif; background-color: #ECEFF1; display: flex; height: 100vh; margin: 0; }
        
        /* DARKER SIDEBAR */
        #sidebar { width: 260px; background-color: #1B5E20; color: #fff; display: flex; flex-direction: column; padding-top: 30px; box-shadow: 4px 0px 15px rgba(0,0,0,0.1); position: fixed; height: 100%; z-index: 1000; }
        #profile-header { text-align: center; padding-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); margin-bottom: 15px; }
        #profile-pic { width: 80px; height: 80px; border-radius: 50%; border: 3px solid #fff; object-fit: cover; margin-bottom: 15px; background-color: #fff; padding: 2px; }
        #resident-name { font-weight: 800; font-size: 18px; margin-bottom: 2px; letter-spacing: 0.5px; }
        #resident-role { font-size: 12px; color: #A5D6A7; margin-bottom: 5px; text-transform: uppercase; font-weight: bold; }
        
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
        .dashboard-card { background: #fff; padding: 30px; border-radius: 16px; box-shadow: 0 8px 24px rgba(0,0,0,0.04); border: 1px solid rgba(0,0,0,0.05); margin-bottom: 25px; }
        .card-header-custom { border-bottom: 2px solid #CFD8DC; padding-bottom: 15px; margin-bottom: 20px; font-weight: 800; color: #1B5E20; font-size: 18px; }

        /* SUMMARY BOXES */
        .summary-box { flex: 1 1 30%; background: #fff; padding: 25px; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid rgba(0,0,0,0.05); transition: 0.3s; min-width: 250px; }
        .summary-box:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.08); }
        .summary-box h6 { font-size: 14px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px; color: #78909C; }
        .summary-box h2 { font-size: 48px; font-weight: 800; margin: 0; }

        .border-left-red { border-left: 6px solid #E53935 !important; }
        .border-left-green { border-left: 6px solid #43A047 !important; }
        .border-left-blue { border-left: 6px solid #1E88E5 !important; }
        .text-red { color: #E53935; }
        .text-green { color: #43A047; }
        .text-blue { color: #1E88E5; }

        /* BUTTONS & INPUTS */
        .btn-custom-primary { background-color: #1E88E5; color: #fff; border: none; border-radius: 8px; font-weight: 700; box-shadow: 0 4px 10px rgba(30,136,229,0.2); transition: 0.2s; padding: 10px 20px; }
        .btn-custom-primary:hover { background-color: #1565C0; color: #fff; transform: translateY(-2px); }
        
        .btn-custom-success { background-color: #2E7D32; color: #fff; border: none; border-radius: 8px; font-weight: 700; box-shadow: 0 4px 10px rgba(46,125,50,0.2); transition: 0.2s; padding: 10px 20px; }
        .btn-custom-success:hover { background-color: #1B5E20; color: #fff; transform: translateY(-2px); }

        .btn-custom-outline { border: 2px solid #1E88E5; color: #1E88E5; background: transparent; border-radius: 8px; font-weight: 700; transition: 0.2s; padding: 8px 16px; }
        .btn-custom-outline:hover { background-color: #1E88E5; color: #fff; }

        .form-control { border-radius: 8px; border: 1px solid #CFD8DC; padding: 12px 14px; background-color: #F8FDFF; color: #37474F; transition: 0.2s; }
        .form-control:focus { border-color: #81C784; box-shadow: 0 0 0 0.2rem rgba(76, 175, 80, 0.15); }
        .form-label { font-weight: 700; color: #546E7A; font-size: 14px; margin-bottom: 6px; }

        /* MODERN TABLES */
        table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 14px; }
        th, td { padding: 16px 12px; border-bottom: 1px solid #ECEFF1; text-align: left; vertical-align: middle; }
        th { background-color: #F8FDFF; color: #546E7A; font-weight: 700; text-transform: uppercase; font-size: 12px; letter-spacing: 0.5px; border-bottom: 2px solid #CFD8DC; }
        tr:hover { background-color: #F5F7F8; }

        /* BADGES */
        .badge-pending { background-color: #FFEBEE; color: #C62828; padding: 6px 12px; border-radius: 6px; font-weight: 700; font-size: 12px; border: 1px solid #FFCDD2; display: inline-block; }
        .badge-cleaned { background-color: #E8F5E9; color: #2E7D32; padding: 6px 12px; border-radius: 6px; font-weight: 700; font-size: 12px; border: 1px solid #C8E6C9; display: inline-block; }

        /* CUSTOM SCROLLBAR */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-thumb { background: #90A4AE; border-radius: 4px; }
        ::-webkit-scrollbar-track { background: #ECEFF1; }
    </style>
</head>
<body>

    <div id="sidebar">
        <div id="profile-header">
            <?php 
            // Now fetches the Resident's Profile Picture instead of the Barangay Logo
            $user_pic = !empty($resident_data['profile_pic']) ? 'uploads/profiles/' . $resident_data['profile_pic'] : 'https://cdn-icons-png.flaticon.com/512/149/149071.png';
            $current_view = isset($_GET['view']) ? $_GET['view'] : 'dashboard';
            ?>
            <img src="<?php echo htmlspecialchars($user_pic); ?>" id="profile-pic" alt="User Profile" onerror="this.src='https://cdn-icons-png.flaticon.com/512/149/149071.png'">
            <div id="resident-name"><?php echo htmlspecialchars($resident_data['username']); ?></div>
            <div id="resident-role">Approved Resident</div>
        </div>

        <div class="sidebar-menu-title">Menu</div>
        <div id="nav-menu">
            <a href="resident_dashboard.php?view=dashboard" id="tab-dashboard" class="<?php echo ($current_view == 'dashboard' || empty($_GET['view'])) ? 'active' : ''; ?>">📊 Dashboard</a>
            <a href="resident_dashboard.php?view=report" id="tab-report" class="<?php echo ($current_view == 'report') ? 'active' : ''; ?>">📸 New Report</a>
            <a href="resident_dashboard.php?view=history" id="tab-history" class="<?php echo ($current_view == 'history') ? 'active' : ''; ?>">📝 My Reports</a>
            <a href="resident_dashboard.php?view=community" id="tab-community" class="<?php echo ($current_view == 'community') ? 'active' : ''; ?>">🌟 Community Feed</a>
            <a href="resident_dashboard.php?view=info" id="tab-info" class="<?php echo ($current_view == 'info') ? 'active' : ''; ?>">📞 Emergency Info</a>
            
            <a href="resident_dashboard.php?view=profile" id="tab-profile" class="<?php echo ($current_view == 'profile') ? 'active' : ''; ?>">👤 My Profile</a>
            
            <a href="logout.php" id="logout-link" onclick="return confirm('Are you sure you want to log out?');">🚪 Logout</a>
        </div>
    </div>

    <div id="main-content">
        
        <div class="page-header">
            <div>
                <h2 id="page-title" class="page-title">📊 Resident Dashboard</h2>
                <p class="text-muted fw-bold mb-0" style="color: #78909C !important;">Welcome back, <?php echo htmlspecialchars($resident_data['username']); ?>!</p>
            </div>
            <div style="display: flex; align-items: center; gap: 20px;">
                <div class="d-none d-md-block" style="font-size:14px; color:#546E7A; font-weight:700;">
                    📅 <?php echo date('M d, Y'); ?>  |  🕒 <span id="liveClock"></span>
                </div>
            </div>
        </div>

        <div id="section-profile" class="content-section" style="display: none;">
            <div class="dashboard-card border-left-green" style="padding: 40px;">
                <h4 style="color: #1B5E20; font-weight: 800; margin-bottom: 25px;">👤 Edit Profile Details</h4>
                
                <form action="resident_dashboard.php" method="POST" enctype="multipart/form-data" onsubmit="return confirm('Are you sure you want to submit this waste report? Please make sure your photo and location are correct.');">
                    <div class="row">
                        <div class="col-md-4 text-center mb-4">
                            <img id="editProfilePreview" src="<?php echo htmlspecialchars($user_pic); ?>" style="width: 150px; height: 150px; border-radius: 50%; object-fit: cover; border: 4px solid #CFD8DC; margin-bottom: 15px;">
                            <label class="form-label d-block text-muted">Change Profile Picture (Optional)</label>
                            <input class="form-control form-control-sm" type="file" name="profile_pic" accept=".jpg,.jpeg,.png" onchange="document.getElementById('editProfilePreview').src = window.URL.createObjectURL(this.files[0])">
                        </div>
                        
                        <div class="col-md-8">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Full Name</label>
                                    <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($resident_data['full_name'] ?? ''); ?>" placeholder="E.g. Juan Dela Cruz">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Username</label>
                                    <input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($resident_data['username']); ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Phone Number</label>
                                    <input type="text" name="phone_number" class="form-control" value="<?php echo htmlspecialchars($resident_data['phone_number'] ?? ''); ?>" placeholder="09XXXXXXXXX">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Date of Birth</label>
                                    <input type="date" name="date_of_birth" class="form-control" value="<?php echo htmlspecialchars($resident_data['date_of_birth'] ?? ''); ?>">
                                </div>
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Complete Address</label>
                                    <input type="text" name="address_purok_sitio" class="form-control" value="<?php echo htmlspecialchars($resident_data['address_purok_sitio'] ?? ''); ?>" placeholder="Sitio, Street, etc.">
                                </div>
                                <div class="col-md-12 mb-4">
                                    <label class="form-label">New Password (Leave blank to keep current password)</label>
                                    <input type="password" name="password" class="form-control" placeholder="••••••••">
                                </div>
                            </div>
                            
                            <button type="submit" name="update_profile" class="btn-custom-success w-100 py-3 fs-5">
                                Save Profile Changes
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div id="section-dashboard" class="content-section" style="display: block;">
            
            <?php if ($account_status === 'Pending'): ?>
                <div class="dashboard-card border-left-red text-center">
                    <h4 class="fw-bold text-dark mb-3">⏳ Account Pending Approval</h4>
                    <p class="mb-0 text-muted" style="font-size: 16px;">Your registration is currently being reviewed by the Barangay Admin. Once approved, you will unlock the ability to report waste hotspots and view your history. Check the Community Feed to see recent activities!</p>
                </div>
            <?php else: ?>
                
                <div class="d-flex flex-wrap gap-4 mb-4">
                    <div class="summary-box border-left-red">
                        <h6>My Pending Reports</h6>
                        <?php
                        $my_pending = $conn->query("SELECT COUNT(*) as count FROM waste_reports WHERE status='Pending' AND resident_id=$resident_id")->fetch_assoc();
                        echo "<h2 class='text-red'>" . $my_pending['count'] . "</h2>";
                        ?>
                    </div>
                    <div class="summary-box border-left-green">
                        <h6>My Cleaned Areas</h6>
                        <?php
                        $my_cleaned = $conn->query("SELECT COUNT(*) as count FROM waste_reports WHERE status='Cleaned' AND resident_id=$resident_id")->fetch_assoc();
                        echo "<h2 class='text-green'>" . $my_cleaned['count'] . "</h2>";
                    ?>
                    </div>
                    <div class="summary-box border-left-blue">
                        <h6>My Total Reports</h6>
                        <?php
                        $my_total = $conn->query("SELECT COUNT(*) as count FROM waste_reports WHERE resident_id=$resident_id")->fetch_assoc();
                        echo "<h2 class='text-blue'>" . $my_total['count'] . "</h2>";
                        ?>
                    </div>
                </div>
                
                <div class="dashboard-card" style="padding: 25px;">
                    <h3 style="margin-top: 0; color:#1B5E20; font-weight: 800; font-size: 18px;">Community Waste Map</h3>
                    <p style="color: #546E7A; font-size: 14px;">See all reported waste and cleaned areas across the barangay.</p>
                    <div id="communityMap" style="height: 400px; width: 100%; border-radius: 12px; border: 1px solid #CFD8DC; z-index: 1;"></div>
                </div>

            <?php endif; ?>
        </div>

        <div id="section-report" class="content-section" style="display: none;">
            <?php if ($account_status === 'Pending'): ?>
                <div class="alert alert-light text-center" style="color: #90A4AE; font-weight: 500; border: 1px dashed #CFD8DC;">
                    Your account must be approved by an Admin before you can submit a new waste report.
                </div>
            <?php else: ?>
                <div class="dashboard-card border-left-green" style="padding: 40px;">
                    <h4 style="color: #1B5E20; font-weight: 800; margin-bottom: 25px;">Reporting Station</h4>
                    
                    <form action="resident_dashboard.php" method="POST" enctype="multipart/form-data">
                        <div class="mb-4">
                            <label class="form-label">Description of Waste:</label>
                            <textarea name="description" class="form-control" rows="3" required placeholder="E.g., Plastic bottles washed up on the shore..."></textarea>
                        </div>
                        <div class="mb-4">
                            <label class="form-label">Upload 'Before' Photo:</label>
                            <input class="form-control" style="background-color: #fff;" type="file" name="photo_before" accept=".jpg,.jpeg,.png" required>
                            <img id="photoPreview" src="" style="display:none; width:100%; max-height:250px; object-fit:cover; border-radius:12px; border:2px solid #CFD8DC; margin-top:15px;">
                        </div>
                        <hr style="border-color: #CFD8DC; margin: 30px 0;">
                        
                        <h5 class="fw-bold mb-3" style="color: #263238;">Set Location</h5>
                        <input type="hidden" name="latitude" id="lat" required>
                        <input type="hidden" name="longitude" id="lng" required>
                        
                        <div class="mb-3 d-flex align-items-center">
                            <button type="button" onclick="getLocation()" class="btn-custom-outline me-3">📍 Auto-Detect My Location</button>
                            <span id="location_status" class="fw-bold small" style="color: #E53935;">Not pinned yet.</span>
                        </div>
                        <p class="small fw-bold mb-3" style="color: #78909C;">Or Pin Location Manually on the Map (Use the layers button for Satellite View!):</p>
                        
                        <div id="pinMap" style="height: 400px; width: 100%; border-radius: 12px; border: 1px solid #CFD8DC; z-index: 1; margin-bottom: 25px;"></div>
                        
                        <button type="submit" name="submit_report" id="submit_btn" class="btn-custom-success w-100 py-3 fs-5" disabled onclick="return confirm('Are you sure you want to submit this waste report? Please double-check your photo and map location.');">
    Submit Report to Barangay
</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <div id="section-history" class="content-section" style="display: none;">
            <div class="dashboard-card border-left-green" style="padding: 40px;">
                <h4 style="color: #1B5E20; font-weight: 800; margin-bottom: 25px;">My Reported Waste History</h4>
                
                <?php if ($account_status === 'Pending'): ?>
                    <div class="alert alert-light text-center" style="color: #90A4AE; font-weight: 500; border: 1px dashed #CFD8DC;">
                        Your account must be approved by an Admin before you can view report history.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                            <tbody>
                                <?php
                                $history_query = "SELECT * FROM waste_reports WHERE resident_id = $resident_id ORDER BY status ASC, report_id DESC";
                                $history = $conn->query($history_query);

                                if ($history->num_rows > 0) {
                                    while ($row = $history->fetch_assoc()) {
                                        echo "<tr>";
                                        echo "<td style='color: #455A64;'>" . htmlspecialchars($row['description']) . "</td>";
                                        
                                        $l_lat = isset($row['latitude']) ? $row['latitude'] : '0';
                                        $l_lng = isset($row['longitude']) ? $row['longitude'] : '0';
                                        echo "<td><a href='https://www.google.com/maps?q=" . $l_lat . "," . $l_lng . "' target='_blank' style='color:#1E88E5; text-decoration:none; font-weight:700;'>Map 📍</a></td>";
                                        
                                        echo "<td><a href='uploads/reports/" . $row['before_photo_path'] . "' target='_blank' class='badge bg-secondary text-decoration-none'>Before</a> ";
                                        if ($row['status'] === 'Cleaned' && !empty($row['after_photo_path'])) {
                                            echo "<br><a href='uploads/reports/" . $row['after_photo_path'] . "' target='_blank' class='badge bg-success text-decoration-none mt-1'>After</a>";
                                        }
                                        echo "</td>";
                                        
                                        $badgeColorClass = ($row['status'] == 'Pending') ? 'badge-pending' : 'badge-cleaned';
                                        echo "<td><span class='" . $badgeColorClass . "'>" . $row['status'] . "</span></td>";
                                        
                                        echo "</tr>";
                                    }
                                } else {
                                    echo "<tr><td colspan='4' style='text-align:center; padding: 30px; color: #90A4AE; font-weight: 500;'>You haven't submitted any waste reports yet.</td></tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div id="section-community" class="content-section" style="display: none;">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4 class="page-title mb-0" style="color: #1B5E20;">Community Clean-ups</h4>
                <p class="fw-bold mb-0" style="color: #78909C !important;">See how Barangay Tanza is taking action!</p>
            </div>

            <div class="row">
                <?php
                $feed_query = $conn->query("SELECT description, before_photo_path, after_photo_path, created_at FROM waste_reports WHERE status = 'Cleaned' AND after_photo_path IS NOT NULL AND after_photo_path != '' ORDER BY created_at DESC LIMIT 10");
                
                if ($feed_query->num_rows > 0) {
                    while ($feed = $feed_query->fetch_assoc()) {
                        ?>
                        <div class="col-md-6 mb-4">
                            <div class="dashboard-card h-100 border-left-green" style="margin-bottom: 0;">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <span class="badge-cleaned">✅ Area Cleaned</span>
                                    <small style="color: #90A4AE; font-weight: 700;"><?php echo date("M d, Y", strtotime($feed['created_at'])); ?></small>
                                </div>
                                <p style="color: #455A64; font-size: 15px; margin-bottom: 20px;">"<?php echo htmlspecialchars($feed['description']); ?>"<br><i style="color: #90A4AE; font-size: 13px;">— Reported by a concerned resident</i></p>
                                
                                <div class="row g-3 mt-auto">
                                    <div class="col-6">
                                        <img src="uploads/reports/<?php echo $feed['before_photo_path']; ?>" style="height: 160px; width: 100%; object-fit: cover; border-radius: 8px; border: 1px solid #CFD8DC;">
                                        <div class="text-center fw-bold mt-2" style="font-size: 12px; color: #E53935;">🔴 BEFORE</div>
                                    </div>
                                    <div class="col-6">
                                        <img src="uploads/reports/<?php echo $feed['after_photo_path']; ?>" style="height: 160px; width: 100%; object-fit: cover; border-radius: 8px; border: 1px solid #CFD8DC;">
                                        <div class="text-center fw-bold mt-2" style="font-size: 12px; color: #43A047;">🟢 AFTER</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php
                    }
                } else {
                    echo '<div class="col-12"><div class="dashboard-card text-center" style="color: #90A4AE; font-weight: 500;">No clean-ups posted yet. Be the first to report an area!</div></div>';
                }
                ?>
            </div>
        </div>

        <div id="section-info" class="content-section" style="display: none;">
            <div class="row align-items-stretch">
                
                <div class="col-md-6 mb-4">
                    <div class="dashboard-card border-left-red" style="padding: 35px; height: 100%;">
                        <h4 style="color: #C62828; font-weight: 800; margin-bottom: 20px;">Emergency Hotlines</h4>
                        <p style="font-size: 15px; color: #455A64; line-height: 1.8;">
                            <?php echo nl2br(htmlspecialchars($info['emergency_hotlines'])); ?>
                        </p>
                    </div>
                </div>

                <div class="col-md-6 mb-4">
                    <div class="dashboard-card border-left-green" style="padding: 35px; height: 100%;">
                        <h4 style="color: #1B5E20; font-weight: 800; margin-bottom: 20px;">Barangay Information</h4>
                        <ul style="list-style: none; padding: 0; color: #455A64; line-height: 2;">
                            <li><strong>Punong Barangay:</strong> <?php echo htmlspecialchars($info['captain_name']); ?></li>
                            <li><strong>Barangay Secretary:</strong> <?php echo htmlspecialchars($info['secretary_name']); ?></li>
                            <li><strong>Office Hours:</strong> <?php echo htmlspecialchars($info['office_hours']); ?></li>
                            <li><strong>Address:</strong> <?php echo htmlspecialchars($info['full_address']); ?></li>
                        </ul>
                    </div>
                </div>

            </div>
        </div>

    </div> 
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // GLOBAL MAP VARIABLES
        var submitMap = null;
        var currentPin = null;
        var communityMap = null;
        
        var mapData = <?php echo json_encode($map_data); ?>;
        var redPin = new L.Icon({ iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-red.png', shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png', iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34], shadowSize: [41, 41] });
        var greenPin = new L.Icon({ iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-green.png', shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png', iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34], shadowSize: [41, 41] });

        // INITIALIZE NEW REPORT MAP
        var mapContainer = document.getElementById('pinMap');
        if(mapContainer) {
            submitMap = L.map('pinMap').setView([11.45, 123.15], 13);
            var streetView = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 });
            var satelliteView = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19 });
            streetView.addTo(submitMap);
            L.control.layers({"🗺️ Street View": streetView, "🛰️ Satellite View": satelliteView}).addTo(submitMap);

            submitMap.on('click', function(e) {
                var click_lat = e.latlng.lat;
                var click_lng = e.latlng.lng;
                if (currentPin) { submitMap.removeLayer(currentPin); }
                currentPin = L.marker([click_lat, click_lng]).addTo(submitMap);
                document.getElementById('lat').value = click_lat;
                document.getElementById('lng').value = click_lng;
                document.getElementById("location_status").innerHTML = "Map Pinned Manually! ✔️";
                document.getElementById("location_status").style.color = "#1E88E5";
                document.getElementById("submit_btn").disabled = false; 
            });
        }

        // INITIALIZE COMMUNITY MAP
        function initCommunityMap() {
            if (!communityMap && document.getElementById('communityMap')) {
                communityMap = L.map('communityMap').setView([11.45, 123.15], 13);
                var streetView = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 });
                var satelliteView = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19 });
                streetView.addTo(communityMap);
                L.control.layers({"🗺️ Street View": streetView, "🛰️ Satellite View": satelliteView}).addTo(communityMap);

                mapData.forEach(function(loc) {
                    var currentIcon = (loc.status === 'Pending') ? redPin : greenPin; 
                    var marker = L.marker([loc.latitude, loc.longitude], {icon: currentIcon}).addTo(communityMap);
                    var dateObj = new Date(loc.created_at);
                    var dateStr = dateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

                    var hoverCard = `
                        <div style="text-align:center; min-width: 160px; padding: 5px;">
                            <img src="uploads/reports/${loc.before_photo_path}" style="width: 100%; height: 100px; object-fit: cover; border-radius: 5px; margin-bottom: 8px; border: 1px solid #ccc;">
                            <div style="font-size: 14px; color: ${(loc.status === 'Pending') ? '#dc3545' : '#28a745'}; font-weight: bold; margin-bottom: 5px;">
                                ${(loc.status === 'Pending') ? '🔴 Pending Report' : '✅ Cleaned Area'}
                            </div>
                            <div style="font-size: 12px; text-align: left; line-height: 1.4;">
                                <b>Date:</b> ${dateStr}<br>
                                <b>Desc:</b> <span style="color:#555;">"${loc.description}"</span>
                            </div>
                        </div>
                    `;
                    marker.bindTooltip(hoverCard, { direction: 'top', opacity: 1, className: 'custom-hover-card' });
                });
            }
        }

        // TAB SWITCHING LOGIC
        window.onload = function() {
            var urlParams = new URLSearchParams(window.location.search);
            var viewToOpen = urlParams.get('view');
            if (viewToOpen) { switchTab(viewToOpen); } else { switchTab('dashboard'); }
        };

        function switchTab(tabName) {
            document.querySelectorAll('.content-section').forEach(function(section) {
                section.style.display = 'none';
            });
            
            document.getElementById('tab-dashboard').classList.remove('active');
            document.getElementById('tab-report').classList.remove('active');
            document.getElementById('tab-history').classList.remove('active');
            document.getElementById('tab-community').classList.remove('active');
            document.getElementById('tab-info').classList.remove('active');
            
            var targetSection = document.getElementById('section-' + tabName);
            if(targetSection) targetSection.style.display = 'block';
            
            var targetTab = document.getElementById('tab-' + tabName);
            if(targetTab) targetTab.classList.add('active');

            if (tabName === 'dashboard') {
                document.getElementById('page-title').innerText = "Resident Dashboard";
                setTimeout(function(){ 
                    initCommunityMap();
                    if(communityMap) communityMap.invalidateSize(); 
                }, 300); 
            } else if (tabName === 'report') {
                document.getElementById('page-title').innerText = "Submit a Waste Report";
                setTimeout(function(){ if(submitMap) submitMap.invalidateSize(); }, 300); 
            } else if (tabName === 'history') {
                document.getElementById('page-title').innerText = "My Reports";
            } else if (tabName === 'community') {
                document.getElementById('page-title').innerText = "Community Feed";
            } else if (tabName === 'info') {
                document.getElementById('page-title').innerText = "Emergency & Barangay Info";
            }
        }

        // CLOCK AND NOTIFICATIONS
        setInterval(function() {
            var now = new Date();
            document.getElementById('liveClock').innerText = now.toLocaleTimeString();
        }, 1000);

        var notifBtn = document.getElementById("notifBtn");
        if(notifBtn) {
            notifBtn.onclick = function() {
                var box = document.getElementById("notifBox");
                box.style.display = (box.style.display === "none" || box.style.display === "") ? "block" : "none";
            }
        }

        // GEOLOCATION
        function getLocation() {
            if (navigator.geolocation) {
                document.getElementById("location_status").innerHTML = "Locating...";
                document.getElementById("location_status").style.color = "#FF9800";
                
                navigator.geolocation.getCurrentPosition(showPosition, showError, { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 });
            } else { 
                alert("Geolocation is not supported by your browser."); 
            }
        }

        function showPosition(position) {
            document.getElementById("lat").value = position.coords.latitude;
            document.getElementById("lng").value = position.coords.longitude;
            document.getElementById("location_status").innerHTML = "Location Pinned! ✔️";
            document.getElementById("location_status").style.color = "#43A047";
            document.getElementById("submit_btn").disabled = false;
            
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
            document.getElementById("location_status").style.color = "#E53935";
        }

        // PHOTO PREVIEW
        var photoInput = document.querySelector('input[name="photo_before"]');
        if(photoInput) {
            photoInput.addEventListener('change', function(event) {
                var file = event.target.files[0];
                if (!file) return;
                if (file.size > 5242880) {
                    alert("⚠️ Image is too large (over 5MB).");
                    this.value = ""; document.getElementById('photoPreview').style.display = 'none'; return; 
                }
                var reader = new FileReader();
                reader.onload = function(e) {
                    var img = document.getElementById('photoPreview');
                    img.src = e.target.result; img.style.display = 'block'; 
                }
                reader.readAsDataURL(file);
            });
        }
    </script>
</body>
</html>
