<?php
require_once '../../includes/session_config.php';
require_once '../../includes/config.php';
require_once '../../includes/functions.php';

if (!is_logged_in()) {
    header('Location: ../../login.php');
    exit();
}

$user_role = $_SESSION['role'] ?? 'citizen';
$user_id = $_SESSION['user_id'];

$report_id = intval($_GET['id'] ?? 0);
$report_type = sanitize_input($_GET['type'] ?? 'transportation');

if ($report_id <= 0) {
    set_flash_message('error', 'Invalid report ID');
    redirect('../admin/report_management.php');
}

$transport_types = ['transportation', 'infrastructure_issue', 'traffic_jam', 'accident', 'road_closure', 'potholes', 'road_damage'];
$table = in_array($report_type, $transport_types) ? 'road_transportation_reports' : 'road_maintenance_reports';
$report = fetch_one("SELECT * FROM {$table} WHERE id = ?", [$report_id], "i");

if (!$report) {
    set_flash_message('error', 'Report not found');
    redirect('../admin/report_management.php');
}

log_audit_action($user_id, "Viewed print report", "Report ID: {$report_id}, Type: {$report_type}");

$status_colors = [
    'pending' => '#d97706',
    'in-progress' => '#2563eb',
    'completed' => '#059669',
    'cancelled' => '#dc2626',
    'approved' => '#059669',
    'rejected' => '#dc2626'
];

$priority_colors = [
    'high' => '#dc2626',
    'medium' => '#d97706',
    'low' => '#059669'
];

$attachments = [];
if (!empty($report['attachments'])) {
    $attachments = json_decode($report['attachments'], true) ?: [];
}

