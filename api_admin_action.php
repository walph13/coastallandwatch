<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, ngrok-skip-browser-warning");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { 
    http_response_code(200); 
    exit(); 
}

error_reporting(E_ERROR | E_PARSE);
include 'db_connect.php';
$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['action'])) {
    echo json_encode(["status" => "error", "message" => "No action specified"]);
    exit();
}

$action = $data['action'];

if ($action === 'resolve_report') {
    $id = $conn->real_escape_string($data['report_id']);
    
    // Gamitin ang report_id na nakalagay sa waste_reports table mo
    $conn->query("UPDATE waste_reports SET status = 'Cleaned' WHERE report_id = '$id'");
    
    echo json_encode(["status" => "success", "message" => "Report marked as Cleaned!"]);
} 
elseif ($action === 'approve_user') {
    $id = $conn->real_escape_string($data['user_id']);
    
    // 🚨 DATABASE SYNC FIX: 'account_status' at 'user_id' ang eksaktong gamit sa users table mo[cite: 8]
    $sql = "UPDATE users SET account_status = 'Approved' WHERE user_id = '$id'";
    
    if ($conn->query($sql)) {
        echo json_encode(["status" => "success", "message" => "Resident Approved!"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Database Error: " . $conn->error]);
    }
} 
else {
    echo json_encode(["status" => "error", "message" => "Unknown action"]);
}
?>
