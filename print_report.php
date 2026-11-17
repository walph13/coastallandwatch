<?php
session_start();
include 'db_connect.php';

// SECURITY CHECK
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Admin') {
    die("Unauthorized access.");
}

// FETCH BARANGAY INFO FOR THE HEADER
$check_info = $conn->query("SELECT * FROM barangay_information LIMIT 1");
if ($check_info && $check_info->num_rows > 0) {
    $info = $check_info->fetch_assoc();
} else {
    $info = [
        'barangay_name' => 'Tanza', 
        'municipal' => 'Estancia', 
        'city' => 'Iloilo', 
        'logo_path' => ''
    ];
}

// READ FILTERS FROM URL (Supports both startDate/endDate from admin_dashboard.php and from/to)
$startDate = trim($_GET['startDate'] ?? $_GET['from'] ?? '');
$endDate   = trim($_GET['endDate'] ?? $_GET['to'] ?? '');
$status    = trim($_GET['status'] ?? 'All');
$search    = trim($_GET['search'] ?? '');

$conditions = [];
$params     = [];
$types      = "";

// 1. Date Filter
if ($startDate !== '' && $endDate !== '') {
    $conditions[] = "DATE(w.created_at) BETWEEN ? AND ?";
    $params[] = $startDate;
    $params[] = $endDate;
    $types .= "ss";
    $date_subtitle = "Report Period: " . date("F d, Y", strtotime($startDate)) . " to " . date("F d, Y", strtotime($endDate));
} elseif ($startDate !== '') {
    $conditions[] = "DATE(w.created_at) >= ?";
    $params[] = $startDate;
    $types .= "s";
    $date_subtitle = "Report Period: From " . date("F d, Y", strtotime($startDate));
} elseif ($endDate !== '') {
    $conditions[] = "DATE(w.created_at) <= ?";
    $params[] = $endDate;
    $types .= "s";
    $date_subtitle = "Report Period: Up to " . date("F d, Y", strtotime($endDate));
} else {
    $date_subtitle = "Report Period: All Records";
}

// 2. Status Filter (Pending / Cleaned)
if ($status !== '' && $status !== 'All') {
    $conditions[] = "w.status = ?";
    $params[] = $status;
    $types .= "s";
    $date_subtitle .= " (" . htmlspecialchars($status) . " Only)";
}

// 3. Search Filter (Reporter Name or Description)
if ($search !== '') {
    $conditions[] = "(COALESCE(u.full_name, 'Deleted user') LIKE ? OR w.description LIKE ?)";
    $like = "%" . $search . "%";
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}

$where_clause = count($conditions) > 0 ? " WHERE " . implode(" AND ", $conditions) : "";

// FETCH REPORTS (LEFT JOIN ensures reports from deleted users still appear)
$sql = "
    SELECT w.*, COALESCE(u.full_name, 'Deleted user') AS full_name 
    FROM waste_reports w 
    LEFT JOIN users u ON w.resident_id = u.user_id 
    $where_clause
    ORDER BY w.created_at DESC
";

