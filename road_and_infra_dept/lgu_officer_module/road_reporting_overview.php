<?php
// Road Reporting Overview - LGU Officer Module
session_start();
require_once '../config/auth.php';
require_once '../config/database.php';

$auth->requireAnyRole(['lgu_officer', 'admin']);

// Initialize database connection
try {
    $database = new Database();
    $conn = $database->getConnection();
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and validate inputs
    $location = trim($_POST['location'] ?? '');
    $damage_type = trim($_POST['damage_type'] ?? '');
    $severity = trim($_POST['severity'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    // Validate required fields
    if (empty($location) || empty($damage_type) || empty($severity) || empty($description)) {
        $error = "All required fields must be filled.";
    } elseif (!in_array($damage_type, ['pothole', 'crack', 'landslide', 'flooding', 'drainage'])) {
        $error = "Invalid damage type selected.";
    } elseif (!in_array($severity, ['low', 'medium', 'high', 'critical'])) {
        $error = "Invalid severity level selected.";
    } else {
        try {
            // Check if road_name column exists
            $column_check = $conn->query("SHOW COLUMNS FROM damage_reports LIKE 'road_name'");
            $has_road_name = $column_check->num_rows > 0;
            
            // Check for user reference column (try multiple possibilities)
            $user_column = 'reported_by'; // default
            $possible_columns = ['reported_by', 'user_id', 'reporter_id', 'created_by'];
            
            foreach ($possible_columns as $col) {
                $check = $conn->query("SHOW COLUMNS FROM damage_reports LIKE '$col'");
                if ($check && $check->num_rows > 0) {
                    $user_column = $col;
                    break;
                }
            }
            
            if ($has_road_name) {
                // Insert with road_name column
                $stmt = $conn->prepare("
                    INSERT INTO damage_reports (
                        report_id, road_name, location, damage_type, severity, description, 
                        created_at, $user_column, status
                    ) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, 'pending')
                    ");
                
                $user_id = $_SESSION['user_id'] ?? 1;
                $temp_report_id = 'TEMP-' . time(); // Temporary unique value
                $stmt->bind_param("ssssssi", $temp_report_id, $location, $location, $damage_type, $severity, $description, $user_id);
            } else {
                // Insert without road_name column (fallback)
                $stmt = $conn->prepare("
                    INSERT INTO damage_reports (
                        report_id, location, damage_type, severity, description, 
                        created_at, $user_column, status
                    ) VALUES (?, ?, ?, ?, ?, NOW(), ?, 'pending')
                    ");
                
                $user_id = $_SESSION['user_id'] ?? 1;
                $temp_report_id = 'TEMP-' . time(); // Temporary unique value
                $stmt->bind_param("sssssi", $temp_report_id, $location, $damage_type, $severity, $description, $user_id);
            }
            
            if ($stmt->execute()) {
                $report_id = $conn->insert_id;
                
                // Generate a unique report_id that doesn't conflict with existing ones
                $generated_report_id = 'RD-' . str_pad($report_id, 4, '0', STR_PAD_LEFT);
                
                // Check if this report_id already exists and find next available
                $check_stmt = $conn->prepare("SELECT COUNT(*) FROM damage_reports WHERE report_id = ?");
                $check_stmt->bind_param("s", $generated_report_id);
                $check_stmt->execute();
                $count = $check_stmt->get_result()->fetch_row()[0];
                
                // If conflict exists, find next available number
                if ($count > 0) {
                    $max_stmt = $conn->prepare("SELECT MAX(CAST(SUBSTRING(report_id, 4) AS UNSIGNED)) as max_num FROM damage_reports WHERE report_id LIKE 'RD-%'");
                    $max_stmt->execute();
                    $max_result = $max_stmt->get_result()->fetch_assoc();
                    $next_num = ($max_result['max_num'] ?? 0) + 1;
                    $generated_report_id = 'RD-' . str_pad($next_num, 4, '0', STR_PAD_LEFT);
                }
                
                // Update the report_id
                $update_stmt = $conn->prepare("UPDATE damage_reports SET report_id = ? WHERE id = ?");
                $update_stmt->bind_param("si", $generated_report_id, $report_id);
                $update_stmt->execute();
                
                // Redirect to prevent form resubmission
                header("Location: " . $_SERVER['PHP_SELF'] . "?success=1");
                exit();
            } else {
                $error = "Failed to submit report. Please try again.";
            }
        } catch (Exception $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Handle success message from redirect
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $success = "Road damage report submitted successfully!";
}

// Get filtering and sorting parameters
$sort_by = $_GET['sort_by'] ?? 'latest';
$status_filter = $_GET['status'] ?? 'all';

// Build the query
$where_conditions = [];
$params = [];
$types = '';

if ($status_filter !== 'all') {
    $where_conditions[] = "dr.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Sorting
$order_by = $sort_by === 'oldest' ? 'ORDER BY dr.created_at ASC' : 'ORDER BY dr.created_at DESC';

// Fetch damage reports from database
$reports = [];
try {
    // Check if road_name column exists
    $column_check = $conn->query("SHOW COLUMNS FROM damage_reports LIKE 'road_name'");
    if ($column_check === false) {
        throw new Exception("Failed to check database schema");
    }
    $has_road_name = $column_check->num_rows > 0;
    
    // Check for user reference column (try multiple possibilities)
    $user_column = 'reported_by'; // default
    $possible_columns = ['reported_by', 'user_id', 'reporter_id', 'created_by'];
    
    foreach ($possible_columns as $col) {
        $check = $conn->query("SHOW COLUMNS FROM damage_reports LIKE '$col'");
        if ($check && $check->num_rows > 0) {
            $user_column = $col;
            break;
        }
    }
    
    // Build query based on available columns
    $select_fields = $has_road_name ? 
        "dr.id, dr.road_name, dr.damage_type, dr.severity, dr.status, dr.created_at" :
        "dr.id, 'Unknown Location' as road_name, dr.damage_type, dr.severity, dr.status, dr.created_at";
    
    $sql = "
        SELECT 
            $select_fields,
            CONCAT('RD-', LPAD(dr.id, 4, '0')) as report_id,
            COALESCE(CONCAT(u.first_name, ' ', u.last_name), 'Unknown User') as reporter_name
        FROM damage_reports dr
        LEFT JOIN users u ON dr.$user_column = u.id
        $where_clause
        $order_by
        LIMIT 50
    ";
    
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        throw new Exception("Failed to prepare database query");
    }
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result === false) {
        throw new Exception("Failed to execute database query");
    }
    
    while ($row = $result->fetch_assoc()) {
        $reports[] = $row;
    }
} catch (Exception $e) {
    $error = "Error fetching reports: " . $e->getMessage();
    // Log the actual error for debugging
    error_log("Database error in road_reporting_overview: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Road Reporting | LGU Officer</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url("https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap");
        
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --glass-bg: rgba(255, 255, 255, 0.85);
            --glass-border: rgba(255, 255, 255, 0.4);
            --text-main: #1e293b;
            --text-muted: #64748b;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Inter", sans-serif;
        }

        body {
            height: 100vh;
            background: url('../user_and_access_management_module/assets/img/cityhall.jpeg') center/cover no-repeat fixed;
            position: relative;
            overflow: hidden;
            color: var(--text-main);
        }

        body::before {
            content: "";
            position: absolute;
            inset: 0;
            backdrop-filter: blur(8px);
            background: rgba(15, 23, 42, 0.45);
            z-index: 0;
        }

        .main-content {
            position: relative;
            margin-left: 250px;
            height: 100vh;
            padding: 30px 40px;
            display: grid;
            grid-template-columns: 450px 1fr;
            gap: 25px;
            overflow-y: auto;
            z-index: 1;
        }

        /* Module Header */
        .module-header {
            grid-column: 1 / -1;
            color: white;
            margin-bottom: 10px;
        }

        .module-header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .module-header p {
            font-size: 1rem;
            opacity: 0.9;
            letter-spacing: 1px;
        }

        .header-divider {
            border: none;
            height: 1px;
            background: rgba(255, 255, 255, 0.3);
            margin: 15px 0;
        }

        /* Generic Card Style */
        .glass-card {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            border: 1px solid var(--glass-border);
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            display: flex;
            flex-direction: column;
        }

        .card-title {
            font-size: 1.4rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 25px;
        }

        /* Form Styling */
        .form-group {
            margin-bottom: 18px;
        }

        .form-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 0.95rem;
            color: #1e293b;
            transition: all 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            background: white;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        textarea.form-control {
            resize: none;
            height: 100px;
        }

        /* Photo Upload Styling */
        .photo-upload-zone {
            border: 2px dashed #cbd5e1;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: 0.2s;
            background: rgba(248, 250, 252, 0.5);
        }

        .photo-upload-zone:hover {
            background: rgba(248, 250, 252, 0.8);
            border-color: var(--primary);
        }

        .photo-upload-zone i {
            font-size: 1.5rem;
            color: #64748b;
            margin-bottom: 8px;
        }

        .photo-upload-zone p {
            font-size: 0.85rem;
            color: #64748b;
        }

        .photo-upload-zone span {
            color: var(--primary);
            font-weight: 600;
        }

        .btn-submit {
            width: 100%;
            padding: 14px;
            background: #2563eb;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 0.95rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 10px;
        }

        .btn-submit:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }

        /* Table/Right Side Styling */
        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .table-controls {
            display: flex;
            gap: 20px;
        }

        .control-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .control-label {
            font-size: 0.7rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
        }

        .control-select {
            padding: 6px 12px;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.85rem;
            color: #1e293b;
        }

        .damage-table {
            width: 100%;
            border-collapse: collapse;
        }

        .damage-table th {
            text-align: left;
            padding: 12px 15px;
            font-size: 0.75rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            border-bottom: 2px solid #f1f5f9;
        }

        .damage-table td {
            padding: 15px;
            font-size: 0.9rem;
            color: #334155;
            border-bottom: 1px solid #f1f5f9;
        }

        .damage-table tr:last-child td {
            border-bottom: none;
        }

        .id-cell {
            font-weight: 700;
            color: #1e293b;
        }

        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-under-review {
            background: #e0e7ff;
            color: #3730a3;
        }

        .status-approved {
            background: #dcfce7;
            color: #166534;
        }

        .status-in-progress {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-completed {
            background: #d1fae5;
            color: #065f46;
        }

        /* Scrollbar */
        .main-content::-webkit-scrollbar {
            width: 8px;
        }
        .main-content::-webkit-scrollbar-track {
            background: rgba(0,0,0,0.05);
        }
        .main-content::-webkit-scrollbar-thumb {
            background: rgba(37,99,235,0.5);
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <?php include '../sidebar/sidebar.php'; ?>

    <main class="main-content">
        <header class="module-header">
            <h1>Infrastructure Maintenance</h1>
            <p>Submit and track road repair requests</p>
            <hr class="header-divider">
        </header>

        <!-- Left Side: Report Form -->
        <div class="glass-card">
            <h2 class="card-title">Report Road Damage</h2>
            
            <form action="" method="POST" enctype="multipart/form-data">
                <?php if (isset($error)): ?>
                    <div style="background: #fee2e2; color: #dc2626; padding: 12px; border-radius: 8px; margin-bottom: 20px; font-size: 0.9rem;">
                        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($success)): ?>
                    <div style="background: #dcfce7; color: #166534; padding: 12px; border-radius: 8px; margin-bottom: 20px; font-size: 0.9rem;">
                        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>
                
                <div class="form-group">
                    <label class="form-label">Location</label>
                    <input type="text" name="location" class="form-control" placeholder="Street / Barangay Name" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Damage Type</label>
                    <select name="damage_type" class="form-control" required>
                        <option value="">Select damage type</option>
                        <option value="pothole">Pothole</option>
                        <option value="crack">Crack</option>
                        <option value="landslide">Landslide</option>
                        <option value="flooding">Flooding</option>
                        <option value="drainage">Drainage Issue</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Severity Level</label>
                    <select name="severity" class="form-control" required>
                        <option value="">Select severity</option>
                        <option value="low">Low (Minor wear)</option>
                        <option value="medium">Medium (Moderate damage)</option>
                        <option value="high">High (Severe/Dangerous)</option>
                        <option value="critical">Critical (Emergency)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" placeholder="Describe the damage extent..." required></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Evidence Photos</label>
                    <div style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 20px; text-align: center; color: #6c757d;">
                        <i class="fas fa-camera" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                        <p style="margin: 0;">Photo upload temporarily unavailable</p>
                        <small style="color: #868e96;">Please contact administrator to enable photo uploads</small>
                    </div>
                </div>

                <button type="submit" class="btn-submit">Submit Official Report</button>
            </form>
        </div>

        <!-- Right Side: Recent Reports -->
        <div class="glass-card">
            <div class="table-header">
                <h2 class="card-title" style="margin-bottom: 0;">Reported Damages</h2>
                
                <div class="table-controls">
                    <div class="control-group">
                        <label class="control-label">Sort by Date</label>
                        <select class="control-select" onchange="window.location.href='?sort_by=' + this.value + '&status=<?php echo urlencode($status_filter); ?>'">
                            <option value="latest" <?php echo $sort_by === 'latest' ? 'selected' : ''; ?>>Latest</option>
                            <option value="oldest" <?php echo $sort_by === 'oldest' ? 'selected' : ''; ?>>Oldest</option>
                        </select>
                    </div>
                    <div class="control-group">
                        <label class="control-label">Filter by Status</label>
                        <select class="control-select" onchange="window.location.href='?status=' + this.value + '&sort_by=<?php echo urlencode($sort_by); ?>'">
                            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All</option>
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="under_review" <?php echo $status_filter === 'under_review' ? 'selected' : ''; ?>>Under Review</option>
                            <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        </select>
                    </div>
                </div>
            </div>

            <table class="damage-table">
                <thead>
                    <tr>
                        <th width="80">ID</th>
                        <th>Location</th>
                        <th>Type</th>
                        <th width="120">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reports)): ?>
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 40px; color: var(--text-muted);">
                                <i class="fas fa-info-circle" style="font-size: 1.5rem; margin-bottom: 10px; display: block;"></i>
                                No damage reports found.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($reports as $report): ?>
                        <tr>
                            <td class="id-cell"><?php echo htmlspecialchars($report['report_id']); ?></td>
                            <td><?php echo htmlspecialchars($report['road_name'] ?? 'Unknown Location'); ?></td>
                            <td><?php echo ucfirst(htmlspecialchars($report['damage_type'] ?? 'Unknown')); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo str_replace('_', '-', $report['status'] ?? 'unknown'); ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', htmlspecialchars($report['status'] ?? 'Unknown'))); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <script>
        // Clear any success/error messages after 5 seconds
        setTimeout(() => {
            const errorDiv = document.querySelector('[style*="background: #fee2e2"]');
            const successDiv = document.querySelector('[style*="background: #dcfce7"]');
            
            if (errorDiv) errorDiv.style.display = 'none';
            if (successDiv) successDiv.style.display = 'none';
        }, 5000);
        
        console.log('Road reporting form ready');
    </script>
</body>
</html>

