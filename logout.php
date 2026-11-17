<?php
session_start();
include 'db_connect.php';

$login_error = '';
$login_warning = '';

if (isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        $is_password_correct = false;
        if (password_verify($password, $user['password']) || $password === $user['password']) {
            $is_password_correct = true;
        }

        if ($is_password_correct) {
            if ($user['role'] === 'Resident' && $user['account_status'] === 'Rejected') {
                $login_error = "Your registration was rejected. Please contact the Barangay Hall.";
            } 
            else {
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['role'] = $user['role'];

                if ($user['role'] === 'Admin') {
                    header("Location: admin_dashboard.php?view=dashboard");
                } else {
                    header("Location: resident_dashboard.php");
                }
                exit();
            }
        } else {
            $login_error = "Incorrect password.";
        }
    } else {
        $login_error = "Username not found.";
    }
}

$check_info = $conn->query("SELECT * FROM barangay_information LIMIT 1");
if ($check_info && $check_info->num_rows > 0) {
    $info = $check_info->fetch_assoc();
} else {
    $info = [];
}

$login_logo = !empty($info['logo_path']) ? 'uploads/logo/' . $info['logo_path'] : 'tanzalogo.jpg';
$system_title = !empty($info['system_subtitle']) ? $info['system_subtitle'] : 'Barangay Coastal & Land Watch';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#3F4D67">
    <title>System Login - Coastal & Land Watch</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">

    <style>
        :root {
            --primary: #0ea5e9;
            --primary-light: #e0f2fe;
            --primary-dark: #0284c7;
            --brand-slate: #3F4D67;
            --text-main: #172033;
            --text-muted: #64748b;
            --border-color: #d9e1ec;
        }

        * { box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            margin: 0; min-height: 100vh;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            padding: 28px 16px;
            color: var(--text-main);
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
            /* soft slate-tinted backdrop */
            background:
                radial-gradient(900px 560px at 12% 8%, rgba(63, 77, 103, 0.16), transparent 62%),
                radial-gradient(800px 520px at 92% 92%, rgba(14, 165, 233, 0.16), transparent 60%),
                linear-gradient(135deg, #eef2f8 0%, #dde6f1 100%);
        }

        /* The barangay logo, huge and faded, sitting behind everything */
        .bg-logo {
            position: fixed; top: 50%; left: 50%; z-index: 0;
            width: min(112vmin, 860px); height: min(112vmin, 860px);
            transform: translate(-50%, -50%);
            object-fit: contain; border-radius: 50%;
            opacity: 0.2; filter: blur(1.5px);
            mix-blend-mode: multiply;          /* the white of the JPG disappears into the background */
            pointer-events: none; user-select: none;
        }

        .stage { position: relative; z-index: 1; width: 100%; max-width: 400px; }

        .login-card {
            background: rgba(255, 255, 255, 0.96);
            -webkit-backdrop-filter: blur(8px); backdrop-filter: blur(8px);
            border-radius: 24px;
            padding: 34px 30px 30px;
            box-shadow: 0 28px 60px -14px rgba(30, 41, 59, 0.32), 0 2px 8px rgba(30, 41, 59, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.8);
            animation: cardIn 0.5s ease both;
        }
        @keyframes cardIn { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }

        .logo-wrapper {
            width: 88px; height: 88px; margin: 0 auto 16px;
            border-radius: 50%; background: #fff;
            border: 3px solid var(--primary-light);
            box-shadow: 0 8px 22px rgba(63, 77, 103, 0.22);
            overflow: hidden;
        }
        .logo-wrapper img { width: 100%; height: 100%; object-fit: cover; display: block; }

        .header-text { text-align: center; margin-bottom: 26px; }
        .badge {
            display: inline-block; background: var(--brand-slate); color: #fff;
            font-size: 18px; font-weight: 800; letter-spacing: 0.1em; text-transform: uppercase;
            padding: 6px 18px; border-radius: 8px; margin-bottom: 12px;
        }
        .header-text p {
            margin: 0; font-size: 11.5px; font-weight: 700; letter-spacing: 0.07em;
            text-transform: uppercase; color: var(--brand-slate); line-height: 1.6;
        }

        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-weight: 600; color: var(--text-main); font-size: 13px; margin-bottom: 8px; }

        .custom-input {
            width: 100%; height: 50px; padding: 0 14px;
            border-radius: 12px; border: 1.5px solid var(--border-color);
            background: #fff; color: var(--text-main);
            font-size: 14px; font-family: inherit; transition: 0.2s;
        }
        .custom-input::placeholder { color: #a3adbd; }
        .custom-input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 4px var(--primary-light); }

        .input-group { position: relative; }
        .input-group .custom-input { padding-right: 46px; }
        .custom-input-btn {
            position: absolute; right: 6px; top: 50%; transform: translateY(-50%);
            width: 38px; height: 38px; border: none; background: transparent; border-radius: 8px;
            color: var(--text-muted); font-size: 19px; cursor: pointer;
            display: flex; align-items: center; justify-content: center; transition: 0.2s;
        }
        .custom-input-btn:hover { color: var(--text-main); background: #f1f5f9; }

        .btn-login {
            width: 100%; height: 50px; margin-top: 8px;
            border: none; border-radius: 12px; cursor: pointer;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: #fff; font-family: inherit; font-size: 14px; font-weight: 700;
            letter-spacing: 0.1em; text-transform: uppercase;
            box-shadow: 0 10px 22px rgba(14, 165, 233, 0.32);
            transition: transform 0.18s ease, box-shadow 0.18s ease, filter 0.18s ease;
        }
        .btn-login:hover { transform: translateY(-2px); box-shadow: 0 14px 28px rgba(14, 165, 233, 0.4); filter: brightness(1.04); }
        .btn-login:active { transform: translateY(0) scale(0.98); }
        .btn-login:disabled { opacity: 0.7; cursor: wait; transform: none; }

        .alert { border-radius: 12px; font-size: 13px; font-weight: 500; text-align: center; padding: 12px; margin-bottom: 20px; display: flex; align-items: center; justify-content: center; gap: 8px; line-height: 1.4; }
        .alert-danger { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .alert-warning { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }

        .page-footer { margin-top: 22px; text-align: center; font-size: 12px; line-height: 1.7; color: #55657d; }

        @media (max-width: 420px) {
            .login-card { padding: 30px 22px 26px; }
            .badge { font-size: 16px; }
        }
        @media (prefers-reduced-motion: reduce) {
            .login-card { animation: none; }
            .btn-login { transition: none; }
        }
    </style>
</head>
<body>

    <img class="bg-logo" src="tanzalogo.jpg" alt="" aria-hidden="true" onerror="this.style.display='none'">

    <div class="stage">
        <div class="login-card">

            <div class="logo-wrapper">
                <img src="<?php echo htmlspecialchars($login_logo); ?>" alt="Barangay Tanza logo" onerror="this.onerror=null; this.src='tanzalogo.jpg'">
            </div>

            <div class="header-text">
                <span class="badge">Admin Portal</span>
                <p><?php echo htmlspecialchars($system_title); ?></p>
            </div>

            <?php if(!empty($login_warning)): ?>
                <div class="alert alert-warning" role="alert"><i class="ti ti-alert-triangle"></i> <?php echo $login_warning; ?></div>
            <?php endif; ?>

            <?php if(!empty($login_error)): ?>
                <div class="alert alert-danger" role="alert"><i class="ti ti-alert-circle"></i> <?php echo $login_error; ?></div>
            <?php endif; ?>

            <form method="POST" action="" id="loginForm">
                <!-- sent even when the button is disabled while logging in -->
                <input type="hidden" name="login" value="1">

                <div class="form-group">
                    <label class="form-label" for="usernameInput">Username</label>
                    <input type="text" name="username" id="usernameInput" class="custom-input" placeholder="Enter your username" autocomplete="username" required autofocus>
                </div>

                <div class="form-group">
                    <label class="form-label" for="passwordInput">Password</label>
                    <div class="input-group">
                        <input type="password" name="password" id="passwordInput" class="custom-input" placeholder="••••••••" autocomplete="current-password" required>
                        <button class="custom-input-btn" type="button" id="togglePassword" aria-label="Show or hide password">
                            <i class="ti ti-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" name="login" class="btn-login" id="loginBtn">Log In</button>
            </form>

        </div>

        <div class="page-footer">
            &copy; <?php echo date('Y'); ?> Barangay Tanza, Estancia, Iloilo<br>
            Coastal &amp; Land Watch &middot; Admin Portal
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

        // show "Logging in..." and stop double clicks
        document.getElementById('loginForm').addEventListener('submit', function () {
            const btn = document.getElementById('loginBtn');
            setTimeout(function () { btn.disabled = true; btn.textContent = 'Logging in...'; }, 0);
        });
        // coming back with the browser's Back button should not leave the button stuck
        window.addEventListener('pageshow', function (e) {
            if (e.persisted) { const btn = document.getElementById('loginBtn'); btn.disabled = false; btn.textContent = 'Log In'; }
        });
    </script>
</body>
</html>
