<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, ngrok-skip-browser-warning');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit(0); 
}

include 'db_connect.php';

$data = json_decode(file_get_contents("php://input"));

// Kunin ang lahat ng data mula sa app
$username = isset($data->username) ? trim($data->username) : '';
$phone = isset($data->phone) ? trim($data->phone) : '';
$dob = isset($data->dob) ? trim($data->dob) : '';
$new_password = isset($data->new_password) ? $data->new_password : '';

if (!empty($username) && !empty($phone) && !empty($dob) && !empty($new_password)) {
    
    // 1. Hanapin kung nag-e-exist ang user at kung tugma ang Phone at DOB
    // (Base ito sa pangalan ng columns na ginamit mo sa register[cite: 7])
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ? AND phone_number = ? AND dob = ?");
    $stmt->bind_param("sss", $username, $phone, $dob);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows === 1) {
        // TAMA ANG IMPORMASYON! 
        $user = $result->fetch_assoc();
        $user_id = $user['user_id'];
        
        // 2. I-hash ang bagong password para secure (best practice)
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

        // 3. I-update ang password sa database
        $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
        $update_stmt->bind_param("si", $hashed_password, $user_id);
        
        if ($update_stmt->execute()) {
            echo json_encode([
                "status" => "success",
                "message" => "Password successfully reset! You can now log in using your new password."
            ]);
        } else {
            echo json_encode(["status" => "error", "message" => "Failed to update password in database."]);
        }
        
    } else {
        // MALI ANG IBINIGAY NA DETALYE
        echo json_encode(["status" => "error", "message" => "Verification failed. The Phone Number or Date of Birth does not match this Username."]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Please fill in all the required fields."]);
}
?>