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

// Ensure data was actually sent
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // Grab the text fields sent from the phone
    $full_name = $conn->real_escape_string($_POST['full_name']);
    $username = $conn->real_escape_string($_POST['username']);
    $password = $_POST['password']; 
    $phone = $conn->real_escape_string($_POST['phone_number']);
    $address = $conn->real_escape_string($_POST['address']);
    $dob = $conn->real_escape_string($_POST['dob']);

    // Encrypt the password!
    $hashed_password = password_hash($password, PASSWORD_BCRYPT);

    // 1. CHECK IF USERNAME EXISTS
    $check_user = $conn->query("SELECT * FROM users WHERE username = '$username'");
    if ($check_user->num_rows > 0) {
        echo json_encode(["status" => "error", "message" => "That username is already taken. Please choose another."]);
        exit();
    }

    // 2. HANDLE THE ID PHOTO UPLOAD
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
            // 3. SAVE TO THE DATABASE
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