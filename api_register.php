<?php
// ALLOW MOBILE APP TO CONNECT (CORS Headers)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, ngrok-skip-browser-warning");
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight OPTIONS request from the mobile phone
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

include 'db_connect.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    $full_name = trim($_POST['full_name'] ?? '');
    $username  = trim($_POST['username'] ?? '');
    $password  = $_POST['password'] ?? ''; 
    $phone     = trim($_POST['phone_number'] ?? '');
    $address   = trim($_POST['address'] ?? '');
    $dob       = trim($_POST['dob'] ?? '');

    // 1. VALIDATE PHILIPPINE MOBILE NUMBER (Must be 11 digits starting with 09)
    if (!preg_match('/^09\d{9}$/', $phone)) {
        echo json_encode(["status" => "error", "message" => "Please enter a valid 11-digit Philippine mobile number starting with 09."]);
        exit();
    }

    // Encrypt the password
    $hashed_password = password_hash($password, PASSWORD_BCRYPT);

    // 2. CHECK IF USERNAME EXISTS (Prepared statement to prevent SQL injection)
    $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
    $check_stmt->bind_param("s", $username);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result && $check_result->num_rows > 0) {
        echo json_encode(["status" => "error", "message" => "That username is already taken. Please choose another."]);
        exit();
    }

    // 3. HANDLE THE ID PHOTO UPLOAD
    if (isset($_FILES['valid_id']) && $_FILES['valid_id']['error'] === UPLOAD_ERR_OK) {
        $target_dir = "uploads/ids/";
        if (!is_dir($target_dir)) { mkdir($target_dir, 0777, true); }
        
        $id_file_name = time() . "_" . basename($_FILES["valid_id"]["name"]);
        $target_file = $target_dir . $id_file_name;
        $file_extension = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
        $allowed_extensions = array("jpg", "jpeg", "png");

        if (!in_array($file_extension, $allowed_extensions)) {
            echo json_encode(["status" => "error", "message" => "Only JPG, JPEG, and PNG files are allowed for ID upload."]);
            exit();
        }

        if (move_uploaded_file($_FILES["valid_id"]["tmp_name"], $target_file)) {
            // 4. SAVE TO THE DATABASE WITH 'Pending' STATUS
            $insert_sql = "INSERT INTO users (full_name, username, password, phone_number, address_purok_sitio, date_of_birth, id_photo_path, role, account_status) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, 'Resident', 'Pending')";
            
            $stmt = $conn->prepare($insert_sql);
            $stmt->bind_param("sssssss", $full_name, $username, $hashed_password, $phone, $address, $dob, $id_file_name);
            
            if ($stmt->execute()) {
                echo json_encode(["status" => "success", "message" => "Registration successful! Please wait for Admin approval."]);
            } else {
                echo json_encode(["status" => "error", "message" => "Database error. Please try again."]);
            }
        } else {
            echo json_encode(["status" => "error", "message" => "Error saving your ID photo to the server."]);
        }
    } else {
        echo json_encode(["status" => "error", "message" => "Please upload a valid ID photo."]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid request method."]);
}
?>
