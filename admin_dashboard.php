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

// Escape helper for all HTML output
if (!function_exists('h')) {
    function h($value) { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8'); }
}

// CSRF token: every POST form below must send it back as "csrf"
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

// Only known views are allowed; anything else falls back to the dashboard
$view_titles = [
    'dashboard' => 'Dashboard',
    'reports'   => 'Waste Reports',
    'residents' => 'Resident Management',
    'alert'     => 'Basura-Alert Broadcast',
    'settings'  => 'System Settings',
];
$view = $_GET['view'] ?? 'dashboard';
if (!is_string($view) || !isset($view_titles[$view])) { $view = 'dashboard'; }

$admin_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT full_name, password FROM users WHERE user_id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin_data = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();
$admin_name = $admin_data['full_name'] ?? 'Admin User';

if (!isset($_SESSION['last_seen_report_id'])) {
    $_SESSION['last_seen_report_id'] = 0;
}

if ($view === 'reports') {
    $max_id_query = $conn->query("SELECT MAX(report_id) as max_id FROM waste_reports");
    $max_id = $max_id_query->fetch_assoc()['max_id'] ?? 0;
    $_SESSION['last_seen_report_id'] = $max_id;
}

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
$info_id = (int)$info['id'];

// -----------------------------------------------------------------------------
// 3. BACKEND ACTION HANDLERS
// -----------------------------------------------------------------------------
$update_success = false;
$update_error = '';
$pass_success = false;
$pass_error = '';
$alert_error = '';
$alert_sent = isset($_GET['sent']);
$alert_draft = 'The garbage truck is currently near your area. Please prepare your segregated waste.';

/**
 * Validates and stores an uploaded JPG/PNG (max 2MB). Returns the new file name,
 * or $current when nothing was uploaded or validation failed ($error explains why).
 */
function save_image_upload($field, $dir, $prefix, $current, &$error) {
    if (empty($_FILES[$field]['name'])) { return $current; }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) { $error = 'Image upload failed. Please try again.'; return $current; }
    if ($f['size'] > 2 * 1024 * 1024) { $error = 'Image is larger than 2MB.'; return $current; }
    $img = @getimagesize($f['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png'];
    if (!$img || !isset($types[$img[2]])) { $error = 'Only JPG or PNG images are allowed.'; return $current; }
    if (!is_dir($dir)) { mkdir($dir, 0755, true); }
    $name = $prefix . bin2hex(random_bytes(8)) . '.' . $types[$img[2]];
    if (!move_uploaded_file($f['tmp_name'], $dir . $name)) { $error = 'Could not save the image.'; return $current; }
    return $name;
}

// ---- Save barangay profile -------------------------------------------------
if (isset($_POST['update_info'])) {
    $field = function ($key) { return trim((string)($_POST[$key] ?? '')); };

    $b_name = $field('barangay_name');
    if ($b_name === '') { $b_name = (string)($info['barangay_name'] ?? ''); }

    $logo_file_name = save_image_upload('barangay_logo', 'uploads/logo/', 'logo_', (string)($info['logo_path'] ?? ''), $update_error);
    $sec_pic_name   = save_image_upload('secretary_pic', 'uploads/profiles/', 'sec_', (string)($info['secretary_profile_pic'] ?? ''), $update_error);

    // Stored as plain text. Escape when displaying (htmlspecialchars / esc()), not when saving.
    $custom_hotlines_arr = [];
    $names = $_POST['custom_hotline_names'] ?? [];
    $numbers = $_POST['custom_hotline_numbers'] ?? [];
    if (is_array($names) && is_array($numbers)) {
        foreach ($names as $i => $n) {
            $name = trim((string)$n);
            $num  = trim((string)($numbers[$i] ?? ''));
            if ($name !== '' && $num !== '') {
                $custom_hotlines_arr[] = ['name' => mb_substr($name, 0, 100), 'number' => mb_substr($num, 0, 50)];
            }
        }
    }
    $custom_hotlines_json = json_encode($custom_hotlines_arr);

    $values = [
        $b_name, $field('captain_name'), $field('captain_contact'),
        $field('secretary_name'), $field('secretary_contact'), $field('contact_number'),
        $field('full_address'), $field('zip_code'), $field('region'),
        $logo_file_name, $sec_pic_name,
        $field('system_subtitle'), $field('system_description'), $field('city'), $field('municipal'),
        $field('office_hours'), $field('emergency_hotlines'),
        $field('pnp_hotline'), $field('bfp_hotline'), $field('mdrrmo_hotline'), $field('health_center_hotline'),
        $custom_hotlines_json,
        $info_id
    ];

    $stmt = $conn->prepare("UPDATE barangay_information SET barangay_name=?, captain_name=?, captain_contact=?, secretary_name=?, secretary_contact=?, contact_number=?, full_address=?, zip_code=?, region=?, logo_path=?, secretary_profile_pic=?, system_subtitle=?, system_description=?, city=?, municipal=?, office_hours=?, emergency_hotlines=?, pnp_hotline=?, bfp_hotline=?, mdrrmo_hotline=?, health_center_hotline=?, custom_hotlines=? WHERE id=?");
    $stmt->bind_param(str_repeat('s', 22) . 'i', ...$values);

    if ($stmt->execute()) {
        $update_success = true;
        $info = $conn->query("SELECT * FROM barangay_information LIMIT 1")->fetch_assoc();
    } else {
        $update_error = $update_error !== '' ? $update_error : 'Could not save the profile. Please try again.';
    }
    $stmt->close();
}

// ---- Change password -------------------------------------------------------
if (isset($_POST['change_password'])) {
    $current_pass = (string)($_POST['current_password'] ?? '');
    $new_pass     = (string)($_POST['new_password'] ?? '');
    $confirm_pass = (string)($_POST['confirm_password'] ?? '');
    $stored       = (string)($admin_data['password'] ?? '');

    // The second check only exists for old plain-text passwords; they become hashed on the next change.
    $current_ok = password_verify($current_pass, $stored) || ($stored !== '' && hash_equals($stored, $current_pass));

    if (!$current_ok) {
        $pass_error = "Current password is incorrect.";
    } elseif (strlen($new_pass) < 8) {
        $pass_error = "New password must be at least 8 characters.";
    } elseif ($new_pass !== $confirm_pass) {
        $pass_error = "New passwords do not match.";
    } else {
        $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
        $stmt->bind_param("si", $hashed, $admin_id);
        if ($stmt->execute()) {
            session_regenerate_id(true);
            $pass_success = true;
        } else {
            $pass_error = "Could not update the password. Please try again.";
        }
        $stmt->close();
    }
}

// ---- Send Basura-Alert -----------------------------------------------------
if (isset($_POST['send_basura_alert'])) {
    $purok = 'All Areas'; // only audience available for now
    $message = trim((string)($_POST['alert_message'] ?? ''));
    $alert_draft = $message;

    if ($message === '') {
        $alert_error = 'Please type a message before sending.';
    } elseif (mb_strlen($message) > 250) {
        $alert_error = 'Message is too long. Please keep it to 250 characters or fewer.';
    } else {
        $stmt = $conn->prepare("INSERT INTO basura_alerts (purok_area, admin_id, message) VALUES (?, ?, ?)");
        $stmt->bind_param("sis", $purok, $admin_id, $message);
        if ($stmt->execute()) {
            header("Location: admin_dashboard.php?view=alert&sent=1");
            exit();
        }
        $alert_error = 'The alert could not be saved. Please try again.';
        $stmt->close();
    }
}

// ---- Approve / reject / restore a resident (POST + CSRF) -------------------
if (isset($_POST['resident_action'], $_POST['id'])) {
    $target_id = (int)$_POST['id'];
    $status_map = ['approve' => 'Approved', 'reject' => 'Rejected'];
    $action = (string)$_POST['resident_action'];
    $return_view = in_array($_POST['return'] ?? '', ['residents', 'dashboard'], true) ? $_POST['return'] : 'residents';

    if (isset($status_map[$action])) {
        $new_status = $status_map[$action];
        $stmt = $conn->prepare("UPDATE users SET account_status = ? WHERE user_id = ? AND role = 'Resident'");
        $stmt->bind_param("si", $new_status, $target_id);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: admin_dashboard.php?view=" . $return_view);
    exit();
}

// ---- Delete a resident (POST + CSRF, residents only) -----------------------
if (isset($_POST['delete_resident_id'])) {
    $del_id = (int)$_POST['delete_resident_id'];
    $delete_result = 'notfound';
    $return_tab = 'Approved';

    try {
        // Remember the ID photo and tab first, so the file can be removed once the account is gone
        $stmt = $conn->prepare("SELECT id_photo_path, account_status FROM users WHERE user_id = ? AND role = 'Resident'");
        $stmt->bind_param("i", $del_id);
        $stmt->execute();
        $target = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($target) {
            $return_tab = ($target['account_status'] ?? '') === 'Rejected' ? 'Rejected' : 'Approved';

            $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ? AND role = 'Resident'");
            $stmt->bind_param("i", $del_id);
            $stmt->execute();
            $removed = $stmt->affected_rows > 0;
            $stmt->close();

            if ($removed) {
                $delete_result = 'deleted';
                $id_file = basename((string)($target['id_photo_path'] ?? ''));
                if ($id_file !== '' && is_file('uploads/ids/' . $id_file)) {
                    @unlink('uploads/ids/' . $id_file);
                }
            }
        }
    } catch (mysqli_sql_exception $e) {
        // Usually a database link (foreign key) that still points to this resident
        error_log('Delete resident failed: ' . $e->getMessage());
        $delete_result = 'blocked';
    }

    header("Location: admin_dashboard.php?view=residents&tab=" . $return_tab . "&status=" . $delete_result);
    exit();
}

// -----------------------------------------------------------------------------
// 4. DATA FOR THE CURRENT VIEW
// -----------------------------------------------------------------------------

// Sidebar logo: use the uploaded logo when it exists, otherwise the default
$logo_file = (string)($info['logo_path'] ?? '');
$logo_src = ($logo_file !== '' && file_exists('uploads/logo/' . $logo_file)) ? 'uploads/logo/' . $logo_file : 'tanzalogo.jpg';

// Residents for the modal. Only the fields the modal needs are sent to the browser (never password hashes).
$all_residents = [];
if ($view === 'residents') {
    $report_counts = [];
    $rc = $conn->query("SELECT resident_id, COUNT(*) AS c FROM waste_reports GROUP BY resident_id");
    if ($rc) {
        while ($x = $rc->fetch_assoc()) { $report_counts[(int)$x['resident_id']] = (int)$x['c']; }
    }

    $res_query = $conn->query("SELECT * FROM users WHERE role='Resident' AND account_status IN ('Pending', 'Approved', 'Rejected')");
    if ($res_query) {
        while ($r = $res_query->fetch_assoc()) {
            $all_residents[$r['user_id']] = [
                'user_id'       => (int)$r['user_id'],
                'full_name'     => $r['full_name'] ?? '',
                'username'      => $r['username'] ?? '',
                'phone_number'  => $r['phone_number'] ?? '',
                'address'       => $r['address_purok_sitio'] ?? ($r['address'] ?? ''),
                'date_of_birth' => $r['date_of_birth'] ?? ($r['dob'] ?? ''),
                'id_photo_path' => $r['id_photo_path'] ?? '',
                'account_status'=> $r['account_status'] ?? '',
                'report_count'  => $report_counts[(int)$r['user_id']] ?? 0,
            ];
        }
    }
}

// Dashboard numbers (real values instead of hardcoded text)
$stat_pending = $stat_cleaned = $stat_total = $stat_week = $stat_pending_week = 0;
$stat_active = $stat_awaiting = $stat_cleaned_pct = 0;
$map_data = [];
if ($view === 'dashboard') {
    $s = $conn->query("SELECT COUNT(*) AS total,
        COALESCE(SUM(status='Pending'),0) AS pending,
        COALESCE(SUM(status='Cleaned'),0) AS cleaned,
        COALESCE(SUM(created_at >= NOW() - INTERVAL 7 DAY),0) AS week,
        COALESCE(SUM(status='Pending' AND created_at >= NOW() - INTERVAL 7 DAY),0) AS pending_week
        FROM waste_reports");
    if ($s && ($row = $s->fetch_assoc())) {
        $stat_total = (int)$row['total'];
        $stat_pending = (int)$row['pending'];
        $stat_cleaned = (int)$row['cleaned'];
        $stat_week = (int)$row['week'];
        $stat_pending_week = (int)$row['pending_week'];
        $stat_cleaned_pct = $stat_total > 0 ? (int)round($stat_cleaned / $stat_total * 100) : 0;
    }
    $s = $conn->query("SELECT COALESCE(SUM(account_status='Approved'),0) AS active, COALESCE(SUM(account_status='Pending'),0) AS awaiting FROM users WHERE role='Resident'");
    if ($s && ($row = $s->fetch_assoc())) {
        $stat_active = (int)$row['active'];
        $stat_awaiting = (int)$row['awaiting'];
    }

    // LEFT JOIN so reports from a deleted resident still show up
    $map_query = $conn->query("SELECT w.report_id, w.description, w.latitude, w.longitude, w.status, w.before_photo_path, w.created_at, COALESCE(u.full_name, 'Deleted user') AS full_name FROM waste_reports w LEFT JOIN users u ON w.resident_id = u.user_id WHERE w.latitude IS NOT NULL AND w.longitude IS NOT NULL");
    if ($map_query) {
        while ($row = $map_query->fetch_assoc()) { $map_data[] = $row; }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard | Coastal and Land Watch</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

  <style>
    :root {
        /* EXACT COLOR PALETTE FROM THE HTML PROVIDED */
        --bg-body: #f3f4f6; 
        --bg-surface: #ffffff;
        
        /* Sidebar */
        --sidebar-bg: #3F4D67; 
        --sidebar-hover: #344056; 
        --sidebar-text: #C9CFDA; 
        --sidebar-text-active: #ffffff;
        --sidebar-header-text: #B3BCCC;
        --sidebar-footer-bg: #344056;
        
        /* Accents - CHANGED TO SKY BLUE */
        --active-tab-blue: #0ea5e9;
        --primary: #0ea5e9; 
        --primary-light: #e0f2fe; 
        --primary-dark: #0284c7; 
        --avatar-text-dark: #0369a1;
        --primary-strong: #0369a1;
        --primary-strong-hover: #075985;
        --alert-red: #EF4444;
        
        /* Text */
        --text-main: #1F2937; 
        --text-muted: #687280; 
        --border-color: #e5e7eb;
        
        /* Welcome Banner */
        --banner-bg: #0284c7;
        
        /* Shadows & Radius */
        --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
        --radius-card: 12px;
    }

    /* Always reserve the scrollbar space so the top bar sits in the same place on every page */
    html { overflow-y: scroll; }
    body { font-family: 'Inter', sans-serif; background-color: var(--bg-body); color: var(--text-main); margin: 0; padding: 0; overflow-x: hidden; font-size: 14px; }
    
    .app-container { display: flex; min-height: 100vh; }
    .sidebar { width: 260px; background: var(--sidebar-bg); display: flex; flex-direction: column; position: fixed; top: 0; bottom: 0; left: 0; z-index: 50; }
    
    .workspace-header { padding: 32px 20px 24px 20px; display: flex; align-items: center; justify-content: center; border-bottom: 1px solid var(--sidebar-hover); }
    .workspace-brand { display: flex; flex-direction: column; align-items: center; gap: 16px; width: 100%; text-align: center;}
    .workspace-logo { width: 85px; height: 85px; border-radius: 50%; object-fit: cover; box-shadow: 0 0 0 3px rgba(255,255,255,0.15); }
    .workspace-text h2 { margin: 0; font-size: 18px; font-weight: 700; color: var(--sidebar-text-active); line-height: 1.4; letter-spacing: 0.5px; }
    
    .sidebar-menu { padding: 10px 16px; flex: 1; overflow-y: auto; }
    .menu-label { font-size: 11px; color: var(--sidebar-header-text); font-weight: 600; padding: 16px 16px 8px; text-transform: uppercase; letter-spacing: 0.5px; }
    
    .nav-link { display: flex; align-items: center; gap: 12px; padding: 12px 16px; padding-left: 12px; color: var(--sidebar-text); text-decoration: none; border-radius: 6px; font-size: 14px; font-weight: 500; transition: background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease, transform 0.2s ease; margin-bottom: 4px; border-left: 4px solid transparent; will-change: transform; }
    .nav-link i { font-size: 20px; display: inline-block; width: 20px; text-align: center; color: var(--sidebar-text); }
    .nav-link:hover { background: var(--sidebar-hover); color: var(--sidebar-text-active); transform: translateX(3px); }
    
    /* ACTIVE TAB COLORS */
    .nav-link.active { background: var(--sidebar-hover); color: var(--sidebar-text-active); border-left: 4px solid var(--active-tab-blue); border-radius: 4px 8px 8px 4px; font-weight: 600; }
    .nav-link.active i { color: var(--active-tab-blue); }
    .nav-link.active:hover { transform: none; }
    
    .user-footer { padding: 20px; border-top: 1px solid #2B3548; background: var(--sidebar-footer-bg); display: flex; align-items: center; justify-content: space-between; }
    .user-info { display: flex; align-items: center; gap: 12px; }
    .user-avatar { width: 36px; height: 36px; border-radius: 50%; background: var(--primary-dark); color: #fff; display: flex; justify-content: center; align-items: center; font-weight: 600; font-size: 14px; overflow: hidden; }
    .user-text h4 { margin: 0; font-size: 13px; font-weight: 600; color: var(--sidebar-text-active); }
    .user-text p { margin: 0; font-size: 11px; color: var(--sidebar-header-text); }
    .user-footer a { color: var(--sidebar-text); transition: color 0.2s ease; }
    .user-footer a:hover { color: var(--alert-red); }
    .user-footer a i { display: inline-block; transition: transform 0.2s ease; }
    .user-footer a:hover i { transform: scale(1.15); }

    .main-wrapper { flex: 1; min-width: 0; margin-left: 260px; display: flex; flex-direction: column; }
    
    /* TOPBAR */
    .topbar { position: sticky; top: 0; z-index: 40; background: var(--sidebar-bg); border-bottom: 1px solid var(--sidebar-hover); height: 70px; padding: 0 32px; display: flex; justify-content: space-between; align-items: center; }
    .topbar-left { min-width: 0; white-space: nowrap; display: flex; align-items: center; gap: 8px; color: #ffffff; font-size: 15px; font-weight: 600; }
    .topbar-left i { color: var(--sidebar-text); font-size: 22px; }
    .topbar-right { display: flex; align-items: center; gap: 20px; flex-shrink: 0; }
    .topbar-icons { display: flex; gap: 16px; color: #ffffff; font-size: 20px; align-items: center; }
    
    .notification-btn { border: 0; color: #fff; padding: 0; font: inherit; position: relative; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: transform 0.18s ease, background-color 0.18s ease, color 0.18s ease; background: rgba(0, 0, 0, 0.2); will-change: transform; }
    .notification-btn:hover { background: rgba(0, 0, 0, 0.3); color: var(--sidebar-text); transform: scale(1.1); }
    .notification-btn:active { transform: scale(0.95); }
    .notification-badge-count { position: absolute; top: 4px; right: 4px; background: var(--alert-red); color: white; font-size: 10px; font-weight: bold; width: 16px; height: 16px; display: none; align-items: center; justify-content: center; border-radius: 50%; border: 2px solid var(--sidebar-bg); }
    
    .notification-dropdown { position: absolute; top: 60px; right: 32px; width: 320px; background: var(--bg-surface); border-radius: 12px; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1); border: 1px solid var(--border-color); z-index: 100; display: none; flex-direction: column; overflow: hidden; color: var(--text-main); }
    .notification-dropdown.active { display: flex; }
    .notif-header { padding: 16px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: #f9fafb; }
    .notif-header h3 { margin: 0; font-size: 14px; font-weight: 600; }
    .notif-list { max-height: 300px; overflow-y: auto; }
    .notif-item { padding: 12px 16px; border-bottom: 1px solid var(--border-color); text-decoration: none; display: block; transition: background-color 0.2s ease; }
    .notif-item:hover { background: #f9fafb; }
    .notif-item .title { font-size: 13px; font-weight: 600; color: var(--primary); margin-bottom: 4px; }
    .notif-item .desc { font-size: 12px; color: var(--text-muted); line-height: 1.4; margin-bottom: 4px; }
    .notif-item .time { font-size: 11px; color: #6b7280; display: flex; align-items: center; gap: 4px; }
    .notif-footer { padding: 12px; text-align: center; border-top: 1px solid var(--border-color); background: #f9fafb; }
    .notif-footer a { font-size: 13px; color: var(--primary); text-decoration: none; font-weight: 500; }

    .live-datetime { display: flex; align-items: center; justify-content: center; gap: 8px; box-sizing: border-box; height: 40px; min-width: 260px; flex-shrink: 0; white-space: nowrap; line-height: 1; font-variant-numeric: tabular-nums; background: rgba(0, 0, 0, 0.2); border: 1px solid rgba(0, 0, 0, 0.2); padding: 0 14px; border-radius: 8px; font-size: 13px; font-weight: 500; color: #ffffff; }
    .live-datetime i { color: var(--sidebar-text); font-size: 16px; }

    .content-area { padding: 32px; flex: 1; }
    
    /* WELCOME BANNER */
    .welcome-banner { background: var(--banner-bg); border-radius: var(--radius-card); padding: 32px; color: white; display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; box-shadow: var(--shadow-md); }
    .welcome-text h1 { margin: 0 0 8px 0; font-size: 28px; font-weight: 700; letter-spacing: -0.5px; }
    .welcome-text p { margin: 0; font-size: 14px; color: #ffffff; }
    .welcome-btn { background: rgba(255, 255, 255, 0.1); color: white; border: 1px solid rgba(255, 255, 255, 0.3); padding: 10px 20px; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer; transition: transform 0.18s ease, box-shadow 0.18s ease, background-color 0.18s ease, border-color 0.18s ease; display: flex; align-items: center; gap: 8px; text-decoration: none; will-change: transform; }
    .welcome-btn:hover { background: rgba(255, 255, 255, 0.2); border-color: rgba(255, 255, 255, 0.5); transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0, 0, 0, 0.15); }
    .welcome-btn:active { transform: translateY(0); box-shadow: none; }

    .flat-card { background: var(--bg-surface); border: 1px solid #f3f4f6; border-radius: var(--radius-card); padding: 24px; margin-bottom: 24px; box-shadow: var(--shadow-sm); }
    .card-title { font-size: 16px; font-weight: 600; color: var(--text-main); margin: 0 0 20px 0; display: flex; justify-content: space-between; align-items: center; }
    
    .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 24px; }
    .stat-box { background: var(--bg-surface); border-radius: var(--radius-card); padding: 20px; box-shadow: var(--shadow-sm); border: 1px solid #f3f4f6; display: flex; flex-direction: column; transition: 0.2s; cursor: default; }
    .stat-box:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }
    .stat-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
    .stat-header span { font-size: 14px; font-weight: 500; color: var(--text-muted); }
    
    .stat-icon { width: 42px; height: 42px; border-radius: 12px; background: var(--primary-dark); color: #ffffff; display: flex; align-items: center; justify-content: center; font-size: 22px; transition: all 0.3s ease; }
    .stat-box:hover .stat-icon { box-shadow: 0 4px 12px rgba(14, 165, 233, 0.3); transform: scale(1.05); }
    
    .stat-body h2 { margin: 0 0 8px 0; font-size: 28px; font-weight: 700; color: var(--text-main); line-height: 1; }
    .stat-footer { font-size: 12px; display: flex; align-items: center; gap: 4px; font-weight: 500; }
    .stat-footer.up { color: var(--primary); }
    .stat-footer.down { color: var(--alert-red); }
    .stat-footer.neutral { color: var(--text-muted); }

    .table-controls { display: flex; flex-wrap: wrap; gap: 12px; justify-content: space-between; align-items: center; padding-bottom: 20px; border-bottom: 1px solid var(--border-color); margin-bottom: 20px; }
    .flat-input { border: 1px solid var(--border-color); border-radius: 8px; padding: 10px 14px; font-size: 14px; outline: none; transition: 0.2s; font-family: inherit; color: var(--text-main); background: var(--bg-body); }
    .flat-input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); background: #fff; }
    
    .flat-btn { background: var(--bg-surface); border: 1px solid var(--border-color); color: var(--text-main); padding: 10px 16px; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer; transition: transform 0.18s ease, box-shadow 0.18s ease, background-color 0.18s ease, border-color 0.18s ease, color 0.18s ease; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; will-change: transform; }
    .flat-btn:hover:not(:disabled) { background: var(--bg-body); color: var(--text-main); transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08); }
    .flat-btn:active:not(:disabled) { transform: translateY(0); box-shadow: none; }
    .flat-btn.primary { background: var(--primary-strong); color: #fff; border-color: var(--primary-strong); }
    .flat-btn.primary:hover:not(:disabled) { background: var(--primary-strong-hover); border-color: var(--primary-strong-hover); transform: translateY(-2px); box-shadow: 0 6px 16px rgba(14, 165, 233, 0.35); }
    .flat-btn.primary:active:not(:disabled) { transform: translateY(0); box-shadow: 0 2px 6px rgba(14, 165, 233, 0.25); }
    .flat-btn.danger { color: #dc2626; border-color: #fca5a5; background: #fef2f2; }
    .flat-btn.danger:hover:not(:disabled) { background: #fee2e2; border-color: #f87171; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(220, 38, 38, 0.2); }
    .flat-btn.danger-solid { background: #dc2626; color: #fff; border-color: #dc2626; }
    .flat-btn.danger-solid:hover:not(:disabled) { background: #b91c1c; border-color: #b91c1c; color: #fff; transform: translateY(-2px); box-shadow: 0 6px 16px rgba(220, 38, 38, 0.4); }
    .flat-btn.danger-solid:active:not(:disabled) { transform: translateY(0); box-shadow: 0 2px 6px rgba(220, 38, 38, 0.3); }
    .flat-btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none !important; box-shadow: none !important; }
    .row-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }

    /* Delete confirmation box */
    .confirm-box { max-width: 440px; text-align: center; padding: 36px 32px 28px; }
    .confirm-icon { width: 68px; height: 68px; border-radius: 50%; margin: 0 auto 18px; display: flex; align-items: center; justify-content: center; font-size: 30px; background: #fef2f2; color: #dc2626; box-shadow: 0 0 0 8px #fee2e2; }
    .confirm-title { margin: 0 0 10px; font-size: 20px; font-weight: 700; color: var(--text-main); }
    .confirm-text { margin: 0 0 14px; font-size: 14px; line-height: 1.6; color: var(--text-muted); }
    .confirm-text strong { color: var(--text-main); }
    .confirm-user { color: var(--text-muted); font-weight: 500; }
    .confirm-note { margin: 0 0 22px; padding: 10px 14px; border-radius: 8px; background: var(--bg-body); font-size: 13px; font-weight: 500; color: var(--text-muted); }
    .confirm-icon.info { background: var(--primary-light); color: var(--primary-strong); box-shadow: 0 0 0 8px #e0f2fe; }
    .confirm-preview { text-align: left; background: var(--bg-body); border: 1px solid var(--border-color); border-left: 3px solid var(--primary); border-radius: 8px; padding: 12px 14px; margin: 0 0 22px; font-size: 14px; line-height: 1.55; color: var(--text-main); }
    .confirm-preview #broadcastPreview { white-space: pre-wrap; word-break: break-word; max-height: 160px; overflow-y: auto; }
    .confirm-preview span { display: block; margin-top: 8px; font-size: 12px; color: var(--text-muted); }
    .confirm-actions { display: flex; gap: 12px; }
    .confirm-actions .flat-btn { flex: 1; justify-content: center; padding: 12px 16px; }

    .flat-table { width: 100%; border-collapse: collapse; }
    .flat-table th { text-align: left; padding: 16px; background-color: transparent; font-size: 13px; font-weight: 700; color: var(--text-main); text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid var(--border-color); }
    .flat-table td { padding: 16px; border-bottom: 1px solid var(--border-color); font-size: 14px; vertical-align: middle; color: var(--text-main); transition: 0.2s; }
    .flat-table tr:last-child td { border-bottom: none; }
    .flat-table tr:hover td { background-color: #f9fafb; }

    .status-badge { padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; }
    .status-badge.pending { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
    .status-badge.active { background: #e0f2fe; color: #075985; border: 1px solid #bae6fd; }
    .status-badge.rejected { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
    
    .reporter-cell { display: flex; align-items: center; gap: 12px; }
    .reporter-avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--primary-light); color: var(--avatar-text-dark); display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 13px; }
    
    .photo-link { display: inline-flex; align-items: center; gap: 4px; text-decoration: none; font-size: 12px; font-weight: 600; padding: 4px 10px; border-radius: 6px; transition: transform 0.18s ease, box-shadow 0.18s ease, background-color 0.18s ease; margin-right: 6px; }
    .photo-link:hover { transform: translateY(-1px); box-shadow: 0 3px 8px rgba(0, 0, 0, 0.12); }
    .photo-link.before { background: #fef2f2; color: var(--alert-red); border: 1px solid #fecaca; }
    .photo-link.before:hover { background: #fee2e2; }
    .photo-link.after { background: var(--primary-light); color: var(--avatar-text-dark); border: 1px solid #bae6fd; }
    .photo-link.after:hover { background: #bae6fd; }

    .flat-tabs { display: flex; flex-wrap: wrap; gap: 8px; }
    .tab-btn { background: transparent; border: 1px solid transparent; font-size: 14px; font-weight: 500; color: var(--text-muted); padding: 8px 16px; border-radius: 8px; cursor: pointer; transition: transform 0.18s ease, background 0.18s ease, color 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease; display: flex; align-items: center; gap: 8px; will-change: transform; }
    .tab-btn i { font-size: 18px; }
    .tab-btn:hover { color: var(--text-main); background: var(--bg-body); transform: translateY(-1px); box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05); }
    .tab-btn:active { transform: translateY(0); }
    .tab-btn.active { background: var(--bg-surface); color: var(--primary); font-weight: 600; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1); border: 1px solid var(--border-color); }

    .form-group { margin-bottom: 24px; }
    .form-label { display: flex; align-items: center; gap: 6px; font-size: 14px; font-weight: 500; margin-bottom: 8px; color: var(--text-main); }
    .form-label i { color: var(--primary); font-size: 16px; }
    .form-control { width: 100%; border: 1px solid var(--border-color); border-radius: 8px; padding: 12px 14px; font-size: 14px; font-family: inherit; transition: 0.2s; box-sizing: border-box; background: var(--bg-body); }
    .form-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); background: #fff; }
    textarea.form-control { resize: vertical; min-height: 120px; line-height: 1.5; }

    .alert-banner { background: #fffbeb; border: 1px solid #fde68a; border-left: 4px solid #f59e0b; color: #b45309; padding: 16px; border-radius: 8px; margin-bottom: 24px; display: flex; align-items: flex-start; gap: 12px; font-size: 14px; }
    .alert-banner i { font-size: 20px; margin-top: 1px; color: #d97706; }
    
    .history-list { display: flex; flex-direction: column; gap: 12px; padding: 16px; }
    .history-item { padding: 16px; border: 1px solid var(--border-color); border-radius: 8px; display: flex; flex-direction: column; gap: 8px; transition: 0.2s; background: var(--bg-surface); }
    .history-item:hover { border-color: var(--primary-light); box-shadow: 0 2px 4px rgba(0,0,0,0.02); background: #fafafa; }
    .history-header { display: flex; justify-content: space-between; align-items: center; font-size: 13px; }
    .history-target { font-weight: 600; color: var(--text-main); display: flex; align-items: center; gap: 6px; }
    .history-target i { color: var(--primary); font-size: 16px; }
    .history-time { color: var(--text-muted); font-size: 12px; display: flex; align-items: center; gap: 4px; }
    .history-msg { font-size: 14px; color: var(--text-muted); line-height: 1.5; }

    .widget-list { list-style: none; padding: 0; margin: 0; }
    .widget-list li:last-child { border-bottom: none !important; padding-bottom: 0 !important; margin-bottom: 0 !important; }

    /* MODAL STYLES */
    .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 1000; padding: 20px; }
    .modal-content { background: #fff; border-radius: var(--radius-card); width: 100%; max-width: 500px; padding: 32px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); position: relative; max-height: 90vh; overflow-y: auto; }
    .modal-close { position: absolute; top: 20px; right: 20px; background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-muted); transition: transform 0.2s ease, color 0.2s ease; }
    .modal-close:hover { color: #dc2626; transform: rotate(90deg); }
    .modal-title { margin: 0 0 20px 0; font-size: 20px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px; }
    .modal-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
    .modal-table td { padding: 12px 8px; border-bottom: 1px solid var(--border-color); font-size: 14px; }
    .modal-table td:first-child { font-weight: 600; color: var(--text-muted); width: 35%; }
    .modal-table td:last-child { color: var(--text-main); font-weight: 500; }

    /* ---------- Layout helpers (replace repeated inline grid styles) ---------- */
    .dash-grid { display: grid; grid-template-columns: 2.2fr 1fr; gap: 24px; align-items: stretch; margin-bottom: 24px; }
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
    .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; }
    .settings-top { display: grid; grid-template-columns: 1fr 2fr; gap: 32px; margin-bottom: 32px; }
    .align-start { align-items: start; }
    .mb-24 { margin-bottom: 24px; }
    .mb-32 { margin-bottom: 32px; }
    .hotline-row { display: grid; grid-template-columns: 2fr 2fr auto; gap: 16px; margin-bottom: 12px; }

    .workspace-text p { margin: 4px 0 0; font-size: 12px; font-weight: 500; color: var(--sidebar-header-text); }
    .nav-text { display: inline; }
    .desc-cell { max-width: 250px; overflow-wrap: anywhere; }
    .empty-note { padding: 40px 24px; text-align: center; color: var(--text-muted); font-size: 14px; }

    .notice { padding: 16px; border-radius: 8px; margin-bottom: 24px; font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 8px; }
    .notice i { font-size: 20px; }
    .notice.success { background: var(--primary-light); border: 1px solid var(--primary); color: #075985; }
    .notice.error { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
    .notice.warn { background: #fffbeb; border: 1px solid #fde68a; color: #b45309; }

    /* Resident tabs are filtered by CSS so rows never flash before the script runs */
    #residentsTableBody[data-tab="Pending"] .resident-row:not([data-status="Pending"]),
    #residentsTableBody[data-tab="Approved"] .resident-row:not([data-status="Approved"]),
    #residentsTableBody[data-tab="Rejected"] .resident-row:not([data-status="Rejected"]) { display: none; }

    .form-control:disabled { cursor: not-allowed; opacity: 0.85; }

    /* ---------- Map pins: same colours as the legend ---------- */
    .map-pin-wrap { background: transparent; border: 0; }
    .map-pin { display: block; overflow: visible; filter: drop-shadow(0 3px 3px rgba(0, 0, 0, 0.35)); transition: transform 0.15s; transform-origin: 50% 100%; }
    .map-pin path { stroke: #fff; stroke-width: 2; stroke-linejoin: round; }
    .map-pin circle { fill: #fff; }
    .map-pin.pending path { fill: var(--alert-red); }
    .map-pin.cleaned path { fill: var(--primary); }
    .leaflet-marker-icon:hover .map-pin { transform: scale(1.12); }
    .legend-pin { display: block; }
    .legend-pin path { stroke: #fff; stroke-width: 2; stroke-linejoin: round; }
    .legend-pin circle { fill: #fff; }
    .legend-pin.pending path { fill: var(--alert-red); }
    .legend-pin.cleaned path { fill: var(--primary); }

    /* ---------- Keyboard focus + reduced motion ---------- */
    :focus-visible { outline: 2px solid var(--primary-strong); outline-offset: 2px; }
    .nav-link:focus-visible, .user-footer a:focus-visible, .notification-btn:focus-visible { outline-color: #ffffff; }

    @media (prefers-reduced-motion: reduce) {
        * { transition: none !important; animation: none !important; }
        .stat-box:hover, .welcome-btn:hover, .flat-btn:hover,
        .flat-btn.primary:hover, .flat-btn.danger:hover,
        .flat-btn.danger-solid:hover, .tab-btn:hover, .nav-link:hover,
        .notification-btn:hover, .notification-btn:active, .modal-close:hover,
        .photo-link:hover, .user-footer a:hover i { transform: none; }
    }

    /* ---------- Responsive ---------- */
    @media (max-width: 1200px) {
        .stats-grid { grid-template-columns: repeat(2, 1fr); }
        .dash-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 992px) {
        .grid-2, .grid-3, .settings-top { grid-template-columns: 1fr; }
    }
    @media (max-width: 860px) {
        /* Sidebar becomes an icon rail; labels stay available to screen readers */
        .sidebar { width: 72px; }
        .main-wrapper { margin-left: 72px; }
        .workspace-header { padding: 20px 8px 16px; }
        .workspace-logo { width: 44px; height: 44px; }
        .workspace-text, .menu-label, .user-text { display: none; }
        .sidebar-menu { padding: 10px 8px; }
        .nav-link { justify-content: center; padding: 12px 0; border-left-width: 0; }
        .nav-link:hover { transform: none; }
        .nav-link.active { border-left-width: 0; box-shadow: inset 3px 0 0 var(--active-tab-blue); }
        .nav-text { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
        .user-footer { flex-direction: column; gap: 12px; padding: 12px 8px; }
        .topbar { padding: 0 16px; }
        .content-area { padding: 16px; }
        .welcome-banner { flex-direction: column; align-items: flex-start; gap: 16px; padding: 24px; }
        .notification-dropdown { right: 16px; width: calc(100vw - 104px); max-width: 320px; }
    }
    @media (max-width: 560px) {
        .stats-grid { grid-template-columns: 1fr; }
        .live-datetime { display: none; }
        .hotline-row { grid-template-columns: 1fr; }
        .hotline-row .remove-hotline { padding: 10px 16px !important; justify-content: center; }
        .modal-content { padding: 24px 20px; }
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
            <a href="?view=dashboard" class="nav-link <?php echo $view == 'dashboard' ? 'active' : ''; ?>">
                <i class="ti ti-layout-grid"></i> <span class="nav-text">Dashboard</span>
            </a>
            <a href="?view=reports" class="nav-link <?php echo $view == 'reports' ? 'active' : ''; ?>">
                <i class="ti ti-file-report"></i> <span class="nav-text">Waste Reports</span>
            </a>
            
            <div class="menu-label" style="margin-top: 16px;">Community</div>
            <a href="?view=residents" class="nav-link <?php echo $view == 'residents' ? 'active' : ''; ?>">
                <i class="ti ti-users"></i> <span class="nav-text">Residents</span>
            </a>
            <a href="?view=alert" class="nav-link <?php echo $view == 'alert' ? 'active' : ''; ?>">
                <i class="ti ti-speakerphone"></i> <span class="nav-text">Basura-Alert</span>
            </a>
            
            <div class="menu-label" style="margin-top: 16px;">Configuration</div>
            <a href="?view=settings" class="nav-link <?php echo $view == 'settings' ? 'active' : ''; ?>">
                <i class="ti ti-settings"></i> <span class="nav-text">Settings</span>
            </a>
        </div>

        <div class="user-footer">
            <?php 
                $sidebar_display_name = !empty($info['secretary_name']) ? $info['secretary_name'] : $admin_name;
                $sidebar_initial = strtoupper(substr($sidebar_display_name, 0, 1));
                $sec_pic_path = !empty($info['secretary_profile_pic']) ? 'uploads/profiles/' . $info['secretary_profile_pic'] : '';
                $has_sec_pic = (!empty($sec_pic_path) && file_exists($sec_pic_path));
            ?>
            <div class="user-info">
                <div class="user-avatar">
                    <?php if ($has_sec_pic): ?>
                        <img src="<?php echo htmlspecialchars($sec_pic_path); ?>" alt="Profile" style="width: 100%; height: 100%; object-fit: cover;">
                    <?php else: ?>
                        <?php echo $sidebar_initial; ?>
                    <?php endif; ?>
                </div>
                <div class="user-text">
                    <h4><?php echo htmlspecialchars($sidebar_display_name); ?></h4>
                    <p>Administrator</p>
                </div>
            </div>
            <a href="logout.php" onclick="return confirm('Logout of system?');" title="Logout" aria-label="Logout"><i class="ti ti-logout"></i></a>
        </div>
    </aside>

    <div class="main-wrapper">
        <header class="topbar">
            <div class="topbar-left">
                <i class="ti ti-layout-dashboard"></i>
                <?php echo h($view_titles[$view]); ?>
            </div>
            <div class="topbar-right">
                <div class="live-datetime" id="liveDateTime">
                    <i class="ti ti-clock"></i> Loading...
                </div>
                <div class="topbar-icons">
                    <button type="button" class="notification-btn" id="notifBtn" onclick="toggleNotifications()" aria-label="Notifications" aria-haspopup="true" aria-expanded="false">
                        <i class="ti ti-bell"></i>
                        <span class="notification-badge-count" id="notifCount">0</span>
                    </button>
                </div>
            </div>

            <div class="notification-dropdown" id="notifDropdown">
                <div class="notif-header">
                    <h3>New Reports</h3>
                    <span style="font-size: 11px; color: var(--text-muted);" id="notifHeaderText">0 unread</span>
                </div>
                <div class="notif-list" id="notifList">
                    <div style="padding: 24px; text-align: center; color: var(--text-muted); font-size: 13px;">No new notifications</div>
                </div>
                <div class="notif-footer">
                    <a href="?view=reports">View All Reports</a>
                </div>
            </div>
        </header>

        <main class="content-area">
            
            <?php if ($view == 'dashboard'): ?>
            <div class="welcome-banner">
                <div class="welcome-text">
                    <!-- DINITO ANG PAGBABAGO: Kinukuha ang secretary name o admin name -->
                    <h1>Welcome back, <?php echo htmlspecialchars(!empty($info['secretary_name']) ? $info['secretary_name'] : $admin_name); ?>!</h1>
                    <p>Here's what's happening in your barangay today.</p>
                </div>
                <button class="welcome-btn" onclick="window.location.href='?view=reports'">
                    View Reports <i class="ti ti-arrow-right"></i>
                </button>
            </div>

            <div class="stats-grid">
                <div class="stat-box">
                    <div class="stat-header">
                        <span>Pending Reports</span> 
                        <div class="stat-icon"><i class="ti ti-clipboard-text"></i></div>
                    </div>
                    <div class="stat-body">
                        <h2><?php echo $stat_pending; ?></h2>
                        <div class="stat-footer neutral"><i class="ti ti-clock"></i> <?php echo $stat_pending_week; ?> new in the last 7 days</div>
                    </div>
                </div>

                <div class="stat-box">
                    <div class="stat-header">
                        <span>Cleaned Areas</span> 
                        <div class="stat-icon"><i class="ti ti-check"></i></div>
                    </div>
                    <div class="stat-body">
                        <h2><?php echo $stat_cleaned; ?></h2>
                        <div class="stat-footer up"><i class="ti ti-check"></i> <?php echo $stat_cleaned_pct; ?>% of all reports</div>
                    </div>
                </div>

                <div class="stat-box">
                    <div class="stat-header">
                        <span>Active Residents</span> 
                        <div class="stat-icon"><i class="ti ti-users"></i></div>
                    </div>
                    <div class="stat-body">
                        <h2><?php echo $stat_active; ?></h2>
                        <div class="stat-footer neutral"><i class="ti ti-clock"></i> <?php echo $stat_awaiting; ?> awaiting approval</div>
                    </div>
                </div>

                <div class="stat-box">
                    <div class="stat-header">
                        <span>Total Logs</span> 
                        <div class="stat-icon"><i class="ti ti-file-analytics"></i></div>
                    </div>
                    <div class="stat-body">
                        <h2><?php echo $stat_total; ?></h2>
                        <div class="stat-footer neutral"><i class="ti ti-calendar"></i> <?php echo $stat_week; ?> in the last 7 days</div>
                    </div>
                </div>
            </div>

            <div class="dash-grid">
                
                <div class="flat-card" style="margin-bottom: 0; display: flex; flex-direction: column; padding: 0; overflow: hidden;">
                    <div class="card-title" style="padding: 24px 24px 16px 24px; margin: 0;">
                        <div>
                            <div style="font-size: 16px; color: var(--text-main);">Barangay Map Activity</div>
                            <div style="font-size: 13px; color: var(--text-muted); font-weight: 400; margin-top: 4px;">Real-time geographical tracking</div>
                        </div>
                        <div style="display: flex; gap: 16px; font-size: 13px; font-weight: 500;">
                            <span style="display: flex; align-items: center; gap: 6px;"><svg class="legend-pin pending" width="14" height="19" viewBox="0 0 30 42" aria-hidden="true"><path d="M15 1C7.3 1 1 7.2 1 14.9c0 9.9 11.4 23.4 13.1 25.3a1.2 1.2 0 0 0 1.8 0C17.6 38.3 29 24.8 29 14.9 29 7.2 22.7 1 15 1z"/><circle cx="15" cy="15" r="5.5"/></svg> Pending</span>
                            <span style="display: flex; align-items: center; gap: 6px;"><svg class="legend-pin cleaned" width="14" height="19" viewBox="0 0 30 42" aria-hidden="true"><path d="M15 1C7.3 1 1 7.2 1 14.9c0 9.9 11.4 23.4 13.1 25.3a1.2 1.2 0 0 0 1.8 0C17.6 38.3 29 24.8 29 14.9 29 7.2 22.7 1 15 1z"/><circle cx="15" cy="15" r="5.5"/></svg> Cleaned</span>
                        </div>
                    </div>
                    <div id="masterMap" style="flex: 1; min-height: 400px; width: 100%; z-index: 1;"></div>
                </div>

                <div style="display: flex; flex-direction: column; gap: 24px;">
                    
                    <div class="flat-card" style="margin-bottom: 0; flex: 1; display: flex; flex-direction: column; padding: 24px;">
                        <div class="card-title" style="margin-bottom: 12px; padding: 0;">
                            <div>Pending Approvals</div>
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
                                    echo "      <div style='width: 36px; height: 36px; border-radius: 50%; background: var(--primary-light); color: var(--avatar-text-dark); display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 14px;'>{$initial}</div>";
                                    echo "      <div><h4 style='margin: 0; font-size: 14px; font-weight: 600; color: var(--text-main);'>" . htmlspecialchars($u['full_name']) . "</h4><p style='margin: 0; font-size: 12px; color: var(--text-muted);'>" . htmlspecialchars($u['phone_number']) . "</p></div>";
                                    echo "  </div>";
                                    echo "  <a href='admin_dashboard.php?view=residents&view_user_id=" . $u['user_id'] . "' class='flat-btn primary' style='padding: 6px 12px; font-size: 12px;'>View</a>";
                                    echo "</li>";
                                }
                            } else {
                                echo "<li style='padding: 20px 0; text-align: center; color: var(--text-muted); font-size: 13px;'>No pending approvals.</li>";
                            }
                            ?>
                        </ul>
                    </div>

                    <div class="flat-card" style="margin-bottom: 0; flex: 1; display: flex; flex-direction: column; padding: 24px;">
                        <div class="card-title" style="margin-bottom: 12px; padding: 0;">
                            <div>New Reports</div>
                            <a href="?view=reports" style="font-size: 13px; color: var(--primary); text-decoration: none; font-weight: 500;">View All</a>
                        </div>
                        <ul class="widget-list">
                            <?php
                            $pending_reps = $conn->query("SELECT w.*, COALESCE(u.full_name, 'Deleted user') AS full_name FROM waste_reports w LEFT JOIN users u ON w.resident_id = u.user_id WHERE w.status='Pending' ORDER BY w.created_at DESC LIMIT 3");
                            if($pending_reps && $pending_reps->num_rows > 0) {
                                while($r = $pending_reps->fetch_assoc()) {
                                    $time_ago = date("M d, g:i A", strtotime($r['created_at']));
                                    echo "<li style='display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid var(--border-color);'>";
                                    echo "  <div style='display: flex; align-items: center; gap: 12px;'>";
                                    echo "      <img src='uploads/reports/" . htmlspecialchars($r['before_photo_path']) . "' style='width: 40px; height: 40px; border-radius: 8px; object-fit: cover;' onerror=\"this.onerror=null;this.src='https://placehold.co/40x40/e5e7eb/a1a1aa?text=NA'\">";
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

            <?php elseif ($view == 'reports'): ?>
            <?php if (isset($_GET['resolved'])): ?>
                <div class="notice success" role="status"><i class="ti ti-circle-check"></i> Report #<?php echo (int)$_GET['resolved']; ?> was marked as Cleaned.</div>
            <?php endif; ?>
            <div class="flat-card" style="padding: 0; overflow: hidden;">
                <div class="table-controls" style="padding: 24px; margin: 0;">
                    <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                        <input type="text" id="searchInput" class="flat-input" aria-label="Search reports" placeholder="Search reporter or description..." style="width: 280px;">
                        
                        <div style="position: relative;">
                            <select id="statusFilter" class="flat-input" aria-label="Filter by status" onchange="filterTableDropdown()" style="padding-right: 32px; appearance: none; cursor: pointer;">
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

                <div style="overflow-x: auto;">
                    <table class="flat-table">
                        <colgroup>
                            <col style="width: 22%;"> <col style="width: 15%;"> <col style="width: 25%;"> <col style="width: 15%;"> <col style="width: 11%;"> <col style="width: 12%;">
                        </colgroup>
                        <thead>
                            <tr>
                                <th>Reporter</th> <th>Date</th> <th>Description</th> <th>Evidence</th> <th>Status</th> <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="reportsTableBody">
                            <?php
                            $reports_query = $conn->query("SELECT w.*, COALESCE(u.full_name, 'Deleted user') AS full_name FROM waste_reports w LEFT JOIN users u ON w.resident_id = u.user_id ORDER BY w.created_at DESC");
                            if($reports_query && $reports_query->num_rows > 0) {
                                while($row = $reports_query->fetch_assoc()) {
                                    $raw_date = date('Y-m-d', strtotime($row['created_at']));
                                    $initial = strtoupper(substr($row['full_name'], 0, 1));
                                    echo "<tr class='report-row' data-status='" . h($row['status']) . "' data-date='" . h($raw_date) . "'>";
                                    echo "<td><div class='reporter-cell'><div class='reporter-avatar'>{$initial}</div><span style='font-weight: 600;'>" . htmlspecialchars($row['full_name']) . "</span></div></td>";
                                    echo "<td style='color: var(--text-muted); font-weight: 500; white-space: nowrap;'>" . date("M d, Y", strtotime($row['created_at'])) . "<div style='font-size: 12px; font-weight: 500; margin-top: 2px; color: var(--text-muted);'><i class='ti ti-clock' style='font-size: 13px; vertical-align: -2px;'></i> " . date("g:i A", strtotime($row['created_at'])) . "</div></td>";
                                    echo "<td class='desc-cell'>" . h($row['description']) . "</td>";
                                    echo "<td>";
                                    if (!empty($row['before_photo_path'])) {
                                        echo "<a href='uploads/reports/" . h($row['before_photo_path']) . "' target='_blank' rel='noopener' class='photo-link before'><i class='ti ti-photo'></i> Before</a>";
                                    } else {
                                        echo "<span style='color: var(--text-muted); font-size: 12px;'>No photo</span>";
                                    }
                                    if ($row['status'] === 'Cleaned' && !empty($row['after_photo_path'])) {
                                        echo "<a href='uploads/reports/" . h($row['after_photo_path']) . "' target='_blank' rel='noopener' class='photo-link after'><i class='ti ti-photo-check'></i> After</a>";
                                    }
                                    echo "</td>";
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
                                echo "<tr><td colspan='6' style='text-align: center; padding: 40px;'><i class='ti ti-inbox' style='font-size: 32px; color: #d1d5db; margin-bottom: 8px; display: block;'></i><span style='color: var(--text-muted);'>No records found matching your criteria.</span></td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                    <div id="reportsEmpty" class="empty-note" hidden>No reports match your filters.</div>
                </div>
            </div>

            <?php elseif ($view == 'residents'): ?>
            <?php $res_status = (string)($_GET['status'] ?? ''); ?>
            <?php if ($res_status === 'deleted'): ?>
                <div id="deleteAlert" class="notice success" role="status"><i class="ti ti-circle-check"></i> User account removed successfully.</div>
            <?php elseif ($res_status === 'blocked'): ?>
                <div class="notice error" role="alert"><i class="ti ti-alert-circle"></i> This account could not be deleted because other records are still linked to it. You can reject it instead, or check the database links with your developer.</div>
            <?php elseif ($res_status === 'notfound'): ?>
                <div class="notice warn" role="status"><i class="ti ti-info-circle"></i> That account no longer exists. The list has been refreshed.</div>
            <?php endif; ?>

            <div class="flat-card" style="padding: 0; overflow: hidden;">
                <div class="table-controls" style="padding: 24px; margin: 0; border-bottom: 1px solid var(--border-color);">
                    <div class="flat-tabs" id="resident-tabs">
                        <button class="tab-btn active" data-status="Pending" onclick="switchResTab(this)"><i class="ti ti-clock"></i> Pending Approvals</button>
                        <button class="tab-btn" data-status="Approved" onclick="switchResTab(this)"><i class="ti ti-users"></i> Active Users</button>
                        <button class="tab-btn" data-status="Rejected" onclick="switchResTab(this)"><i class="ti ti-user-x"></i> Rejected</button>
                    </div>
                </div>

                <div style="overflow-x: auto;">
                    <table class="flat-table">
                        <colgroup>
                            <col style="width: 20%;"> <col style="width: 15%;"> <col style="width: 15%;"> <col style="width: 15%;"> <col style="width: 15%;"> <col style="width: 20%;">
                        </colgroup>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Username</th>
                                <th>Phone</th>
                                <th>Valid ID</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="residentsTableBody" data-tab="Pending">
                            <?php
                            $residents_query = $conn->query("SELECT * FROM users WHERE role='Resident' AND account_status IN ('Pending', 'Approved', 'Rejected') ORDER BY FIELD(account_status, 'Pending', 'Approved', 'Rejected'), full_name ASC");
                            if ($residents_query && $residents_query->num_rows > 0) {
                                while ($row = $residents_query->fetch_assoc()) {
                                    $status = $row['account_status'];
                                    
                                    echo "<tr class='resident-row' data-status='{$status}'>";
                                    echo "<td><strong style='color: var(--text-main);'>" . htmlspecialchars($row['full_name']) . "</strong></td>";
                                    echo "<td style='color: var(--text-muted); font-weight: 500;'>" . htmlspecialchars($row['username']) . "</td>";
                                    echo "<td>" . htmlspecialchars($row['phone_number']) . "</td>";
                                    
                                    echo "<td>";
                                    if (!empty($row['id_photo_path'])) {
                                        $id_path = 'uploads/ids/' . htmlspecialchars($row['id_photo_path']);
                                        echo "<a href='{$id_path}' target='_blank' class='flat-btn' style='padding: 6px 12px; font-size: 12px;'><i class='ti ti-id'></i> View ID</a>";
                                    } else {
                                        echo "<span style='color: var(--text-muted); font-size: 12px;'>No ID</span>";
                                    }
                                    echo "</td>";

                                    echo "<td>";
                                    if ($status == 'Pending') {
                                        echo "<span class='status-badge pending'><i class='ti ti-clock'></i> Pending</span>";
                                    } elseif ($status == 'Rejected') {
                                        echo "<span class='status-badge rejected'><i class='ti ti-x'></i> Rejected</span>";
                                    } else {
                                        echo "<span class='status-badge active'><i class='ti ti-shield-check'></i> Active</span>";
                                    }
                                    echo "</td>";
                                    
                                    echo "<td>";
                                    echo "<div class='row-actions'>";
                                    echo "<button type='button' onclick='openUserModal(" . (int)$row['user_id'] . ")' class='flat-btn' style='padding: 6px 16px; font-size: 12px;'><i class='ti ti-eye'></i> View</button>";
                                    if ($status === 'Approved') {
                                        echo "<button type='button' onclick='openDeleteModal(" . (int)$row['user_id'] . ")' class='flat-btn danger' style='padding: 6px 14px; font-size: 12px;' aria-label='Delete account'><i class='ti ti-trash'></i> Delete</button>";
                                    }
                                    echo "</div>";
                                    echo "</td></tr>";
                                }
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- MODAL PARA SA RESIDENT INFO (Dito na ilalagay ang Approve/Reject/Delete) -->
            <div class="modal-overlay" id="userModal" role="dialog" aria-modal="true" aria-labelledby="userModalTitle">
                <div class="modal-content">
                    <button type="button" class="modal-close" onclick="closeUserModal()" aria-label="Close"><i class="ti ti-x"></i></button>
                    <h3 class="modal-title" id="userModalTitle"><i class="ti ti-user-circle" style="color: var(--primary);"></i> Resident Information</h3>
                    
                    <table class="modal-table">
                        <tr><td>Full Name</td><td id="modalName"></td></tr>
                        <tr><td>Username</td><td id="modalUsername"></td></tr>
                        <tr><td>Phone Number</td><td id="modalPhone"></td></tr>
                        <tr><td>Address / Purok</td><td id="modalAddress"></td></tr>
                        <tr><td>Date of Birth</td><td id="modalDob"></td></tr>
                        <tr><td>Status</td><td id="modalStatus"></td></tr>
                    </table>
                    
                    <div id="modalIdWrap" style="display: flex; gap: 12px; margin-bottom: 24px;">
                        <a href="#" id="modalIdLink" target="_blank" rel="noopener" class="flat-btn primary" style="flex: 1; justify-content: center; padding: 12px;"><i class="ti ti-id"></i> View Uploaded ID</a>
                    </div>

                    <!-- CONTAINER PARA SA APPROVE / REJECT / DELETE -->
                    <div id="modalActionsContainer" style="display: flex; gap: 12px; justify-content: flex-end; border-top: 1px solid var(--border-color); padding-top: 20px;">
                        <!-- Ipopopulate ito ng JavaScript batay sa status -->
                    </div>
                </div>
            </div>

            <!-- CONFIRMATION BEFORE DELETING AN ACCOUNT -->
            <div class="modal-overlay" id="deleteModal" role="alertdialog" aria-modal="true" aria-labelledby="deleteModalTitle" aria-describedby="deleteModalDesc" style="z-index: 1100;">
                <div class="modal-content confirm-box">
                    <div class="confirm-icon"><i class="ti ti-trash"></i></div>
                    <h3 class="confirm-title" id="deleteModalTitle">Delete this account?</h3>
                    <p class="confirm-text" id="deleteModalDesc">
                        <strong id="deleteModalName"></strong> <span id="deleteModalUser" class="confirm-user"></span><br>
                        will be permanently removed together with the uploaded ID photo. This cannot be undone.
                    </p>
                    <p class="confirm-note" id="deleteModalReports"></p>
                    <form method="POST" action="admin_dashboard.php?view=residents" id="deleteForm" style="margin: 0;">
                        <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                        <input type="hidden" name="delete_resident_id" id="deleteResidentId" value="">
                        <div class="confirm-actions">
                            <button type="button" class="flat-btn" id="deleteCancelBtn" onclick="closeDeleteModal()">Cancel</button>
                            <button type="submit" class="flat-btn danger-solid" id="deleteConfirmBtn"><i class="ti ti-trash"></i> Delete account</button>
                        </div>
                    </form>
                </div>
            </div>

            <?php elseif ($view == 'alert'): ?>
            <?php if ($alert_sent): ?>
                <div class="notice success" role="status"><i class="ti ti-circle-check"></i> Basura-Alert sent to all registered residents.</div>
            <?php endif; ?>
            <?php if ($alert_error !== ''): ?>
                <div class="notice error" role="alert"><i class="ti ti-alert-circle"></i> <?php echo h($alert_error); ?></div>
            <?php endif; ?>
            <div class="grid-2 align-start">
                <div class="flat-card">
                    <div class="card-title" style="justify-content: flex-start; gap: 10px; font-size: 16px; margin-bottom: 24px;">
                        <i class="ti ti-speakerphone" style="color: var(--primary); font-size: 22px;"></i>
                        <span style="color: var(--primary);">Compose Broadcast</span>
                    </div>
                    
                    <div class="alert-banner">
                        <i class="ti ti-alert-triangle"></i>
                        <div><strong>Notice:</strong> This message will be instantly pushed to the resident mobile app or portal. Ensure the information is accurate and urgent.</div>
                    </div>

                    <form method="POST" action="admin_dashboard.php?view=alert" id="broadcastForm">
                        <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                        <div class="form-group">
                            <label class="form-label"><i class="ti ti-users"></i> Target Audience</label>
                            <div style="position: relative;">
                                <select name="purok_area" class="form-control" disabled aria-label="Target audience" style="appearance: none; padding-right: 32px;">
                                    <option value="All Areas" selected>All registered residents</option>
                                </select>
                                <i class="ti ti-chevron-down" style="position: absolute; right: 14px; top: 50%; transform: translateY(-50%); pointer-events: none; color: var(--text-muted);"></i>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label"><i class="ti ti-message-2"></i> Message Payload</label>
                            <textarea name="alert_message" id="alertMsg" class="form-control" required placeholder="Type your alert message here..." maxlength="250" oninput="updateCharCount()"><?php echo h($alert_draft); ?></textarea>
                            <div style="text-align: right; font-size: 12px; color: var(--text-muted); margin-top: 8px; font-weight: 500;"><span id="charCount">0</span> / 250 characters</div>
                        </div>

                        <div style="display: flex; gap: 12px; margin-top: 32px;">
                            <button type="submit" name="send_basura_alert" class="flat-btn primary" style="flex: 1; justify-content: center; padding: 14px; font-size: 15px;"><i class="ti ti-send"></i> Send Broadcast Now</button>
                        </div>
                    </form>
                </div>

                <div class="flat-card" style="padding: 0; overflow: hidden; display: flex; flex-direction: column;">
                    <div class="card-title" style="padding: 24px; margin: 0; border-bottom: 1px solid var(--border-color); background: transparent; justify-content: space-between;">
                        <div style="display: flex; align-items: center; gap: 10px; font-size: 16px;">
                            <i class="ti ti-history" style="color: var(--primary); font-size: 22px;"></i>
                            <span style="color: var(--primary);">Recent Broadcasts</span>
                        </div>
                        <span style="font-size: 12px; font-weight: 500; background: #f9fafb; border: 1px solid var(--border-color); padding: 4px 10px; border-radius: 20px; color: var(--text-muted);">Last 5</span>
                    </div>
                    
                    <div class="history-list" style="flex: 1; overflow-y: auto;">
                        <?php
                        $history_query = $conn->query("SELECT * FROM basura_alerts ORDER BY alert_id DESC LIMIT 5");
                        if($history_query && $history_query->num_rows > 0) {
                            while($h = $history_query->fetch_assoc()) {
                                $raw_date = $h['sent_at'] ?? ($h['created_at'] ?? null);
                                $date_display = $raw_date ? date("M d, g:i A", strtotime($raw_date)) : '-';
                                ?>
                                <div class="history-item">
                                    <div class="history-header">
                                        <span class="history-target"><i class="ti ti-map-pin"></i> <?php echo htmlspecialchars($h['purok_area'] ?? 'All Areas'); ?></span>
                                        <span class="history-time"><i class="ti ti-clock" style="font-size: 14px;"></i> <?php echo $date_display; ?></span>
                                    </div>
                                    <div class="history-msg"><?php echo htmlspecialchars($h['message']); ?></div>
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

            <!-- CONFIRMATION BEFORE SENDING A BROADCAST -->
            <div class="modal-overlay" id="broadcastModal" role="alertdialog" aria-modal="true" aria-labelledby="broadcastModalTitle" aria-describedby="broadcastModalDesc" style="z-index: 1100;">
                <div class="modal-content confirm-box">
                    <div class="confirm-icon info"><i class="ti ti-speakerphone"></i></div>
                    <h3 class="confirm-title" id="broadcastModalTitle">Send this broadcast?</h3>
                    <p class="confirm-text" id="broadcastModalDesc">This alert is pushed instantly to <strong>all registered residents</strong> and cannot be recalled once it is sent.</p>
                    <div class="confirm-preview">
                        <div id="broadcastPreview"></div>
                        <span id="broadcastCount"></span>
                    </div>
                    <div class="confirm-actions">
                        <button type="button" class="flat-btn" id="broadcastCancelBtn" onclick="closeBroadcastModal()">Cancel</button>
                        <button type="button" class="flat-btn primary" id="broadcastConfirmBtn"><i class="ti ti-send"></i> Send broadcast</button>
                    </div>
                </div>
            </div>

            <?php elseif ($view == 'settings'): ?>
            <!-- Settings content -->
            <?php if ($update_success): ?>
                <div style="background: var(--primary-light); border: 1px solid var(--primary); color: var(--primary-dark); padding: 16px; border-radius: 8px; margin-bottom: 24px; font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 8px;">
                    <i class="ti ti-circle-check" style="font-size: 20px;"></i> Profile configuration saved successfully.
                </div>
            <?php endif; ?>
            
            <?php if ($update_error !== ''): ?>
                <div class="notice warn" role="alert"><i class="ti ti-alert-triangle"></i> <?php echo h($update_error); ?></div>
            <?php endif; ?>

            <?php if ($pass_success): ?>
                <div style="background: var(--primary-light); border: 1px solid var(--primary); color: var(--primary-dark); padding: 16px; border-radius: 8px; margin-bottom: 24px; font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 8px;">
                    <i class="ti ti-lock-check" style="font-size: 20px;"></i> Password updated successfully.
                </div>
            <?php endif; ?>

            <?php if (!empty($pass_error)): ?>
                <div style="background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; padding: 16px; border-radius: 8px; margin-bottom: 24px; font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 8px;">
                    <i class="ti ti-alert-circle" style="font-size: 20px;"></i> <?php echo h($pass_error); ?>
                </div>
            <?php endif; ?>
            
            <div class="flat-card">
                <form method="POST" action="admin_dashboard.php?view=settings" enctype="multipart/form-data">
                    <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                    <div class="card-title" style="justify-content: flex-start; gap: 10px; font-size: 16px; margin-bottom: 24px;">
                        <i class="ti ti-building" style="color: var(--primary); font-size: 22px;"></i>
                        <span style="color: var(--primary);">Barangay Profile & Branding</span>
                    </div>

                    <div class="settings-top">
                        <div style="text-align: center; padding: 20px; background: var(--bg-body); border-radius: 12px; border: 1px dashed var(--border-color);">
                            <label class="form-label" style="justify-content: center; margin-bottom: 16px;"><i class="ti ti-photo"></i> Barangay Logo</label>
                            <?php 
                                $current_logo = $logo_src;
                            ?>
                            <img src="<?php echo htmlspecialchars($current_logo); ?>" alt="Logo Preview" style="width: 100px; height: 100px; border-radius: 50%; object-fit: cover; border: 3px solid #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.1); margin-bottom: 16px;" onerror="this.onerror=null;this.src='tanzalogo.jpg'">
                            <input type="file" name="barangay_logo" class="form-control" accept=".jpg,.jpeg,.png" style="font-size: 12px; padding: 8px;">
                            <small style="display: block; margin-top: 8px; color: var(--text-muted); font-size: 11px;">Max size: 2MB (JPG/PNG)</small>
                        </div>

                        <div class="grid-2 align-start">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label"><i class="ti ti-map-2"></i> Barangay Name</label>
                                <input type="text" name="barangay_name" class="form-control" value="<?php echo htmlspecialchars($info['barangay_name'] ?? ''); ?>" required>
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label"><i class="ti ti-phone"></i> Main Office Contact</label>
                                <input type="text" name="contact_number" class="form-control" value="<?php echo htmlspecialchars($info['contact_number'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <hr style="border: 0; border-top: 1px solid var(--border-color); margin: 0 0 32px 0;">

                    <div class="grid-2 mb-32">
                        <div style="border: 1px solid var(--border-color); border-radius: 12px; padding: 24px; background: #f9fafb;">
                            <h4 style="margin: 0 0 16px 0; font-size: 15px; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                                <i class="ti ti-user-star" style="color: var(--primary); font-size: 20px;"></i> Barangay Captain
                            </h4>
                            <div class="form-group">
                                <label class="form-label">Full Name</label>
                                <input type="text" name="captain_name" class="form-control" value="<?php echo htmlspecialchars($info['captain_name'] ?? ''); ?>">
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label">Contact Number</label>
                                <input type="text" name="captain_contact" class="form-control" value="<?php echo htmlspecialchars($info['captain_contact'] ?? ''); ?>" placeholder="09xx xxx xxxx">
                            </div>
                        </div>

                        <div style="border: 1px solid var(--border-color); border-radius: 12px; padding: 24px; background: #f9fafb;">
                            <h4 style="margin: 0 0 16px 0; font-size: 15px; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                                <i class="ti ti-user-check" style="color: var(--primary); font-size: 20px;"></i> Barangay Secretary
                            </h4>
                            
                            <div style="display: flex; gap: 20px; align-items: flex-start;">
                                <div style="text-align: center; flex-shrink: 0;">
                                    <?php 
                                        $sec_pic_preview = !empty($info['secretary_profile_pic']) ? 'uploads/profiles/' . $info['secretary_profile_pic'] : 'https://placehold.co/80x80/e5e7eb/6b7280?text=Photo';
                                    ?>
                                    <img src="<?php echo htmlspecialchars($sec_pic_preview); ?>" alt="Secretary Preview" style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 2px solid #fff; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 8px;">
                                    <input type="file" name="secretary_pic" class="form-control" accept=".jpg,.jpeg,.png" style="font-size: 10px; padding: 4px; width: 100px;">
                                </div>
                                
                                <div style="flex: 1;">
                                    <div class="form-group" style="margin-bottom: 16px;">
                                        <label class="form-label">Full Name</label>
                                        <input type="text" name="secretary_name" class="form-control" value="<?php echo htmlspecialchars($info['secretary_name'] ?? ''); ?>">
                                    </div>
                                    <div class="form-group" style="margin-bottom: 0;">
                                        <label class="form-label">Contact Number</label>
                                        <input type="text" name="secretary_contact" class="form-control" value="<?php echo htmlspecialchars($info['secretary_contact'] ?? ''); ?>" placeholder="09xx xxx xxxx">
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>

                    <hr style="border: 0; border-top: 1px solid var(--border-color); margin: 0 0 32px 0;">

                    <div class="card-title" style="justify-content: flex-start; gap: 10px; font-size: 16px; margin-bottom: 24px;">
                        <i class="ti ti-phone-call" style="color: var(--primary); font-size: 22px;"></i>
                        <span style="color: var(--primary);">Emergency Hotlines</span>
                    </div>

                    <div class="grid-2 mb-24">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label"><i class="ti ti-shield"></i> Police Station</label>
                            <input type="text" name="pnp_hotline" class="form-control" value="<?php echo htmlspecialchars($info['pnp_hotline'] ?? ''); ?>">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label"><i class="ti ti-flame"></i> Fire Station (BFP)</label>
                            <input type="text" name="bfp_hotline" class="form-control" value="<?php echo htmlspecialchars($info['bfp_hotline'] ?? ''); ?>">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label"><i class="ti ti-ambulance"></i> MDRRMO</label>
                            <input type="text" name="mdrrmo_hotline" class="form-control" value="<?php echo htmlspecialchars($info['mdrrmo_hotline'] ?? ''); ?>">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label"><i class="ti ti-activity"></i> Health Center</label>
                            <input type="text" name="health_center_hotline" class="form-control" value="<?php echo htmlspecialchars($info['health_center_hotline'] ?? ''); ?>">
                        </div>
                    </div>

                    <div style="background: var(--bg-body); padding: 20px; border-radius: 12px; border: 1px solid var(--border-color); margin-bottom: 24px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                            <h4 style="margin: 0; font-size: 15px; font-weight: 600; color: var(--text-main);"><i class="ti ti-list-details" style="color: var(--primary); vertical-align: middle; margin-right: 4px;"></i> Other Important Contacts</h4>
                            <button type="button" id="addHotlineBtn" class="flat-btn primary" style="padding: 6px 12px; font-size: 12px;"><i class="ti ti-plus"></i> Add Contact</button>
                        </div>
                        
                        <div id="customHotlinesContainer">
                            <?php
                            $custom_hotlines_arr = !empty($info['custom_hotlines']) ? json_decode($info['custom_hotlines'], true) : [];
                            if (is_array($custom_hotlines_arr) && count($custom_hotlines_arr) > 0) {
                                foreach ($custom_hotlines_arr as $index => $ch) {
                                    ?>
                                    <div class="hotline-row">
                                        <div>
                                            <input type="text" name="custom_hotline_names[]" class="form-control" placeholder="e.g., Barangay Tanod" value="<?php echo htmlspecialchars($ch['name'] ?? ''); ?>" required>
                                        </div>
                                        <div>
                                            <input type="text" name="custom_hotline_numbers[]" class="form-control" placeholder="e.g., 09123456789" value="<?php echo htmlspecialchars($ch['number'] ?? ''); ?>" required>
                                        </div>
                                        <button type="button" class="flat-btn danger remove-hotline" style="padding: 0 16px;" aria-label="Remove contact"><i class="ti ti-trash"></i></button>
                                    </div>
                                    <?php
                                }
                            } else {
                                ?>
                                <div class="hotline-row">
                                    <div>
                                        <input type="text" name="custom_hotline_names[]" class="form-control" placeholder="e.g., Barangay Tanod">
                                    </div>
                                    <div>
                                        <input type="text" name="custom_hotline_numbers[]" class="form-control" placeholder="e.g., 09123456789">
                                    </div>
                                    <button type="button" class="flat-btn danger remove-hotline" style="padding: 0 16px;" aria-label="Remove contact"><i class="ti ti-trash"></i></button>
                                </div>
                                <?php
                            }
                            ?>
                        </div>
                    </div>
                    
                    <input type="hidden" name="full_address" value="<?php echo htmlspecialchars($info['full_address'] ?? ''); ?>">
                    <input type="hidden" name="zip_code" value="<?php echo htmlspecialchars($info['zip_code'] ?? ''); ?>">
                    <input type="hidden" name="region" value="<?php echo htmlspecialchars($info['region'] ?? ''); ?>">
                    <input type="hidden" name="system_subtitle" value="<?php echo htmlspecialchars($info['system_subtitle'] ?? ''); ?>">
                    <input type="hidden" name="system_description" value="<?php echo htmlspecialchars($info['system_description'] ?? ''); ?>">
                    <input type="hidden" name="city" value="<?php echo htmlspecialchars($info['city'] ?? ''); ?>">
                    <input type="hidden" name="municipal" value="<?php echo htmlspecialchars($info['municipal'] ?? ''); ?>">
                    <input type="hidden" name="office_hours" value="<?php echo htmlspecialchars($info['office_hours'] ?? ''); ?>">
                    <input type="hidden" name="emergency_hotlines" value="<?php echo htmlspecialchars($info['emergency_hotlines'] ?? ''); ?>">
                    
                    <div style="margin-top: 32px;">
                        <button type="submit" name="update_info" class="flat-btn primary" style="padding: 12px 24px; font-size: 15px;">
                            <i class="ti ti-device-floppy"></i> Save Profile Configuration
                        </button>
                    </div>
                </form>
            </div>

            <div class="flat-card">
                <div class="card-title" style="justify-content: flex-start; gap: 10px; font-size: 16px; margin-bottom: 24px;">
                    <i class="ti ti-lock" style="color: var(--primary); font-size: 22px;"></i>
                    <span style="color: var(--primary);">Change Password</span>
                </div>

                <form method="POST" action="admin_dashboard.php?view=settings">
                    <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                    <div class="grid-3">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label"><i class="ti ti-key"></i> Current Password</label>
                            <input type="password" name="current_password" class="form-control" placeholder="Enter current password" required>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label"><i class="ti ti-lock-square"></i> New Password</label>
                            <input type="password" name="new_password" class="form-control" placeholder="Enter new password" required minlength="8">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label"><i class="ti ti-lock-check"></i> Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control" placeholder="Confirm new password" required minlength="8">
                        </div>
                    </div>
                    
                    <div style="margin-top: 32px;">
                        <button type="submit" name="change_password" class="flat-btn primary" style="padding: 12px 24px; font-size: 15px;">
                            <i class="ti ti-shield-check"></i> Update Password
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

        </main>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    const CSRF = <?php echo json_encode($csrf); ?>;
    // Only the fields the modal needs (no password hashes)
    const residentsData = <?php echo json_encode((object)$all_residents, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    // Escape anything that came from the database before putting it into innerHTML
    function esc(value) {
        return String(value ?? '').replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    const STATUS_LABEL = { Pending: 'Pending', Approved: 'Active', Rejected: 'Rejected' };
    const STATUS_CLASS = { Pending: 'pending', Approved: 'active', Rejected: 'rejected' };
    const STATUS_ICON  = { Pending: 'ti-clock', Approved: 'ti-shield-check', Rejected: 'ti-x' };
    const EMPTY_TEXT   = { Pending: 'pending resident approvals', Approved: 'active residents', Rejected: 'rejected residents' };

    // ---------- Resident modal ----------
    let lastFocusedElement = null;

    function actionButton(fields, cls, icon, label, confirmMsg) {
        const all = Object.assign({ csrf: CSRF }, fields);
        const inputs = Object.keys(all).map(function (k) {
            return `<input type="hidden" name="${esc(k)}" value="${esc(all[k])}">`;
        }).join('');
        const onsubmit = confirmMsg ? ` onsubmit="return confirm('${esc(confirmMsg)}');"` : '';
        return `<form action="admin_dashboard.php?view=residents" method="POST"${onsubmit} style="margin:0;">${inputs}<button type="submit" class="flat-btn ${cls}" style="padding: 10px 20px;"><i class="ti ${icon}"></i> ${esc(label)}</button></form>`;
    }

    function deleteButton(id) {
        return `<button type="button" class="flat-btn danger" style="padding: 10px 20px;" onclick="openDeleteModal(${Number(id)})"><i class="ti ti-trash"></i> Delete User</button>`;
    }

    // ---------- Delete confirmation ----------
    let deleteReturnFocus = null;

    function openDeleteModal(id) {
        const user = residentsData[id];
        if (!user) return;
        deleteReturnFocus = document.activeElement;

        document.getElementById('deleteResidentId').value = user.user_id;
        document.getElementById('deleteModalName').textContent = user.full_name;
        document.getElementById('deleteModalUser').textContent = user.username ? '(@' + user.username + ')' : '';
        const n = Number(user.report_count) || 0;
        document.getElementById('deleteModalReports').textContent =
            n === 0 ? 'This resident has not submitted any reports.'
                    : 'Reports submitted by this resident: ' + n;

        const btn = document.getElementById('deleteConfirmBtn');
        btn.disabled = false;
        btn.innerHTML = '<i class="ti ti-trash"></i> Delete account';

        document.getElementById('deleteModal').style.display = 'flex';
        document.getElementById('deleteCancelBtn').focus();   // Cancel is the safe default
    }

    function closeDeleteModal() {
        const modal = document.getElementById('deleteModal');
        if (!modal || modal.style.display !== 'flex') return false;
        modal.style.display = 'none';
        if (deleteReturnFocus && deleteReturnFocus.focus) deleteReturnFocus.focus();
        return true;
    }

    document.getElementById('deleteModal')?.addEventListener('click', function (e) {
        if (e.target.id === 'deleteModal') closeDeleteModal();
    });

    // stop double clicks from sending the request twice
    document.getElementById('deleteForm')?.addEventListener('submit', function () {
        const btn = document.getElementById('deleteConfirmBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="ti ti-loader"></i> Deleting...';
    });

    function openUserModal(id) {
        const user = residentsData[id];
        if (!user) return;
        lastFocusedElement = document.activeElement;

        document.getElementById('modalName').textContent = user.full_name;
        document.getElementById('modalUsername').textContent = user.username;
        document.getElementById('modalPhone').textContent = user.phone_number;
        document.getElementById('modalAddress').textContent = user.address || 'N/A';
        document.getElementById('modalDob').textContent = user.date_of_birth || 'N/A';

        const st = user.account_status;
        document.getElementById('modalStatus').innerHTML =
            `<span class="status-badge ${STATUS_CLASS[st] || 'pending'}"><i class="ti ${STATUS_ICON[st] || 'ti-clock'}"></i> ${esc(STATUS_LABEL[st] || st)}</span>`;

        const idLink = document.getElementById('modalIdLink');
        const idWrap = document.getElementById('modalIdWrap');
        if (user.id_photo_path) {
            idLink.href = 'uploads/ids/' + encodeURIComponent(user.id_photo_path);
            idWrap.style.display = 'flex';
        } else {
            idWrap.style.display = 'none';
        }

        let actions = '';
        if (st === 'Pending') {
            actions += actionButton({ resident_action: 'approve', id: user.user_id, 'return': 'residents' }, 'primary', 'ti-check', 'Approve');
            actions += actionButton({ resident_action: 'reject', id: user.user_id, 'return': 'residents' }, 'danger', 'ti-x', 'Reject', 'Reject this resident?');
        } else if (st === 'Rejected') {
            actions += actionButton({ resident_action: 'approve', id: user.user_id, 'return': 'residents' }, 'primary', 'ti-check', 'Approve instead');
            actions += deleteButton(user.user_id);
        } else {
            actions += deleteButton(user.user_id);
        }
        document.getElementById('modalActionsContainer').innerHTML = actions;

        const modal = document.getElementById('userModal');
        modal.style.display = 'flex';
        modal.querySelector('.modal-close').focus();
    }

    function closeUserModal() {
        const modal = document.getElementById('userModal');
        if (!modal || modal.style.display !== 'flex') return;
        modal.style.display = 'none';
        if (lastFocusedElement && lastFocusedElement.focus) lastFocusedElement.focus();
    }

    document.getElementById('userModal')?.addEventListener('click', function (e) {
        if (e.target.id === 'userModal') closeUserModal();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            if (closeBroadcastModal()) return;
            if (closeDeleteModal()) return;   // close the confirmation first, keep the resident popup open
            closeUserModal();
            if (isNotifOpen) setNotifOpen(false);
        }
    });

    // ---------- Clock ----------
    function updateDateTime() {
        const now = new Date();
        const options = { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' };
        const el = document.getElementById('liveDateTime');
        if (el) el.innerHTML = `<i class="ti ti-clock"></i> ${now.toLocaleDateString('en-US', options)}`;
    }
    setInterval(updateDateTime, 30000); // only minutes are shown
    updateDateTime();

    // Open the resident modal when coming from the dashboard widget (?view_user_id=)
    <?php if (isset($_GET['view_user_id']) && $view === 'residents'): ?>
    window.addEventListener('load', function () {
        const id = <?php echo (int)$_GET['view_user_id']; ?>;
        const user = residentsData[id];
        if (user) {
            const tab = document.querySelector('#resident-tabs [data-status="' + user.account_status + '"]');
            if (tab) switchResTab(tab);
            openUserModal(id);
        }
        if (window.history.replaceState) {
            const url = new URL(window.location.href);
            url.searchParams.delete('view_user_id');
            window.history.replaceState({ path: url.href }, '', url.href);
        }
    });
    <?php endif; ?>

    if (window.history.replaceState) {
        const url = new URL(window.location.href);
        if (url.searchParams.has('status') || url.searchParams.has('sent') || url.searchParams.has('resolved')) {
            url.searchParams.delete('status');
            url.searchParams.delete('sent');
            url.searchParams.delete('resolved');
            window.history.replaceState({ path: url.href }, '', url.href);
        }
    }

    setTimeout(function () { const b = document.getElementById('deleteAlert'); if (b) b.style.display = 'none'; }, 3000);

    // ---------- Notifications ----------
    let isNotifOpen = false;
    let latestCount = 0;
    let dismissedCount = 0;
    try { dismissedCount = parseInt(sessionStorage.getItem('notifDismissed') || '0', 10) || 0; } catch (e) {}

    function renderBadge() {
        const badge = document.getElementById('notifCount');
        const unseen = Math.max(0, latestCount - dismissedCount);
        if (unseen > 0) {
            badge.innerText = unseen;
            badge.style.display = 'flex';
        } else {
            badge.style.display = 'none';
        }
    }

    function setNotifOpen(open) {
        isNotifOpen = open;
        document.getElementById('notifDropdown').classList.toggle('active', open);
        document.getElementById('notifBtn').setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
            // Remember what was seen so the badge doesn't come back on the next refresh
            dismissedCount = latestCount;
            try { sessionStorage.setItem('notifDismissed', String(dismissedCount)); } catch (e) {}
            renderBadge();
        }
    }

    function toggleNotifications() { setNotifOpen(!isNotifOpen); }

    document.addEventListener('click', function (event) {
        const dropdown = document.getElementById('notifDropdown');
        const btn = document.getElementById('notifBtn');
        if (isNotifOpen && !btn.contains(event.target) && !dropdown.contains(event.target)) {
            setNotifOpen(false);
        }
    });

    function fetchNotifications() {
        fetch('fetch_notifications.php')
            .then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            })
            .then(function (data) {
                const list = document.getElementById('notifList');
                latestCount = Number(data.count) || 0;
                if (latestCount < dismissedCount) {
                    dismissedCount = latestCount; // reports were opened on the Reports page
                    try { sessionStorage.setItem('notifDismissed', String(dismissedCount)); } catch (e) {}
                }
                renderBadge();
                document.getElementById('notifHeaderText').innerText = `${latestCount} unread`;

                if (data.notifications && data.notifications.length > 0) {
                    list.innerHTML = data.notifications.map(function (n) {
                        return `<a href="resolve_report.php?id=${encodeURIComponent(n.id)}" class="notif-item">
                                    <div class="title">${esc(n.name)}</div>
                                    <div class="desc">${esc(n.desc)}</div>
                                    <div class="time"><i class="ti ti-clock"></i> ${esc(n.time)}</div>
                                </a>`;
                    }).join('');
                } else {
                    list.innerHTML = `<div style="padding: 24px; text-align: center; color: var(--text-muted); font-size: 13px;">No new notifications</div>`;
                }
            })
            .catch(function (err) { console.error('Error fetching notifications:', err); });
    }

    setInterval(fetchNotifications, 10000);
    fetchNotifications();

    // ---------- Reports filters ----------
    function filterTableDropdown() {
        const statusFilter = document.getElementById('statusFilter').value;
        const startDate = document.getElementById('startDate').value;
        const endInput = document.getElementById('endDate');
        const endDate = endInput.value;
        const searchText = (document.getElementById('searchInput').value || '').toLowerCase();
        endInput.min = startDate || '';

        const rows = document.querySelectorAll('.report-row');
        let visible = 0;
        rows.forEach(function (row) {
            const rowStatus = row.getAttribute('data-status');
            const rowDate = row.getAttribute('data-date');
            let show = row.innerText.toLowerCase().includes(searchText)
                && (statusFilter === 'All' || rowStatus === statusFilter);
            if (startDate && rowDate < startDate) show = false;
            if (endDate && rowDate > endDate) show = false;
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        const empty = document.getElementById('reportsEmpty');
        if (empty) empty.hidden = rows.length === 0 || visible > 0;
    }

    document.getElementById('searchInput')?.addEventListener('input', filterTableDropdown);

    // ---------- Resident tabs ----------
    function switchResTab(btn) {
        const target = btn.getAttribute('data-status');
        document.querySelectorAll('#resident-tabs .tab-btn').forEach(function (b) { b.classList.remove('active'); });
        btn.classList.add('active');

        const tbody = document.getElementById('residentsTableBody');
        tbody.dataset.tab = target; // CSS hides the other statuses
        const count = tbody.querySelectorAll('.resident-row[data-status="' + target + '"]').length;

        let emptyRow = document.getElementById('emptyStateRow');
        if (!emptyRow) {
            emptyRow = document.createElement('tr');
            emptyRow.id = 'emptyStateRow';
            emptyRow.innerHTML = `<td colspan="6" style="padding: 60px 24px; text-align: center;">
                <div style="display: inline-flex; align-items: center; justify-content: center; width: 64px; height: 64px; border-radius: 50%; background: var(--bg-body); margin-bottom: 16px;">
                    <i class="ti ti-mood-smile" style="font-size: 32px; color: #9ca3af;"></i>
                </div>
                <h3 style="margin: 0 0 8px 0; color: var(--text-main); font-size: 16px;">All caught up!</h3>
                <p id="emptyStateMsg" style="margin: 0; color: var(--text-muted); font-size: 14px;"></p>
            </td>`;
            tbody.appendChild(emptyRow);
        }
        document.getElementById('emptyStateMsg').textContent = `There are no ${EMPTY_TEXT[target] || 'residents'} right now.`;
        emptyRow.hidden = count > 0;
    }

    // Start on the tab named in ?tab= (default: Pending) so the page does not jump back after an action
    (function () {
        if (!document.getElementById('resident-tabs')) return;
        const url = new URL(window.location.href);
        const wanted = url.searchParams.get('tab');
        const tabName = ['Pending', 'Approved', 'Rejected'].indexOf(wanted) !== -1 ? wanted : 'Pending';
        const btn = document.querySelector('#resident-tabs [data-status="' + tabName + '"]');
        if (btn) switchResTab(btn);
        if (url.searchParams.has('tab') && window.history.replaceState) {
            url.searchParams.delete('tab');
            window.history.replaceState({ path: url.href }, '', url.href);
        }
    })();

    document.addEventListener('DOMContentLoaded', function () {
        const activeBtn = document.querySelector('#resident-tabs .tab-btn.active');
        if (activeBtn) switchResTab(activeBtn);
    });

    function generatePrintReport() {
        const status = document.getElementById('statusFilter').value;
        const startDate = document.getElementById('startDate').value;
        const endDate = document.getElementById('endDate').value;
        const search = document.getElementById('searchInput').value;
        window.open(`print_report.php?status=${encodeURIComponent(status)}&startDate=${encodeURIComponent(startDate)}&endDate=${encodeURIComponent(endDate)}&search=${encodeURIComponent(search)}`, '_blank');
    }

    // ---------- Basura-Alert counter ----------
    function updateCharCount() {
        const textarea = document.getElementById('alertMsg');
        const counter = document.getElementById('charCount');
        if (textarea && counter) {
            counter.innerText = textarea.value.length;
            counter.style.color = textarea.value.length >= 250 ? '#dc2626' : 'var(--text-muted)';
        }
    }
    if (document.getElementById('alertMsg')) { updateCharCount(); }

    // ---------- Ask for confirmation before sending a broadcast ----------
    let broadcastReturnFocus = null;

    function closeBroadcastModal() {
        const modal = document.getElementById('broadcastModal');
        if (!modal || modal.style.display !== 'flex') return false;
        modal.style.display = 'none';
        if (broadcastReturnFocus && broadcastReturnFocus.focus) broadcastReturnFocus.focus();
        return true;
    }

    (function () {
        const form = document.getElementById('broadcastForm');
        const modal = document.getElementById('broadcastModal');
        const confirmBtn = document.getElementById('broadcastConfirmBtn');
        if (!form || !modal || !confirmBtn) return;

        function resetSendState() {
            form.dataset.confirmed = '';
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = '<i class="ti ti-send"></i> Send broadcast';
        }

        form.addEventListener('submit', function (e) {
            if (form.dataset.confirmed === '1') return;              // second pass: really send it
            const text = document.getElementById('alertMsg').value.trim();
            if (text === '') return;                                 // empty: the server explains what is missing
            e.preventDefault();
            broadcastReturnFocus = document.activeElement;
            document.getElementById('broadcastPreview').textContent = text;
            document.getElementById('broadcastCount').textContent = text.length + ' / 250 characters';
            resetSendState();
            modal.style.display = 'flex';
            document.getElementById('broadcastCancelBtn').focus();   // Cancel is the safe default
        });

        confirmBtn.addEventListener('click', function () {
            if (form.dataset.confirmed === '1') return;              // ignore double clicks
            form.dataset.confirmed = '1';
            // a form sent by script does not include the button's name, and the server looks for it
            if (!form.querySelector('input[name="send_basura_alert"]')) {
                const flag = document.createElement('input');
                flag.type = 'hidden'; flag.name = 'send_basura_alert'; flag.value = '1';
                form.appendChild(flag);
            }
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<i class="ti ti-loader"></i> Sending...';
            form.submit();
        });

        modal.addEventListener('click', function (e) { if (e.target === modal) closeBroadcastModal(); });

        // coming back with the browser's Back button should not leave the dialog stuck on "Sending..."
        window.addEventListener('pageshow', function (e) { if (e.persisted) { closeBroadcastModal(); resetSendState(); } });
    })();

    // ---------- Custom hotline rows ----------
    document.getElementById('addHotlineBtn')?.addEventListener('click', function () {
        const container = document.getElementById('customHotlinesContainer');
        const row = document.createElement('div');
        row.className = 'hotline-row';
        row.innerHTML = `
            <div><input type="text" name="custom_hotline_names[]" class="form-control" placeholder="e.g., Barangay Tanod" aria-label="Contact name"></div>
            <div><input type="text" name="custom_hotline_numbers[]" class="form-control" placeholder="e.g., 09123456789" aria-label="Contact number"></div>
            <button type="button" class="flat-btn danger remove-hotline" style="padding: 0 16px;" aria-label="Remove contact"><i class="ti ti-trash"></i></button>
        `;
        container.appendChild(row);
        row.querySelector('input').focus();
    });

    document.getElementById('customHotlinesContainer')?.addEventListener('click', function (e) {
        const removeBtn = e.target.closest('.remove-hotline');
        if (removeBtn) removeBtn.closest('.hotline-row').remove();
    });

    <?php if ($view == 'dashboard'): ?>
    document.addEventListener('DOMContentLoaded', function () {
        const locations = <?php echo json_encode($map_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        // Default view: Brgy. Tanza, Estancia, Iloilo (used when there are no pins yet)
        const map = L.map('masterMap').setView([11.439, 123.139], 15);

        const roadmapLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);

        const satelliteLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            maxZoom: 19,
            attribution: 'Tiles &copy; Esri &mdash; Source: Esri, i-cubed, USDA, USGS, AEX, GeoEye, Getmapping, Aerogrid, IGN, IGP, UPR-EGP, and the GIS User Community'
        });

        L.control.layers({ 'Roadmap': roadmapLayer, 'Satellite': satelliteLayer }).addTo(map);

        // Teardrop map pins (inline SVG, no external images) in the same colours as the legend.
        // The tip of the pin sits exactly on the report's location.
        const PIN_SVG = '<svg class="map-pin %CLS%" width="30" height="42" viewBox="0 0 30 42" aria-hidden="true"><path d="M15 1C7.3 1 1 7.2 1 14.9c0 9.9 11.4 23.4 13.1 25.3a1.2 1.2 0 0 0 1.8 0C17.6 38.3 29 24.8 29 14.9 29 7.2 22.7 1 15 1z"/><circle cx="15" cy="15" r="5.5"/></svg>';
        function pinIcon(status) {
            return L.divIcon({
                className: 'map-pin-wrap',
                html: PIN_SVG.replace('%CLS%', status === 'Pending' ? 'pending' : 'cleaned'),
                iconSize: [30, 42],
                iconAnchor: [15, 41],
                tooltipAnchor: [0, -38]
            });
        }

        const points = [];
        locations.forEach(function (loc) {
            const lat = parseFloat(loc.latitude);
            const lng = parseFloat(loc.longitude);
            if (!isFinite(lat) || !isFinite(lng)) return;
            points.push([lat, lng]);

            const isPending = loc.status === 'Pending';
            const dateStr = new Date(loc.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            const photo = loc.before_photo_path
                ? `<img src="uploads/reports/${esc(loc.before_photo_path)}" alt="" style="width:100%; height:90px; object-fit:cover; border-radius:4px; margin-bottom:8px;">`
                : '';
            const card = `<div style="text-align:center; min-width:150px; padding:5px; font-family: 'Inter', sans-serif;">${photo}<div style="font-size:12px; color:${isPending ? '#B91C1C' : '#0369A1'}; font-weight:600;">${esc(loc.status)}</div><div style="font-size:11px; text-align:left; color:#687280; margin-top:4px;"><b>${esc(loc.full_name)}</b><br>${esc(dateStr)}</div></div>`;

            L.marker([lat, lng], { icon: pinIcon(loc.status) }).addTo(map).bindTooltip(card, { direction: 'top', opacity: 1 });
        });

        // Show every pin instead of only the default area
        if (points.length > 0) {
            map.fitBounds(points, { padding: [40, 40], maxZoom: 17 });
        }
        setTimeout(function () { map.invalidateSize(); }, 300);
    });
    <?php endif; ?>
</script>

</body>
</html>
