<?php
session_start();
include 'db_connect.php';

// Ensure the request is coming via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;

    if ($user_id > 0) {
        // Prepare DELETE statement to prevent SQL injection
        $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);

        if ($stmt->execute()) {
            // Redirect back with success message
            header("Location: approved_residents.php?status=deleted");
            exit();
        } else {
            // Redirect back with error message
            header("Location: approved_residents.php?status=error");
            exit();
        }
    }
}

// Redirect back if accessed directly
header("Location: approved_residents.php");
exit();
?>