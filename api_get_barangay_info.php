<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, ngrok-skip-browser-warning");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

include 'db_connect.php';

$query = $conn->query("SELECT * FROM barangay_information LIMIT 1");

if ($query && $query->num_rows > 0) {
    $row = $query->fetch_assoc();
    echo json_encode([
        "status" => "success",
        "data"   => $row
    ]);
} else {
    echo json_encode([
        "status"  => "error",
        "message" => "No barangay information found."
    ]);
}
?>
