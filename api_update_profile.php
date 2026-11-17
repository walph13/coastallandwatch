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
    $user_id   = $conn->real_escape_string(isset($data['user_id']) ? $data['user_id'] : $data['id']);
    $full_name = trim($data['full_name'] ?? '');
    $username  = trim($data['username'] ?? '');
    $phone     = trim($data['phone_number'] ?? '');
    $dob       = trim($data['date_of_birth'] ?? '');
    $address   = trim($data['address_purok_sitio'] ?? '');
    $raw_pass  = $data['password'] ?? '';

    // 1. Block empty required fields
    if ($full_name === '' || $username === '' || $phone === '' || $dob === '' || $address === '') {
        echo json_encode([
            "status" => "error",
            "message" => "All profile fields (Full Name, Username, Phone Number, Date of Birth, and Address) are required."
        ]);
        exit();
    }

    // 2. Validate 11-digit Philippine mobile number
    if (!preg_match('/^09\d{9}$/', $phone)) {
        echo json_encode([
            "status" => "error",
            "message" => "Please enter a valid 11-digit Philippine mobile number starting with 09."
        ]);
        exit();
    }

    // 3. Validate new password if provided
    if ($raw_pass !== '' && (strlen($raw_pass) < 8 || !preg_match('/[a-zA-Z]/', $raw_pass) || !preg_match('/[0-9]/', $raw_pass))) {
        echo json_encode([
            "status" => "error",
            "message" => "New password must be at least 8 characters and contain both a letter and a number."
        ]);
        exit();
    }

    // 4. Prevent duplicate username from another account
    $escaped_username = $conn->real_escape_string($username);
    $dup_check = $conn->query("SELECT user_id FROM users WHERE username = '$escaped_username' AND user_id != '$user_id'");
    if ($dup_check && $dup_check->num_rows > 0) {
        echo json_encode([
            "status" => "error",
            "message" => "That username is already taken by another account."
        ]);
        exit();
    }

    $escaped_fullname = $conn->real_escape_string($full_name);
    $escaped_phone    = $conn->real_escape_string($phone);
    $escaped_dob      = $conn->real_escape_string($dob);
    $escaped_address  = $conn->real_escape_string($address);

    // Check if user is also updating password
    $pass_sql = "";
    if ($raw_pass !== '') {
        $password = password_hash($raw_pass, PASSWORD_DEFAULT);
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

    $update_sql = "UPDATE users SET 
                   full_name = '$escaped_fullname', 
                   username = '$escaped_username', 
                   phone_number = '$escaped_phone', 
                   date_of_birth = '$escaped_dob', 
                   address_purok_sitio = '$escaped_address' 
                   $pass_sql 
                   $pic_sql 
                   WHERE user_id = '$user_id'";

    if ($conn->query($update_sql)) {
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
