<?php
session_start();
include 'db_connect.php';

// -----------------------------------------------------------------------------
// 1. SECURITY CHECK
// -----------------------------------------------------------------------------
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login.php");
    exit();
}

$admin_id = $_SESSION['user_id'];
$admin_data = $conn->query("SELECT full_name FROM users WHERE user_id = $admin_id")->fetch_assoc();
$admin_name = $admin_data['full_name'] ?? 'Admin User';

// -----------------------------------------------------------------------------
// 2. FETCH BARANGAY INFO
// -----------------------------------------------------------------------------
$check_info = $conn->query("SELECT * FROM barangay_information LIMIT 1");
if ($check_info && $check_info->num_rows > 0) {
    $info = $check_info->fetch_assoc();
} else {
    $conn->query("INSERT INTO barangay_information (barangay_name) VALUES ('Barangay Tanza')");
    $check_info = $conn->query("SELECT * FROM barangay_information LIMIT 1");
    $info = $check_info->fetch_assoc();
}
$info_id = $info['id'];

// -----------------------------------------------------------------------------
// 3. BACKEND ACTION HANDLERS
// -----------------------------------------------------------------------------
if (isset($_POST['send_basura_alert'])) {
    $purok = $_POST['purok_area'];
    $message = $_POST['alert_message'];
    $stmt = $conn->prepare("INSERT INTO basura_alerts (purok_area, admin_id, message) VALUES (?, ?, ?)");
    $stmt->bind_param("sis", $purok, $admin_id, $message);
    if ($stmt->execute()) {
        echo "<script>alert('Basura-Alert broadcasted!'); window.location.href='admin_dashboard.php?view=alert';</script>";
        exit();
    }
}

if (isset($_GET['resident_action']) && isset($_GET['id'])) {
    $target_id = intval($_GET['id']);
    $action = $_GET['resident_action'];
    
    $return_view = isset($_GET['return']) ? $_GET['return'] : 'residents';
    
    if ($action === 'approve') {
        $conn->query("UPDATE users SET account_status='Approved' WHERE user_id=$target_id");
        echo "<script>window.location.href='admin_dashboard.php?view=" . $return_view . "';</script>";
    } elseif ($action === 'reject') {
        $conn->query("UPDATE users SET account_status='Rejected' WHERE user_id=$target_id");
        echo "<script>window.location.href='admin_dashboard.php?view=" . $return_view . "';</script>";
    }
    exit();
}

if (isset($_POST['delete_resident_id'])) {
    $del_id = intval($_POST['delete_resident_id']);
    $conn->query("DELETE FROM users WHERE user_id = $del_id AND role = 'Resident'");
    echo "<script>window.location.href='admin_dashboard.php?view=residents&status=deleted';</script>";
    exit();
}

$update_success = false;
if (isset($_POST['update_info'])) {
    $b_name      = $_POST['barangay_name'];
    $captain     = $_POST['captain_name'];
    $secretary   = $_POST['secretary_name']; 
    $contact     = $_POST['contact_number'];
    $address     = $_POST['full_address'];
    $zip         = $_POST['zip_code'];
    $region      = $_POST['region'];
    $subtitle    = $_POST['system_subtitle'];
    $description = $_POST['system_description'];
    $city        = $_POST['city'];
    $municipal   = $_POST['municipal'];
    $office_hrs  = $_POST['office_hours'];
    $hotlines    = $_POST['emergency_hotlines'];
    $pnp         = $conn->real_escape_string($_POST['pnp_hotline']);
    $bfp         = $conn->real_escape_string($_POST['bfp_hotline']);
    $mdrrmo      = $conn->real_escape_string($_POST['mdrrmo_hotline']);
    $health      = $conn->real_escape_string($_POST['health_center_hotline']);
    $logo_file_name = isset($info['logo_path']) ? $info['logo_path'] : '';

    if (!empty($_FILES["barangay_logo"]["name"])) {
        $target_dir = "uploads/logo/";
        if (!is_dir($target_dir)) { mkdir($target_dir, 0777, true); }
        $new_logo = time() . "_" . basename($_FILES["barangay_logo"]["name"]);
        $target_file = $target_dir . $new_logo;
        if (in_array(strtolower(pathinfo($target_file, PATHINFO_EXTENSION)), ["jpg", "jpeg", "png"])) {
            if (move_uploaded_file($_FILES["barangay_logo"]["tmp_name"], $target_file)) {
                $logo_file_name = $new_logo;
            }
        }
    }

    $stmt = $conn->prepare("UPDATE barangay_information SET barangay_name=?, captain_name=?, secretary_name=?, contact_number=?, full_address=?, zip_code=?, region=?, logo_path=?, system_subtitle=?, system_description=?, city=?, municipal=?, office_hours=?, emergency_hotlines=?, pnp_hotline=?, bfp_hotline=?, mdrrmo_hotline=?, health_center_hotline=? WHERE id=?");
    $stmt->bind_param("ssssssssssssssssssi", $b_name, $captain, $secretary, $contact, $address, $zip, $region, $logo_file_name, $subtitle, $description, $city, $municipal, $office_hrs, $hotlines, $pnp, $bfp, $mdrrmo, $health, $info_id);
    
    if ($stmt->execute()) {
        $update_success = true;
        $info = $conn->query("SELECT * FROM barangay_information LIMIT 1")->fetch_assoc();
    }
}

