<?php
session_start();
include 'db_connect.php';

// -----------------------------------------------------------------------------
// 1. SECURITY CHECK
// -----------------------------------------------------------------------------
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Admin') {
    header("Location: login.php");
    exit();
}

function h($value) { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8'); }

// Same CSRF token the dashboard uses
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf']) || !hash_equals($csrf, (string)$_POST['csrf'])) {
        http_response_code(419);
        exit('Your session token is invalid or expired. Please go back, refresh the page and try again.');
    }
}

$admin_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin_data = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();
$admin_name = $admin_data['full_name'] ?? 'Admin User';

// -----------------------------------------------------------------------------
// 2. BARANGAY INFO FOR THE SIDEBAR (same as the dashboard)
// -----------------------------------------------------------------------------
$check_info = $conn->query("SELECT * FROM barangay_information LIMIT 1");
$info = ($check_info && $check_info->num_rows > 0) ? $check_info->fetch_assoc() : ['barangay_name' => 'Barangay System', 'logo_path' => ''];

$logo_file = (string)($info['logo_path'] ?? '');
$logo_src = ($logo_file !== '' && file_exists('uploads/logo/' . $logo_file)) ? 'uploads/logo/' . $logo_file : 'tanzalogo.jpg';

$sidebar_display_name = !empty($info['secretary_name']) ? $info['secretary_name'] : $admin_name;
$sidebar_initial = strtoupper(substr($sidebar_display_name, 0, 1));
$sec_pic_path = !empty($info['secretary_profile_pic']) ? 'uploads/profiles/' . $info['secretary_profile_pic'] : '';
$has_sec_pic = ($sec_pic_path !== '' && file_exists($sec_pic_path));

// -----------------------------------------------------------------------------
// 3. LOAD THE REPORT (validated id + prepared statement)
// -----------------------------------------------------------------------------
$report_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$report_id) {
    header("Location: admin_dashboard.php?view=reports");
    exit();
}

$stmt = $conn->prepare("SELECT w.*, COALESCE(u.full_name, 'Deleted user') AS reporter_name FROM waste_reports w LEFT JOIN users u ON w.resident_id = u.user_id WHERE w.report_id = ?");
$stmt->bind_param("i", $report_id);
$stmt->execute();
$report = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$report) {
    header("Location: admin_dashboard.php?view=reports");
    exit();
}

// -----------------------------------------------------------------------------
// 4. HANDLE THE FORM SUBMISSION
// -----------------------------------------------------------------------------
$error = '';
$max_bytes = 5 * 1024 * 1024; // 5MB

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_resolved'])) {
    $f = $_FILES['photo_after'] ?? null;

    if ($report['status'] === 'Cleaned') {
        $error = 'This report is already marked as Cleaned.';
    } elseif (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
        $error = 'Please choose an "After" photo first.';
    } elseif ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || $f['size'] > $max_bytes) {
        $error = 'That photo is too large. Please upload an image of 5 MB or less.';
    } elseif ($f['error'] !== UPLOAD_ERR_OK) {
        $error = 'The upload failed. Please try again.';
    } else {
        $img = @getimagesize($f['tmp_name']);
        $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png'];

        if (!$img || !isset($types[$img[2]])) {
            $error = 'Only JPG or PNG images are allowed.';
        } else {
            $dir = 'uploads/reports/';
            if (!is_dir($dir)) { mkdir($dir, 0755, true); }
            $file_name = time() . '_after_' . bin2hex(random_bytes(6)) . '.' . $types[$img[2]];

            if (!move_uploaded_file($f['tmp_name'], $dir . $file_name)) {
                $error = 'The photo could not be saved on the server.';
            } else {
                $stmt = $conn->prepare("UPDATE waste_reports SET status = 'Cleaned', after_photo_path = ? WHERE report_id = ? AND status <> 'Cleaned'");
                $stmt->bind_param("si", $file_name, $report_id);
                $ok = $stmt->execute() && $stmt->affected_rows === 1;
                $stmt->close();

                if ($ok) {
                    header("Location: admin_dashboard.php?view=reports&resolved=" . $report_id);
                    exit();
                }
                @unlink($dir . $file_name);
                $error = 'The report could not be updated. It may already have been resolved.';
            }
        }
    }
}

