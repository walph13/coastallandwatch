<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, ngrok-skip-browser-warning");
header("Content-Type: application/json; charset=UTF-8");

// 🚨 ANTI-CACHE HEADERS
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

include 'db_connect.php';

function convertToSafeImage($img_val) {
    if (empty($img_val) || $img_val === 'null' || $img_val === 'undefined') return null;
    if (strpos($img_val, 'data:image') === 0 || strpos($img_val, 'http') === 0) return $img_val;
    $cleanPath = ltrim($img_val, '/');
    $folders = ['', 'uploads/', 'uploads/reports/', 'images/'];
    foreach ($folders as $folder) {
        $fullPath = __DIR__ . '/' . $folder . $cleanPath;
        if (file_exists($fullPath) && !is_dir($fullPath)) {
            $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            $mime = ($ext === 'png') ? 'image/png' : 'image/jpeg';
            return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($fullPath));
        }
    }
    return "https://unaired-undrilled-shortly.ngrok-free.dev/coastal_land_watch/" . $cleanPath;
}

// 1. GET ALL REPORTS
$reports = [];
$pendingCount = 0;
$cleanedCount = 0;

$resReports = $conn->query("SELECT w.*, u.username as reporter_name FROM waste_reports w LEFT JOIN users u ON w.resident_id = u.user_id ORDER BY w.report_id DESC");
if ($resReports) {
    while ($row = $resReports->fetch_assoc()) {
        $row['before_photo_path'] = convertToSafeImage($row['before_photo_path'] ?? null);
        $row['after_photo_path'] = convertToSafeImage($row['after_photo_path'] ?? null);
        
        $rawStatus = isset($row['status']) ? trim($row['status']) : 'Pending';
        $stat = strtolower($rawStatus);
        
        if (in_array($stat, ['pending', 'ongoing'])) { $pendingCount++; } 
        elseif (in_array($stat, ['cleaned', 'resolved', 'done', 'confirmed'])) { $cleanedCount++; }
        
        $reports[] = $row;
    }
}

// 2. GET ALL RESIDENTS
$users = [];
$residentCount = 0;
$resUsers = $conn->query("SELECT * FROM users WHERE role != 'Admin' AND role != 'secretary' ORDER BY user_id DESC");
if ($resUsers) {
    while ($row = $resUsers->fetch_assoc()) {
        $row['password'] = ""; 
        $stat = strtolower(trim($row['account_status'] ?? 'Pending'));
        if ($stat === 'approved' || $stat === 'active') { $residentCount++; }
        
        $row['id'] = $row['user_id']; 
        $row['phone'] = $row['phone_number']; 
        $row['status'] = $row['account_status']; 
        $users[] = $row;
    }
}

// 3. GET BARANGAY INFO & SECRETARY NAME PARA SA PRINTING
$brgyInfo = null;
$resBrgy = $conn->query("SELECT * FROM barangay_information LIMIT 1");
if ($resBrgy && $resBrgy->num_rows > 0) {
    $brgyInfo = $resBrgy->fetch_assoc();
    // Iko-convert ang logo sa Base64 para siguradong magpi-print!
    $brgyInfo['logo_path'] = convertToSafeImage($brgyInfo['logo_path'] ?? null);
}

$secName = "Barangay Secretary";
$resSec = $conn->query("SELECT full_name FROM users WHERE role = 'Admin' LIMIT 1");
if ($resSec && $resSec->num_rows > 0) {
    $sec = $resSec->fetch_assoc();
    $secName = $sec['full_name'];
}
if ($brgyInfo) { $brgyInfo['secretary_name'] = $secName; }

// 4. SEND IT ALL TO THE PHONE
echo json_encode([
    "status" => "success",
    "stats" => ["pending" => $pendingCount, "cleaned" => $cleanedCount, "residents" => $residentCount],
    "reports" => $reports,
    "users" => $users,
    "barangay_info" => $brgyInfo // Pinadala na natin ang data sa phone!
]);
?>