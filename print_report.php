<?php
session_start();
include 'db_connect.php';

// SECURITY CHECK: Kick out anyone who is NOT an Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login.php");
    exit();
}

// BACKEND LOGIC: Fetch ONLY the reports that have been 'Cleaned'
$report_query = "SELECT waste_reports.*, users.full_name FROM waste_reports JOIN users ON waste_reports.resident_id = users.user_id WHERE waste_reports.status = 'Cleaned' ORDER BY waste_reports.created_at DESC";
$result = $conn->query($report_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Official Waste Report - Barangay Tanza</title>
    <style>
        /* This CSS makes it look like a real document */
        body { font-family: 'Times New Roman', Times, serif; padding: 30px; color: #000; }
        .header { text-align: center; margin-bottom: 40px; }
        .header h2, .header h3, .header p { margin: 5px 0; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 14px; }
        th, td { border: 1px solid #000; padding: 10px; text-align: left; }
        th { background-color: #f2f2f2; font-weight: bold; }
        
        /* This hides the buttons when the physical paper prints! */
        @media print {
            .no-print { display: none; }
        }
    </style>
</head>
<body onload="window.print()"> <div class="no-print" style="margin-bottom: 20px; text-align: right;">
        <button onclick="window.print()" style="padding: 10px 15px; background: #007bff; color: white; border: none; cursor: pointer; border-radius: 4px;">Print Now</button>
        <button onclick="window.close()" style="padding: 10px 15px; background: #dc3545; color: white; border: none; cursor: pointer; border-radius: 4px;">Close Tab</button>
    </div>

    <div class="header">
        <h2>Republic of the Philippines</h2>
        <h3>Province of Iloilo, Municipality of Estancia</h3>
        <h3>BARANGAY TANZA</h3>
        <br>
        <h2>OFFICIAL WASTE CLEARANCE REPORT</h2>
        <p>Date Generated: <?php echo date('F d, Y'); ?></p>
    </div>

    <table>
        <thead>
            <tr>
                <th>Date Reported</th>
                <th>Reporter Name</th>
                <th>Description / Location</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php
            if ($result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    echo "<tr>";
                    echo "<td>" . date("M d, Y", strtotime($row['created_at'])) . "</td>";
                    echo "<td>" . htmlspecialchars($row['full_name']) . "</td>";
                    echo "<td>" . htmlspecialchars($row['description']) . "</td>";
                    echo "<td><strong>" . $row['status'] . "</strong></td>";
                    echo "</tr>";
                }
            } else {
                echo "<tr><td colspan='4' style='text-align: center;'>No cleaned reports found for this period.</td></tr>";
            }
            ?>
        </tbody>
    </table>

    <div style="margin-top: 60px; float: right; text-align: center;">
        <p>Prepared and Certified by:</p>
        <br><br>
        <p style="border-bottom: 1px solid #000; width: 200px; margin: 0 auto;"></p>
        <p><b>Barangay Secretary</b></p>
    </div>

</body>
</html>
