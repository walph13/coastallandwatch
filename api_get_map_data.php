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

$map_query = $conn->query("
    SELECT report_id, description, latitude, longitude, status, before_photo_path, created_at 
    FROM waste_reports 
    WHERE latitude IS NOT NULL AND longitude IS NOT NULL
");

$map_data = [];
if ($map_query) {
    while($row = $map_query->fetch_assoc()) {
        
        // 🚨 NGROK BYPASS: Convert the saved image into a Base64 text string
        $image_path = "uploads/reports/" . $row['before_photo_path'];
        
        if (!empty($row['before_photo_path']) && file_exists($image_path)) {
            $type = pathinfo($image_path, PATHINFO_EXTENSION);
            $data = file_get_contents($image_path);
            $base64 = 'data:image/' . $type . ';base64,' . base64_encode($data);
            $row['image_data'] = $base64; 
        } else {
            $row['image_data'] = null;
        }

        $map_data[] = $row;
    }
}

echo json_encode(["status" => "success", "data" => $map_data]);
?>