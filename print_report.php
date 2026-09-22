<?php
session_start();
include 'db_connect.php';

// SECURITY CHECK
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    die("Unauthorized access.");
}

// FETCH BARANGAY INFO FOR THE HEADER
$check_info = $conn->query("SELECT * FROM barangay_information LIMIT 1");
if ($check_info && $check_info->num_rows > 0) {
    $info = $check_info->fetch_assoc();
} else {
    $info = [
        'barangay_name' => 'Barangay System', 
        'municipal' => 'Estancia', 
        'city' => 'Iloilo', 
        'logo_path' => ''
    ];
}

// CHECK FOR DATE FILTERS
$where_clause = "";
$date_subtitle = "All Records";

if (isset($_GET['from']) && isset($_GET['to'])) {
    $from = $conn->real_escape_string($_GET['from']);
    $to = $conn->real_escape_string($_GET['to']);
    
    // Filter by the selected dates
    $where_clause = " WHERE DATE(w.created_at) BETWEEN '$from' AND '$to' ";
    
    // Format the date for the paper subtitle (e.g., "May 01, 2026 to July 31, 2026")
    $date_subtitle = "Report Period: " . date("F d, Y", strtotime($from)) . " to " . date("F d, Y", strtotime($to));
}

// FETCH REPORTS WITH FILTER
$reports_query = $conn->query("
    SELECT w.*, u.full_name 
    FROM waste_reports w 
    JOIN users u ON w.resident_id = u.user_id 
    $where_clause
    ORDER BY w.created_at DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Official Waste Report - Brgy. <?php echo htmlspecialchars($info['barangay_name']); ?></title>
    <style>
        body {
            font-family: 'Times New Roman', Times, serif;
            color: #000;
            background: #fff;
            margin: 0;
            padding: 40px;
        }

        /* HEADER STYLES */
        .document-header {
            text-align: center;
            margin-bottom: 40px;
            border-bottom: 2px solid #000;
            padding-bottom: 20px;
            position: relative;
        }
        .header-logo {
            width: 100px;
            height: 100px;
            position: absolute;
            left: 0;
            top: 0;
            object-fit: contain;
        }
        .republic-text { font-size: 14px; margin: 0 0 5px 0; text-transform: uppercase; }
        .barangay-text { font-size: 22px; font-weight: bold; margin: 0 0 5px 0; text-transform: uppercase; }
        .address-text { font-size: 14px; margin: 0; }
        .document-title { font-size: 20px; font-weight: bold; text-transform: uppercase; margin-top: 20px; letter-spacing: 1px; }

        /* TABLE STYLES */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 12px;
        }
        th, td {
            border: 1px solid #000;
            padding: 10px;
            text-align: left;
            vertical-align: top;
        }
        th {
            background-color: #f2f2f2;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .status-pending { color: #D32F2F; font-weight: bold; }
        .status-cleaned { color: #2E7D32; font-weight: bold; }

        .print-footer {
            margin-top: 50px;
            display: flex;
            justify-content: space-between;
            font-size: 14px;
        }
        .signature-line {
            width: 200px;
            border-bottom: 1px solid #000;
            margin-bottom: 5px;
        }

        /* HIDE BUTTONS WHEN PRINTING */
        .no-print {
            text-align: center;
            margin-bottom: 20px;
        }
        .btn-print {
            background: #212529;
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 20px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
        }
        @media print {
            body { padding: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>

    <!-- MANUAL PRINT BUTTON (Hidden during actual print) -->
    <div class="no-print">
        <button class="btn-print" onclick="window.print()">🖨️ Print Document</button>
        <p style="font-size: 12px; color: #666;">If the print dialog didn't open automatically, click the button above.</p>
    </div>

    <!-- OFFICIAL DOCUMENT HEADER -->
    <table style="width: 100%; border-bottom: 3px solid #000; margin-bottom: 20px; padding-bottom: 10px; border-collapse: collapse;">
        <tr>
            <!-- LEFT LOGO -->
            <td style="width: 20%; text-align: left; vertical-align: middle; border: none;">
                <?php if(!empty($info['logo_path']) && file_exists('uploads/logo/' . $info['logo_path'])): ?>
                    <img src="uploads/logo/<?php echo $info['logo_path']; ?>" style="width: 100px; height: 100px; object-fit: contain;" alt="Barangay Logo">
                <?php endif; ?>
            </td>
            
            <!-- CENTERED TEXT -->
            <td style="width: 60%; text-align: center; vertical-align: middle; border: none; line-height: 1.3;">
                <p style="margin: 0; font-size: 14px; text-transform: uppercase;">Republic of the Philippines</p>
                <p style="margin: 0; font-size: 14px; text-transform: uppercase;">Province of Iloilo, Municipality of <?php echo htmlspecialchars($info['municipal'] ?? 'Estancia'); ?></p>
                <h1 style="margin: 5px 0 0; font-size: 24px; font-weight: bold; text-transform: uppercase;">Barangay <?php echo htmlspecialchars($info['barangay_name'] ?? 'Tanza'); ?></h1>
                <p style="margin: 2px 0 0; font-size: 13px; font-style: italic;">Office of the Punong Barangay</p>
            </td>

            <!-- RIGHT LOGO (Blank space to keep the center text perfectly aligned) -->
            <td style="width: 20%; text-align: right; vertical-align: middle; border: none;">
            </td>
        </tr>
    </table>

    <div style="text-align: center; margin-bottom: 30px;">
        <h2 style="font-size: 18px; font-weight: bold; text-transform: uppercase; margin: 0; letter-spacing: 1px;">Official Coastal & Land Waste Report Log</h2>
        <p style="font-size: 14px; margin-top: 5px; color: #000; font-weight: bold;"><?php echo $date_subtitle; ?></p>
        <p style="font-size: 11px; margin-top: 2px; color: #666;">Generated on: <?php echo date('F d, Y h:i A'); ?></p>
    </div>

    <!-- DATA TABLE (This is the part that was missing!) -->
    <table>
        <thead>
            <tr>
                <th style="width: 5%;">ID</th>
                <th style="width: 15%;">Date Reported</th>
                <th style="width: 20%;">Reporter Name</th>
                <th style="width: 45%;">Issue Description</th>
                <th style="width: 15%;">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php
            if ($reports_query->num_rows > 0) {
                while ($row = $reports_query->fetch_assoc()) {
                    echo "<tr>";
                    echo "<td>" . $row['report_id'] . "</td>";
                    echo "<td>" . date("M d, Y", strtotime($row['created_at'])) . "<br><small>" . date("h:i A", strtotime($row['created_at'])) . "</small></td>";
                    echo "<td>" . htmlspecialchars($row['full_name']) . "</td>";
                    echo "<td>" . htmlspecialchars($row['description']) . "</td>";
                    
                    if ($row['status'] == 'Pending') {
                        echo "<td class='status-pending'>PENDING</td>";
                    } else {
                        echo "<td class='status-cleaned'>CLEANED</td>";
                    }
                    echo "</tr>";
                }
            } else {
                echo "<tr><td colspan='5' style='text-align: center;'>No reports available.</td></tr>";
            }
            ?>
        </tbody>
    </table>

    <!-- SIGNATURE AREA -->
    <div class="print-footer">
        <div style="text-align: center;">
            <p style="text-align: left; margin-bottom: 40px;">Prepared By:</p>
            
            <strong style="font-size: 14px; text-transform: uppercase;">
                <?php echo htmlspecialchars(!empty($info['secretary_name']) ? $info['secretary_name'] : '_________________________'); ?>
            </strong>
            <div class="signature-line" style="margin: 2px auto;"></div>
            <span style="font-size: 12px;">Barangay Secretary</span>
        </div>
        
        <div style="text-align: center;">
            <p style="text-align: left; margin-bottom: 40px;">Noted By:</p>
            
            <strong style="font-size: 14px; text-transform: uppercase;">
                <?php echo htmlspecialchars(!empty($info['captain_name']) ? $info['captain_name'] : '_________________________'); ?>
            </strong>
            <div class="signature-line" style="margin: 2px auto;"></div>
            <span style="font-size: 12px;">Punong Barangay</span>
        </div>
    </div>

    <!-- AUTO-TRIGGER PRINT PREVIEW -->
    <script>
        window.onload = function() {
            window.print();
        };
    </script>

</body>
</html>
