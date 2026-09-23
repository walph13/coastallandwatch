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

    $hashed_password = password_hash($password, PASSWORD_BCRYPT);

    $check_user = $conn->query("SELECT * FROM users WHERE username = '$username'");
    
    if ($check_user->num_rows > 0) {
        $register_error = "Username is already taken. Please choose another.";
    } else {
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
                
                $insert_sql = "INSERT INTO users (full_name, username, password, phone_number, address_purok_sitio, date_of_birth, id_photo_path, role, account_status) 
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
    
    <style>
        :root {
            --bg-color: #F2EFE9; 
            --card-white: #FFFFFF;
            --card-lime: #D9FA4A; 
            --text-dark: #1A1A1A;
            --text-gray: #6B7280;
        }

        body { 
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; 
            background-color: var(--bg-color); 
            display: flex; align-items: center; justify-content: center; 
            min-height: 100vh; margin: 0; padding: 40px 20px;
        }

        .register-card { 
            background: var(--card-white); 
            padding: 48px; 
            border-radius: 32px; 
            box-shadow: 0 10px 40px rgba(0,0,0,0.03); 
            width: 100%; max-width: 650px; 
        }

        .system-title { font-weight: 700; color: var(--text-dark); font-size: 28px; margin-bottom: 8px; letter-spacing: -0.5px; text-align: center; }
        .system-subtitle { color: var(--text-gray); font-size: 15px; font-weight: 500; margin-bottom: 32px; text-align: center; }
        
        .form-label { font-weight: 600; color: var(--text-dark); font-size: 14px; margin-bottom: 8px; }
        
        .custom-input { 
            border-radius: 16px; border: 1px solid #E5E7EB; 
            padding: 14px 16px; background-color: #F9FAFB; 
            color: var(--text-dark); transition: 0.2s; box-shadow: none !important;
        }
        .custom-input:focus { border-color: var(--text-dark); background-color: #fff; }
        
        .input-group .custom-input { border-right: none; border-top-right-radius: 0; border-bottom-right-radius: 0; }
        .custom-input-btn { 
            border-color: #E5E7EB; background-color: #F9FAFB; 
            border-left: none; border-top-right-radius: 16px; border-bottom-right-radius: 16px; 
            color: var(--text-gray); padding: 0 16px;
        }
        .custom-input:focus + .custom-input-btn { border-color: var(--text-dark); background-color: #fff; }

        .btn-register { 
            background-color: var(--text-dark); color: var(--card-lime); 
            border: none; border-radius: 30px; 
            font-weight: 600; font-size: 16px; padding: 16px;
            transition: 0.2s; margin-top: 15px;
        }
        .btn-register:hover { transform: scale(0.98); opacity: 0.9; color: var(--card-lime); }

        .login-text { font-size: 14px; color: var(--text-gray); margin-top: 32px; font-weight: 500; text-align: center; }
        .login-link { color: var(--text-dark); font-weight: 700; text-decoration: none; border-bottom: 2px solid var(--card-lime); padding-bottom: 2px; transition: 0.2s; }
        .login-link:hover { opacity: 0.7; }
        
        .alert { border-radius: 16px; font-size: 14px; font-weight: 500; text-align: center; border: none; }
        .alert-success { background: #E6F8F3; color: #047857; }
        .alert-danger { background: #FCE8E8; color: #DC2626; }
    </style>
</head>
<body>

    <div class="register-card">
        
        <div class="text-center">
            <h2 class="system-title">Resident Registration</h2>
            <p class="system-subtitle">Join Barangay Tanza's Coastal & Land Watch</p>
        </div>

        <?php if (!empty($register_success)): ?>
            <div class="alert alert-success shadow-sm">
                <i class="ti ti-check"></i> <?php echo $register_success; ?> <br>
                <a href="login.php" class="alert-link mt-2 d-block" style="color: #047857; font-weight: 700;">Click here to return to Login</a>
            </div>
        <?php endif; ?>

        <?php if (!empty($register_error)): ?>
            <div class="alert alert-danger shadow-sm"><i class="ti ti-alert-circle"></i> <?php echo $register_error; ?></div>
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
                            <button class="btn btn-outline-secondary custom-input-btn" type="button" id="togglePassword">
                                <i class="ti ti-eye"></i>
                            </button>
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
                    <input type="file" name="valid_id" class="form-control custom-input" accept=".jpg,.jpeg,.png" required style="padding: 10px 16px;">
                    <small class="text-muted d-block mt-2" style="font-size: 12px; font-weight: 500;">Upload a clear photo of your Barangay ID, Voter's ID, or any valid Gov ID.</small>
                </div>

                <div class="form-check mb-4" style="padding-left: 28px;">
                    <input class="form-check-input" type="checkbox" id="termsCheck" required style="width: 18px; height: 18px; margin-top: 2px;">
                    <label class="form-check-label text-muted ms-2" for="termsCheck" style="font-size: 13px; font-weight: 500;">
                        I agree to the Terms of Service and Privacy Policy.
                    </label>
                </div>

                <button type="submit" class="btn w-100 btn-register">
                    Register Account
                </button>
            </form>
        <?php endif; ?>

        <div class="login-text">
            Already have an account? <a href="login.php" class="login-link">Log In Here</a>
        </div>
    </div>

    <script>
        const togglePassword = document.querySelector('#togglePassword');
        const password = document.querySelector('#passwordInput');

        togglePassword.addEventListener('click', function () {
            const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
            password.setAttribute('type', type);
            this.innerHTML = type === 'password' ? '<i class="ti ti-eye"></i>' : '<i class="ti ti-eye-off"></i>';
        });
    </script>
</body>
</html>
