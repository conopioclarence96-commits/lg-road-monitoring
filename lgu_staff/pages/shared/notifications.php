<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ini_set('display_errors', 0);
}

ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);

session_start();
$session_timeout = 5 * 60;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout)) {
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/');
    header('Location: ../../login.php?timeout=1');
    exit();
}
$_SESSION['last_activity'] = time();

require_once '../../includes/config.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/../api/cimm_verification_data.php';

$user_role = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;

if (!isset($_SESSION['user_id']) || !is_admin_or_staff_role($user_role)) {
    header('Location: ../../login.php');
    exit();
}

// Handle mark as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    if ($action === 'mark_read') {
        $type = $_POST['type'] ?? '';
        $id = $_POST['id'] ?? 0;
        
        if ($type === 'report') {
            $stmt = $conn->prepare("UPDATE road_transportation_reports SET updated_at = NOW() WHERE id = ? AND status = 'pending'");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
        }
        
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($action === 'mark_all_read') {
        $conn->query("UPDATE road_transportation_reports SET updated_at = NOW() WHERE status = 'pending'");
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($action === 'mark_read_change' && $id > 0) {
        $stmt = $conn->prepare("UPDATE change_requests SET admin_notes = CONCAT(COALESCE(admin_notes,''), '[Viewed]') WHERE id = ? AND status = 'pending'");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true]);
        exit;
    }
}

$is_admin = ($user_role === 'system_admin');
$pending_reports = [];
$pending_changes = [];
$report_updates = [];
$staff_updates = [];

