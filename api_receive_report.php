<?php
// ALLOW MOBILE APP TO CONNECT
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, ngrok-skip-browser-warning");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

include 'db_connect.php';

// Read the JSON data sent from the mobile app
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if ($data) {
    // 🚨 THIS IS THE FIX: Grab the resident_id we attached in app.js!
    $resident_id = $conn->real_escape_string($data['resident_id']);
    $description = $conn->real_escape_string($data['description']);
    $lat = $conn->real_escape_string($data['latitude']);
    $lng = $conn->real_escape_string($data['longitude']);
    $photo_base64 = $data['photo'];

    // Convert the Base64 code back into a real .jpg image
    $target_dir = "uploads/reports/";
    if (!is_dir($target_dir)) { mkdir($target_dir, 0777, true); }
    $file_name = time() . "_" . uniqid() . ".jpg";
    $target_file = $target_dir . $file_name;

    // Clean the text and save the image
    $photo_base64 = preg_replace('#^data:image/\w+;base64,#i', '', $photo_base64);
    file_put_contents($target_file, base64_decode($photo_base64));

    $status = 'Pending';

    // Insert everything, including the Resident ID, into the database!
    $insert_sql = "INSERT INTO waste_reports (resident_id, description, latitude, longitude, before_photo_path, status) 
                   VALUES ('$resident_id', '$description', '$lat', '$lng', '$file_name', '$status')";

    if ($conn->query($insert_sql)) {
        echo json_encode(["status" => "success", "message" => "Report saved to database!"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "No data received."]);
}
?>