$map_query = $conn->query("SELECT w.report_id, w.description, w.latitude, w.longitude, w.status, w.before_photo_path, w.created_at, u.full_name FROM waste_reports w JOIN users u ON w.resident_id = u.user_id WHERE w.latitude IS NOT NULL AND w.longitude IS NOT NULL");
$map_data = [];
while($row = $map_query->fetch_assoc()) { $map_data[] = $row; }
$view = isset($_GET['view']) ? $_GET['view'] : 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard | Coastal Land Watch</title>

  <!-- Google Fonts: Inter -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  
  <!-- Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

  <style>
    /* ====================================================
       HAZE DASHBOARD STYLE
       ==================================================== */
    :root {
        --bg-body: #f3f4f6; 
        --bg-surface: #ffffff; 
        --sidebar-bg: #111827; 
        --sidebar-hover: #1f2937;
        --sidebar-text: #9ca3af;
        --sidebar-text-active: #ffffff;
        
        --primary: #059669; 
        --primary-light: #ecfdf5;
        --primary-dark: #047857;
        
        --text-main: #111827;
        --text-muted: #6b7280;
        --border-color: #e5e7eb;
        
        --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
        --radius-card: 12px;
    }

    body { font-family: 'Inter', sans-serif; background-color: var(--bg-body); color: var(--text-main); margin: 0; padding: 0; overflow-x: hidden; font-size: 14px; }
    
    /* 1. APP LAYOUT */
    .app-container { display: flex; min-height: 100vh; }
    
    /* 2. SIDEBAR */
    .sidebar { width: 260px; background: var(--sidebar-bg); display: flex; flex-direction: column; position: fixed; top: 0; bottom: 0; left: 0; z-index: 50; }
    
    .workspace-header { padding: 24px 20px; display: flex; align-items: center; justify-content: center; }
    .workspace-brand { display: flex; flex-direction: column; align-items: center; gap: 12px; width: 100%; text-align: center;}
    .workspace-logo { width: 64px; height: 64px; border-radius: 50%; object-fit: cover; box-shadow: 0 0 0 2px rgba(255,255,255,0.1); }
    .workspace-text h2 { margin: 0; font-size: 15px; font-weight: 600; color: var(--sidebar-text-active); line-height: 1.2; }
    
    .sidebar-menu { padding: 10px 16px; flex: 1; overflow-y: auto; }
    
    .menu-label { 
        font-size: 11px; 
        color: #4b5563; 
        font-weight: 600; 
        padding: 16px 16px 8px; 
        text-transform: uppercase; 
        letter-spacing: 0.5px; 
    }
    
    /* SIDEBAR ACTIVE DESIGN */
    .nav-link { 
        display: flex; 
        align-items: center; 
        gap: 12px; 
        padding: 12px 16px; 
        padding-left: 12px; 
        color: var(--sidebar-text); 
        text-decoration: none; 
        border-radius: 6px; 
        font-size: 14px; 
        font-weight: 500; 
        transition: 0.2s; 
        margin-bottom: 4px; 
        border-left: 4px solid transparent; 
    }
    
    /* Forced fixed width for icons to prevent layout collapse */
    .nav-link i { 
        font-size: 20px; 
        display: inline-block; 
        width: 20px; 
        text-align: center;
    }
    
    .nav-link:hover { 
        background: var(--sidebar-hover); 
        color: var(--sidebar-text-active); 
    }
    
    .nav-link.active { 
        background: rgba(5, 150, 105, 0.15); 
        color: #34d399; 
        border-left: 4px solid var(--primary); 
        border-radius: 4px 8px 8px 4px; 
        font-weight: 600;
    }
    
    .nav-link.active i { 
        color: #34d399; 
    }
    
    .user-footer { padding: 20px; border-top: 1px solid #1f2937; display: flex; align-items: center; justify-content: space-between; }
    .user-info { display: flex; align-items: center; gap: 12px; }
    .user-avatar { width: 36px; height: 36px; border-radius: 50%; background: var(--primary); color: #fff; display: flex; justify-content: center; align-items: center; font-weight: 600; font-size: 14px; }
    .user-text h4 { margin: 0; font-size: 13px; font-weight: 600; color: var(--sidebar-text-active); }
    .user-text p { margin: 0; font-size: 11px; color: var(--sidebar-text); }
    .user-footer a { color: var(--sidebar-text); transition: 0.2s; }
    .user-footer a:hover { color: #ef4444; }

    /* 3. MAIN CONTENT */
    .main-wrapper { flex: 1; margin-left: 260px; display: flex; flex-direction: column; }
    
    /* Topbar - GRADIENT BACKGROUND */
    .topbar { 
        background: linear-gradient(135deg, #064e3b 0%, #0f766e 100%);
        border-bottom: none; 
        height: 70px; 
        padding: 0 32px; 
        display: flex; 
        justify-content: space-between; 
        align-items: center; 
    }
    
    .topbar-left { 
        display: flex; 
        align-items: center; 
        gap: 8px; 
        color: #ffffff; 
        font-size: 15px; 
        font-weight: 600; 
    }
    
    .topbar-left i { 
        color: #a7f3d0; 
        font-size: 22px; 
    }
    
    .topbar-right { display: flex; align-items: center; gap: 20px; }
    
    .topbar-icons { display: flex; gap: 16px; color: #ffffff; font-size: 20px; align-items: center; }
    
    /* Improved Notification Button */
    .notification-btn {
        position: relative;
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s ease;
        background: rgba(255, 255, 255, 0.05);
    }
    .notification-btn:hover {
        background: rgba(255, 255, 255, 0.15);
        color: #a7f3d0;
    }
    .notification-btn::after { 
        content: '4'; 
        position: absolute; 
        top: 4px; 
        right: 4px; 
        background: #ef4444; 
        color: white; 
        font-size: 10px; 
        font-weight: bold; 
        width: 16px; 
        height: 16px; 
        display: flex; 
        align-items: center; 
        justify-content: center; 
        border-radius: 50%; 
        border: 2px solid #064e3b; 
    }

    /* Live Date/Time Widget - Adjusted for Dark Background */
    .live-datetime {
        display: flex;
        align-items: center;
        gap: 8px;
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.2);
        padding: 8px 14px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 500;
        color: #ffffff;
    }
    .live-datetime i { color: #a7f3d0; font-size: 16px; }

    /* Content Area */
    .content-area { padding: 32px; flex: 1; }
    
    /* Welcome Banner */
    .welcome-banner {
        background: linear-gradient(135deg, #064e3b 0%, #0f766e 100%);
        border-radius: var(--radius-card);
        padding: 32px;
        color: white;
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
        box-shadow: var(--shadow-md);
    }
    .welcome-text h1 { margin: 0 0 8px 0; font-size: 28px; font-weight: 700; letter-spacing: -0.5px; }
    .welcome-text p { margin: 0; font-size: 14px; color: #a7f3d0; }
    
    /* Improved Welcome Button */
    .welcome-btn { 
        background: rgba(255, 255, 255, 0.1); 
        color: white; 
        border: 1px solid rgba(255, 255, 255, 0.2); 
        padding: 10px 20px; 
        border-radius: 8px; 
        font-size: 14px; 
        font-weight: 500; 
        cursor: pointer; 
        transition: all 0.2s ease; 
        display: flex; 
        align-items: center; 
        gap: 8px; 
        text-decoration: none;
    }
    .welcome-btn:hover { 
        background: rgba(255, 255, 255, 0.25); 
        border-color: rgba(255, 255, 255, 0.4);
        transform: translateY(-1px);
    }

    /* 4. CARDS */
    .flat-card { background: var(--bg-surface); border: none; border-radius: var(--radius-card); padding: 24px; margin-bottom: 24px; box-shadow: var(--shadow-md); }
    .card-title { font-size: 15px; font-weight: 600; color: var(--text-main); margin: 0 0 20px 0; display: flex; justify-content: space-between; align-items: center; }
    
    /* Stats Grid */
    .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 24px; }
    .stat-box { background: var(--bg-surface); border-radius: var(--radius-card); padding: 20px; box-shadow: var(--shadow-md); display: flex; flex-direction: column; transition: 0.2s; cursor: default; }
    .stat-box:hover { box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05); transform: translateY(-2px); }
    
    .stat-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
    .stat-header span { font-size: 14px; font-weight: 500; color: var(--text-muted); }
    
    /* Improved Stat Icon Buttons */
    .stat-icon { 
        width: 42px; 
        height: 42px; 
        border-radius: 12px; 
        background: var(--primary-light); 
        color: var(--primary); 
        display: flex; 
        align-items: center; 
        justify-content: center; 
        font-size: 22px; 
        transition: all 0.3s ease;
    }
    .stat-box:hover .stat-icon {
        background: var(--primary);
        color: #ffffff;
    }
    
    .stat-body h2 { margin: 0 0 8px 0; font-size: 28px; font-weight: 700; color: var(--text-main); line-height: 1; }
    .stat-footer { font-size: 12px; display: flex; align-items: center; gap: 4px; font-weight: 500; }
    .stat-footer.up { color: var(--primary); }
    .stat-footer.down { color: #ef4444; }
    .stat-footer.neutral { color: var(--text-muted); }

    /* 5. TABLES */
    .table-controls { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0; padding-bottom: 20px; border-bottom: 1px solid var(--border-color); margin-bottom: 20px; }
    .flat-input { border: 1px solid var(--border-color); border-radius: 8px; padding: 10px 14px; font-size: 14px; outline: none; transition: 0.2s; font-family: inherit; color: var(--text-main); background: var(--bg-body); }
    .flat-input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); background: #fff; }
    
    .flat-btn { background: var(--bg-surface); border: 1px solid var(--border-color); color: var(--text-main); padding: 10px 16px; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
    .flat-btn:hover { background: var(--bg-body); color: var(--text-main); }
    .flat-btn.primary { background: var(--primary); color: #fff; border-color: var(--primary); }
    .flat-btn.primary:hover { background: var(--primary-dark); }
    .flat-btn.danger { color: #dc2626; border-color: #fca5a5; background: #fef2f2; }
    .flat-btn.danger:hover { background: #fee2e2; }

    /* Clean Table Styling */
    .flat-table { width: 100%; border-collapse: collapse; }
    .flat-table th { 
        text-align: left; 
        padding: 16px; 
        background-color: transparent; 
        font-size: 13px; 
        font-weight: 700; 
        color: var(--primary); 
        text-transform: uppercase; 
        letter-spacing: 0.5px; 
        border-bottom: 1px solid var(--border-color); 
    }
    
    .flat-table td { padding: 16px; border-bottom: 1px solid var(--border-color); font-size: 14px; vertical-align: middle; color: var(--text-main); transition: 0.2s; }
    .flat-table tr:last-child td { border-bottom: none; }
    .flat-table tr:hover td { background-color: #f9fafb; }

    /* Status Badges */
    .status-badge { padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; }
    .status-badge.pending { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
    .status-badge.active { background: var(--primary); color: #ffffff; border: 1px solid var(--primary-dark); }
    
    /* Reporter Avatar */
    .reporter-cell { display: flex; align-items: center; gap: 12px; }
    .reporter-avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--primary-light); color: var(--primary); display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 13px; }
    
    /* Photo Links */
    .photo-link { display: inline-flex; align-items: center; gap: 4px; text-decoration: none; font-size: 12px; font-weight: 600; padding: 4px 10px; border-radius: 6px; transition: 0.2s; margin-right: 6px; }
    .photo-link.before { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
    .photo-link.before:hover { background: #fee2e2; }
    .photo-link.after { background: var(--primary-light); color: var(--primary-dark); border: 1px solid #a7f3d0; }
    .photo-link.after:hover { background: #d1fae5; }

    /* Modern Segmented Tabs */
    .flat-tabs { display: flex; gap: 8px; }
    .tab-btn { background: transparent; border: 1px solid transparent; font-size: 14px; font-weight: 500; color: var(--text-muted); padding: 8px 16px; border-radius: 8px; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: center; gap: 8px; }
    .tab-btn i { font-size: 18px; }
    .tab-btn:hover { color: var(--text-main); background: var(--bg-body); }
    .tab-btn.active { background: var(--bg-surface); color: var(--primary); font-weight: 600; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid var(--border-color); }

    /* Forms */
    .form-group { margin-bottom: 24px; }
    .form-label { display: flex; align-items: center; gap: 6px; font-size: 14px; font-weight: 500; margin-bottom: 8px; color: var(--text-main); }
    .form-label i { color: var(--primary); font-size: 16px; }
    .form-control { width: 100%; border: 1px solid var(--border-color); border-radius: 8px; padding: 12px 14px; font-size: 14px; font-family: inherit; transition: 0.2s; box-sizing: border-box; background: var(--bg-body); }
    .form-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); background: #fff; }
    textarea.form-control { resize: vertical; min-height: 120px; line-height: 1.5; }

    /* ====================================================
       ALERT SPECIFIC STYLES
       ==================================================== */
    .alert-banner { 
        background: #fffbeb; 
        border: 1px solid #fde68a; 
        border-left: 4px solid #f59e0b;
        color: #b45309; 
        padding: 16px; 
        border-radius: 8px; 
        margin-bottom: 24px; 
        display: flex; 
        align-items: flex-start; 
        gap: 12px; 
        font-size: 14px; 
    }
    .alert-banner i { font-size: 20px; margin-top: 1px; color: #d97706; }
    
    /* Modern List Items for History */
    .history-list { display: flex; flex-direction: column; gap: 12px; padding: 16px; }
    .history-item { 
        padding: 16px; 
        border: 1px solid var(--border-color); 
        border-radius: 8px; 
        display: flex; 
        flex-direction: column; 
        gap: 8px; 
        transition: 0.2s; 
        background: var(--bg-surface);
    }
    .history-item:hover { 
        border-color: var(--primary-light); 
        box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        background: #fafafa;
    }
    .history-header { display: flex; justify-content: space-between; align-items: center; font-size: 13px; }
    .history-target { font-weight: 600; color: var(--text-main); display: flex; align-items: center; gap: 6px; }
    .history-target i { color: var(--primary); font-size: 16px; }
    .history-time { color: var(--text-muted); font-size: 12px; display: flex; align-items: center; gap: 4px; }
    .history-msg { font-size: 14px; color: var(--text-muted); line-height: 1.5; }

    /* Widget list override */
    .widget-list { list-style: none; padding: 0; margin: 0; }
    .widget-list li:last-child { border-bottom: none !important; padding-bottom: 0 !important; margin-bottom: 0 !important; }

  </style>
</head>
<body>

<div class="app-container">

    <!-- SIDEBAR -->
    <aside class="sidebar">
        <div class="workspace-header">
            <div class="workspace-brand">
                <img src="tanzalogo.jpg" alt="Barangay Tanza Logo" class="workspace-logo">
                <div class="workspace-text">
                    <h2>Brgy. Coastal Watch</h2>
                </div>
            </div>
        </div>

        <div class="sidebar-menu">
            <div class="menu-label">Overview</div>
            <a href="?view=dashboard" class="nav-link <?php echo $view == 'dashboard' ? 'active' : ''; ?>">
                <i class="ti ti-layout-grid"></i> Dashboard
            </a>
            <a href="?view=reports" class="nav-link <?php echo $view == 'reports' ? 'active' : ''; ?>">
                <i class="ti ti-file-report"></i> Waste Reports
            </a>
            
            <div class="menu-label" style="margin-top: 16px;">Community</div>
            <a href="?view=residents" class="nav-link <?php echo $view == 'residents' ? 'active' : ''; ?>">
                <i class="ti ti-users"></i> Residents
            </a>
            <a href="?view=alert" class="nav-link <?php echo $view == 'alert' ? 'active' : ''; ?>">
                <i class="ti ti-speakerphone"></i> Basura-Alert
            </a>
            
            <div class="menu-label" style="margin-top: 16px;">Configuration</div>
            <a href="?view=settings" class="nav-link <?php echo $view == 'settings' ? 'active' : ''; ?>">
                <i class="ti ti-settings"></i> Settings
            </a>
        </div>

        <div class="user-footer">
            <div class="user-info">
                <div class="user-avatar"><?php echo strtoupper(substr($admin_name, 0, 1)); ?></div>
                <div class="user-text">
                    <h4><?php echo htmlspecialchars($admin_name); ?></h4>
                    <p>Administrator</p>
                </div>
            </div>
            <a href="logout.php" onclick="return confirm('Logout of system?');" title="Logout"><i class="ti ti-logout"></i></a>
        </div>
    </aside>

    <!-- MAIN WRAPPER -->
    <div class="main-wrapper">
        
        <!-- TOPBAR -->
        <header class="topbar">
            <div class="topbar-left">
                <i class="ti ti-layout-dashboard"></i>
                <?php 
                    if($view=='dashboard') echo "Dashboard";
                    elseif($view=='reports') echo "Waste Reports";
                    elseif($view=='residents') echo "Resident Management";
                    elseif($view=='alert') echo "Basura-Alert Broadcast";
                    elseif($view=='settings') echo "System Settings";
                ?>
            </div>
            <div class="topbar-right">
                <div class="live-datetime" id="liveDateTime">
                    <i class="ti ti-clock"></i> Loading...
                </div>
                <div class="topbar-icons">
                    <!-- Improved Notification Button -->
                    <div class="notification-btn" title="Notifications">
                        <i class="ti ti-bell"></i>
                    </div>
                </div>
            </div>
        </header>

        <!-- CONTENT AREA -->
        <main class="content-area">
            
            <?php if ($view == 'dashboard'): ?>
            <!-- Welcome Banner -->
            <div class="welcome-banner">
                <div class="welcome-text">
                    <h1>Welcome back, <?php echo htmlspecialchars(explode(' ', $admin_name)[0]); ?>!</h1>
                    <p>Here's what's happening in Barangay Coastal and Land Watch today.</p>
                </div>
                <button class="welcome-btn" onclick="window.location.href='?view=reports'">
                    View Reports <i class="ti ti-arrow-right"></i>
                </button>
            </div>

            <div class="stats-grid">
                <!-- Pending Reports -->
                <div class="stat-box">
                    <div class="stat-header">
                        <span>Pending Reports</span> 
                        <div class="stat-icon"><i class="ti ti-clipboard-text"></i></div>
                    </div>
                    <div class="stat-body">
                        <h2><?php echo $conn->query("SELECT COUNT(*) as count FROM waste_reports WHERE status='Pending'")->fetch_assoc()['count']; ?></h2>
                        <div class="stat-footer down"><i class="ti ti-trending-up"></i> +2 vs last week</div>
                    </div>
                </div>

                <!-- Cleaned Areas -->
                <div class="stat-box">
                    <div class="stat-header">
                        <span>Cleaned Areas</span> 
                        <div class="stat-icon"><i class="ti ti-check"></i></div>
                    </div>
                    <div class="stat-body">
                        <h2><?php echo $conn->query("SELECT COUNT(*) as count FROM waste_reports WHERE status='Cleaned'")->fetch_assoc()['count']; ?></h2>
                        <div class="stat-footer up"><i class="ti ti-trending-up"></i> +12% vs last month</div>
                    </div>
                </div>

                <!-- Active Residents -->
                <div class="stat-box">
                    <div class="stat-header">
                        <span>Active Residents</span> 
                        <div class="stat-icon"><i class="ti ti-users"></i></div>
                    </div>
                    <div class="stat-body">
                        <h2><?php echo $conn->query("SELECT COUNT(*) as count FROM users WHERE role='Resident'")->fetch_assoc()['count']; ?></h2>
                        <div class="stat-footer neutral"><i class="ti ti-minus"></i> No change vs last month</div>
                    </div>
                </div>

                <!-- Total Reports -->
                <div class="stat-box">
                    <div class="stat-header">
                        <span>Total Logs</span> 
                        <div class="stat-icon"><i class="ti ti-file-analytics"></i></div>
                    </div>
                    <div class="stat-body">
                        <h2><?php echo $conn->query("SELECT COUNT(*) as count FROM waste_reports")->fetch_assoc()['count']; ?></h2>
                        <div class="stat-footer up"><i class="ti ti-trending-up"></i> +4 vs last week</div>
                    </div>
                </div>
            </div>

            <!-- Dashboard Split Layout (Map + Widgets) -->
            <div style="display: grid; grid-template-columns: 2.2fr 1fr; gap: 24px; align-items: stretch; margin-bottom: 24px;">
                
                <!-- LEFT COLUMN: Minimized Map -->
                <div class="flat-card" style="margin-bottom: 0; display: flex; flex-direction: column; padding: 0; overflow: hidden;">
                    <div class="card-title" style="padding: 24px 24px 16px 24px; margin: 0;">
                        <div>
                            <div style="font-size: 16px; color: var(--text-main);">Barangay Map Activity</div>
                            <div style="font-size: 13px; color: var(--text-muted); font-weight: 400; margin-top: 4px;">Real-time geographical tracking</div>
                        </div>
                        <div style="display: flex; gap: 16px; font-size: 13px; font-weight: 500;">
                            <span style="display: flex; align-items: center; gap: 6px;"><span style="width: 10px; height: 10px; background: #ef4444; border-radius: 50%;"></span> Pending</span>
                            <span style="display: flex; align-items: center; gap: 6px;"><span style="width: 10px; height: 10px; background: var(--primary); border-radius: 50%;"></span> Cleaned</span>
                        </div>
                    </div>
                    <div id="masterMap" style="flex: 1; min-height: 400px; width: 100%; z-index: 1;"></div>
                </div>

                <!-- RIGHT COLUMN: Stacked Widgets -->
                <div style="display: flex; flex-direction: column; gap: 24px;">
                    
                    <!-- Widget 1: Pending Approvals -->
                    <div class="flat-card" style="margin-bottom: 0; flex: 1; display: flex; flex-direction: column; padding: 20px;">
                        <div class="card-title" style="margin-bottom: 12px; padding: 0;">
                            <div style="font-size: 15px;">Pending Approvals</div>
                            <a href="?view=residents" style="font-size: 13px; color: var(--primary); text-decoration: none; font-weight: 500;">View All</a>
                        </div>
                        <ul class="widget-list">
                            <?php
                            $pending_users = $conn->query("SELECT * FROM users WHERE role='Resident' AND account_status='Pending' ORDER BY user_id DESC LIMIT 3");
                            if($pending_users && $pending_users->num_rows > 0) {
                                while($u = $pending_users->fetch_assoc()) {
                                    $initial = strtoupper(substr($u['full_name'], 0, 1));
                                    echo "<li style='display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid var(--border-color);'>";
                                    echo "  <div style='display: flex; align-items: center; gap: 12px;'>";
                                    echo "      <div style='width: 36px; height: 36px; border-radius: 50%; background: var(--primary-light); color: var(--primary); display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 14px;'>{$initial}</div>";
                                    echo "      <div><h4 style='margin: 0; font-size: 14px; font-weight: 600; color: var(--text-main);'>" . htmlspecialchars($u['full_name']) . "</h4><p style='margin: 0; font-size: 12px; color: var(--text-muted);'>" . htmlspecialchars($u['phone_number']) . "</p></div>";
                                    echo "  </div>";
                                    echo "  <a href='admin_dashboard.php?resident_action=approve&id=" . $u['user_id'] . "&return=dashboard' class='flat-btn primary' style='padding: 6px 12px; font-size: 12px;'>Approve</a>";
                                    echo "</li>";
                                }
                            } else {
                                echo "<li style='padding: 20px 0; text-align: center; color: var(--text-muted); font-size: 13px;'>No pending approvals.</li>";
                            }
                            ?>
                        </ul>
                    </div>

                    <!-- Widget 2: New Reports -->
                    <div class="flat-card" style="margin-bottom: 0; flex: 1; display: flex; flex-direction: column; padding: 20px;">
                        <div class="card-title" style="margin-bottom: 12px; padding: 0;">
                            <div style="font-size: 15px;">New Reports</div>
                            <a href="?view=reports" style="font-size: 13px; color: var(--primary); text-decoration: none; font-weight: 500;">View All</a>
                        </div>
                        <ul class="widget-list">
                            <?php
                            $pending_reps = $conn->query("SELECT w.*, u.full_name FROM waste_reports w JOIN users u ON w.resident_id = u.user_id WHERE w.status='Pending' ORDER BY w.created_at DESC LIMIT 3");
                            if($pending_reps && $pending_reps->num_rows > 0) {
                                while($r = $pending_reps->fetch_assoc()) {
                                    $time_ago = date("M d, g:i A", strtotime($r['created_at']));
                                    echo "<li style='display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid var(--border-color);'>";
                                    echo "  <div style='display: flex; align-items: center; gap: 12px;'>";
                                    echo "      <img src='uploads/reports/" . htmlspecialchars($r['before_photo_path']) . "' style='width: 40px; height: 40px; border-radius: 8px; object-fit: cover;' onerror=\"this.src='https://placehold.co/40x40/e5e7eb/a1a1aa?text=NA'\">";
                                    echo "      <div><h4 style='margin: 0; font-size: 14px; font-weight: 600; color: var(--text-main);'>" . htmlspecialchars($r['full_name']) . "</h4><p style='margin: 0; font-size: 12px; color: var(--text-muted);'>{$time_ago}</p></div>";
                                    echo "  </div>";
                                    echo "  <a href='resolve_report.php?id=" . $r['report_id'] . "' class='flat-btn' style='padding: 6px 10px; border-radius: 6px;'><i class='ti ti-arrow-right'></i></a>";
                                    echo "</li>";
                                }
                            } else {
                                echo "<li style='padding: 20px 0; text-align: center; color: var(--text-muted); font-size: 13px;'>No new reports.</li>";
                            }
                            ?>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- =============================================================== -->
            <!-- VIEW 2: REPORTS -->
            <!-- =============================================================== -->
            <?php elseif ($view == 'reports'): ?>
            <div class="flat-card" style="padding: 0; overflow: hidden;">
                <!-- Header / Filter Bar -->
                <div class="table-controls" style="padding: 24px; margin: 0;">
                    <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                        <input type="text" id="searchInput" class="flat-input" placeholder="Search reporter or description..." style="width: 280px;">
                        
                        <div style="position: relative;">
                            <select id="statusFilter" class="flat-input" onchange="filterTableDropdown()" style="padding-right: 32px; appearance: none; cursor: pointer;">
                                <option value="All">All Status</option>
                                <option value="Pending">Pending</option>
                                <option value="Cleaned">Cleaned</option>
                            </select>
                            <i class="ti ti-chevron-down" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); pointer-events: none; color: var(--text-muted);"></i>
                        </div>
                        
                        <div style="display: flex; align-items: center; gap: 8px; margin-left: 8px;">
                            <span style="font-size: 13px; color: var(--text-muted); font-weight: 500;">From:</span>
                            <input type="date" id="startDate" class="flat-input" onchange="filterTableDropdown()" title="Start Date">
                            <span style="font-size: 13px; color: var(--text-muted); font-weight: 500;">To:</span>
                            <input type="date" id="endDate" class="flat-input" onchange="filterTableDropdown()" title="End Date">
                        </div>
                    </div>
                    <button class="flat-btn primary" onclick="generatePrintReport()"><i class="ti ti-file-download"></i> Export PDF</button>
                </div>

                <!-- Table -->
                <div style="overflow-x: auto;">
                    <table class="flat-table">
                        <colgroup>
                            <col style="width: 22%;"> <!-- Reporter -->
                            <col style="width: 15%;"> <!-- Date -->
                            <col style="width: 25%;"> <!-- Description -->
                            <col style="width: 15%;"> <!-- Photos -->
                            <col style="width: 11%;"> <!-- Status -->
                            <col style="width: 12%;"> <!-- Action -->
                        </colgroup>
                        
                        <thead>
                            <tr>
                                <th>Reporter</th>
                                <th>Date</th>
                                <th>Description</th>
                                <th>Photos</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="reportsTableBody">
                            <?php
                            $reports_query = $conn->query("SELECT w.*, u.full_name FROM waste_reports w JOIN users u ON w.resident_id = u.user_id ORDER BY w.created_at DESC");
                            if($reports_query && $reports_query->num_rows > 0) {
                                while($row = $reports_query->fetch_assoc()) {
                                    $raw_date = date('Y-m-d', strtotime($row['created_at']));
                                    $initial = strtoupper(substr($row['full_name'], 0, 1));
                                    
                                    echo "<tr class='report-row' data-status='" . $row['status'] . "' data-date='" . $raw_date . "'>";
                                    
                                    // Reporter with Avatar
                                    echo "<td>";
                                    echo "  <div class='reporter-cell'>";
                                    echo "      <div class='reporter-avatar'>{$initial}</div>";
                                    echo "      <span style='font-weight: 600;'>" . htmlspecialchars($row['full_name']) . "</span>";
                                    echo "  </div>";
                                    echo "</td>";
                                    
                                    echo "<td style='color: var(--text-muted); font-weight: 500;'>" . date("M d, Y", strtotime($row['created_at'])) . "</td>";
                                    
                                    echo "<td style='max-width: 250px;'>" . htmlspecialchars($row['description']) . "</td>";
                                    
                                    // Photos with distinct colors
                                    echo "<td>";
                                    echo "  <a href='uploads/reports/" . $row['before_photo_path'] . "' target='_blank' class='photo-link before'><i class='ti ti-photo'></i> Before</a>";
                                    if ($row['status'] === 'Cleaned' && !empty($row['after_photo_path'])) {
                                        echo "  <a href='uploads/reports/" . $row['after_photo_path'] . "' target='_blank' class='photo-link after'><i class='ti ti-photo-check'></i> After</a>";
                                    }
                                    echo "</td>";
                                    
                                    // Status Badges
                                    if ($row['status'] == 'Pending') {
                                        echo "<td><span class='status-badge pending'><i class='ti ti-clock'></i> Pending</span></td>";
                                        echo "<td><a href='resolve_report.php?id=" . $row['report_id'] . "' class='flat-btn primary' style='padding: 6px 14px; font-size: 12px;'>Resolve</a></td>";
                                    } else {
                                        echo "<td><span class='status-badge active'><i class='ti ti-check'></i> Cleaned</span></td>";
                                        echo "<td><span style='color: var(--text-muted); font-size: 13px; font-weight: 500;'><i class='ti ti-archive'></i> Archived</span></td>";
                                    }
                                    echo "</tr>";
                                }
                            } else {
                                echo "<tr><td colspan='6' style='text-align: center; padding: 40px;'>";
                                echo "  <i class='ti ti-inbox' style='font-size: 32px; color: #d1d5db; margin-bottom: 8px; display: block;'></i>";
                                echo "  <span style='color: var(--text-muted);'>No records found matching your criteria.</span>";
                                echo "</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- =============================================================== -->
            <!-- VIEW 3: RESIDENTS -->
            <!-- =============================================================== -->
            <?php elseif ($view == 'residents'): ?>
            <?php if(isset($_GET['status']) && $_GET['status'] == 'deleted'): ?>
                <div id="deleteAlert" style="background: var(--primary-light); border: 1px solid var(--primary); color: var(--primary-dark); padding: 16px; border-radius: 8px; margin-bottom: 24px; font-size: 14px; font-weight: 500;">
                    User account removed successfully.
                </div>
            <?php endif; ?>

            <!-- SINGLE UNIFIED CARD -->
            <div class="flat-card" style="padding: 0; overflow: hidden;">
                
                <!-- Integrated Tab Header -->
                <div class="table-controls" style="padding: 24px; margin: 0; border-bottom: 1px solid var(--border-color);">
                    <div class="flat-tabs" id="resident-tabs">
                        <button class="tab-btn active" data-status="Pending" onclick="switchResTab(this)">
                            <i class="ti ti-clock"></i> Pending Approvals
                        </button>
                        <button class="tab-btn" data-status="Approved" onclick="switchResTab(this)">
                            <i class="ti ti-users"></i> Active Users
                        </button>
                    </div>
                </div>

                <!-- Table -->
                <div style="overflow-x: auto;">
                    <table class="flat-table">
                        <colgroup>
                            <col style="width: 25%;"> <!-- Name -->
                            <col style="width: 20%;"> <!-- Username -->
                            <col style="width: 20%;"> <!-- Phone -->
                            <col style="width: 15%;"> <!-- Status -->
                            <col style="width: 20%;"> <!-- Action -->
                        </colgroup>
                        
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Username</th>
                                <th>Phone</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="residentsTableBody">
                            <?php
                            $residents_query = $conn->query("SELECT * FROM users WHERE role='Resident' AND account_status IN ('Pending', 'Approved') ORDER BY account_status ASC, full_name ASC");
                            
                            if ($residents_query && $residents_query->num_rows > 0) {
                                while ($row = $residents_query->fetch_assoc()) {
                                    $status = $row['account_status'];
                                    
                                    echo "<tr class='resident-row' data-status='{$status}'>";
                                    
                                    // Name
                                    echo "<td><strong style='color: var(--text-main);'>" . htmlspecialchars($row['full_name']) . "</strong></td>";
                                    
                                    // Username
                                    echo "<td style='color: var(--text-muted); font-weight: 500;'>" . htmlspecialchars($row['username']) . "</td>";
                                    
                                    // Phone
                                    echo "<td>" . htmlspecialchars($row['phone_number']) . "</td>";
                                    
                                    // Status Column
                                    echo "<td>";
                                    if ($status == 'Pending') {
                                        echo "<span class='status-badge pending'><i class='ti ti-clock'></i> Pending</span>";
                                    } else {
                                        echo "<span class='status-badge active'><i class='ti ti-shield-check'></i> Active</span>";
                                    }
                                    echo "</td>";
                                    
                                    // Action Column
                                    echo "<td>";
                                    if ($status == 'Pending') {
                                        echo "<a href='admin_dashboard.php?resident_action=approve&id=" . $row['user_id'] . "' class='flat-btn primary' style='margin-right: 8px; padding: 6px 12px; font-size: 12px;'><i class='ti ti-check'></i> Approve</a>";
                                        echo "<a href='admin_dashboard.php?resident_action=reject&id=" . $row['user_id'] . "' onclick=\"return confirm('Reject user?');\" class='flat-btn danger' style='padding: 6px 12px; font-size: 12px;'><i class='ti ti-x'></i> Reject</a>";
                                    } else {
                                        echo "<form action='admin_dashboard.php' method='POST' onsubmit=\"return confirm('Delete permanently?');\" style='margin:0;'>";
                                        echo "<input type='hidden' name='delete_resident_id' value='" . $row['user_id'] . "'>";
                                        echo "<button type='submit' class='flat-btn danger' style='padding: 6px 12px; font-size: 12px;'><i class='ti ti-trash'></i> Delete</button>";
                                        echo "</form>";
                                    }
                                    echo "</td></tr>";
                                }
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- =============================================================== -->
            <!-- VIEW 4: ALERT (ALIGNED UI) -->
            <!-- =============================================================== -->
            <?php elseif ($view == 'alert'): ?>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; align-items: start;">
                
                <!-- LEFT: Broadcast Form -->
                <div class="flat-card">
                    <!-- Aligned Header with Green Text -->
                    <div class="card-title" style="justify-content: flex-start; gap: 10px; font-size: 16px; margin-bottom: 24px;">
                        <i class="ti ti-speakerphone" style="color: var(--primary); font-size: 22px;"></i>
                        <span style="color: var(--primary);">Compose Broadcast</span>
                    </div>
                    
                    <div class="alert-banner">
                        <i class="ti ti-alert-triangle"></i>
                        <div>
                            <strong>Notice:</strong> This message will be instantly pushed to the resident mobile app or portal. Ensure the information is accurate and urgent.
                        </div>
                    </div>

                    <form method="POST" action="admin_dashboard.php?view=alert">
                        <div class="form-group">
                            <label class="form-label"><i class="ti ti-users"></i> Target Audience</label>
                            <div style="position: relative;">
                                <select name="purok_area" class="form-control" required style="appearance: none; padding-right: 32px; cursor: pointer;">
                                    <option value="All Areas" selected>All Registered Residents (Default)</option>
                                </select>
                                <i class="ti ti-chevron-down" style="position: absolute; right: 14px; top: 50%; transform: translateY(-50%); pointer-events: none; color: var(--text-muted);"></i>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label"><i class="ti ti-message-2"></i> Message Payload</label>
                            <textarea name="alert_message" id="alertMsg" class="form-control" required placeholder="Type your alert message here..." onkeyup="updateCharCount()">The garbage truck is currently near your area. Please prepare your segregated waste.</textarea>
                            <div style="text-align: right; font-size: 12px; color: var(--text-muted); margin-top: 8px; font-weight: 500;">
                                <span id="charCount">0</span> / 250 characters
                            </div>
                        </div>

                        <div style="display: flex; gap: 12px; margin-top: 32px;">
                            <button type="submit" name="send_basura_alert" class="flat-btn primary" style="flex: 1; justify-content: center; padding: 14px; font-size: 15px;">
                                <i class="ti ti-send"></i> Send Broadcast Now
                            </button>
                        </div>
                    </form>
                </div>

                <!-- RIGHT: Recent Broadcasts History -->
                <div class="flat-card" style="padding: 0; overflow: hidden; display: flex; flex-direction: column;">
                    <!-- Aligned Header with Green Text -->
                    <div class="card-title" style="padding: 24px; margin: 0; border-bottom: 1px solid var(--border-color); background: transparent; justify-content: space-between;">
                        <div style="display: flex; align-items: center; gap: 10px; font-size: 16px;">
                            <i class="ti ti-history" style="color: var(--primary); font-size: 22px;"></i>
                            <span style="color: var(--primary);">Recent Broadcasts</span>
                        </div>
                        <span style="font-size: 12px; font-weight: 500; background: #f9fafb; border: 1px solid var(--border-color); padding: 4px 10px; border-radius: 20px; color: var(--text-muted);">Last 5</span>
                    </div>
                    
                    <!-- Redesigned History List -->
                    <div class="history-list" style="flex: 1; overflow-y: auto;">
                        <?php
                        $history_query = $conn->query("SELECT * FROM basura_alerts ORDER BY alert_id DESC LIMIT 5");
                        
                        if($history_query && $history_query->num_rows > 0) {
                            while($h = $history_query->fetch_assoc()) {
                                // Changed fallback from "Recently" to actual date/time format
                                $raw_date = isset($h['created_at']) ? $h['created_at'] : date('Y-m-d H:i:s');
                                $date_display = date("M d, g:i A", strtotime($raw_date));
                                ?>
                                <div class="history-item">
                                    <div class="history-header">
                                        <span class="history-target">
                                            <i class="ti ti-map-pin"></i> <?php echo htmlspecialchars($h['purok_area'] ?? 'All Areas'); ?>
                                        </span>
                                        <span class="history-time">
                                            <i class="ti ti-clock" style="font-size: 14px;"></i> <?php echo $date_display; ?>
                                        </span>
                                    </div>
                                    <div class="history-msg">
                                        <?php echo htmlspecialchars($h['message']); ?>
                                    </div>
                                </div>
                                <?php
                            }
                        } else {
                            echo "<div style='padding: 48px 24px; text-align: center; color: var(--text-muted); font-size: 14px; display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%;'>";
                            echo "  <div style='width: 64px; height: 64px; border-radius: 50%; background: var(--bg-body); display: flex; align-items: center; justify-content: center; margin-bottom: 16px;'>";
                            echo "      <i class='ti ti-inbox' style='font-size: 32px; color: #d1d5db;'></i>";
                            echo "  </div>";
                            echo "  <h3 style='margin: 0 0 8px 0; font-size: 16px; color: var(--text-main);'>No Broadcasts Yet</h3>";
                            echo "  <p style='margin: 0; color: var(--text-muted); font-size: 13px;'>Messages you send will appear here.</p>";
                            echo "</div>";
                        }
                        ?>
                    </div>
                </div>
            </div>

            <!-- =============================================================== -->
            <!-- VIEW 5: SETTINGS -->
            <!-- =============================================================== -->
            <?php elseif ($view == 'settings'): ?>
            <?php if ($update_success): ?>
                <div style="background: var(--primary-light); border: 1px solid var(--primary); color: var(--primary-dark); padding: 16px; border-radius: 8px; margin-bottom: 24px; font-size: 14px; font-weight: 500;">Configuration saved successfully.</div>
            <?php endif; ?>
            
            <div class="flat-card">
                <form method="POST" action="admin_dashboard.php?view=settings" enctype="multipart/form-data">
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                        <div class="form-group">
                            <label class="form-label">Barangay Name</label>
                            <input type="text" name="barangay_name" class="form-control" value="<?php echo htmlspecialchars($info['barangay_name'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Barangay Captain</label>
                            <input type="text" name="captain_name" class="form-control" value="<?php echo htmlspecialchars($info['captain_name'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Barangay Secretary</label>
                            <input type="text" name="secretary_name" class="form-control" value="<?php echo htmlspecialchars($info['secretary_name'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Contact Number</label>
                            <input type="text" name="contact_number" class="form-control" value="<?php echo htmlspecialchars($info['contact_number'] ?? ''); ?>">
                        </div>
                    </div>

                    <hr style="border: 0; border-top: 1px solid var(--border-color); margin: 32px 0;">
                    <div class="card-title" style="font-size: 18px; margin-bottom: 24px;">Emergency Hotlines</div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                        <div class="form-group">
                            <label class="form-label">Police Station</label>
                            <input type="text" name="pnp_hotline" class="form-control" value="<?php echo htmlspecialchars($info['pnp_hotline'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Fire Station (BFP)</label>
                            <input type="text" name="bfp_hotline" class="form-control" value="<?php echo htmlspecialchars($info['bfp_hotline'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">MDRRMO</label>
                            <input type="text" name="mdrrmo_hotline" class="form-control" value="<?php echo htmlspecialchars($info['mdrrmo_hotline'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Health Center</label>
                            <input type="text" name="health_center_hotline" class="form-control" value="<?php echo htmlspecialchars($info['health_center_hotline'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <!-- Hidden inputs -->
                    <input type="hidden" name="full_address" value="<?php echo htmlspecialchars($info['full_address'] ?? ''); ?>">
                    <input type="hidden" name="zip_code" value="<?php echo htmlspecialchars($info['zip_code'] ?? ''); ?>">
                    <input type="hidden" name="region" value="<?php echo htmlspecialchars($info['region'] ?? ''); ?>">
                    <input type="hidden" name="system_subtitle" value="<?php echo htmlspecialchars($info['system_subtitle'] ?? ''); ?>">
                    <input type="hidden" name="system_description" value="<?php echo htmlspecialchars($info['system_description'] ?? ''); ?>">
                    <input type="hidden" name="city" value="<?php echo htmlspecialchars($info['city'] ?? ''); ?>">
                    <input type="hidden" name="municipal" value="<?php echo htmlspecialchars($info['municipal'] ?? ''); ?>">
                    <input type="hidden" name="office_hours" value="<?php echo htmlspecialchars($info['office_hours'] ?? ''); ?>">
                    <input type="hidden" name="emergency_hotlines" value="<?php echo htmlspecialchars($info['emergency_hotlines'] ?? ''); ?>">
                    
                    <button type="submit" name="update_info" class="flat-btn primary" style="padding: 12px 24px;">Save Configuration</button>
                </form>
            </div>
            <?php endif; ?>

        </main>
    </div>
</div>

<!-- SCRIPTS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
    // LIVE DATE AND TIME CLOCK
    function updateDateTime() {
        const now = new Date();
        const options = { 
            weekday: 'short', 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric',
            hour: '2-digit', 
            minute: '2-digit'
        };
        const formatted = now.toLocaleDateString('en-US', options);
        const el = document.getElementById('liveDateTime');
        if (el) {
            el.innerHTML = `<i class="ti ti-clock"></i> ${formatted}`;
        }
    }
    setInterval(updateDateTime, 1000);
    updateDateTime(); // Call immediately on load

    // URL CLEANER
    if (window.history.replaceState) {
        const url = new URL(window.location.href);
        if (url.searchParams.has('status')) {
            url.searchParams.delete('status');
            window.history.replaceState({path: url.href}, '', url.href);
        }
    }
    
    // Auto-hide alerts
    setTimeout(() => { let b = document.getElementById('deleteAlert'); if(b) b.style.display = 'none'; }, 3000);

    // FILTER REPORTS
    function filterTableDropdown() {
        let statusFilter = document.getElementById('statusFilter').value;
        let startDate = document.getElementById('startDate').value; 
        let endDate = document.getElementById('endDate').value; 
        let searchText = (document.getElementById('searchInput').value || '').toLowerCase();
        
        document.querySelectorAll('.report-row').forEach(row => {
            let rowText = row.innerText.toLowerCase();
            let rowStatus = row.getAttribute('data-status');
            let rowDate = row.getAttribute('data-date');
            
            let matchesSearch = rowText.includes(searchText);
            let matchesStatus = (statusFilter === 'All') || (rowStatus === statusFilter);
            
            let matchesDate = true;
            if (startDate && rowDate < startDate) matchesDate = false; 
            if (endDate && rowDate > endDate) matchesDate = false;     
            
            if (matchesSearch && matchesStatus && matchesDate) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }
    
    if(document.getElementById('searchInput')) {
        document.getElementById('searchInput').addEventListener('keyup', filterTableDropdown);
    }

    // RESIDENT TABS (UNIFIED TABLE FILTERING)
    function switchResTab(btn) {
        let targetStatus = btn.getAttribute('data-status');

        // 1. Update Tab UI
        document.querySelectorAll('#resident-tabs .tab-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        // 2. Filter Table Rows
        let rows = document.querySelectorAll('.resident-row');
        let visibleCount = 0;
        
        rows.forEach(row => {
            if(row.getAttribute('data-status') === targetStatus) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });

        // 3. Handle Empty State
        let emptyRow = document.getElementById('emptyStateRow');
        if(visibleCount === 0) {
            if(!emptyRow) {
                let tbody = document.getElementById('residentsTableBody');
                let tr = document.createElement('tr');
                tr.id = 'emptyStateRow';
                tr.innerHTML = `<td colspan="5" style="padding: 60px 24px; text-align: center;">
                    <div style="display: inline-flex; align-items: center; justify-content: center; width: 64px; height: 64px; border-radius: 50%; background: var(--bg-body); margin-bottom: 16px;">
                        <i class="ti ti-mood-smile" style="font-size: 32px; color: #d1d5db;"></i>
                    </div>
                    <h3 style="margin: 0 0 8px 0; color: var(--text-main); font-size: 16px;">All caught up!</h3>
                    <p style="margin: 0; color: var(--text-muted); font-size: 14px;">There are no ${targetStatus === 'Pending' ? 'pending resident approvals' : 'active residents'} right now.</p>
                </td>`;
                tbody.appendChild(tr);
            } else {
                emptyRow.style.display = '';
                emptyRow.querySelector('p').innerText = `There are no ${targetStatus === 'Pending' ? 'pending resident approvals' : 'active residents'} right now.`;
            }
        } else if (emptyRow) {
            emptyRow.style.display = 'none';
        }
    }

    // Initialize Resident Tabs on Load
    document.addEventListener("DOMContentLoaded", function() {
        if(document.querySelector('#resident-tabs .tab-btn.active')) {
            let activeBtn = document.querySelector('#resident-tabs .tab-btn.active');
            switchResTab(activeBtn);
        }
    });

    // PRINT
    function generatePrintReport() { 
        let status = document.getElementById('statusFilter').value;
        let startDate = document.getElementById('startDate').value;
        let endDate = document.getElementById('endDate').value;
        let search = document.getElementById('searchInput').value;
        
        window.open(`print_report.php?status=${encodeURIComponent(status)}&startDate=${encodeURIComponent(startDate)}&endDate=${encodeURIComponent(endDate)}&search=${encodeURIComponent(search)}`, '_blank'); 
    }

    // ALERT BROADCAST CHARACTER COUNTER
    function updateCharCount() {
        let textarea = document.getElementById('alertMsg');
        let counter = document.getElementById('charCount');
        if(textarea && counter) {
            counter.innerText = textarea.value.length;
            if(textarea.value.length > 250) {
                counter.style.color = '#dc2626';
            } else {
                counter.style.color = 'var(--text-muted)';
            }
        }
    }
    if(document.getElementById('alertMsg')) {
        updateCharCount();
    }

    // LEAFLET MAP
    <?php if ($view == 'dashboard'): ?>
    document.addEventListener("DOMContentLoaded", function() {
        var locations = <?php echo json_encode($map_data); ?>;
        var redPin = new L.Icon({ iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-red.png', shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png', iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34], shadowSize: [41, 41] });
        var greenPin = new L.Icon({ iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-green.png', shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png', iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34], shadowSize: [41, 41] });

        var m = L.map('masterMap').setView([11.45, 123.15], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(m);
        
        locations.forEach(function(loc) {
            var marker = L.marker([loc.latitude, loc.longitude], {icon: (loc.status === 'Pending') ? redPin : greenPin}).addTo(m);
            var dateStr = new Date(loc.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            var hoverCard = `<div style="text-align:center; min-width:150px; padding:5px; font-family: 'Inter', sans-serif;"><img src="uploads/reports/${loc.before_photo_path}" style="width:100%; height:90px; object-fit:cover; border-radius:4px; margin-bottom:8px;"><div style="font-size:12px; color:${(loc.status==='Pending')?'#dc2626':'#059669'}; font-weight:600;">${loc.status}</div><div style="font-size:11px; text-align:left; color:#6b7280; margin-top:4px;"><b>${loc.full_name}</b><br>${dateStr}</div></div>`;
            marker.bindTooltip(hoverCard, { direction: 'top', opacity: 1 });
        });
        setTimeout(function() { m.invalidateSize(); }, 300);
    });
    <?php endif; ?>
</script>

</body>
</html>