if ($is_admin) {
    // Admin: get pending reports
    try {
        $rstmt = $conn->prepare("
            SELECT id, report_id, title, department, priority, status, description, location, 
                   reporter_name, reporter_email, created_at,
                   report_type, report_category, report_source, created_by
            FROM road_transportation_reports 
            WHERE status = 'pending'
            ORDER BY 
                CASE priority WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 END,
                created_at DESC
        ");
        $rstmt->execute();
        $pending_reports = $rstmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $rstmt->close();
    } catch (Exception $e) {
        error_log("Pending reports query error: " . $e->getMessage());
    }

    // Admin: also include CIMM reports that are still awaiting review/approval.
    // These live in cimm_verification_reports (synced from the CIMM module via
    // the webhook/pull sync), so they are fetched from that table and flagged
    // with _source='cimm' so the source badge below stays accurate. Any row in
    // that table whose current status maps to "pending" is shown.
    try {
        $cimmPdo = rgmap_verification_pdo();
        $cimmRows = rgmap_fetch_cimm_verification_reports($cimmPdo, ['limit' => 500]);
        $cimmPendingStatus = ['Pending', 'Pending Review'];
        foreach ($cimmRows as $crow) {
            $verification = (string)($crow['verification_status'] ?? 'Pending Review');
            if (!in_array($verification, $cimmPendingStatus, true) && (string)($crow['approval_status'] ?? 'Pending') !== 'Pending') {
                continue;
            }
            $facility = (string)($crow['cprf_facility_name'] ?? '');
            $pending_reports[] = [
                '_source'       => 'cimm',
                'id'            => (int)($crow['id'] ?? $crow['cimm_req_id'] ?? 0),
                'report_id'     => $crow['reference_code'] ?? ('REQ-' . ($crow['cimm_req_id'] ?? '')),
                'title'         => (string)($crow['infrastructure'] ?? 'CIMM Report'),
                'department'    => $facility !== '' ? $facility : 'CIMM',
                'priority'      => strtolower((string)($crow['priority'] ?? 'medium')),
                'status'        => 'pending',
                'description'   => (string)($crow['issue'] ?? ''),
                'location'      => (string)($crow['location'] ?? ''),
                'reporter_name' => $crow['reporter_name'] ?? null,
                'reporter_email'=> $crow['email'] ?? null,
                'created_at'    => $crow['submitted_at'] ?? $crow['created_at'] ?? date('Y-m-d H:i:s'),
                'report_type'   => null,
                'report_category' => null,
                'report_source' => null,
                'created_by'    => null,
            ];
        }

        // Keep the same ordering as the transport query: high -> medium -> low,
        // then newest first.
        usort($pending_reports, function ($a, $b) {
            $prio = ['high' => 1, 'medium' => 2, 'low' => 3];
            $pa = $prio[strtolower((string)($a['priority'] ?? 'medium'))] ?? 2;
            $pb = $prio[strtolower((string)($b['priority'] ?? 'medium'))] ?? 2;
            if ($pa !== $pb) return $pa <=> $pb;
            $ta = strtotime((string)($a['created_at'] ?? '')) ?: 0;
            $tb = strtotime((string)($b['created_at'] ?? '')) ?: 0;
            return $tb <=> $ta;
        });
    } catch (Exception $e) {
        error_log("Pending CIMM reports query error: " . $e->getMessage());
    }

    // Admin: get all pending change requests
    try {
        $cstmt = $conn->prepare("
            SELECT cr.*, u.full_name as user_name
            FROM change_requests cr
            LEFT JOIN users u ON cr.user_id = u.id
            WHERE cr.status = 'pending'
            ORDER BY cr.created_at DESC
        ");
        $cstmt->execute();
        $pending_changes = $cstmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $cstmt->close();
    } catch (Exception $e) {
        error_log("Pending change requests query error: " . $e->getMessage());
    }

    // Admin: get progress update notifications
    try {
        $nstmt = $conn->prepare("
            SELECT rn.*, r.report_id as report_code, r.title as report_title,
                   r.report_type, r.report_category, r.report_source, r.created_by
            FROM report_notifications rn
            LEFT JOIN road_transportation_reports r ON rn.report_id = r.id
            WHERE rn.is_read = 0
            ORDER BY rn.created_at DESC
            LIMIT 20
        ");
        $nstmt->execute();
        $progress_notifications = $nstmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $nstmt->close();
    } catch (Exception $e) {
        error_log("Progress notifications query error: " . $e->getMessage());
        $progress_notifications = [];
    }
} else {
    // LGU Staff: get their own change request status updates
    try {
        $sstmt = $conn->prepare("
            SELECT id, status, admin_notes, created_at, reviewed_at
            FROM change_requests
            WHERE user_id = ? AND status != 'pending'
            ORDER BY reviewed_at DESC
            LIMIT 20
        ");
        $sstmt->bind_param("i", $user_id);
        $sstmt->execute();
        $staff_updates = $sstmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $sstmt->close();
    } catch (Exception $e) {
        error_log("Staff updates query error: " . $e->getMessage());
    }

    // LGU Staff: get report status updates (approved = completed, rejected = cancelled)
    try {
        $rstmt = $conn->prepare("
            SELECT id, report_id, title, status, location, 
                   approved_at, rejected_at, updated_at, created_at,
                   report_type, report_category, report_source, created_by
            FROM road_transportation_reports
            WHERE created_by = ? AND status IN ('completed', 'cancelled')
            ORDER BY GREATEST(COALESCE(approved_at, '1970-01-01'), COALESCE(rejected_at, '1970-01-01'), updated_at) DESC
            LIMIT 20
        ");
        $rstmt->bind_param("i", $user_id);
        $rstmt->execute();
        $report_updates = $rstmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $rstmt->close();
    } catch (Exception $e) {
        error_log("Staff report updates query error: " . $e->getMessage());
    }
}

$total_notifications = $is_admin ? (count($pending_reports) + count($pending_changes) + count($progress_notifications)) : (count($staff_updates) + count($report_updates));

// --- Notification deep-link helpers ----------------------------------------
// Every View/Review button must pass the report source together with the id
// so destination pages can look up the record in the correct table — ids are
// only unique within their own table. Returns one of:
//   'citizen'     -> verification_monitoring.php?source=citizen&id=...
//   'lgu'         -> report_management.php?source=lgu&id=...
//   'maintenance' -> report_management.php?source=maintenance&id=...
function notification_report_source(array $r): string {
    if (($r['_source'] ?? '') === 'cimm') return 'cimm';
    if (($r['report_type'] ?? '') === 'infrastructure_issue') return 'maintenance';
    if (!empty($r['created_by'])) return 'lgu';
    return 'citizen';
}

// Resolve the originating module of a report so the Pending Reports panel can
// show it as a colored badge. Determined server-side from the report's
// originating table (_source, set when appended from cimm_verification_reports)
// or its source fields (report_type / created_by) — never guessed or hardcoded
// per-row. Returns ['key' => .., 'label' => .., 'icon' => .., 'class' => ..].
function notification_source_badge(array $r): array {
    switch (notification_report_source($r)) {
        case 'cimm':
            return ['key' => 'cimm',        'label' => 'CIMM',                  'icon' => '🟣', 'class' => 'source-cimm'];
        case 'maintenance':
            return ['key' => 'maintenance', 'label' => 'Infrastructure Projects', 'icon' => '🟠', 'class' => 'source-maintenance'];
        case 'lgu':
            return ['key' => 'lgu',         'label' => 'LGU Monitoring',         'icon' => '🔵', 'class' => 'source-lgu'];
        case 'citizen':
        default:
            return ['key' => 'citizen',     'label' => 'Citizen Reports',        'icon' => '🟢', 'class' => 'source-citizen'];
    }
}

function notification_report_url_for(int $id, array $r): string {
    $type = rawurlencode((string)($r['report_type'] ?? ''));
    $src = notification_report_source($r);
    if ($src === 'citizen') {
        return '../admin/verification_monitoring.php?source=citizen&id=' . $id;
    }
    $url = '../admin/report_management.php?source=' . $src . '&id=' . $id;
    if ($type !== '') $url .= '&type=' . $type;
    return $url;
}

function notification_report_url(array $r): string {
    return notification_report_url_for((int)($r['id'] ?? 0), $r);
}

// Deep-link for the "Pending Reports from Departments" panel. Every pending
// report lives in road_transportation_reports, so the primary key alone is
// enough — verification_monitoring.php locates it across all sections via
// ?focus_report_id= (backend-verified, then scrolled + highlighted on load).
function notification_pending_report_focus_url(array $r): string {
    // CIMM reports live in cimm_verification_reports, not
    // road_transportation_reports, so they need the explicit source+id deep link
    // (verification_monitoring.php's ?focus_report_id= path only resolves
    // transport-table rows).
    if (($r['_source'] ?? '') === 'cimm') {
        return '../admin/verification_monitoring.php?source=cimm&id=' . (int)($r['id'] ?? 0);
    }
    return '../admin/verification_monitoring.php?focus_report_id=' . (int)($r['id'] ?? 0);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - LGU Road Monitoring</title>
    <link rel="icon" type="image/png" href="../../assets/img/logocityhall.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../css/theme-tokens.css">
    <link rel="stylesheet" href="../../css/theme-utilities.css">
    <link rel="stylesheet" href="../../css/sidebar.css">
    <link rel="stylesheet" href="../../../styles/transition.css">
    <?php if (!empty($_SESSION['darkmode'])): ?><link rel="stylesheet" href="../../css/dark-mode.css"><?php endif; ?>
    <style>
        body {
            background: #f7f5f0;
            min-height: 100vh;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        .main-content {
            margin-left: 250px;
            padding: 20px;
            position: relative;
            z-index: 1;
        }

        .dashboard-header {
            background: #f0f4fa;
            backdrop-filter: blur(15px);
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            margin-bottom: 25px;
        }

        .welcome-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .welcome-text h1 {
            font-size: 28px;
            font-weight: 700;
            color: #1e3c72;
            margin-bottom: 8px;
        }

        .welcome-text p {
            color: #64748b;
            font-size: 16px;
        }

        .date-time {
            text-align: right;
            color: #1e3c72;
        }

        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: #f0f4fa;
            backdrop-filter: blur(15px);
            padding: 20px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            text-align: center;
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            font-size: 24px;
            margin-bottom: 10px;
        }

        .stat-number {
            font-size: 32px;
            font-weight: 700;
            color: #1e3c72;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 14px;
            color: #64748b;
            font-weight: 500;
        }

        .workflow-container {
            display: grid;
            grid-template-columns: 1fr;
            gap: 25px;
        }

        .workflow-card {
            background: #f0f4fa;
            backdrop-filter: blur(15px);
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .workflow-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(55, 98, 200, 0.1);
        }

        .workflow-title {
            font-size: 18px;
            font-weight: 600;
            color: #1e3c72;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .workflow-badge {
            background: #3762c8;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .workflow-content {
            max-height: 600px;
            overflow-y: auto;
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        th {
            background: rgba(55, 98, 200, 0.1);
            font-weight: 600;
            color: #1e3c72;
        }

        .priority-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .priority-high {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        .priority-medium {
            background: #fffbeb;
            color: #d97706;
            border: 1px solid #fde68a;
        }

        .priority-low {
            background: #f0fdf4;
            color: #16a34a;
            border: 1px solid #bbf7d0;
        }

        .department-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #bfdbfe;
        }

        .source-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .source-citizen {
            background: #f0fdf4;
            color: #16a34a;
            border: 1px solid #bbf7d0;
        }

        .source-cimm {
            background: #faf5ff;
            color: #9333ea;
            border: 1px solid #e9d5ff;
        }

        .source-lgu {
            background: #f0f9ff;
            color: #0284c7;
            border: 1px solid #bae6fd;
        }

        .source-maintenance {
            background: #fff7ed;
            color: #ea580c;
            border: 1px solid #fed7aa;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .btn-sm {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.2s;
        }

        .btn-view {
            background: #3762c8;
            color: white;
        }

        .btn-view:hover {
            background: #2a4a9a;
        }

        .btn-approve {
            background: #10b981;
            color: white;
        }

        .btn-approve:hover {
            background: #059669;
        }

        .btn-reject {
            background: #ef4444;
            color: white;
        }

        .btn-reject:hover {
            background: #dc2626;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #64748b;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            color: #cbd5e1;
        }

        .empty-state p {
            font-size: 16px;
        }

        .notification-item {
            padding: 15px;
            border-bottom: 1px solid #e5e7eb;
            transition: background 0.2s;
        }

        .notification-item:hover {
            background: rgba(55, 98, 200, 0.03);
        }

        .notification-item:last-child {
            border-bottom: none;
        }

        .notification-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
        }

        .notification-title {
            font-weight: 600;
            color: #1e3c72;
            font-size: 14px;
        }

        .notification-time {
            font-size: 12px;
            color: #94a3b8;
        }

        .notification-body {
            font-size: 13px;
            color: #64748b;
            margin-bottom: 10px;
        }

        .notification-meta {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .notification-tag {
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 8px;
            background: #f1f5f9;
            color: #475569;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 10px;
            }

            .welcome-section {
                flex-direction: column;
                align-items: flex-start;
            }

            .date-time {
                text-align: left;
            }
        }
    </style>
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?>">
    <?php include '../../includes/sidebar_nav.php'; ?>

    <div class="main-content">
        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <div class="welcome-section">
                <div class="welcome-text">
                    <h1><i class="fas fa-bell"></i> Notifications</h1>
                    <p><?php echo $is_admin ? 'Reports from other departments and staff change requests' : 'Updates on your submitted reports and change requests'; ?></p>
                </div>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <?php if ($is_admin): ?>
                    <button class="btn-sm btn-approve" onclick="markAllRead()" <?php echo $total_notifications === 0 ? 'disabled' : ''; ?>>
                        <i class="fas fa-check-double"></i> Mark All as Read
                    </button>
                    <?php endif; ?>
                    <div class="date-time">
                        <div id="currentDate"></div>
                        <div id="currentTime"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics -->
        <div class="quick-stats">
            <?php if ($is_admin): ?>
            <div class="stat-card">
                <div class="stat-icon" style="color: #f59e0b;">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="stat-number"><?php echo count($pending_reports); ?></div>
                <div class="stat-label">Pending Reports</div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="color: #8b5cf6;">
                    <i class="fas fa-user-edit"></i>
                </div>
                <div class="stat-number"><?php echo count($pending_changes); ?></div>
                <div class="stat-label">Change Requests</div>
            </div>
            <?php else: ?>
            <div class="stat-card">
                <div class="stat-icon" style="color: #f59e0b;">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="stat-number"><?php echo count($report_updates); ?></div>
                <div class="stat-label">Report Updates</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon t-text-success">
                    <i class="fas fa-user-edit"></i>
                </div>
                <div class="stat-number"><?php echo count($staff_updates); ?></div>
                <div class="stat-label">Change Request Updates</div>
            </div>
            <?php endif; ?>
            <div class="stat-card">
                <div class="stat-icon t-text-success">
                    <i class="fas fa-bell"></i>
                </div>
                <div class="stat-number"><?php echo $total_notifications; ?></div>
                <div class="stat-label">Total Notifications</div>
            </div>
        </div>

        <div class="workflow-container">
            <?php if ($is_admin): ?>
            <!-- Pending Reports -->
            <div class="workflow-card">
                <div class="workflow-header">
                    <h3 class="workflow-title">
                        <i class="fas fa-file-alt" style="color: #f59e0b;"></i>
                        <span>Pending Reports from Departments</span>
                        <span class="workflow-badge"><?php echo count($pending_reports); ?></span>
                    </h3>
                </div>
                
                <div class="workflow-content">
                    <?php if (empty($pending_reports)): ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <p>No pending reports</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($pending_reports as $report): ?>
                            <?php $source_badge = notification_source_badge($report); ?>
                            <div class="notification-item" id="report-<?php echo $report['id']; ?>">
                                <div class="notification-header">
                                    <div class="notification-title"><?php echo htmlspecialchars($report['title']); ?></div>
                                    <div class="notification-time"><?php echo date('M d, Y H:i', strtotime($report['created_at'])); ?></div>
                                </div>
                                <div class="notification-body">
                                    <?php echo htmlspecialchars(substr($report['description'], 0, 150)); ?><?php echo strlen($report['description']) > 150 ? '...' : ''; ?>
                                </div>
                                <div class="notification-meta">
                                    <span class="notification-tag"><i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($report['report_id']); ?></span>
                                    <span class="priority-badge priority-<?php echo $report['priority']; ?>"><?php echo ucfirst($report['priority']); ?></span>
                                    <span class="department-badge"><?php echo ucfirst(htmlspecialchars($report['department'])); ?></span>
                                    <span class="source-badge <?php echo $source_badge['class']; ?>"><?php echo $source_badge['icon']; ?> <?php echo htmlspecialchars($source_badge['label']); ?></span>
                                    <?php if ($report['location']): ?>
                                        <span class="notification-tag"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($report['location']); ?></span>
                                    <?php endif; ?>
                                    <?php if ($report['reporter_name']): ?>
                                        <span class="notification-tag"><i class="fas fa-user"></i> <?php echo htmlspecialchars($report['reporter_name']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div style="margin-top: 10px;">
                                    <div class="action-buttons">
                                        <a href="<?php echo notification_pending_report_focus_url($report); ?>" class="btn-sm btn-view" target="_parent"><i class="fas fa-eye"></i> View</a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Pending Change Requests -->
            <div class="workflow-card">
                <div class="workflow-header">
                    <h3 class="workflow-title">
                        <i class="fas fa-user-edit" style="color: #8b5cf6;"></i>
                        <span>Staff Change Requests</span>
                        <span class="workflow-badge"><?php echo count($pending_changes); ?></span>
                    </h3>
                </div>
                
                <div class="workflow-content">
                    <?php if (empty($pending_changes)): ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <p>No pending change requests</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($pending_changes as $cr): 
                            $req_data = json_decode($cr['requested_data'], true);
                            $changes_list = [];
                            if (!empty($req_data['email'])) $changes_list[] = 'Email: ' . $req_data['email'];
                            if (!empty($req_data['address'])) $changes_list[] = 'Address: ' . $req_data['address'];
                            if (!empty($req_data['civil_status'])) $changes_list[] = 'Status: ' . ucfirst($req_data['civil_status']);
                            if (!empty($req_data['birthday'])) $changes_list[] = 'Birthday: ' . $req_data['birthday'];
                            if (!empty($req_data['new_password'])) $changes_list[] = 'Password change requested';
                            if (!empty($req_data['id_file_path'])) $changes_list[] = 'New ID photo uploaded';
                        ?>
                            <div class="notification-item">
                                <div class="notification-header">
                                    <div class="notification-title"><?php echo htmlspecialchars($cr['user_name']); ?></div>
                                    <div class="notification-time"><?php echo date('M d, Y H:i', strtotime($cr['created_at'])); ?></div>
                                </div>
                                <div class="notification-body">
                                    Requesting information update:
                                    <?php echo !empty($changes_list) ? '<br><small>' . implode(' &bull; ', $changes_list) . '</small>' : ''; ?>
                                </div>
                                <div class="notification-meta">
                                    <?php if (!empty($cr['reason'])): ?>
                                        <span class="notification-tag"><i class="fas fa-comment"></i> <?php echo htmlspecialchars($cr['reason']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div style="margin-top: 10px;">
                                    <div class="action-buttons">
                                        <a href="../admin/account_approvals.php?cr_id=<?php echo (int)$cr['id']; ?>" class="btn-sm btn-view" target="_parent"><i class="fas fa-eye"></i> Review</a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Admin: Progress Update Notifications -->
            <div class="workflow-card">
                <div class="workflow-header">
                    <h3 class="workflow-title">
                        <i class="fas fa-clock" style="color: #10b981;"></i>
                        <span>Progress Updates</span>
                        <span class="workflow-badge"><?php echo count($progress_notifications); ?></span>
                    </h3>
                </div>
                <div class="workflow-content">
                    <?php if (empty($progress_notifications)): ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <p>No progress updates yet</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($progress_notifications as $pn): ?>
                            <div class="notification-item">
                                <div class="notification-header">
                                    <div class="notification-title">
                                        <span class="t-text-success"><i class="fas fa-clipboard-list"></i> <?php echo htmlspecialchars($pn['message']); ?></span>
                                    </div>
                                    <div class="notification-time"><?php echo date('M d, Y H:i', strtotime($pn['created_at'])); ?></div>
                                </div>
                                <div class="notification-body">
                                    <small>Report: <strong><?php echo htmlspecialchars($pn['report_code'] ?? '#' . $pn['report_id']); ?></strong></small>
                                    <?php if (!empty($pn['report_title'])): ?>
                                        &mdash; <?php echo htmlspecialchars($pn['report_title']); ?>
                                    <?php endif; ?>
                                </div>
                                <div style="margin-top: 10px;">
                                    <div class="action-buttons">
                                        <a href="<?php echo notification_report_url_for((int)$pn['report_id'], $pn); ?>" class="btn-sm btn-view" target="_parent"><i class="fas fa-eye"></i> View Report</a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <!-- Staff: My Report Status Updates -->
            <div class="workflow-card">
                <div class="workflow-header">
                    <h3 class="workflow-title">
                        <i class="fas fa-file-alt" style="color: #f59e0b;"></i>
                        <span>Report Updates</span>
                        <span class="workflow-badge"><?php echo count($report_updates); ?></span>
                    </h3>
                </div>
                
                <div class="workflow-content">
                    <?php if (empty($report_updates)): ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <p>No report updates yet. Submit a report and wait for admin review.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($report_updates as $report): 
                            $is_approved = ($report['status'] === 'approved' || $report['status'] === 'completed');
                            $action_time = $is_approved ? ($report['approved_at'] ?? $report['updated_at']) : ($report['rejected_at'] ?? $report['updated_at']);
                        ?>
                            <div class="notification-item">
                                <div class="notification-header">
                                    <div class="notification-title">
                                        <?php if ($is_approved): ?>
                                            <span class="t-text-success"><i class="fas fa-check-circle"></i> Approved</span>
                                        <?php else: ?>
                                            <span class="t-text-danger"><i class="fas fa-times-circle"></i> Rejected</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="notification-time"><?php echo date('M d, Y H:i', strtotime($action_time)); ?></div>
                                </div>
                                <div class="notification-body">
                                    <strong><?php echo htmlspecialchars($report['title']); ?></strong> was <strong><?php echo $is_approved ? 'approved' : 'rejected'; ?></strong>.
                                    <?php if ($report['location']): ?>
                                        <br><small class="t-text-secondary">Location: <?php echo htmlspecialchars($report['location']); ?></small>
                                    <?php endif; ?>
                                </div>
                                <div class="notification-meta">
                                    <span class="notification-tag"><i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($report['report_id']); ?></span>
                                    <span class="notification-tag"><i class="fas fa-calendar"></i> Submitted <?php echo date('M d, Y', strtotime($report['created_at'])); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Staff: My Change Request Status -->
            <div class="workflow-card">
                <div class="workflow-header">
                    <h3 class="workflow-title">
                        <i class="fas fa-user-edit" style="color: #10b981;"></i>
                        <span>Change Request Updates</span>
                        <span class="workflow-badge"><?php echo count($staff_updates); ?></span>
                    </h3>
                </div>
                
                <div class="workflow-content">
                    <?php if (empty($staff_updates)): ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <p>No updates yet. Submit a change request to see updates here.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($staff_updates as $update): ?>
                            <div class="notification-item">
                                <div class="notification-header">
                                    <div class="notification-title">
                                        <?php if ($update['status'] === 'approved'): ?>
                                            <span class="t-text-success"><i class="fas fa-check-circle"></i> Approved</span>
                                        <?php else: ?>
                                            <span class="t-text-danger"><i class="fas fa-times-circle"></i> Rejected</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="notification-time"><?php echo date('M d, Y H:i', strtotime($update['reviewed_at'] ?? $update['created_at'])); ?></div>
                                </div>
                                <div class="notification-body">
                                    Your change request was <strong><?php echo $update['status']; ?></strong>.
                                    <?php if (!empty($update['admin_notes'])): ?>
                                        <br><small class="t-text-secondary">Admin note: <?php echo htmlspecialchars($update['admin_notes']); ?></small>
                                    <?php endif; ?>
                                </div>
                                <div class="notification-meta">
                                    <span class="notification-tag"><i class="fas fa-calendar"></i> Submitted <?php echo date('M d, Y', strtotime($update['created_at'])); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function updateDateTime() {
            const now = new Date();
            const dateOptions = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            const timeOptions = { hour: '2-digit', minute: '2-digit', second: '2-digit' };
            
            document.getElementById('currentDate').textContent = now.toLocaleDateString('en-US', dateOptions);
            document.getElementById('currentTime').textContent = now.toLocaleTimeString('en-US', timeOptions);
        }
        
        updateDateTime();
        setInterval(updateDateTime, 1000);

        function markAllRead() {
            if (confirm('Mark all notifications as read?')) {
                const formData = new FormData();
                formData.append('action', 'mark_all_read');
                
                fetch('', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        location.reload();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                });
            }
        }
    </script>
    

</body>
</html>
