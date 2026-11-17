<?php
session_start();
include 'db_connect.php';

$reset_success = "";
$reset_error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username     = trim($_POST['username'] ?? '');
    $phone        = trim($_POST['phone_number'] ?? '');
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $errors = [];

    if ($username === '') $errors[] = "Username is required.";
    if (!preg_match('/^[0-9]{11}$/', $phone))
        $errors[] = "Phone number must be exactly 11 digits.";
    if (strlen($new_password) < 8)
        $errors[] = "New password must be at least 8 characters long.";
    if ($new_password !== $confirm_password)
        $errors[] = "New passwords do not match.";

    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT user_id, password FROM users WHERE username = ? AND phone_number = ?");
        $stmt->bind_param("ss", $username, $phone);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            $current_hashed_password = $user['password'];

            if (password_verify($new_password, $current_hashed_password)) {
                $reset_error = "You entered your previous password. Please choose a new one.";
            } else {
                $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);

                $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE username = ?");
                $update_stmt->bind_param("ss", $hashed_password, $username);

                if ($update_stmt->execute()) {
                    $reset_success = "Password successfully reset! You can now log in.";
                } else {
                    $reset_error = "Database error. Failed to update password.";
                }
                $update_stmt->close();
            }
        } else {
            $reset_error = "Verification failed. The details provided do not match our records.";
        }
    } else {
        $reset_error = implode("<br>", $errors);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | Coastal &amp; Land Watch</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">

    <style>
        :root {
            --bg-body: #3F4D67; /* EXACT COLOR FROM IMAGE */
            --bg-surface: #ffffff;
            --primary: #0ea5e9;
            --primary-light: #e0f2fe;
            --primary-dark: #0284c7;
            --text-main: #111827;
            --text-muted: #687280;
            --border-color: #e5e7eb;
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
            --shadow-lg: 0 20px 40px -10px rgba(0,0,0,0.3);
            --radius-card: 12px;
        }

        * { box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-body);
            color: var(--text-main);
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 40px 20px;
            font-size: 14px;
        }

        .reset-card {
            width: 100%;
            max-width: 560px;
            background: var(--bg-surface);
            border-radius: var(--radius-card);
            box-shadow: var(--shadow-lg);
            border: 1px solid rgba(255, 255, 255, 0.1);
            padding: 40px 44px 36px;
        }

        .card-header {
            text-align: center;
            padding-bottom: 28px;
            margin-bottom: 28px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .card-logo {
            width: 64px; 
            height: 64px;
            border-radius: 12px;
            background: var(--primary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 14px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(14, 165, 233, 0.2);
        }
        .card-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .card-title {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.4px;
            color: var(--text-main);
            margin: 0 0 6px;
        }
        .card-sub {
            font-size: 13.5px;
            color: var(--text-muted);
            font-weight: 500;
            margin: 0;
        }

        .info-box {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 16px;
            border-radius: 10px;
            background-color: var(--primary-light);
            border: 1px solid var(--primary);
            color: var(--primary-dark);
            font-size: 13.5px;
            font-weight: 500;
            line-height: 1.55;
            margin-bottom: 24px;
        }
        .info-box > i {
            font-size: 22px;
            flex-shrink: 0;
            margin-top: 1px;
        }
        .info-box strong { font-weight: 700; display: block; margin-bottom: 4px; color: var(--primary-dark); }

        .section-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 600;
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 18px;
        }
        .section-title i { font-size: 18px; }

        .section-divider {
            border: 0;
            border-top: 1px solid var(--border-color);
            margin: 28px 0;
        }

        .form-group { margin-bottom: 18px; }
        .form-group:last-child { margin-bottom: 0; }

        .form-label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13.5px;
            font-weight: 500;
            color: var(--text-main);
            margin-bottom: 8px;
        }
        .form-label i { color: var(--primary); font-size: 15px; }

        .form-control {
            width: 100%;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 11px 13px;
            font-size: 14px;
            font-family: inherit;
            background-color: #f9fafb;
            color: var(--text-main);
            transition: 0.2s;
            outline: none;
        }
        .form-control::placeholder { color: #9ca3af; }
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-light);
            background-color: #ffffff;
        }

        .password-wrap { display: flex; }
        .password-wrap .form-control {
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
            border-right: none;
        }
        .password-toggle {
            border: 1px solid var(--border-color);
            border-left: none;
            background-color: #f9fafb;
            border-top-right-radius: 8px;
            border-bottom-right-radius: 8px;
            padding: 0 13px;
            color: var(--text-muted);
            cursor: pointer;
            transition: 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
        }
        .password-toggle:hover { color: var(--primary); }
        .password-wrap:focus-within .form-control {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-light);
            background-color: #fff;
        }
        .password-wrap:focus-within .password-toggle {
            border-color: var(--primary);
            background-color: #fff;
            color: var(--primary);
        }

        .strength-meter {
            height: 5px;
            border-radius: 999px;
            background: #e5e7eb;
            margin-top: 8px;
            overflow: hidden;
        }
        .strength-fill {
            height: 100%;
            width: 0;
            border-radius: 999px;
            background: #dc2626;
            transition: 0.3s;
        }
        .strength-text {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 6px;
            font-weight: 500;
        }

        .btn-reset {
            width: 100%;
            background-color: var(--primary);
            color: #ffffff;
            border: none;
            border-radius: 8px;
            padding: 13px;
            font-size: 15px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 8px;
        }
        .btn-reset:hover {
            background-color: var(--primary-dark);
            box-shadow: 0 6px 16px rgba(14, 165, 233, 0.25);
        }
        .btn-reset:active { transform: scale(0.99); }

        .alert {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 14px 16px;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 500;
            margin-bottom: 22px;
            line-height: 1.5;
        }
        .alert i { font-size: 19px; flex-shrink: 0; margin-top: 1px; }
        .alert-success {
            background-color: var(--primary-light);
            border: 1px solid var(--primary);
            color: var(--primary-dark);
        }
        .alert-danger {
            background-color: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
        }
        .alert a {
            color: inherit;
            font-weight: 700;
            text-decoration: underline;
        }

        .btn-go-login {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 12px;
            padding: 10px 18px;
            background-color: var(--primary);
            color: #ffffff;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 600;
            text-decoration: none;
            transition: 0.2s;
        }
        .btn-go-login:hover {
            background-color: var(--primary-dark);
            color: #ffffff;
            text-decoration: none;
        }

        .login-text {
            font-size: 13.5px;
            color: var(--text-muted);
            margin-top: 24px;
            padding-top: 22px;
            border-top: 1px solid var(--border-color);
            text-align: center;
            font-weight: 500;
        }
        .login-link {
            color: var(--primary);
            font-weight: 600;
            text-decoration: none;
        }
        .login-link:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }

        @media (max-width: 560px) {
            body { padding: 20px 12px; }
            .reset-card { padding: 28px 22px 24px; }
        }
    </style>
