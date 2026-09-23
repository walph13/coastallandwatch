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

if (isset($_GET['user_id'])) {
    $user_id = $conn->real_escape_string($_GET['user_id']);
    
    // Look at the table first to see if the column is named 'resident_id' or 'user_id'
    $col_test = $conn->query("SHOW COLUMNS FROM waste_reports LIKE 'resident_id'");
    $id_column = ($col_test && $col_test->num_rows > 0) ? "resident_id" : "user_id";

    // Count total reports
    $query_total = $conn->query("SELECT COUNT(*) as total FROM waste_reports WHERE $id_column = '$user_id'");
    if (!$query_total) {
        echo json_encode(["status" => "error", "message" => "MySQL Error: " . $conn->error]);
        exit();
    }
    $row_total = $query_total->fetch_assoc();
    $total = $row_total['total'] ? $row_total['total'] : 0;

    // 🚨 UPGRADED RESOLVED QUERY:
    // This now catches 'Resolved', 'Cleaned', 'Done', lowercase words, OR anything that simply isn't 'Pending'!
    $query_resolved = $conn->query("SELECT COUNT(*) as resolved FROM waste_reports WHERE $id_column = '$user_id' AND (LOWER(status) IN ('resolved', 'cleaned', 'done', 'completed') OR (status != 'Pending' AND status IS NOT NULL AND status != ''))");
    
    $row_resolved = $query_resolved ? $query_resolved->fetch_assoc() : ['resolved' => 0];
    $resolved = $row_resolved['resolved'] ? $row_resolved['resolved'] : 0;

    echo json_encode(["status" => "success", "total" => $total, "resolved" => $resolved]);
} else {
    echo json_encode(["status" => "error", "message" => "User ID missing."]);
}
?>