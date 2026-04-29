<?php
session_start();
include 'db_connect.php';

$register_success = "";
$register_error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $full_name = $conn->real_escape_string($_POST['full_name']);
    $username = $conn->real_escape_string($_POST['username']);
    $password = $_POST['password']; 
    $phone = $conn->real_escape_string($_POST['phone_number']);
    $address = $conn->real_escape_string($_POST['address']);
    $dob = $conn->real_escape_string($_POST['dob']);

    // Encrypt the password for security
    $hashed_password = password_hash($password, PASSWORD_BCRYPT);

    // Check if username already exists
    $check_user = $conn->query("SELECT * FROM users WHERE username = '$username'");
    
    if ($check_user->num_rows > 0) {
        $register_error = "Username is already taken. Please choose another.";
    } else {
        // Handle ID Upload
        $target_dir = "uploads/ids/";
        if (!is_dir($target_dir)) { mkdir($target_dir, 0777, true); }
        
        $id_file_name = time() . "_" . basename($_FILES["valid_id"]["name"]);
        $target_file = $target_dir . $id_file_name;
        $file_extension = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
        $allowed_extensions = array("jpg", "jpeg", "png");

        if (!in_array($file_extension, $allowed_extensions)) {
            $register_error = "Only JPG, JPEG, and PNG files are allowed for ID upload.";
        } else {
            if (move_uploaded_file($_FILES["valid_id"]["tmp_name"], $target_file)) {
                
                // Insert into database (Status is Pending by default!)
                $insert_sql = "INSERT INTO users (full_name, username, password, phone_number, address, dob, id_photo_path, role, account_status) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, 'Resident', 'Pending')";
                
                $stmt = $conn->prepare($insert_sql);
                $stmt->bind_param("sssssss", $full_name, $username, $hashed_password, $phone, $address, $dob, $id_file_name);
                
                if ($stmt->execute()) {
                    $register_success = "Registration successful! Please wait for the Barangay Admin to approve your account.";
                } else {
                    $register_error = "Database error. Please try again.";
                }
            } else {
                $register_error = "Error uploading your ID photo.";
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
    <title>Resident Registration - Coastal & Land Watch</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <style>
        body { 
            font-family: 'Segoe UI', Arial, sans-serif; 
            background-color: #ECEFF1; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            min-height: 100vh; 
            margin: 0; 
            padding: 40px 0;
        }
        .register-card { 
            background: #fff; 
            padding: 40px; 
            border-radius: 16px; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.08); 
            border: 1px solid rgba(0,0,0,0.05); 
            width: 100%; 
            max-width: 600px; 
        }
        .system-title { font-weight: 800; color: #2E7D32; font-size: 28px; margin-bottom: 5px; text-align: center; }
        .system-subtitle { color: #546E7A; font-size: 14px; font-weight: 600; margin-bottom: 30px; text-align: center; }
        .form-label { font-weight: 700; color: #455A64; font-size: 13px; margin-bottom: 6px; }
        .custom-input { border-radius: 8px; border: 1px solid #CFD8DC; padding: 10px 14px; background-color: #fff; color: #263238; transition: 0.2s; box-shadow: none !important; }
        .custom-input:focus { border-color: #81C784; background-color: #F8FDFF; }
        
        .input-group .custom-input { border-right: none; border-top-right-radius: 0; border-bottom-right-radius: 0; }
        .custom-input-btn { border-color: #CFD8DC; background-color: #fff; border-left: none; border-top-right-radius: 8px; border-bottom-right-radius: 8px; color: #546E7A; }
        .custom-input:focus + .custom-input-btn { border-color: #81C784; background-color: #F8FDFF; }

        .btn-register { background-color: #1B5E20; color: #fff; border: none; border-radius: 8px; font-weight: 800; font-size: 16px; padding: 12px; transition: 0.3s; margin-top: 15px; }
        .btn-register:hover { background-color: #2E7D32; color: #fff; transform: translateY(-2px); box-shadow: 0 6px 15px rgba(46,125,50,0.3); }
        
        .login-link { color: #1B5E20; font-weight: 700; text-decoration: none; transition: 0.2s; }
        .login-link:hover { color: #2E7D32; text-decoration: underline; }
    </style>
</head>
<body>

    <div class="register-card">
        
        <div class="text-center">
            <h2 class="system-title">Resident Registration</h2>
            <p class="system-subtitle">Join Barangay Tanza's Coastal & Land Watch</p>
        </div>

        <?php if (!empty($register_success)): ?>
            <div class="alert alert-success text-center shadow-sm" style="font-size: 14px; font-weight: 700; border-radius: 8px;">
                ✅ <?php echo $register_success; ?> <br>
                <a href="login.php" class="alert-link mt-2 d-block">Click here to return to Login</a>
            </div>
        <?php endif; ?>

        <?php if (!empty($register_error)): ?>
            <div class="alert alert-danger text-center shadow-sm" style="font-size: 14px; font-weight: 700; border-radius: 8px;">
                ⚠️ <?php echo $register_error; ?>
            </div>
        <?php endif; ?>

        <?php if (empty($register_success)): ?>
            <form method="POST" action="" enctype="multipart/form-data">
                
                <div class="mb-3">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="full_name" class="form-control custom-input" placeholder="e.g. Juan Dela Cruz" required>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-control custom-input" placeholder="Choose a username" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Password</label>
                        <div class="input-group">
                            <input type="password" name="password" id="passwordInput" class="form-control custom-input" placeholder="••••••••" required>
                            <button class="btn btn-outline-secondary custom-input-btn" type="button" id="togglePassword">👁️</button>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Phone Number</label>
                        <input type="text" name="phone_number" class="form-control custom-input" placeholder="09xxxxxxxxx" required pattern="[0-9]{11}" title="Please enter an 11-digit phone number">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Date of Birth</label>
                        <input type="date" name="dob" class="form-control custom-input" required>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Address / Purok / Sitio</label>
                    <input type="text" name="address" class="form-control custom-input" placeholder="e.g. Sitio Gulod, Purok 2" required>
                </div>

                <div class="mb-4">
                    <label class="form-label">Upload Valid ID</label>
                    <input type="file" name="valid_id" class="form-control custom-input" accept=".jpg,.jpeg,.png" required>
                    <small class="text-muted d-block mt-1" style="font-size: 11px;">Upload a clear photo of your Barangay ID, Voter's ID, or any valid Gov ID.</small>
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="termsCheck" required>
                    <label class="form-check-label text-muted" for="termsCheck" style="font-size: 13px;">
                        I agree to the Terms of Service and Privacy Policy.
                    </label>
                </div>

                <button type="submit" class="btn w-100 btn-register">
                    Register Account
                </button>

            </form>
        <?php endif; ?>

        <div class="text-center mt-4" style="font-size: 14px; color: #546E7A;">
            Already have an account? <a href="login.php" class="login-link">Log In Here</a>
        </div>

    </div>

    <script>
        // Password visibility toggle
        const togglePassword = document.querySelector('#togglePassword');
        const password = document.querySelector('#passwordInput');

        togglePassword.addEventListener('click', function (e) {
            const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
            password.setAttribute('type', type);
            this.textContent = type === 'password' ? '👁️' : '🙈';
        });
    </script>

</body>
</html>
