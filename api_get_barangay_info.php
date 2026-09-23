<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, ngrok-skip-browser-warning");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

include 'db_connect.php';

// Fetch the single row of settings from the database
$result = $conn->query("SELECT * FROM barangay_information LIMIT 1");

if ($result && $result->num_rows > 0) {
    $info = $result->fetch_assoc();
    echo json_encode(["status" => "success", "data" => $info]);
} else {
    echo json_encode(["status" => "error", "message" => "No information available."]);
}
?>