$stmt = $conn->prepare($sql);
if ($types !== "") {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$reports_query = $stmt->get_result();
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

        /* TABLE STYLES */
        table.report-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 12px;
        }
        table.report-table th, table.report-table td {
            border: 1px solid #000;
            padding: 10px;
            text-align: left;
            vertical-align: top;
        }
        table.report-table th {
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

        /* CONTROL BAR (Hidden during actual print) */
        .no-print {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 14px 20px;
            margin-bottom: 25px;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            font-family: Arial, sans-serif;
            font-size: 13px;
        }
        .filter-controls {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
        }
        .filter-controls input, .filter-controls select {
            padding: 6px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 13px;
        }
        .btn-filter {
            background: #0284c7;
            color: #fff;
            border: none;
            padding: 7px 14px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
        }
        .btn-clear {
            background: #e2e8f0;
            color: #334155;
            text-decoration: none;
            padding: 7px 12px;
            border-radius: 6px;
            font-weight: bold;
        }
        .btn-print {
            background: #212529;
            color: #fff;
            border: none;
            padding: 8px 18px;
            border-radius: 20px;
            cursor: pointer;
            font-size: 13px;
            font-weight: bold;
        }
        @media print {
            body { padding: 0; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

    <!-- FILTER & PRINT BAR (Hidden when printing) -->
    <div class="no-print">
        <form method="GET" action="print_report.php" class="filter-controls">
            <label><strong>From:</strong></label>
            <input type="date" name="startDate" value="<?php echo htmlspecialchars($startDate); ?>">

            <label><strong>To:</strong></label>
            <input type="date" name="endDate" value="<?php echo htmlspecialchars($endDate); ?>">

            <select name="status">
                <option value="All" <?php echo $status === 'All' ? 'selected' : ''; ?>>All Status</option>
                <option value="Pending" <?php echo $status === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="Cleaned" <?php echo $status === 'Cleaned' ? 'selected' : ''; ?>>Cleaned</option>
            </select>

            <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit" class="btn-filter">Apply Date</button>
            <a href="print_report.php" class="btn-clear">Reset</a>
        </form>

        <button class="btn-print" onclick="window.print()">🖨️ Print Document</button>
    </div>

    <!-- OFFICIAL DOCUMENT HEADER -->
    <table style="width: 100%; border-bottom: 3px solid #000; margin-bottom: 20px; padding-bottom: 10px; border-collapse: collapse;">
        <tr>
            <!-- LEFT LOGO -->
            <td style="width: 20%; text-align: left; vertical-align: middle; border: none;">
                <?php if(!empty($info['logo_path']) && file_exists('uploads/logo/' . $info['logo_path'])): ?>
                    <img src="uploads/logo/<?php echo htmlspecialchars($info['logo_path']); ?>" style="width: 100px; height: 100px; object-fit: contain;" alt="Barangay Logo">
                <?php endif; ?>
            </td>
            
            <!-- CENTERED TEXT -->
            <td style="width: 60%; text-align: center; vertical-align: middle; border: none; line-height: 1.3;">
                <p style="margin: 0; font-size: 14px; text-transform: uppercase;">Republic of the Philippines</p>
                <p style="margin: 0; font-size: 14px; text-transform: uppercase;">Province of Iloilo, Municipality of <?php echo htmlspecialchars($info['municipal'] ?? 'Estancia'); ?></p>
                <h1 style="margin: 5px 0 0; font-size: 24px; font-weight: bold; text-transform: uppercase;">Barangay <?php echo htmlspecialchars($info['barangay_name'] ?? 'Tanza'); ?></h1>
                <p style="margin: 2px 0 0; font-size: 13px; font-style: italic;">Office of the Punong Barangay</p>
            </td>

            <!-- RIGHT LOGO (Blank space to keep center text aligned) -->
            <td style="width: 20%; text-align: right; vertical-align: middle; border: none;"></td>
        </tr>
    </table>

    <div style="text-align: center; margin-bottom: 30px;">
        <h2 style="font-size: 18px; font-weight: bold; text-transform: uppercase; margin: 0; letter-spacing: 1px;">Official Coastal & Land Waste Report Log</h2>
        <p style="font-size: 14px; margin-top: 5px; color: #000; font-weight: bold;"><?php echo $date_subtitle; ?></p>
        <p style="font-size: 11px; margin-top: 2px; color: #666;">Generated on: <?php echo date('F d, Y h:i A'); ?></p>
    </div>

    <!-- DATA TABLE -->
    <table class="report-table">
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
            if ($reports_query && $reports_query->num_rows > 0) {
                while ($row = $reports_query->fetch_assoc()) {
                    echo "<tr>";
                    echo "<td>" . (int)$row['report_id'] . "</td>";
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
                echo "<tr><td colspan='5' style='text-align: center;'>No reports found for the selected date range.</td></tr>";
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
