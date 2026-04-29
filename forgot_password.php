<?php
session_start();
include 'db_connect.php';

$reset_success = "";
$reset_error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $conn->real_escape_string($_POST['username']);
    $phone = $conn->real_escape_string($_POST['phone_number']);
    $dob = $conn->real_escape_string($_POST['dob']);
    $new_password = $_POST['new_password'];

    // 1. VERIFY THE TRUE USER: Check if Username, Phone, and DOB match exactly
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ? AND phone_number = ? AND dob = ?");
    $stmt->bind_param("sss", $username, $phone, $dob);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        // MATCH FOUND! The true user is verified. Let's update the password.
        
        // Encrypt the new password exactly like we do in registration
        $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
        
        $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE username = ?");
        $update_stmt->bind_param("ss", $hashed_password, $username);
        
        if ($update_stmt->execute()) {
            $reset_success = "Password successfully reset! You can now log in.";
        } else {
            $reset_error = "Database error. Failed to update password.";
        }
    } else {
        // NO MATCH. Do not tell them exactly WHICH field was wrong to prevent hackers from guessing.
        $reset_error = "Verification failed. The details provided do not match our records.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Coastal & Land Watch</title>
    
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
        .reset-card { 
            background: #fff; 
            padding: 40px; 
            border-radius: 16px; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.08); 
            border: 1px solid rgba(0,0,0,0.05); 
            width: 100%; 
            max-width: 500px; 
        }
        /* GREEN THEME */
        .system-title { font-weight: 800; color: #2E7D32; font-size: 26px; margin-bottom: 5px; text-align: center; }
        .system-subtitle { color: #546E7A; font-size: 14px; font-weight: 600; margin-bottom: 25px; text-align: center; }
        .info-text { font-size: 13px; color: #78909C; text-align: center; margin-bottom: 30px; background: #E8F5E9; padding: 15px; border-radius: 8px; border: 1px solid #C8E6C9; }
        
        .form-label { font-weight: 700; color: #455A64; font-size: 13px; margin-bottom: 6px; }
        .custom-input { border-radius: 8px; border: 1px solid #CFD8DC; padding: 10px 14px; background-color: #fff; color: #263238; transition: 0.2s; box-shadow: none !important; }
        .custom-input:focus { border-color: #81C784; background-color: #F8FDFF; }
        
        .input-group .custom-input { border-right: none; border-top-right-radius: 0; border-bottom-right-radius: 0; }
        .custom-input-btn { border-color: #CFD8DC; background-color: #fff; border-left: none; border-top-right-radius: 8px; border-bottom-right-radius: 8px; color: #546E7A; }
        .custom-input:focus + .custom-input-btn { border-color: #81C784; background-color: #F8FDFF; }

        .btn-reset { background-color: #1B5E20; color: #fff; border: none; border-radius: 8px; font-weight: 800; font-size: 16px; padding: 12px; transition: 0.3s; margin-top: 10px; }
        .btn-reset:hover { background-color: #2E7D32; color: #fff; transform: translateY(-2px); box-shadow: 0 6px 15px rgba(46,125,50,0.3); }
        
        .login-link { color: #1B5E20; font-weight: 700; text-decoration: none; transition: 0.2s; }
        .login-link:hover { color: #2E7D32; text-decoration: underline; }
    </style>
</head>
<body>

    <div class="reset-card">
        
        <div class="text-center">
            <h2 class="system-title">Reset Password</h2>
            <p class="system-subtitle">Barangay Tanza Coastal & Land Watch</p>
        </div>

        <?php if (!empty($reset_success)): ?>
            
            <div class="alert alert-success text-center shadow-sm mb-0" style="font-size: 14px; font-weight: 700; border-radius: 8px;">
                ✅ <?php echo $reset_success; ?> <br>
                <a href="login.php" class="btn btn-sm btn-success mt-3 fw-bold px-4 py-2">Go to Login</a>
            </div>
            
        <?php else: ?>

            <div class="info-text">
                🔒 <strong>Identity Verification Required</strong><br>
                To reset your password, please verify your identity by entering your registered Username, Phone Number, and Date of Birth exactly as they appear on your account.
            </div>

            <?php if (!empty($reset_error)): ?>
                <div class="alert alert-danger text-center shadow-sm" style="font-size: 14px; font-weight: 700; border-radius: 8px;">
                    ⚠️ <?php echo $reset_error; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" class="form-control custom-input" required>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Registered Phone Number</label>
                        <input type="text" name="phone_number" class="form-control custom-input" placeholder="09xxxxxxxxx" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Date of Birth</label>
                        <input type="date" name="dob" class="form-control custom-input" required>
                    </div>
                </div>

                <hr style="border-color: #CFD8DC; margin: 25px 0;">

                <div class="mb-4">
                    <label class="form-label text-success">Create New Password</label>
                    <div class="input-group">
                        <input type="password" name="new_password" id="passwordInput" class="form-control custom-input" placeholder="Enter new password" required>
                        <button class="btn btn-outline-secondary custom-input-btn" type="button" id="togglePassword">👁️</button>
                    </div>
                </div>

                <button type="submit" class="btn w-100 btn-reset" onclick="return confirm('Are you sure you want to reset your password?');">
                    Verify & Reset Password
                </button>

            </form>

            <div class="text-center mt-4" style="font-size: 14px; color: #546E7A;">
                Remembered your password? <a href="login.php" class="login-link">Log In Here</a>
            </div>

        <?php endif; ?>

    </div>

    <script>
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