// -----------------------------------------------------------------------------
// 5. VALUES FOR THE PAGE
// -----------------------------------------------------------------------------
$is_cleaned = ($report['status'] === 'Cleaned');
$lat = is_numeric($report['latitude'] ?? null) ? (float)$report['latitude'] : null;
$lng = is_numeric($report['longitude'] ?? null) ? (float)$report['longitude'] : null;
$has_location = ($lat !== null && $lng !== null && abs($lat) <= 90 && abs($lng) <= 180 && !($lat == 0 && $lng == 0));
$before_photo = (string)($report['before_photo_path'] ?? '');
$after_photo = (string)($report['after_photo_path'] ?? '');
$date_reported = !empty($report['created_at']) ? date("M d, Y h:i A", strtotime($report['created_at'])) : '-';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resolve Report #<?php echo (int)$report_id; ?> | Coastal and Land Watch</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">

    <style>
        /* ---------- Same design tokens as admin_dashboard.php ---------- */
        :root {
            --bg-body: #f3f4f6;
            --bg-surface: #ffffff;
            --sidebar-bg: #3F4D67;
            --sidebar-hover: #344056;
            --sidebar-text: #C9CFDA;
            --sidebar-text-active: #ffffff;
            --sidebar-header-text: #B3BCCC;
            --sidebar-footer-bg: #344056;
            --active-tab-blue: #0ea5e9;
            --primary: #0ea5e9;
            --primary-light: #e0f2fe;
            --primary-dark: #0284c7;
            --avatar-text-dark: #0369a1;
            --primary-strong: #0369a1;
            --primary-strong-hover: #075985;
            --alert-red: #EF4444;
            --text-main: #1F2937;
            --text-muted: #687280;
            --border-color: #e5e7eb;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            --radius-card: 12px;
        }

        html { overflow-y: scroll; }
        body { font-family: 'Inter', sans-serif; background-color: var(--bg-body); color: var(--text-main); margin: 0; padding: 0; overflow-x: hidden; font-size: 14px; }
        * { box-sizing: border-box; }

        /* ---------- Shell (sidebar + top bar), identical to the dashboard ---------- */
        .app-container { display: flex; min-height: 100vh; }
        .sidebar { width: 260px; background: var(--sidebar-bg); display: flex; flex-direction: column; position: fixed; top: 0; bottom: 0; left: 0; z-index: 50; }
        .workspace-header { padding: 32px 20px 24px 20px; display: flex; align-items: center; justify-content: center; border-bottom: 1px solid var(--sidebar-hover); }
        .workspace-brand { display: flex; flex-direction: column; align-items: center; gap: 16px; width: 100%; text-align: center; }
        .workspace-logo { width: 85px; height: 85px; border-radius: 50%; object-fit: cover; box-shadow: 0 0 0 3px rgba(255,255,255,0.15); }
        .workspace-text h2 { margin: 0; font-size: 18px; font-weight: 700; color: var(--sidebar-text-active); line-height: 1.4; letter-spacing: 0.5px; }
        .workspace-text p { margin: 4px 0 0; font-size: 12px; font-weight: 500; color: var(--sidebar-header-text); }

        .sidebar-menu { padding: 10px 16px; flex: 1; overflow-y: auto; }
        .menu-label { font-size: 11px; color: var(--sidebar-header-text); font-weight: 600; padding: 16px 16px 8px; text-transform: uppercase; letter-spacing: 0.5px; }
        .nav-link { display: flex; align-items: center; gap: 12px; padding: 12px 16px; padding-left: 12px; color: var(--sidebar-text); text-decoration: none; border-radius: 6px; font-size: 14px; font-weight: 500; transition: 0.2s; margin-bottom: 4px; border-left: 4px solid transparent; }
        .nav-link i { font-size: 20px; display: inline-block; width: 20px; text-align: center; color: var(--sidebar-text); }
        .nav-link:hover { background: var(--sidebar-hover); color: var(--sidebar-text-active); }
        .nav-link.active { background: var(--sidebar-hover); color: var(--sidebar-text-active); border-left: 4px solid var(--active-tab-blue); border-radius: 4px 8px 8px 4px; font-weight: 600; }
        .nav-link.active i { color: var(--active-tab-blue); }

        .user-footer { padding: 20px; border-top: 1px solid #2B3548; background: var(--sidebar-footer-bg); display: flex; align-items: center; justify-content: space-between; }
        .user-info { display: flex; align-items: center; gap: 12px; }
        .user-avatar { width: 36px; height: 36px; border-radius: 50%; background: var(--primary-dark); color: #fff; display: flex; justify-content: center; align-items: center; font-weight: 600; font-size: 14px; overflow: hidden; }
        .user-text h4 { margin: 0; font-size: 13px; font-weight: 600; color: var(--sidebar-text-active); }
        .user-text p { margin: 0; font-size: 11px; color: var(--sidebar-header-text); }
        .user-footer a { color: var(--sidebar-text); transition: 0.2s; }
        .user-footer a:hover { color: var(--alert-red); }

        .main-wrapper { flex: 1; min-width: 0; margin-left: 260px; display: flex; flex-direction: column; }
        .topbar { position: sticky; top: 0; z-index: 40; background: var(--sidebar-bg); border-bottom: 1px solid var(--sidebar-hover); height: 70px; padding: 0 32px; display: flex; justify-content: space-between; align-items: center; }
        .topbar-left { min-width: 0; white-space: nowrap; display: flex; align-items: center; gap: 8px; color: #ffffff; font-size: 15px; font-weight: 600; }
        .topbar-left i { color: var(--sidebar-text); font-size: 22px; }
        .topbar-left .crumb-sep { font-size: 16px; opacity: 0.7; }
        .topbar-left .crumb-link { color: inherit; text-decoration: none; }
        .topbar-left .crumb-link:hover { text-decoration: underline; }
        .topbar-right { display: flex; align-items: center; gap: 20px; flex-shrink: 0; }
        .live-datetime { display: flex; align-items: center; justify-content: center; gap: 8px; box-sizing: border-box; height: 40px; min-width: 260px; flex-shrink: 0; white-space: nowrap; line-height: 1; font-variant-numeric: tabular-nums; background: rgba(0, 0, 0, 0.2); border: 1px solid rgba(0, 0, 0, 0.2); padding: 0 14px; border-radius: 8px; font-size: 13px; font-weight: 500; color: #ffffff; }
        .live-datetime i { color: var(--sidebar-text); font-size: 16px; }
        .content-area { padding: 32px; flex: 1; }

        /* ---------- Components shared with the dashboard ---------- */
        .flat-card { background: var(--bg-surface); border: 1px solid #f3f4f6; border-radius: var(--radius-card); padding: 24px; margin-bottom: 24px; box-shadow: var(--shadow-sm); }
        .card-title { font-size: 16px; font-weight: 600; color: var(--text-main); margin: 0 0 20px 0; display: flex; align-items: center; gap: 8px; }
        .card-title i { color: var(--primary-dark); font-size: 20px; }

        .flat-btn { background: var(--bg-surface); border: 1px solid var(--border-color); color: var(--text-main); padding: 10px 16px; border-radius: 8px; font-size: 14px; font-weight: 500; font-family: inherit; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; }
        .flat-btn:hover { background: var(--bg-body); color: var(--text-main); }
        .flat-btn.primary { background: var(--primary-strong); color: #fff; border-color: var(--primary-strong); }
        .flat-btn.primary:hover { background: var(--primary-strong-hover); border-color: var(--primary-strong-hover); }
        .flat-btn.primary:disabled { background: #9ca3af; border-color: #9ca3af; cursor: not-allowed; }
        .flat-btn.block { width: 100%; padding: 14px; font-size: 15px; }
        .flat-btn.small { padding: 6px 12px; font-size: 13px; }
        .flat-btn.outline-blue { color: var(--avatar-text-dark); border-color: #bae6fd; background: var(--primary-light); }
        .flat-btn.outline-blue:hover { background: #bae6fd; }

        .status-badge { padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; }
        .status-badge.pending { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
        .status-badge.active { background: #e0f2fe; color: #075985; border: 1px solid #bae6fd; }

        .notice { padding: 16px; border-radius: 8px; margin-bottom: 24px; font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 8px; }
        .notice i { font-size: 20px; flex-shrink: 0; }
        .notice.success { background: var(--primary-light); border: 1px solid var(--primary); color: #075985; }
        .notice.error { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }

        .form-label { display: flex; align-items: center; gap: 6px; font-size: 14px; font-weight: 500; margin-bottom: 8px; color: var(--text-main); }
        .form-label i { color: var(--primary-dark); font-size: 16px; }

        .map-pin-wrap { background: transparent; border: 0; }
        .map-pin { display: block; width: 20px; height: 20px; border-radius: 50%; border: 3px solid #fff; box-shadow: 0 2px 6px rgba(0, 0, 0, 0.35); }
        .map-pin.pending { background: var(--alert-red); }
        .map-pin.cleaned { background: var(--primary); }

        :focus-visible { outline: 2px solid var(--primary-strong); outline-offset: 2px; }
        .nav-link:focus-visible, .user-footer a:focus-visible { outline-color: #ffffff; }

        /* ---------- Page header ---------- */
        .page-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; margin-bottom: 24px; }
        .page-header h1 { margin: 0 0 6px 0; font-size: 28px; font-weight: 700; letter-spacing: -0.5px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .page-header p { margin: 0; font-size: 14px; color: var(--text-muted); }

        .resolve-grid { display: grid; grid-template-columns: 1.1fr 1fr; gap: 24px; align-items: start; }

        /* ---------- Photos ---------- */
        .photo-frame { position: relative; border-radius: 10px; overflow: hidden; border: 1px solid var(--border-color); background: var(--bg-body); margin-bottom: 20px; aspect-ratio: 16 / 10; }
        .photo-frame img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .photo-fallback { position: absolute; inset: 0; display: none; flex-direction: column; align-items: center; justify-content: center; gap: 8px; color: var(--text-muted); font-size: 13px; }
        .photo-fallback i { font-size: 32px; }
        .photo-open { position: absolute; right: 10px; bottom: 10px; background: rgba(31, 41, 55, 0.78); color: #fff; font-size: 12px; font-weight: 600; padding: 6px 10px; border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .photo-open:hover { background: rgba(31, 41, 55, 0.95); }

        .detail-list { margin: 0; display: grid; gap: 0; }
        .detail-row { display: grid; grid-template-columns: 130px 1fr; gap: 12px; padding: 12px 0; border-top: 1px solid var(--bg-body); align-items: start; }
        .detail-row:first-child { border-top: 0; padding-top: 0; }
        .detail-row dt { color: var(--text-muted); font-weight: 500; font-size: 13px; }
        .detail-row dd { margin: 0; color: var(--text-main); font-weight: 500; overflow-wrap: anywhere; }

        /* ---------- Upload ---------- */
        .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
        .dropzone { border: 2px dashed #bae6fd; background: #f0f9ff; border-radius: 10px; transition: 0.2s; }
        .dropzone.dragover, .dropzone:focus-within { border-color: var(--primary); background: var(--primary-light); }
        .dropzone.has-error { border-color: #fca5a5; background: #fef2f2; }
        .dz-empty { display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 32px 16px; text-align: center; cursor: pointer; }
        .dz-empty i { font-size: 36px; color: var(--primary-dark); }
        .dz-empty strong { font-size: 14px; color: var(--text-main); }
        .dz-empty span { font-size: 13px; color: var(--text-muted); }
        .dz-empty small { margin-top: 6px; font-size: 12px; color: var(--text-muted); }
        .dz-empty[hidden] { display: none; }
        .dz-preview { display: flex; gap: 16px; align-items: center; padding: 16px; }
        .dz-preview[hidden] { display: none; }
        .dz-preview img { width: 120px; height: 90px; object-fit: cover; border-radius: 8px; border: 1px solid var(--border-color); flex-shrink: 0; }
        .dz-meta { min-width: 0; display: flex; flex-direction: column; gap: 4px; flex: 1; }
        .dz-meta .name { font-weight: 600; overflow-wrap: anywhere; }
        .dz-meta .size { font-size: 12px; color: var(--text-muted); }
        .field-error { margin: 10px 0 0; font-size: 13px; font-weight: 500; color: #b91c1c; }
        .field-error[hidden] { display: none; }

        .next-steps { margin: 20px 0 0; padding: 16px; background: var(--bg-body); border-radius: 8px; font-size: 13px; color: var(--text-muted); }
        .next-steps strong { display: block; color: var(--text-main); margin-bottom: 6px; font-size: 13px; }
        .next-steps ul { margin: 0; padding-left: 18px; display: grid; gap: 4px; }

        #miniMap { height: 220px; border-radius: 10px; border: 1px solid var(--border-color); z-index: 1; margin-bottom: 16px; }
        .coords { font-size: 12px; color: var(--text-muted); margin-bottom: 12px; font-variant-numeric: tabular-nums; }

        .compare { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .compare .photo-frame { margin-bottom: 8px; }
        .compare figcaption { font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
        .compare figure { margin: 0; }

        @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }

        /* ---------- Responsive (same breakpoints as the dashboard) ---------- */
        @media (max-width: 1200px) { .resolve-grid { grid-template-columns: 1fr; } }
        @media (max-width: 860px) {
            .sidebar { width: 72px; }
            .main-wrapper { margin-left: 72px; }
            .workspace-header { padding: 20px 8px 16px; }
            .workspace-logo { width: 44px; height: 44px; }
            .workspace-text, .menu-label, .user-text { display: none; }
            .sidebar-menu { padding: 10px 8px; }
            .nav-link { justify-content: center; padding: 12px 0; border-left-width: 0; }
            .nav-link.active { border-left-width: 0; box-shadow: inset 3px 0 0 var(--active-tab-blue); }
            .nav-text { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
            .user-footer { flex-direction: column; gap: 12px; padding: 12px 8px; }
            .topbar { padding: 0 16px; }
            .content-area { padding: 16px; }
        }
        @media (max-width: 560px) {
            .live-datetime { display: none; }
            .detail-row { grid-template-columns: 1fr; gap: 4px; }
            .compare { grid-template-columns: 1fr; }
            .dz-preview { flex-direction: column; align-items: flex-start; }
            .dz-preview img { width: 100%; height: 160px; }
        }
    </style>
</head>
<body>

<div class="app-container">

    <aside class="sidebar">
        <div class="workspace-header">
            <div class="workspace-brand">
                <img src="<?php echo h($logo_src); ?>" alt="<?php echo h($info['barangay_name'] ?? 'Barangay'); ?> logo" class="workspace-logo" onerror="this.onerror=null;this.src='tanzalogo.jpg'">
                <div class="workspace-text">
                    <h2>Coastal and Land Watch</h2>
                    <p><?php echo h($info['barangay_name'] ?? ''); ?></p>
                </div>
            </div>
        </div>

        <div class="sidebar-menu">
            <div class="menu-label">Overview</div>
            <a href="admin_dashboard.php?view=dashboard" class="nav-link">
                <i class="ti ti-layout-grid"></i> <span class="nav-text">Dashboard</span>
            </a>
            <a href="admin_dashboard.php?view=reports" class="nav-link active" aria-current="page">
                <i class="ti ti-file-report"></i> <span class="nav-text">Waste Reports</span>
            </a>

            <div class="menu-label" style="margin-top: 16px;">Community</div>
            <a href="admin_dashboard.php?view=residents" class="nav-link">
                <i class="ti ti-users"></i> <span class="nav-text">Residents</span>
            </a>
            <a href="admin_dashboard.php?view=alert" class="nav-link">
                <i class="ti ti-speakerphone"></i> <span class="nav-text">Basura-Alert</span>
            </a>

            <div class="menu-label" style="margin-top: 16px;">Configuration</div>
            <a href="admin_dashboard.php?view=settings" class="nav-link">
                <i class="ti ti-settings"></i> <span class="nav-text">Settings</span>
            </a>
        </div>

        <div class="user-footer">
            <div class="user-info">
                <div class="user-avatar">
                    <?php if ($has_sec_pic): ?>
                        <img src="<?php echo h($sec_pic_path); ?>" alt="Profile" style="width: 100%; height: 100%; object-fit: cover;">
                    <?php else: ?>
                        <?php echo h($sidebar_initial); ?>
                    <?php endif; ?>
                </div>
                <div class="user-text">
                    <h4><?php echo h($sidebar_display_name); ?></h4>
                    <p>Administrator</p>
                </div>
            </div>
            <a href="logout.php" onclick="return confirm('Logout of system?');" title="Logout" aria-label="Logout"><i class="ti ti-logout"></i></a>
        </div>
    </aside>

    <div class="main-wrapper">
        <header class="topbar">
            <div class="topbar-left">
                <i class="ti ti-file-report"></i>
                <a href="admin_dashboard.php?view=reports" class="crumb-link">Waste Reports</a>
                <i class="ti ti-chevron-right crumb-sep"></i>
                <span>Resolve Report</span>
            </div>
            <div class="topbar-right">
                <div class="live-datetime" id="liveDateTime">
                    <i class="ti ti-clock"></i> Loading...
                </div>
            </div>
        </header>

        <main class="content-area">

            <div class="page-header">
                <div>
                    <h1>
                        Resolve Report #<?php echo (int)$report['report_id']; ?>
                        <?php if ($is_cleaned): ?>
                            <span class="status-badge active"><i class="ti ti-check"></i> Cleaned</span>
                        <?php else: ?>
                            <span class="status-badge pending"><i class="ti ti-clock"></i> Pending</span>
                        <?php endif; ?>
                    </h1>
                    <p>Review the details and attach proof of cleanup to close this report.</p>
                </div>
                <a href="admin_dashboard.php?view=reports" class="flat-btn"><i class="ti ti-arrow-left"></i> Back to Reports</a>
            </div>

            <?php if ($error !== ''): ?>
                <div class="notice error" role="alert"><i class="ti ti-alert-circle"></i> <?php echo h($error); ?></div>
            <?php endif; ?>

            <div class="resolve-grid">

                <!-- LEFT: ORIGINAL REPORT -->
                <section class="flat-card" aria-labelledby="origTitle">
                    <h2 class="card-title" id="origTitle"><i class="ti ti-photo"></i> Original Report</h2>

                    <div class="photo-frame">
                        <?php if ($before_photo !== ''): ?>
                            <img src="uploads/reports/<?php echo h($before_photo); ?>" alt="Photo of the reported waste" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                            <div class="photo-fallback"><i class="ti ti-photo-off"></i> Image not found</div>
                            <a class="photo-open" href="uploads/reports/<?php echo h($before_photo); ?>" target="_blank" rel="noopener"><i class="ti ti-arrows-maximize"></i> Open full size</a>
                        <?php else: ?>
                            <div class="photo-fallback" style="display:flex;"><i class="ti ti-photo-off"></i> No photo was attached</div>
                        <?php endif; ?>
                    </div>

                    <dl class="detail-list">
                        <div class="detail-row"><dt>Reported by</dt><dd><?php echo h($report['reporter_name']); ?></dd></div>
                        <div class="detail-row"><dt>Date reported</dt><dd><?php echo h($date_reported); ?></dd></div>
                        <div class="detail-row"><dt>Description</dt><dd><?php echo h($report['description']); ?></dd></div>
                    </dl>
                </section>

                <!-- RIGHT: ACTION + LOCATION -->
                <div>
                    <section class="flat-card" aria-labelledby="actionTitle">
                        <?php if ($is_cleaned): ?>
                            <h2 class="card-title" id="actionTitle"><i class="ti ti-circle-check"></i> Cleanup Completed</h2>
                            <div class="notice success" role="status" style="margin-bottom: 20px;"><i class="ti ti-circle-check"></i> This report has already been marked as Cleaned.</div>

                            <?php if ($after_photo !== ''): ?>
                                <figure style="margin: 0 0 20px 0;">
                                    <div class="photo-frame" style="margin-bottom: 8px;">
                                        <img src="uploads/reports/<?php echo h($after_photo); ?>" alt="Photo of the cleaned area" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                        <div class="photo-fallback"><i class="ti ti-photo-off"></i> Image not found</div>
                                        <a class="photo-open" href="uploads/reports/<?php echo h($after_photo); ?>" target="_blank" rel="noopener"><i class="ti ti-arrows-maximize"></i> Open full size</a>
                                    </div>
                                    <figcaption style="font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">After photo</figcaption>
                                </figure>
                            <?php endif; ?>
                            <a href="admin_dashboard.php?view=reports" class="flat-btn primary block"><i class="ti ti-arrow-left"></i> Back to Reports</a>

                        <?php else: ?>
                            <h2 class="card-title" id="actionTitle"><i class="ti ti-checkup-list"></i> Mark as Cleaned</h2>
                            <p style="margin: 0 0 20px 0; color: var(--text-muted); line-height: 1.6;">
                                Upload a photo showing the area has been cleaned by barangay personnel. It is saved as the <strong>"After"</strong> photo.
                            </p>

                            <form method="POST" action="resolve_report.php?id=<?php echo (int)$report_id; ?>" enctype="multipart/form-data" id="resolveForm" novalidate>
                                <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                                <input type="hidden" name="mark_resolved" value="1">

                                <label class="form-label" for="photoInput"><i class="ti ti-upload"></i> 'After' photo</label>

                                <div class="dropzone" id="dropzone">
                                    <input type="file" id="photoInput" name="photo_after" class="sr-only" accept=".jpg,.jpeg,.png,image/jpeg,image/png" aria-describedby="photoHelp photoError">
                                    <label for="photoInput" class="dz-empty" id="dzEmpty">
                                        <i class="ti ti-cloud-upload"></i>
                                        <strong>Click to choose a photo</strong>
                                        <span>or drag and drop it here</span>
                                        <small id="photoHelp">JPG or PNG, up to 5 MB</small>
                                    </label>
                                    <div class="dz-preview" id="dzPreview" hidden>
                                        <img id="previewImg" src="" alt="Preview of the selected After photo">
                                        <div class="dz-meta">
                                            <span class="name" id="fileName"></span>
                                            <span class="size" id="fileSize"></span>
                                            <div><button type="button" class="flat-btn small" id="clearBtn"><i class="ti ti-x"></i> Remove</button></div>
                                        </div>
                                    </div>
                                </div>
                                <p class="field-error" id="photoError" role="alert" hidden></p>

                                <button type="submit" class="flat-btn primary block" id="submitBtn" style="margin-top: 20px;" disabled>
                                    <i class="ti ti-check"></i> Mark as Cleaned
                                </button>
                            </form>

                            <div class="next-steps">
                                <strong>What happens next</strong>
                                <ul>
                                    <li>The report status changes to Cleaned.</li>
                                    <li>The After photo appears in the Waste Reports table.</li>
                                    <li>Its pin on the dashboard map turns blue.</li>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </section>

                    <?php if ($has_location): ?>
                    <section class="flat-card" aria-labelledby="locTitle">
                        <h2 class="card-title" id="locTitle"><i class="ti ti-map-pin"></i> Location</h2>
                        <div id="miniMap" role="img" aria-label="Map showing where the report was made"></div>
                        <div class="coords"><?php echo h(number_format($lat, 6)); ?>, <?php echo h(number_format($lng, 6)); ?></div>
                        <a href="https://www.google.com/maps?q=<?php echo h($lat . ',' . $lng); ?>" target="_blank" rel="noopener" class="flat-btn outline-blue" style="width: 100%;">
                            <i class="ti ti-external-link"></i> Open in Google Maps
                        </a>
                    </section>
                    <?php endif; ?>
                </div>

            </div>
        </main>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    // ---------- Clock (same as the dashboard) ----------
    function updateDateTime() {
        const now = new Date();
        const options = { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' };
        const el = document.getElementById('liveDateTime');
        if (el) el.innerHTML = `<i class="ti ti-clock"></i> ${now.toLocaleDateString('en-US', options)}`;
    }
    setInterval(updateDateTime, 30000);
    updateDateTime();

    <?php if (!$is_cleaned): ?>
    // ---------- After-photo picker: validation, preview, drag and drop ----------
    (function () {
        const MAX_BYTES = 5 * 1024 * 1024;
        const input = document.getElementById('photoInput');
        const zone = document.getElementById('dropzone');
        const empty = document.getElementById('dzEmpty');
        const preview = document.getElementById('dzPreview');
        const img = document.getElementById('previewImg');
        const nameEl = document.getElementById('fileName');
        const sizeEl = document.getElementById('fileSize');
        const errEl = document.getElementById('photoError');
        const clearBtn = document.getElementById('clearBtn');
        const submitBtn = document.getElementById('submitBtn');
        const form = document.getElementById('resolveForm');
        let objectUrl = null;

        function showError(msg) {
            errEl.textContent = msg;
            errEl.hidden = !msg;
            zone.classList.toggle('has-error', !!msg);
        }

        function reset() {
            if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
            input.value = '';
            img.removeAttribute('src');
            preview.hidden = true;
            empty.hidden = false;
            submitBtn.disabled = true;
        }

        function formatSize(bytes) {
            return bytes >= 1048576 ? (bytes / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(bytes / 1024)) + ' KB';
        }

        function handleFile(file) {
            showError('');
            if (!['image/jpeg', 'image/png'].includes(file.type)) {
                reset();
                showError('Only JPG or PNG images are allowed.');
                return;
            }
            if (file.size > MAX_BYTES) {
                reset();
                showError('That photo is ' + formatSize(file.size) + '. Please choose one that is 5 MB or smaller.');
                return;
            }
            if (objectUrl) URL.revokeObjectURL(objectUrl);
            objectUrl = URL.createObjectURL(file);
            img.src = objectUrl;
            nameEl.textContent = file.name;
            sizeEl.textContent = formatSize(file.size);
            empty.hidden = true;
            preview.hidden = false;
            submitBtn.disabled = false;
        }

        input.addEventListener('change', function () {
            if (input.files && input.files[0]) { handleFile(input.files[0]); } else { reset(); }
        });

        ['dragenter', 'dragover'].forEach(function (evt) {
            zone.addEventListener(evt, function (e) { e.preventDefault(); zone.classList.add('dragover'); });
        });
        ['dragleave', 'drop'].forEach(function (evt) {
            zone.addEventListener(evt, function (e) { e.preventDefault(); zone.classList.remove('dragover'); });
        });
        zone.addEventListener('drop', function (e) {
            const file = e.dataTransfer && e.dataTransfer.files[0];
            if (!file) return;
            const dt = new DataTransfer();
            dt.items.add(file);
            input.files = dt.files;
            handleFile(file);
        });

        clearBtn.addEventListener('click', function () { showError(''); reset(); input.focus(); });

        form.addEventListener('submit', function (e) {
            if (!input.files || !input.files[0]) {
                e.preventDefault();
                showError('Please choose an "After" photo first.');
                return;
            }
            if (!confirm('Mark this report as cleaned?')) {
                e.preventDefault();
                return;
            }
            submitBtn.disabled = true; // prevents double submits
            submitBtn.innerHTML = '<i class="ti ti-loader-2"></i> Saving...';
        });
    })();
    <?php endif; ?>

    <?php if ($has_location): ?>
    // ---------- Mini map (pin colours match the dashboard map) ----------
    window.addEventListener('load', function () {
        const lat = <?php echo json_encode($lat); ?>;
        const lng = <?php echo json_encode($lng); ?>;
        const isCleaned = <?php echo $is_cleaned ? 'true' : 'false'; ?>;
        const map = L.map('miniMap', { scrollWheelZoom: false }).setView([lat, lng], 17);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);
        L.marker([lat, lng], {
            icon: L.divIcon({
                className: 'map-pin-wrap',
                html: '<span class="map-pin ' + (isCleaned ? 'cleaned' : 'pending') + '"></span>',
                iconSize: [20, 20],
                iconAnchor: [10, 10]
            })
        }).addTo(map);
        setTimeout(function () { map.invalidateSize(); }, 200);
    });
    <?php endif; ?>
</script>

</body>
</html>
