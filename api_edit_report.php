<?php
// coastal_land_watch/api_edit_report.php
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, ngrok-skip-browser-warning');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function sendJson($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(['status' => 'error', 'message' => 'Invalid request method.'], 405);
}

// 1. Connect to MySQL (reuses your existing connection file if present)
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
    $dbCandidates = ['barangay_waste_reporting', 'coastal_land_watch'];
    foreach ($dbCandidates as $dbName) {
        $tryConn = @new mysqli('localhost', 'root', '', $dbName);
        if ($tryConn && !$tryConn->connect_error) {
            $conn = $tryConn;
            break;
        }
    }
}

if (!$conn || $conn->connect_error) {
    sendJson(['status' => 'error', 'message' => 'Database connection failed.'], 500);
}
$conn->set_charset('utf8mb4');

// 2. Parse incoming JSON payload from edit_report.js
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    sendJson(['status' => 'error', 'message' => 'Invalid JSON payload.'], 400);
}

$residentId = isset($data['resident_id']) ? (int)$data['resident_id'] : 0;
$reportId   = isset($data['report_id']) ? (int)$data['report_id'] : 0;

if ($residentId <= 0 || $reportId <= 0) {
    sendJson(['status' => 'error', 'message' => 'Missing resident ID or report ID.'], 400);
}

// 3. Detect actual column names in waste_reports
$columns = [];
$colRes = $conn->query("SHOW COLUMNS FROM waste_reports");
if ($colRes) {
    while ($row = $colRes->fetch_assoc()) {
        $columns[] = $row['Field'];
    }
}

$ownerCol = in_array('resident_id', $columns) ? 'resident_id' : (in_array('user_id', $columns) ? 'user_id' : 'resident_id');
$photoCol = in_array('before_photo_path', $columns) ? 'before_photo_path' : (in_array('image_data', $columns) ? 'image_data' : 'before_photo_path');

// 4. Verify the report belongs to this resident and is still Pending
$stmt = $conn->prepare("SELECT status, `$photoCol` AS current_photo FROM waste_reports WHERE report_id = ? AND `$ownerCol` = ? LIMIT 1");
if (!$stmt) {
    sendJson(['status' => 'error', 'message' => 'Failed to prepare lookup query.'], 500);
}
$stmt->bind_param('ii', $reportId, $residentId);
$stmt->execute();
$result = $stmt->get_result();
$existing = $result->fetch_assoc();
$stmt->close();

if (!$existing) {
    sendJson(['status' => 'error', 'message' => 'Report not found or access denied.'], 404);
}

if (strtolower(trim((string)$existing['status'])) !== 'pending') {
    sendJson([
        'status'  => 'error',
        'code'    => 'not_pending',
        'message' => 'This report can no longer be edited because it has already been resolved or processed.'
    ]);
}

// 5. Build dynamic UPDATE query for only the fields that changed
$updates = [];
$types   = '';
$params  = [];

if (array_key_exists('description', $data)) {
    $description = trim((string)$data['description']);
    if ($description === '') {
        sendJson(['status' => 'error', 'message' => 'Description cannot be empty.'], 400);
    }
    if (mb_strlen($description) > 500) {
        sendJson(['status' => 'error', 'message' => 'Description must be 500 characters or fewer.'], 400);
    }
    $updates[] = '`description` = ?';
    $types    .= 's';
    $params[]  = $description;
}

if (array_key_exists('latitude', $data) && array_key_exists('longitude', $data)) {
    if (!is_numeric($data['latitude']) || !is_numeric($data['longitude'])) {
        sendJson(['status' => 'error', 'message' => 'Invalid map coordinates.'], 400);
    }
    $lat = (float)$data['latitude'];
    $lng = (float)$data['longitude'];
    $updates[] = '`latitude` = ?';
    $types    .= 'd';
    $params[]  = $lat;
    $updates[] = '`longitude` = ?';
    $types    .= 'd';
    $params[]  = $lng;
}

if (!empty($data['photo'])) {
    $rawPhoto = (string)$data['photo'];
    $base64Clean = preg_replace('#^data:image/\w+;base64,#i', '', $rawPhoto);
    $binaryData = base64_decode($base64Clean, true);

    if ($binaryData === false) {
        sendJson(['status' => 'error', 'message' => 'Invalid photo format.'], 400);
    }

    // Match however your existing reports store photos (Base64 data URI vs. file in uploads/reports/)
    $existingPhoto = (string)($existing['current_photo'] ?? '');
    $uploadDir = __DIR__ . '/uploads/reports';

    if ($existingPhoto !== '' && strpos($existingPhoto, 'data:image') !== 0 && is_dir($uploadDir)) {
        $fileName = 'report_' . $reportId . '_' . time() . '.jpg';
        if (file_put_contents($uploadDir . '/' . $fileName, $binaryData) === false) {
            sendJson(['status' => 'error', 'message' => 'Failed to save updated photo file.'], 500);
        }
        $photoValue = $fileName;
    } else {
        $photoValue = 'data:image/jpeg;base64,' . $base64Clean;
    }

    $updates[] = "`$photoCol` = ?";
    $types    .= 's';
    $params[]  = $photoValue;
}

if (empty($updates)) {
    sendJson(['status' => 'success', 'message' => 'No changes were needed.']);
}

// 6. Execute the update (guarding with status = 'Pending' to prevent race conditions)
$sql = "UPDATE waste_reports SET " . implode(', ', $updates) . " WHERE report_id = ? AND `$ownerCol` = ? AND LOWER(status) = 'pending'";
$types   .= 'ii';
$params[] = $reportId;
$params[] = $residentId;

$updateStmt = $conn->prepare($sql);
if (!$updateStmt) {
    sendJson(['status' => 'error', 'message' => 'Failed to prepare update query.'], 500);
}

$updateStmt->bind_param($types, ...$params);

if (!$updateStmt->execute()) {
    $updateStmt->close();
    sendJson(['status' => 'error', 'message' => 'Database error while updating report.'], 500);
}

$updateStmt->close();
$conn->close();

sendJson([
    'status'  => 'success',
    'message' => 'Your report has been updated.'
]);
