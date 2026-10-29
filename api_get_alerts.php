<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, ngrok-skip-browser-warning");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header("Content-Type: application/json; charset=UTF-8");

try {
    include 'db_connect.php';

    $sql = "SELECT alert_id, purok_area, message, sent_at FROM basura_alerts ORDER BY sent_at DESC LIMIT 20";
    $result = $conn->query($sql);
    $alerts = [];

    if ($result && $result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $alerts[] = [
                'alert_id' => $row['alert_id'],
                'purok_area' => $row['purok_area'],
                'message' => $row['message'],
                'created_at' => $row['sent_at'], // Map sent_at to created_at for the app
                'time_ago' => time_elapsed_string($row['sent_at'])
            ];
        }
    }

    // Wrap in 'status' and 'data' so app.js can parse it correctly
    echo json_encode([
        "status" => "success",
        "data" => $alerts
    ]);

} catch (Exception $e) {
    echo json_encode([
        "status" => "error", 
        "message" => "Database Error: " . $e->getMessage()
    ]);
}

function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'year', 'm' => 'month', 'w' => 'week', 'd' => 'day',
        'h' => 'hr', 'i' => 'min', 's' => 'sec',
    );
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }
    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}
?>
