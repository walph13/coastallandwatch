<?php
// coastal_land_watch/api_get_map_data.php
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, ngrok-skip-browser-warning');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$conn = null;
foreach (['db_connect.php', 'db.php', 'config.php', 'conn.php'] as $dbFile) {
    $path = __DIR__ . '/' . $dbFile;
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    mysqli_report(MYSQLI_REPORT_OFF);
    foreach (['barangay_waste_reporting', 'coastal_land_watch'] as $dbName) {
        $tryConn = @new mysqli('localhost', 'root', '', $dbName);
        if ($tryConn && !$tryConn->connect_error) {
            $conn = $tryConn;
            break;
        }
    }
}

if (!$conn || $conn->connect_error) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
    exit;
}
$conn->set_charset('utf8mb4');

// Detect column names dynamically
$columns = [];
$colRes = $conn->query("SHOW COLUMNS FROM waste_reports");
if ($colRes) {
    while ($row = $colRes->fetch_assoc()) {
        $columns[] = $row['Field'];
    }
}

$ownerCol = in_array('resident_id', $columns) ? 'resident_id' : (in_array('user_id', $columns) ? 'user_id' : 'NULL');
$photoCol = in_array('before_photo_path', $columns) ? 'before_photo_path' : (in_array('image_data', $columns) ? 'image_data' : 'NULL');
$dateCol  = in_array('created_at', $columns) ? 'created_at' : (in_array('timestamp', $columns) ? 'timestamp' : 'NULL');

$sql = "SELECT 
            report_id,
            $ownerCol AS resident_id,
            latitude,
            longitude,
            description,
            status,
            $photoCol AS raw_photo,
            $dateCol AS created_at
        FROM waste_reports
        WHERE latitude IS NOT NULL AND longitude IS NOT NULL
        ORDER BY report_id DESC";

$result = $conn->query($sql);
$data = [];

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? '';
$baseUrl = rtrim($protocol . '://' . $host . dirname($_SERVER['SCRIPT_NAME']), '/');

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $photo = $row['raw_photo'] ?? '';
        if ($photo !== '' && strpos($photo, 'data:image') !== 0 && strpos($photo, 'http') !== 0) {
            $photo = $baseUrl . '/uploads/reports/' . $photo;
        }

        $data[] = [
            'report_id'   => $row['report_id'],
            'resident_id' => $row['resident_id'],
            'latitude'    => $row['latitude'],
            'longitude'   => $row['longitude'],
            'description' => $row['description'],
            'status'      => $row['status'],
            'image_data'  => $photo,
            'created_at'  => $row['created_at']
        ];
    }
}

echo json_encode(['status' => 'success', 'data' => $data]);
$conn->close();