</head>
<body>

<div class="reset-card">

    <div class="card-header">
        <div class="card-logo">
            <img src="tanzalogo.jpg" alt="Barangay Tanza Logo">
        </div>
        <h2 class="card-title">Reset Password</h2>
        <p class="card-sub">Barangay Tanza Coastal &amp; Land Watch</p>
    </div>

    <?php if (!empty($reset_success)): ?>

        <div class="alert alert-success">
            <i class="ti ti-circle-check"></i>
            <div>
                <strong><?php echo $reset_success; ?></strong><br>
                <a href="login.php" class="btn-go-login">
                    <i class="ti ti-login"></i> Go to Login
                </a>
            </div>
        </div>

    <?php else: ?>

        <div class="info-box">
            <i class="ti ti-shield-lock"></i>
            <div>
                <strong>Identity Verification Required</strong>
                To reset your password, please enter your registered Username and Phone Number exactly as they appear on your account.
            </div>
        </div>

        <?php if (!empty($reset_error)): ?>
            <div class="alert alert-danger">
                <i class="ti ti-alert-circle"></i>
                <div><?php echo $reset_error; ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" action="" novalidate>

            <div class="section-title"><i class="ti ti-id-badge-2"></i> Identity Verification</div>

            <div class="form-group">
                <label class="form-label"><i class="ti ti-at"></i> Username</label>
                <input type="text" name="username" class="form-control" placeholder="Enter your username" required
                       autocomplete="username"
                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label class="form-label"><i class="ti ti-phone"></i> Registered Phone Number</label>
                <input type="text" name="phone_number" class="form-control" placeholder="09xxxxxxxxx" required
                       pattern="[0-9]{11}" title="Please enter an 11-digit phone number"
                       value="<?php echo htmlspecialchars($_POST['phone_number'] ?? ''); ?>">
            </div>

            <hr class="section-divider">

            <div class="section-title"><i class="ti ti-key"></i> Create New Password</div>

            <div class="form-group">
                <label class="form-label"><i class="ti ti-lock"></i> New Password</label>
                <div class="password-wrap">
                    <input type="password" name="new_password" id="passwordInput" class="form-control"
                           placeholder="Enter new password" autocomplete="new-password" required>
                    <button class="password-toggle" type="button" id="togglePassword">
                        <i class="ti ti-eye"></i>
                    </button>
                </div>
                <div class="strength-meter"><div class="strength-fill" id="strengthFill"></div></div>
                <div class="strength-text" id="strengthText">Use 8+ characters with letters, numbers &amp; symbols.</div>
            </div>

            <div class="form-group">
                <label class="form-label"><i class="ti ti-lock-check"></i> Confirm New Password</label>
                <div class="password-wrap">
                    <input type="password" name="confirm_password" id="confirmPassword" class="form-control"
                           placeholder="Re-enter new password" autocomplete="new-password" required>
                    <button class="password-toggle" type="button" id="toggleConfirm">
                        <i class="ti ti-eye"></i>
                    </button>
                </div>
                <div class="strength-text" id="matchText"></div>
            </div>

            <button type="submit" class="btn-reset"
                    onclick="return confirm('Are you sure you want to reset your password?');">
                <i class="ti ti-shield-check"></i> Verify &amp; Reset Password
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
    togglePassword.addEventListener('click', () => {
        const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
        password.setAttribute('type', type);
        togglePassword.innerHTML = type === 'password'
            ? '<i class="ti ti-eye"></i>' : '<i class="ti ti-eye-off"></i>';
    });

    const toggleConfirm = document.querySelector('#toggleConfirm');
    const confirmPassword = document.querySelector('#confirmPassword');
    toggleConfirm.addEventListener('click', () => {
        const type = confirmPassword.getAttribute('type') === 'password' ? 'text' : 'password';
        confirmPassword.setAttribute('type', type);
        toggleConfirm.innerHTML = type === 'password'
            ? '<i class="ti ti-eye"></i>' : '<i class="ti ti-eye-off"></i>';
    });

    const strengthFill = document.querySelector('#strengthFill');
    const strengthText = document.querySelector('#strengthText');
    password.addEventListener('input', () => {
        const val = password.value;
        let score = 0;
        if (val.length >= 8) score++;
        if (/[A-Z]/.test(val) && /[a-z]/.test(val)) score++;
        if (/\d/.test(val)) score++;
        if (/[^A-Za-z0-9]/.test(val)) score++;

        const widths = ['0%','25%','50%','75%','100%'];
        const colors = ['#dc2626','#dc2626','#f59e0b','#84cc16','#10b981'];
        const labels = ['', 'Weak', 'Fair', 'Good', 'Strong'];

        strengthFill.style.width = val ? widths[score] : '0%';
        strengthFill.style.background = colors[score];
        strengthText.textContent = val
            ? labels[score] + ' password'
            : 'Use 8+ characters with letters, numbers & symbols.';
        strengthText.style.color = val ? colors[score] : 'var(--text-muted)';
    });

    const matchText = document.querySelector('#matchText');
    confirmPassword.addEventListener('input', () => {
        if (!confirmPassword.value) { matchText.textContent = ''; return; }
        const ok = confirmPassword.value === password.value;
        matchText.textContent = ok ? 'Passwords match' : 'Passwords do not match';
        matchText.style.color = ok ? 'var(--primary)' : '#dc2626';
    });
</script>

</body>
</html>
