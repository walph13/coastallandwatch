
<?php
$servername = "localhost";
$username = "root";
$password = ""; // Default XAMPP password
$dbname = "barangay_waste_reporting"; 

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
// echo "Connected successfully"; 
?>
