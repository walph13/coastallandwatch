<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, ngrok-skip-browser-warning');
header('Content-Type: application/json');

// 1. Payagan ang "Preflight" request ng mobile app (Dahil sa Ngrok)
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit(0); 
}

include 'db_connect.php';

$data = json_decode(file_get_contents("php://input"));

// 2. Kunin ang data at tanggalin ang extra spaces (trim) na galing sa mobile keyboard
$username = isset($data->username) ? trim($data->username) : '';
$password = isset($data->password) ? $data->password : '';

if (!empty($username) && !empty($password)) {
    
    // 3. Eksaktong logic ng website mo (walang email column na hinahanap)
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        // 4. I-check ang password
        if (password_verify($password, $user['password']) || $password === $user['password']) {
            
            // SECURITY CHECK: I-block kung rejected ang status
            if ($user['role'] === 'Resident' && $user['account_status'] === 'Rejected') {
                echo json_encode([
                    "status" => "error", 
                    "message" => "Your registration was rejected. Please contact the Barangay Hall."
                ]);
                exit();
            }

            // TAMA LAHAT!
            echo json_encode([
                "status" => "success",
                "user" => $user
            ]);
            
        } else {
            echo json_encode(["status" => "error", "message" => "Incorrect password."]);
        }
    } else {
        echo json_encode(["status" => "error", "message" => "Username not found."]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Please fill in all fields."]);
}
?>