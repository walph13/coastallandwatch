<?php
// ALLOW MOBILE APP TO CONNECT
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, ngrok-skip-browser-warning");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

include 'db_connect.php';

// 🚨 TARGETS YOUR EXACT STATUS ENUMS: 'Cleaned', 'Confirmed', 'Ongoing', etc.
$sql = "SELECT * FROM waste_reports WHERE LOWER(status) IN ('resolved', 'cleaned', 'done', 'completed', 'confirmed', 'ongoing') OR (status != 'Pending' AND status IS NOT NULL AND status != '') ORDER BY report_id DESC LIMIT 50";
$result = $conn->query($sql);

if (!$result) {
    echo json_encode(["status" => "error", "message" => "MySQL Error: " . $conn->error]);
    exit();
}

// Helper function to convert XAMPP file paths or Base64 into mobile-safe strings
function convertToSafeImage($img_val) {
    if (empty($img_val) || $img_val === 'null' || $img_val === 'undefined') return null;
    
    // 1. If it's already Base64 or a full web URL, return as is:
    if (str_starts_with($img_val, 'data:image') || str_starts_with($img_val, 'http://') || str_starts_with($img_val, 'https://')) {
        return $img_val;
    }

    // 2. Clean the filename/path
    $cleanPath = ltrim($img_val, '/');

    // 3. Search local XAMPP folders on your laptop hard drive and convert to Base64
    $possible_folders = ['', 'uploads/', 'uploads/reports/', 'uploads/waste/', 'images/', 'reports/'];
    foreach ($possible_folders as $folder) {
        $fullPath = __DIR__ . '/' . $folder . $cleanPath;
        if (file_exists($fullPath) && !is_dir($fullPath)) {
            $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            $mimeType = ($extension === 'png') ? 'image/png' : 'image/jpeg';
            $fileData = file_get_contents($fullPath);
            return 'data:' . $mimeType . ';base64,' . base64_encode($fileData);
        }
    }

    // 4. If file isn't found locally, attach Ngrok domain as a fallback web link
    $ngrok_domain = "https://unaired-undrilled-shortly.ngrok-free.dev/coastal_land_watch/";
    return $ngrok_domain . $cleanPath;
}

$reports = [];
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        // 🚨 TARGETS YOUR EXACT COLUMN NAMES FROM PHPMYADMIN:
        $raw_before = $row['before_photo_path'] ?? $row['image_data'] ?? $row['photo'] ?? null;
        $row['before_photo_path'] = convertToSafeImage($raw_before);
        $row['image_data'] = $row['before_photo_path']; // Duplicated so front-end never fails
        
        $raw_after = $row['after_photo_path'] ?? $row['after_image'] ?? $row['after_photo'] ?? null;
        $row['after_photo_path'] = convertToSafeImage($raw_after);
        $row['after_image'] = $row['after_photo_path']; // Duplicated so front-end never fails
        
        $reports[] = $row;
    }
}

echo json_encode(["status" => "success", "data" => $reports]);
?>