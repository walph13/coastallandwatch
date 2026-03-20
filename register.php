<?php
include 'db_connect.php';

// BACKEND LOGIC: Handle Registration
if (isset($_POST['register'])) {
    $fullname = $_POST['full_name'];
    $username = $_POST['username'];
    
    // Encrypt the password using Bcrypt!
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $phone = $_POST['phone_number'];
    
    // Handle ID Photo Upload
    $target_dir = "uploads/ids/";
    if (!is_dir($target_dir)) { mkdir($target_dir, 0777, true); }
    
    $id_photo = time() . "_" . basename($_FILES["id_photo"]["name"]);
    $target_file = $target_dir . $id_photo;
    
    // Check if it's an image
    $allowed_extensions = array("jpg", "jpeg", "png");
    $file_extension = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));

    if (!in_array($file_extension, $allowed_extensions)) {
        echo "<script>alert('Only JPG, JPEG, and PNG files are allowed for ID upload.');</script>";
    } else {
        if (move_uploaded_file($_FILES["id_photo"]["tmp_name"], $target_file)) {
            // Insert into database as a 'Resident' with 'Pending' status
            $sql = "INSERT INTO users (full_name, username, password, phone_number, id_photo_path, role, account_status) VALUES (?, ?, ?, ?, ?, 'Resident', 'Pending')";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssss", $fullname, $username, $password, $phone, $id_photo);
            
            if ($stmt->execute()) {
                echo "<script>alert('Registration successful! Please wait for Admin approval.'); window.location.href='login.php';</script>";
            } else {
                echo "<script>alert('Error registering account. Username might already exist.');</script>";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Coastal & Land Watch</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { 
            background-color: #e9ecef; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            min-height: 100vh; 
            margin: 0;
            padding: 20px 0;
        }
        .register-card {
            max-width: 500px;
            width: 100%;
            border-radius: 10px;
            box-shadow: 0px 10px 30px rgba(0,0,0,0.1);
            border: none;
        }
    </style>
</head>
<body>

    <div class="card register-card p-4">
        <div class="text-center mb-4">
            <h3 class="fw-bold text-success mb-1">Resident Registration</h3>
            <p class="text-muted small">Join Barangay Tanza's Coastal Watch</p>
        </div>

        <form method="POST" action="register.php" enctype="multipart/form-data">
            <div class="mb-3">
                <label class="form-label fw-bold">Full Name</label>
                <input type="text" name="full_name" class="form-control" required placeholder="e.g. Juan Dela Cruz">
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold">Username</label>
                    <input type="text" name="username" class="form-control" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold">Password</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold">Phone Number</label>
                <input type="text" name="phone_number" class="form-control" required placeholder="09xxxxxxxxx">
            </div>

            <div class="mb-4">
                <label class="form-label fw-bold">Upload Valid ID (For Verification)</label>
                <input type="file" name="id_photo" class="form-control" accept=".jpg,.jpeg,.png" required>
                <div class="form-text">Please upload a clear picture of any valid ID.</div>
            </div>

            <button type="submit" name="register" class="btn btn-success w-100 fw-bold py-2 shadow-sm">Create Account</button>
        </form>

        <div class="text-center mt-4">
            <span class="text-muted small">Already have an account?</span>
            <a href="login.php" class="text-success fw-bold text-decoration-none small">Log In Here</a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
