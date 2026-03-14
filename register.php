<?php
include 'db_connect.php';

if (isset($_POST['register'])) {
    $full_name = $_POST['full_name'];
    $username = $_POST['username'];
    $phone = $_POST['phone_number'];
    $password = password_hash($_POST['password'], PASSWORD_BCRYPT); // Securely hash the password
    $role = 'Resident'; 

    // Handle ID Photo Upload
    $target_dir = "uploads/ids/";
    if (!is_dir($target_dir)) { mkdir($target_dir, 0777, true); } // Create folder if it doesn't exist
    
    $file_name = time() . "_" . basename($_FILES["id_photo"]["name"]);
    $target_file = $target_dir . $file_name;

    if (move_uploaded_file($_FILES["id_photo"]["tmp_name"], $target_file)) {
        $sql = "INSERT INTO users (full_name, username, password, phone_number, id_photo_path, role, account_status) 
                VALUES ('$full_name', '$username', '$password', '$phone', '$file_name', '$role', 'Pending')";

        if ($conn->query($sql) === TRUE) {
            echo "<script>alert('Registration successful! Please wait for Admin approval.');</script>";
        } else {
            echo "Error: " . $sql . "<br>" . $conn->error;
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Resident Registration - Coastal & Land Watch</title>
</head>
<body>
    <h2>Register for Barangay Tanza Waste Reporting</h2>
    <form action="register.php" method="POST" enctype="multipart/form-data">
        <input type="text" name="full_name" placeholder="Full Name" required><br><br>
        <input type="text" name="username" placeholder="Username" required><br><br>
        <input type="password" name="password" placeholder="Password" required><br><br>
        <input type="text" name="phone_number" placeholder="Phone Number" required><br><br>
        <label>Upload Valid ID:</label>
        <input type="file" name="id_photo" required><br><br>
        <button type="submit" name="register">Register</button>
    </form>
</body>
</html>
