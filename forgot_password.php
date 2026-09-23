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

        .reset-card { 
            background: var(--card-white); 
            padding: 48px; 
            border-radius: 32px; 
            box-shadow: 0 10px 40px rgba(0,0,0,0.03); 
            width: 100%; max-width: 550px; 
        }

        .system-title { font-weight: 700; color: var(--text-dark); font-size: 28px; margin-bottom: 8px; letter-spacing: -0.5px; text-align: center; }
        .system-subtitle { color: var(--text-gray); font-size: 15px; font-weight: 500; margin-bottom: 32px; text-align: center; }
        
        .info-text { 
            font-size: 14px; color: var(--text-gray); text-align: center; margin-bottom: 32px; 
            background: #F9FAFB; padding: 20px; border-radius: 20px; border: 1px solid #E5E7EB; line-height: 1.5;
        }
        
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

        .btn-reset { 
            background-color: var(--text-dark); color: var(--card-lime); 
            border: none; border-radius: 30px; 
            font-weight: 600; font-size: 16px; padding: 16px;
            transition: 0.2s; margin-top: 15px;
        }
        .btn-reset:hover { transform: scale(0.98); opacity: 0.9; color: var(--card-lime); }

        .login-text { font-size: 14px; color: var(--text-gray); margin-top: 32px; font-weight: 500; text-align: center; }
        .login-link { color: var(--text-dark); font-weight: 700; text-decoration: none; border-bottom: 2px solid var(--card-lime); padding-bottom: 2px; transition: 0.2s; }
        .login-link:hover { opacity: 0.7; }
        
        .alert { border-radius: 16px; font-size: 14px; font-weight: 500; text-align: center; border: none; padding: 20px; }
        .alert-success { background: #E6F8F3; color: #047857; }
        .alert-danger { background: #FCE8E8; color: #DC2626; }
    </style>
</head>
<body>

    <div class="reset-card">
        
        <div class="text-center">
            <h2 class="system-title">Reset Password</h2>
            <p class="system-subtitle">Barangay Tanza Coastal & Land Watch</p>
        </div>

        <?php if (!empty($reset_success)): ?>
            
            <div class="alert alert-success shadow-sm mb-0">
                <div style="font-size: 32px; margin-bottom: 12px;"><i class="ti ti-circle-check"></i></div>
                <strong style="font-size: 16px; display: block; margin-bottom: 8px;"><?php echo $reset_success; ?></strong>
                <a href="login.php" class="btn btn-sm mt-3 fw-bold px-4 py-2" style="background: #047857; color: #fff; border-radius: 20px;">Go to Login</a>
            </div>
            
        <?php else: ?>

            <div class="info-text">
                <i class="ti ti-lock" style="font-size: 24px; color: var(--text-dark); margin-bottom: 8px; display: block;"></i>
                <strong style="color: var(--text-dark);">Identity Verification Required</strong><br>
                To reset your password, please verify your identity by entering your registered Username, Phone Number, and Date of Birth exactly as they appear on your account.
            </div>

            <?php if (!empty($reset_error)): ?>
                <div class="alert alert-danger shadow-sm"><i class="ti ti-alert-circle"></i> <?php echo $reset_error; ?></div>
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

                <div style="height: 1px; background: #E5E7EB; margin: 30px 0;"></div>

                <div class="mb-4">
                    <label class="form-label" style="color: #047857;">Create New Password</label>
                    <div class="input-group">
                        <input type="password" name="new_password" id="passwordInput" class="form-control custom-input" placeholder="Enter new password" required>
                        <button class="btn btn-outline-secondary custom-input-btn" type="button" id="togglePassword">
                            <i class="ti ti-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn w-100 btn-reset" onclick="return confirm('Are you sure you want to reset your password?');">
                    Verify & Reset Password
                </button>

            </form>

            <div class="login-text">
                Remembered your password? <a href="login.php" class="login-link">Log In Here</a>
            </div>

        <?php endif; ?>

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
