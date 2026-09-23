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

// Helper function to safely find and convert images (borrowed from your feed script)
function convertToSafeImage($img_val) {
    if (empty($img_val) || $img_val === 'null' || $img_val === 'undefined') return null;
    
    if (str_starts_with($img_val, 'data:image') || str_starts_with($img_val, 'http://') || str_starts_with($img_val, 'https://')) {
        return $img_val;
    }

    $cleanPath = ltrim($img_val, '/');
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

    $ngrok_domain = "https://unaired-undrilled-shortly.ngrok-free.dev/coastal_land_watch/";
    return $ngrok_domain . $cleanPath;
}

if (isset($_GET['user_id'])) {
    $user_id = $conn->real_escape_string($_GET['user_id']);
    
    // LIMIT 20: Prevents the server from choking on too many reports at once!
    $query = $conn->query("SELECT * FROM waste_reports WHERE resident_id = '$user_id' ORDER BY created_at DESC LIMIT 20");

    $reports = [];
   if ($query) {
        while($row = $query->fetch_assoc()) {
            
            // Existing Before Photo Logic
            $raw_photo = $row['before_photo_path'];
            $row['image_data'] = convertToSafeImage($raw_photo);

            // ADD THIS NEW AFTER PHOTO LOGIC:
            $raw_after = $row['after_photo_path'];
            $row['after_image_data'] = convertToSafeImage($raw_after);
            
            // 🚨 THIS IS THE MISSING LINE! 
            // It actually adds the report to the list sent to the app.
            $reports[] = $row; 
        }
    }
    
    echo json_encode(["status" => "success", "data" => $reports]);
} else {
    echo json_encode(["status" => "error", "message" => "User ID missing."]);
}
?>