$image_path = $report['image_path'] ?? '';
$id_file_path = $report['id_file_path'] ?? '';
$created_date = format_datetime($report['created_at'] ?? $report['created_date']);
$updated_date = $report['updated_at'] ? format_datetime($report['updated_at']) : 'N/A';
$due_date = $report['due_date'] ? format_date($report['due_date']) : 'Not set';
$estimation = ($report['estimation'] ?? 0) > 0 ? '₱' . number_format($report['estimation'], 2) : 'Not estimated';
$reporter_name = $report['reporter_name'] ?? 'System';
$reporter_email = $report['reporter_email'] ?? 'N/A';
$assigned_to = $report['assigned_to'] ?? $report['maintenance_team'] ?? 'Unassigned';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Report - <?php echo htmlspecialchars($report['title']); ?></title>
    <link rel="icon" type="image/png" href="../../assets/img/logocityhall.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/sidebar.css">
    <link rel="stylesheet" href="../../css/enhanced-reports.css">
    <link rel="stylesheet" href="../../../styles/transition.css">
    <?php if (!empty($_SESSION['darkmode'])): ?><link rel="stylesheet" href="../../css/dark-mode.css"><?php endif; ?>
    <style>
        .report-container {
            max-width: 900px;
            margin: 0 auto;
            padding: 40px 20px;
        }

        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 32px;
            padding-bottom: 24px;
            border-bottom: 3px solid var(--border);
        }

        .report-brand h1 {
            font-size: 14px;
            color: var(--text-secondary);
            font-weight: 400;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 4px;
        }

        .report-brand h2 {
            font-size: 22px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .report-status-print {
            text-align: right;
        }

        .report-status-print .status-label {
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: white;
            display: inline-block;
        }

        .report-meta-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 28px;
        }

        .meta-item {
            padding: 12px 16px;
            background: rgba(55,98,200,0.04);
            border-radius: 8px;
            border: 1px solid var(--border);
        }

        .meta-item .label {
            font-size: 11px;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 500;
            margin-bottom: 4px;
        }

        .meta-item .value {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .meta-item .value i {
            margin-right: 6px;
            color: var(--primary-light);
        }

        .section-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--primary-light);
        }

        .description-box {
            padding: 20px;
            background: rgba(55,98,200,0.03);
            border-radius: 8px;
            border: 1px solid var(--border);
            line-height: 1.7;
            font-size: 14px;
            color: var(--text-primary);
            margin-bottom: 24px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 24px;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px dashed var(--border);
            font-size: 13px;
        }

        .info-item .info-label {
            color: var(--text-secondary);
            font-weight: 500;
        }

        .info-item .info-value {
            color: var(--text-primary);
            font-weight: 600;
        }

        .footer-note {
            text-align: center;
            padding-top: 32px;
            margin-top: 32px;
            border-top: 2px solid var(--border);
            font-size: 11px;
            color: var(--text-secondary);
        }

        .footer-note img {
            max-width: 120px;
            margin-bottom: 8px;
        }

        @media print {
            .no-print { display: none !important; }
            body { background: white !important; }
            .report-container { padding: 20px; }
            .page-header { display: none !important; }
            .meta-item { background: #f8f9fa; border-color: #dee2e6; }
            .description-box { background: #f8f9fa; border-color: #dee2e6; }
        }
    </style>
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?>">
    <div class="page-header no-print">
        <div>
            <h1><i class="fas fa-print"></i> Print Report View</h1>
            <p>Professional print-ready report format</p>
        </div>
        <div class="header-actions">
            <button class="btn btn-outline" onclick="window.history.back()">
                <i class="fas fa-arrow-left"></i> Back
            </button>
            <button class="btn btn-primary" onclick="window.print()">
                <i class="fas fa-print"></i> Print / PDF
            </button>
            <a href="../admin/report_management.php" class="btn btn-outline">
                <i class="fas fa-list"></i> Report List
            </a>
        </div>
    </div>

    <div class="report-container">
        <div class="report-header">
            <div class="report-brand">
                <h1>Road & Transportation Department</h1>
                <h2>Official Incident Report</h2>
                <p style="font-size: 12px; color: var(--text-secondary); margin-top: 4px;">
                    <?php echo htmlspecialchars($report['report_id'] ?? 'N/A'); ?>
                </p>
            </div>
            <div class="report-status-print">
                <div class="status-label" style="background: <?php echo $status_colors[$report['status']] ?? '#6b7280'; ?>">
                    <?php echo ucfirst(str_replace('_', ' ', $report['status'])); ?>
                </div>
                <div style="margin-top: 8px; font-size: 11px; color: var(--text-secondary);">
                    Printed: <?php echo date('M d, Y h:i A'); ?>
                </div>
            </div>
        </div>

        <div class="report-meta-grid">
            <div class="meta-item">
                <div class="label"><i class="fas fa-file-alt"></i> Report ID</div>
                <div class="value"><?php echo htmlspecialchars($report['report_id'] ?? 'N/A'); ?></div>
            </div>
            <div class="meta-item">
                <div class="label"><i class="fas fa-tag"></i> Report Type</div>
                <div class="value"><?php echo htmlspecialchars($report['report_type'] ?? $report_type); ?></div>
            </div>
            <div class="meta-item">
                <div class="label"><i class="fas fa-building"></i> Department</div>
                <div class="value"><?php echo htmlspecialchars($report['department'] ?? 'N/A'); ?></div>
            </div>
            <div class="meta-item">
                <div class="label"><i class="fas fa-flag"></i> Priority</div>
                <div class="value" style="color: <?php echo $priority_colors[$report['priority']] ?? '#6b7280'; ?>">
                    <i class="fas fa-circle"></i> <?php echo ucfirst($report['priority']); ?>
                </div>
            </div>
            <div class="meta-item">
                <div class="label"><i class="fas fa-user"></i> Assigned To</div>
                <div class="value"><i class="fas fa-user-hard-hat"></i> <?php echo htmlspecialchars($assigned_to); ?></div>
            </div>
            <div class="meta-item">
                <div class="label"><i class="fas fa-peso-sign"></i> Cost Estimation</div>
                <div class="value"><?php echo $estimation; ?></div>
            </div>
        </div>

        <div class="section-title">Report Details</div>
        <div class="description-box">
            <h4 style="font-size: 18px; font-weight: 600; margin-bottom: 12px; color: var(--text-primary);">
                <?php echo htmlspecialchars($report['title']); ?>
            </h4>
            <p style="margin: 0;"><?php echo nl2br(htmlspecialchars($report['description'])); ?></p>
        </div>

        <div class="info-grid">
            <div>
                <div class="section-title" style="margin-bottom: 16px;">Location Information</div>
                <div class="info-item">
                    <span class="info-label"><i class="fas fa-map-marker-alt"></i> Location</span>
                    <span class="info-value"><?php echo htmlspecialchars($report['location'] ?? 'N/A'); ?></span>
                </div>
                <?php if (!empty($report['latitude']) && !empty($report['longitude'])): ?>
                <div class="info-item">
                    <span class="info-label"><i class="fas fa-globe"></i> Coordinates</span>
                    <span class="info-value"><?php echo $report['latitude']; ?>, <?php echo $report['longitude']; ?></span>
                </div>
                <?php endif; ?>
                <div class="info-item">
                    <span class="info-label"><i class="fas fa-calendar-alt"></i> Created Date</span>
                    <span class="info-value"><?php echo $created_date; ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label"><i class="fas fa-calendar-check"></i> Due Date</span>
                    <span class="info-value"><?php echo $due_date; ?></span>
                </div>
            </div>
            <div>
                <div class="section-title" style="margin-bottom: 16px;">Reporter Information</div>
                <div class="info-item">
                    <span class="info-label"><i class="fas fa-user"></i> Reporter</span>
                    <span class="info-value"><?php echo htmlspecialchars($reporter_name); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label"><i class="fas fa-envelope"></i> Email</span>
                    <span class="info-value"><?php echo htmlspecialchars($reporter_email); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label"><i class="fas fa-history"></i> Last Updated</span>
                    <span class="info-value"><?php echo $updated_date; ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label"><i class="fas fa-check-circle"></i> Status</span>
                    <span class="info-value"><?php echo ucfirst(str_replace('_', ' ', $report['status'])); ?></span>
                </div>
            </div>
        </div>

        <?php if (!empty($report['resolution_notes'])): ?>
        <div class="section-title">Resolution Notes</div>
        <div class="description-box" style="background: rgba(5,150,105,0.05); border-color: rgba(5,150,105,0.2);">
            <p style="margin: 0;"><?php echo nl2br(htmlspecialchars($report['resolution_notes'])); ?></p>
        </div>
        <?php endif; ?>

        <?php if ($image_path): ?>
        <div class="section-title">Attached Image</div>
        <div style="text-align: center; margin-bottom: 24px; padding: 16px; background: var(--card-bg); border: 1px solid var(--border); border-radius: 8px;">
            <img src="<?php echo htmlspecialchars($image_path); ?>" alt="Report Image" style="max-width: 100%; max-height: 400px; border-radius: 6px;">
        </div>
        <?php endif; ?>

        <?php if (!empty($attachments)): ?>
        <div class="section-title">Attachments (<?php echo count($attachments); ?>)</div>
        <div style="margin-bottom: 24px;">
            <?php foreach ($attachments as $att): ?>
            <div style="padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px; margin-bottom: 6px; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-paperclip" style="color: var(--primary-light);"></i>
                <span><?php echo htmlspecialchars($att['name'] ?? $att['file'] ?? 'Attachment'); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="footer-note">
            <p><strong>Road & Transportation Department</strong><br>
            Quezon City, Metro Manila, Philippines</p>
            <p>This is a computer-generated document. This document is valid without signature.</p>
            <p style="margin-top: 4px;">Generated on: <?php echo date('F d, Y \a\t h:i A'); ?></p>
        </div>
    </div>

    <script>
        window.onload = function() {
            const params = new URLSearchParams(window.location.search);
            if (params.get('print') === '1') {
                setTimeout(function() { window.print(); }, 500);
            }
        };
    </script>

    <script src="../../js/page-transition.js"></script>
</body>
</html>
