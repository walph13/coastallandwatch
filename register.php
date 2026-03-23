<?php
session_start();
include 'db_connect.php';

$reg_success = false;
$reg_error = '';

if (isset($_POST['register'])) {
    $fullname = trim($_POST['full_name']);
    $username = trim($_POST['username']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $email = trim($_POST['email']);
    $address = trim($_POST['address_purok_sitio']);
    $dob = $_POST['date_of_birth'];
    $phone = trim($_POST['phone_number']);
    
    // BACKEND VALIDATION 1: Username cannot contain spaces
    if (preg_match('/\s/', $username)) {
        $reg_error = "Error: Username cannot contain spaces.";
    } 
    // BACKEND VALIDATION 2: Philippine Phone Number Format (11 digits starting with 09)
    elseif (!preg_match('/^09[0-9]{9}$/', $phone)) {
        $reg_error = "Error: Invalid phone number. Must be 11 digits starting with '09'.";
    } 
    else {
        // BACKEND VALIDATION 3: Check if username is already taken
        $check_user = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
        $check_user->bind_param("s", $username);
        $check_user->execute();
        $user_exists = $check_user->get_result();

        if ($user_exists->num_rows > 0) {
            $reg_error = "Error: Username is already taken.";
        } else {
            // ID Photo Upload Logic
            $target_dir = "uploads/ids/";
            if (!is_dir($target_dir)) { mkdir($target_dir, 0777, true); }
            
            $id_photo = time() . "_" . basename($_FILES["id_photo"]["name"]);
            $target_file = $target_dir . $id_photo;
            $file_extension = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));

            if (!in_array($file_extension, array("jpg", "jpeg", "png"))) {
                $reg_error = "Error: Only JPG, JPEG, and PNG files are allowed for ID upload.";
            } else {
                if (move_uploaded_file($_FILES["id_photo"]["tmp_name"], $target_file)) {
                    // INSERT into database
                    $sql = "INSERT INTO users (full_name, username, password, email, address_purok_sitio, date_of_birth, phone_number, id_photo_path, role, account_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Resident', 'Pending')";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("ssssssss", $fullname, $username, $password, $email, $address, $dob, $phone, $id_photo);
                    
                    if ($stmt->execute()) {
                        $reg_success = true;
                    } else {
                        $reg_error = "Database error: Registration failed.";
                    }
                }
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
        body { background-color: #f4f7f6; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; font-family: Arial, sans-serif; }
        .register-card { max-width: 650px; width: 100%; border-radius: 12px; box-shadow: 0px 10px 30px rgba(0,0,0,0.1); border: none; background-color: white; padding: 30px; }
        .register-title { color: #28a745; font-weight: bold; margin-bottom: 5px; text-align: center; }
        .form-label { font-weight: bold; margin-bottom: 5px; color: #555; }
        
        /* NEW FEATURE: Custom Highlight Active Field (Focus Effect) */
        .form-control:focus, .form-check-input:focus {
            border-color: #28a745;
            box-shadow: 0 0 8px rgba(40, 167, 69, 0.4);
        }
        
        #idPreview { display: none; width: 100%; max-height: 250px; object-fit: cover; border-radius: 8px; border: 2px solid #ddd; margin-top: 15px; }
    </style>
</head>
<body>

    <div class="card register-card">
        <h2 class="register-title">Resident Registration</h2>
        <p class="text-muted small text-center mb-4">Join Barangay Tanza’s Coastal & Land Watch</p>

        <?php if($reg_success): ?>
            <div class="alert alert-success shadow-sm fw-bold text-center">
                ✅ Registration successful! Wait for admin approval.
            </div>
            <div class="text-center mt-3"><a href="login.php" class="btn btn-success fw-bold px-4">Go to Login</a></div>
        <?php else: ?>
            <?php if(!empty($reg_error)): ?>
                <div class="alert alert-danger shadow-sm fw-bold">❌ <?php echo $reg_error; ?></div>
            <?php endif; ?>

            <form method="POST" action="" enctype="multipart/form-data" id="regForm">
                <input type="hidden" name="register" value="1">
                
                <div class="mb-3">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="full_name" class="form-control" required placeholder="e.g. Juan Dela Cruz">
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-control" required pattern="^\S+$" title="Username cannot contain spaces" placeholder="Choose a username">
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Password</label>
                        <div class="input-group">
                            <input type="password" id="regPassword" name="password" class="form-control" required placeholder="••••••••">
                            <button class="btn btn-outline-secondary" type="button" onclick="toggleRegPassword()"><span id="toggleIcon">👁️</span></button>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email" class="form-control" required placeholder="your.name@example.com">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Phone Number (PH Format)</label>
                        <input type="text" name="phone_number" class="form-control" required pattern="^09\d{9}$" maxlength="11" title="Must be a valid 11-digit number starting with 09 (e.g. 09123456789)" placeholder="09xxxxxxxxx" onkeypress="return event.charCode >= 48 && event.charCode <= 57">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Address / Purok / Sitio</label>
                    <input type="text" name="address_purok_sitio" class="form-control" required placeholder="e.g. Sitio Gulod, Purok 2">
                </div>

                <div class="mb-3">
                    <label class="form-label">Date of Birth</label>
                    <input type="date" name="date_of_birth" class="form-control" required max="<?php echo date('Y-m-d'); ?>">
                </div>

                <div class="mb-4">
                    <label class="form-label">Upload Valid ID</label>
                    <input type="file" name="id_photo" class="form-control" accept=".jpg,.jpeg,.png" required id="idUpload">
                    <img id="idPreview" src="" alt="Valid ID Preview">
                </div>

                <div class="form-check mb-4">
                    <input class="form-check-input" type="checkbox" value="" id="termsCheck" required>
                    <label class="form-check-label text-muted small" for="termsCheck">
                        ✅ I agree to the <a href="#" class="text-success text-decoration-none">Terms of Service</a> and <a href="#" class="text-success text-decoration-none">Privacy Policy</a>.
                    </label>
                </div>

                <button type="submit" class="btn btn-success w-100 fw-bold py-2 shadow-sm mb-3" id="submitBtn">
                    <span id="btnText">Register Account</span>
                    <span id="btnSpinner" class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                </button>
            </form>

            <div class="text-center mt-3">
                <span class="text-muted small">Already have an account?</span>
                <a href="login.php" class="text-success fw-bold text-decoration-none small">Log In Here</a>
            </div>
        <?php endif; ?>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleRegPassword() {
            const pwd = document.getElementById("regPassword");
            const icon = document.getElementById("toggleIcon");
            if (pwd.type === "password") { pwd.type = "text"; icon.innerText = "🙈"; } 
            else { pwd.type = "password"; icon.innerText = "👁️"; }
        }

        document.getElementById('idUpload').addEventListener('change', function(event) {
            var reader = new FileReader();
            reader.onload = function(e) {
                var img = document.getElementById('idPreview');
                img.src = e.target.result;
                img.style.display = 'block'; 
            }
            if(event.target.files[0]) { reader.readAsDataURL(event.target.files[0]); }
        });

        // NEW FEATURE: Show Loading Spinner on Form Submit
        document.getElementById('regForm').addEventListener('submit', function() {
            const btn = document.getElementById('submitBtn');
            const btnText = document.getElementById('btnText');
            const spinner = document.getElementById('btnSpinner');
            
            // Change button state
            btn.disabled = true;
            btnText.innerText = 'Submitting... ';
            spinner.classList.remove('d-none'); // Show the spinner
        });
    </script>
</body>
</html>
