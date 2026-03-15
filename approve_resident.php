<?php
session_start();
include 'db_connect.php';

// SECURITY CHECK: Kick out anyone who is NOT an Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login.php");
    exit();
}

// Check if the URL has the ID and the Action (approve/reject)
if (isset($_GET['id']) && isset($_GET['action'])) {
    $target_user_id = intval($_GET['id']);
    $action = $_GET['action'];

    if ($action === 'approve') {
        // Change status from 'Pending' to 'Active'
        $sql = "UPDATE users SET account_status = 'Active' WHERE user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $target_user_id);
        
        if ($stmt->execute()) {
            echo "<script>alert('Resident approved successfully!'); window.location.href='admin_dashboard.php';</script>";
        } else {
            echo "<script>alert('Database error.'); window.location.href='admin_dashboard.php';</script>";
        }

    } elseif ($action === 'reject') {
        // Delete the rejected user's account completely so they can try registering again if they want
        $sql = "DELETE FROM users WHERE user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $target_user_id);
        
        if ($stmt->execute()) {
            echo "<script>alert('Resident application rejected and removed.'); window.location.href='admin_dashboard.php';</script>";
        } else {
            echo "<script>alert('Database error.'); window.location.href='admin_dashboard.php';</script>";
        }
    }
} else {
    // If someone tries to access this page directly without clicking a button, send them back
    header("Location: admin_dashboard.php");
    exit();
}
?>
