<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, ngrok-skip-browser-warning");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

include 'db_connect.php';

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if ($data && (isset($data['user_id']) || isset($data['id']))) {
    // Handle whether the mobile app sends 'user_id' or 'id'
    $user_id = $conn->real_escape_string(isset($data['user_id']) ? $data['user_id'] : $data['id']);
    
    $full_name = $conn->real_escape_string($data['full_name']);
    $username = $conn->real_escape_string($data['username']);
    $phone = $conn->real_escape_string($data['phone_number']);
    
    // Match exact DB column names: date_of_birth and address_purok_sitio
    $dob = $conn->real_escape_string($data['date_of_birth']);
    $address = $conn->real_escape_string($data['address_purok_sitio']);
    
    // Check if user is also updating password
    $pass_sql = "";
    if (!empty($data['password'])) {
        $password = password_hash($data['password'], PASSWORD_DEFAULT);
        $pass_sql = ", password = '$password'";
    }

    // Handle Profile Picture Upload
    $pic_sql = "";
    if (!empty($data['profile_pic']) && strpos($data['profile_pic'], 'data:image') === 0) {
        $target_dir = "uploads/profiles/";
        if (!is_dir($target_dir)) { mkdir($target_dir, 0777, true); }
        
        $file_name = "user_" . $user_id . "_" . time() . ".jpg";
        $target_file = $target_dir . $file_name;
        
        $base64_image = preg_replace('#^data:image/\w+;base64,#i', '', $data['profile_pic']);
        file_put_contents($target_file, base64_decode($base64_image));
        
        $pic_sql = ", profile_pic = '$file_name'";
    }

    // 🚨 EXACT MAPPING TO YOUR TABLE STRUCTURE:
    $update_sql = "UPDATE users SET 
                   full_name = '$full_name', 
                   username = '$username', 
                   phone_number = '$phone', 
                   date_of_birth = '$dob', 
                   address_purok_sitio = '$address' 
                   $pass_sql 
                   $pic_sql 
                   WHERE user_id = '$user_id'";

    if ($conn->query($update_sql)) {
        // Fetch updated data using exact column names
        $res = $conn->query("SELECT user_id, user_id AS id, full_name, username, phone_number, date_of_birth, address_purok_sitio, profile_pic FROM users WHERE user_id = '$user_id'");
        $updated_user = $res->fetch_assoc();
        
        if (!empty($updated_user['profile_pic']) && file_exists("uploads/profiles/" . $updated_user['profile_pic'])) {
            $type = pathinfo("uploads/profiles/" . $updated_user['profile_pic'], PATHINFO_EXTENSION);
            $img_data = file_get_contents("uploads/profiles/" . $updated_user['profile_pic']);
            $updated_user['profile_pic_data'] = 'data:image/' . $type . ';base64,' . base64_encode($img_data);
        } else {
            $updated_user['profile_pic_data'] = null;
        }

        echo json_encode(["status" => "success", "message" => "Profile updated successfully!", "user" => $updated_user]);
    } else {
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid data sent or User ID missing."]);
}
?>