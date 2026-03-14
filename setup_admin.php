<?php
include 'db_connect.php';

$full_name = "Barangay Secretary";
$username = "admin_tanza";
// We hash the password so it's secure in the database
$password = password_hash("admin123", PASSWORD_BCRYPT); 
$role = "Admin";
$status = "Active"; // The admin is automatically Active, not Pending!

// Check if admin already exists so we don't make duplicates
$check = $conn->query("SELECT * FROM users WHERE username = '$username'");

if ($check->num_rows == 0) {
    $sql = "INSERT INTO users (full_name, username, password, role, account_status) 
            VALUES ('$full_name', '$username', '$password', '$role', '$status')";

    if ($conn->query($sql) === TRUE) {
        echo "<h2>Success! Admin account created.</h2>";
        echo "<p>Username: <b>admin_tanza</b></p>";
        echo "<p>Password: <b>admin123</b></p>";
    } else {
        echo "Error: " . $conn->error;
    }
} else {
    echo "Admin account already exists!";
}
?>
