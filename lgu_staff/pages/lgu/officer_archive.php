<?php
require_once '../../includes/session_config.php';
require_once '../../includes/config.php';
require_once '../../includes/functions.php';

// Only Road Monitoring Officers may access this personal read-only archive.
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? '';

if ($user_role !== 'road_monitoring_officer') {
    header('Location: ../../login.php');
    exit();
}

// Ensure the archive table exists.
$conn->query("CREATE TABLE IF NOT EXISTS road_transportation_reports_archive LIKE road_transportation_reports");

// Ensure archive table has the same columns as the source table
foreach (['report_category' => "ENUM('road','transportation') DEFAULT NULL AFTER report_type",
         'report_source' => "VARCHAR(50) DEFAULT NULL AFTER report_category",
         'previous_status' => "VARCHAR(50) DEFAULT NULL",
         'archived_from' => "VARCHAR(100) DEFAULT NULL"] as $col => $def) {
    $chk = $conn->query("SHOW COLUMNS FROM road_transportation_reports_archive LIKE '$col'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE road_transportation_reports_archive ADD COLUMN $col $def");
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';

// Build WHERE clause to find archived reports that were assigned to this officer.
// We join report_assignments to the archive table via archived_from (the source
// table name) and report_id (which matches report_id in the archive).
$status_where = '';
if ($status_filter === 'completed') {
    $status_where = " AND a.status = 'completed'";
} elseif ($status_filter === 'cancelled') {
    $status_where = " AND a.status = 'cancelled'";
} elseif ($status_filter === 'rejected') {
    $status_where = " AND a.status = 'rejected'";
} else {
    $status_where = " AND a.status IN ('completed','cancelled','rejected')";
}

// The report_assignments table stores the report's INT PK in report_id.
// The archive table has both `id` (INT, may differ from original for
// completed reports) and `report_id` (VARCHAR, always matches original).
//   - Cancelled reports: rgmap_archive_report preserves the original `id`,
//     so we match a.id = ra.report_id.
//   - Completed reports: rgmap_archive_report_copy gives the archive row a
//     new auto-increment `id`, so we match through the original report's
//     `report_id` VARCHAR via a subquery against the live table.
try {
    $stmt = $conn->prepare("
        SELECT DISTINCT a.*,
               'transport' AS _source_table,
               ra.report_id AS original_pk
        FROM report_assignments ra
        JOIN road_transportation_reports_archive a ON (
            a.id = ra.report_id
            OR (
                a.report_id IS NOT NULL AND a.report_id != ''
                AND a.report_id = (SELECT r.report_id FROM road_transportation_reports r WHERE r.id = ra.report_id LIMIT 1)
            )
        )
        WHERE ra.user_id = ? AND ra.status = 'active'" . $status_where . "
        ORDER BY a.created_at DESC
        LIMIT 200
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $archives = $stmt->get_result();
    $rows = [];
    while ($r = $archives->fetch_assoc()) { $rows[] = $r; }
    $stmt->close();
} catch (Exception $e) {
    error_log("Officer archive query error: " . $e->getMessage());
    $rows = [];
}

// Also check road_maintenance_reports_archive if it exists
if ($conn->query("SHOW TABLES LIKE 'road_maintenance_reports_archive'")->num_rows > 0) {
    try {
        $stmt2 = $conn->prepare("
            SELECT DISTINCT ma.*,
                   'maintenance' AS _source_table,
                   ra.report_id AS original_pk
            FROM report_assignments ra
            JOIN road_maintenance_reports_archive ma ON (
                ma.id = ra.report_id
                OR (
                    ma.report_id IS NOT NULL AND ma.report_id != ''
                    AND ma.report_id = (SELECT r.report_id FROM road_maintenance_reports r WHERE r.id = ra.report_id LIMIT 1)
                )
            )
            WHERE ra.user_id = ? AND ra.status = 'active'" . $status_where . "
            ORDER BY ma.created_at DESC
            LIMIT 200
        ");
        $stmt2->bind_param("i", $user_id);
        $stmt2->execute();
        while ($r = $stmt2->get_result()->fetch_assoc()) { $rows[] = $r; }
        $stmt2->close();
    } catch (Exception $e) {
        // ignore
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archive | LGU Staff</title>
    <link rel="icon" type="image/png" href="../../assets/img/logocityhall.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/theme-tokens.css">
    <link rel="stylesheet" href="../../css/theme-utilities.css">
    <link rel="stylesheet" href="../../css/sidebar.css">
    <link rel="stylesheet" href="../../../styles/transition.css">
    <?php if (!empty($_SESSION['darkmode'])): ?><link rel="stylesheet" href="../../css/dark-mode.css"><?php endif; ?>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { background: #f7f5f0; min-height: 100vh; }
        html { scroll-behavior: smooth; }
        .main-content { margin-left: 250px; padding: 20px; position: relative; z-index: 1; }
        .archive-header {
            background: #f0f4fa; padding: 25px 30px; border-radius: 16px; margin-bottom: 25px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1); border: 1px solid #e0e0e0;
        }
        .archive-header h1 { color: #1e3c72; font-size: 28px; font-weight: 700; margin-bottom: 5px; }
        .archive-header p { color: #666; font-size: 14px; }
        .archive-card {
            background: #f0f4fa; border-radius: 16px; padding: 25px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1); border: 1px solid #e0e0e0;
        }
        .archive-card-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 20px; padding-bottom: 15px;
            border-bottom: 2px solid rgba(55,98,200,0.1);
        }
        .archive-card-title {
            font-size: 18px; font-weight: 600; color: #1e3c72;
            display: flex; align-items: center; gap: 10px;
        }
        .archive-badge {
            background: #6c757d; color: white; padding: 4px 12px;
            border-radius: 20px; font-size: 12px; font-weight: 500;
        }
        .archive-item {
            display: flex; align-items: flex-start; padding: 20px; margin-bottom: 15px;
            background: rgba(255,255,255,0.7); border-radius: 12px;
            border: 1px solid rgba(55,98,200,0.1); transition: all 0.3s ease;
        }
        .archive-item:hover {
            background: rgba(55,98,200,0.05); transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(55,98,200,0.1);
        }
        .archive-icon {
            width: 50px; height: 50px; background: linear-gradient(135deg,#6c757d,#495057);
            border-radius: 12px; display: flex; align-items: center; justify-content: center;
            color: white; font-size: 20px; margin-right: 20px; flex-shrink: 0;
        }
        .archive-content { flex: 1; }
        .archive-title { font-size: 16px; font-weight: 600; color: #333; margin-bottom: 8px; }
        .archive-meta { display: flex; gap: 20px; margin-bottom: 12px; flex-wrap: wrap; }
        .meta-item { display: flex; align-items: center; gap: 6px; font-size: 12px; color: #666; }
        .meta-item i { color: #6c757d; }
        .archive-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px; }
        .btn-view {
            padding: 8px 16px; background: linear-gradient(135deg,#3762c8,#1e3c72);
            color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 500;
            cursor: pointer; display: flex; align-items: center; gap: 6px; transition: all 0.3s ease;
        }
        .btn-view:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(55,98,200,0.3); }
        .notification {
            position: fixed; top: 20px; right: 20px; padding: 15px 20px; border-radius: 8px;
            color: white; font-weight: 500; z-index: 10000; animation: slideIn 0.3s ease;
        }
        .notification.success { background: #28a745; }
        .notification.error { background: #dc3545; }
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        .empty-state { text-align: center; padding: 60px 20px; color: #666; }
        .empty-state i { font-size: 48px; margin-bottom: 15px; opacity: 0.4; color: #6c757d; }

        /* Modal */
        .modal-overlay {
            display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.7); z-index: 10000; align-items: center;
            justify-content: center; padding: 20px; overflow-y: auto;
        }
        .modal-overlay.active { display: flex; }
        .modal-content {
            background: white; border-radius: 16px; padding: 30px;
            max-width: 900px; width: 100%; max-height: calc(100vh - 40px);
            position: relative; box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            margin: auto; display: flex; flex-direction: column;
        }
        .modal-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 20px; padding-bottom: 15px;
            border-bottom: 2px solid rgba(55,98,200,0.1); flex-shrink: 0;
        }
        .modal-header h2 { color: #1e3c72; font-size: 24px; margin: 0; flex: 1; }
        .modal-close {
            background: none; border: none; font-size: 28px; color: #666;
            cursor: pointer; width: 35px; height: 35px; display: flex;
            align-items: center; justify-content: center; border-radius: 50%;
            transition: all 0.3s; flex-shrink: 0; margin-left: 15px;
        }
        .modal-close:hover { background: rgba(220,53,69,0.1); color: #dc3545; }
        .modal-body { overflow-y: auto; flex: 1; min-height: 0; padding-right: 10px; margin-right: -10px; }
        .modal-body::-webkit-scrollbar { width: 8px; }
        .modal-body::-webkit-scrollbar-track { background: rgba(55,98,200,0.1); border-radius: 4px; }
        .modal-body::-webkit-scrollbar-thumb { background: rgba(55,98,200,0.3); border-radius: 4px; }
        .modal-body::-webkit-scrollbar-thumb:hover { background: rgba(55,98,200,0.5); }
        .detail-row {
            display: flex; margin-bottom: 15px; padding-bottom: 15px;
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }
        .detail-label { font-weight: 600; color: #333; width: 150px; flex-shrink: 0; }
        .detail-value { color: #666; flex: 1; }
        .modal-image {
            max-width: 100%; max-height: 400px; border-radius: 8px;
            margin-top: 10px; cursor: pointer;
        }

        /* Filters section */
        .filters-section {
            background: #f0f4fa; backdrop-filter: blur(15px);
            border-radius: 16px; padding: 20px 25px; border: 1px solid rgba(55,98,200,0.1);
            box-shadow: 0 8px 32px rgba(0,0,0,0.08);
        }
        .filter-group {
            display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end;
        }
        .filter-group > div { flex: 1; min-width: 180px; }
        .filter-group .form-label {
            display: block; font-size: 13px; font-weight: 600;
            color: #1e3c72; margin-bottom: 6px;
        }
        .filter-select {
            width: 100%; padding: 10px 14px; border: 2px solid rgba(55,98,200,0.2);
            border-radius: 10px; font-size: 14px; background: white; color: #333;
            transition: all 0.3s ease; cursor: pointer;
        }
        .filter-select:focus {
            border-color: #3762c8; box-shadow: 0 0 0 3px rgba(55,98,200,0.15); outline: none;
        }
        .btn-secondary-custom {
            padding: 10px 20px; background: linear-gradient(135deg,#6c757d,#495057);
            color: white; border: none; border-radius: 10px; font-size: 14px;
            font-weight: 500; cursor: pointer; display: inline-flex; align-items: center;
            gap: 6px; width: 100%; justify-content: center;
        }
        .btn-secondary-custom:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(108,117,125,0.3); }

        .status-badge {
            display: inline-block; padding: 2px 10px; border-radius: 12px;
            font-size: 11px; font-weight: 600; text-transform: capitalize;
        }
        .status-completed { background: rgba(34,197,94,0.15); color: #22c55e; }
        .status-rejected { background: rgba(249,115,22,0.15); color: #f97316; }
        .status-cancelled { background: rgba(239,68,68,0.15); color: #ef4444; }

        @media (max-width: 768px) {
            .main-content { margin-left: 0; }
            .archive-meta { flex-direction: column; gap: 8px; }
            .detail-row { flex-direction: column; }
            .detail-label { width: 100%; margin-bottom: 5px; }
        }

        /* Dark mode */
        body.dark-mode { background: #1a1d23; }
        body.dark-mode .archive-header,
        body.dark-mode .archive-card {
            background: #22262e !important; border-color: #2d323b !important;
        }
        body.dark-mode .archive-header h1,
        body.dark-mode .archive-card-title { color: #e4e6ea !important; }
        body.dark-mode .archive-header p { color: #9ca3af !important; }
        body.dark-mode .archive-item {
            background: rgba(255,255,255,0.05) !important; border-color: #2d323b !important;
        }
        body.dark-mode .archive-item:hover { background: rgba(255,255,255,0.08) !important; }
        body.dark-mode .archive-title { color: #e4e6ea !important; }
        body.dark-mode .meta-item,
        body.dark-mode .meta-item i,
        body.dark-mode .empty-state { color: #9ca3af !important; }
        body.dark-mode .archive-card-header { border-color: #2d323b !important; }
        body.dark-mode .modal-content { background: #22262e !important; }
        body.dark-mode .modal-header h2 { color: #e4e6ea !important; }
        body.dark-mode .modal-close { color: #9ca3af !important; }
        body.dark-mode .modal-close:hover { background: rgba(220,53,69,0.2) !important; }
        body.dark-mode .detail-label { color: #e4e6ea !important; }
        body.dark-mode .detail-value { color: #9ca3af !important; }
        body.dark-mode .detail-row { border-color: #2d323b !important; }
        body.dark-mode .filters-section { background: #22262e; border-color: #2d323b; }
        body.dark-mode .filter-group .form-label { color: #e4e6ea; }
        body.dark-mode .filter-select { background: #2a2e36; color: #e4e6ea; border-color: #3a3f4a; }
    </style>
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?>">
    <?php include '../../includes/sidebar_nav.php'; ?>

    <div class="main-content">
        <div class="archive-header">
            <h1><i class="fas fa-archive"></i> Archive</h1>
            <p>View-only archive of completed and cancelled reports assigned to you</p>
        </div>

        <!-- Filters -->
        <div class="filters-section" style="margin-bottom:24px;">
            <div class="filter-group">
                <div>
                    <label class="form-label">Status Filter</label>
                    <select class="filter-select" id="statusFilter" onchange="filterReports()">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">&nbsp;</label>
                    <div>
                        <button class="btn-secondary-custom" onclick="resetFilters()">
                            <i class="fas fa-arrow-rotate-left"></i> Reset
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="archive-card">
            <div class="archive-card-header">
                <h3 class="archive-card-title">
                    <i class="fas fa-folder-open"></i>
                    Archived Reports
                    <span class="archive-badge"><?php echo count($rows); ?></span>
                </h3>
            </div>

            <?php if (count($rows) > 0): ?>
                <?php foreach ($rows as $row): ?>
                    <div class="archive-item">
                        <div class="archive-icon">
                            <i class="fas fa-file-archive"></i>
                        </div>
                        <div class="archive-content">
                            <div class="archive-title"><?php echo htmlspecialchars($row['title']); ?></div>
                            <div class="archive-meta">
                                <span class="meta-item"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($row['report_type']); ?></span>
                                <span class="meta-item"><i class="fas fa-building"></i> <?php echo htmlspecialchars($row['department']); ?></span>
                                <span class="meta-item"><i class="fas fa-flag"></i>
                                    <span class="status-badge status-<?php echo strtolower($row['status']); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $row['status']))); ?></span>
                                </span>
                                <span class="meta-item"><i class="fas fa-calendar"></i> <?php echo htmlspecialchars($row['created_at']); ?></span>
                                <span class="meta-item"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($row['location'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="archive-actions">
                                <button type="button" class="btn-view" onclick="viewArchive(<?php echo $row['id']; ?>, '<?php echo $row['_source_table'] ?? 'transport'; ?>')">
                                    <i class="fas fa-eye"></i> View
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-archive"></i>
                    <p>No archived reports assigned to you.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- View Modal -->
    <div class="modal-overlay" id="viewModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-file-alt"></i> <span id="modalTitle">Report Details</span></h2>
                <button type="button" class="modal-close" onclick="closeViewModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalBody"></div>
        </div>
    </div>

    <script>
        var archiveData = <?php echo json_encode($rows); ?>;

        function viewArchive(id, sourceTable) {
            var row = archiveData.find(function(r) { return r.id == id; });
            if (!row) return;

            document.getElementById('modalTitle').textContent = row.title || 'Report Details';

            var html = '';
            html += '<div class="detail-row"><span class="detail-label">Report ID</span><span class="detail-value">' + (row.report_id || 'N/A') + '</span></div>';
            html += '<div class="detail-row"><span class="detail-label">Title</span><span class="detail-value">' + esc(row.title) + '</span></div>';
            html += '<div class="detail-row"><span class="detail-label">Report Type</span><span class="detail-value">' + esc(row.report_type) + '</span></div>';
            html += '<div class="detail-row"><span class="detail-label">Department</span><span class="detail-value">' + esc(row.department) + '</span></div>';
            html += '<div class="detail-row"><span class="detail-label">Priority</span><span class="detail-value">' + esc(row.priority) + '</span></div>';
            html += '<div class="detail-row"><span class="detail-label">Status</span><span class="detail-value">' + esc(row.status) + '</span></div>';
            html += '<div class="detail-row"><span class="detail-label">Location</span><span class="detail-value">' + esc(row.location || 'N/A') + '</span></div>';
            html += '<div class="detail-row"><span class="detail-label">Description</span><span class="detail-value">' + esc(row.description || 'N/A') + '</span></div>';
            html += '<div class="detail-row"><span class="detail-label">Created Date</span><span class="detail-value">' + esc(row.created_date || 'N/A') + '</span></div>';
            html += '<div class="detail-row"><span class="detail-label">Due Date</span><span class="detail-value">' + esc(row.due_date || 'N/A') + '</span></div>';
            html += '<div class="detail-row"><span class="detail-label">Latitude</span><span class="detail-value">' + esc(row.latitude || 'N/A') + '</span></div>';
            html += '<div class="detail-row"><span class="detail-label">Longitude</span><span class="detail-value">' + esc(row.longitude || 'N/A') + '</span></div>';
            html += '<div class="detail-row"><span class="detail-label">Created At</span><span class="detail-value">' + esc(row.created_at || 'N/A') + '</span></div>';
            html += '<div class="detail-row"><span class="detail-label">Updated At</span><span class="detail-value">' + esc(row.updated_at || 'N/A') + '</span></div>';
            html += '<div class="detail-row"><span class="detail-label">Approved At</span><span class="detail-value">' + esc(row.approved_at || 'N/A') + '</span></div>';
            html += '<div class="detail-row"><span class="detail-label">Rejected At</span><span class="detail-value">' + esc(row.rejected_at || 'N/A') + '</span></div>';
            html += '<div class="detail-row"><span class="detail-label">Archived From</span><span class="detail-value">' + esc(row.archived_from || sourceTable || 'N/A') + '</span></div>';

            if (row.attachments) {
                try {
                    var attachments = JSON.parse(row.attachments);
                    if (Array.isArray(attachments) && attachments.length) {
                        html += '<div class="detail-row"><span class="detail-label">Attachments</span><span class="detail-value">';
                        attachments.forEach(function(a) {
                            if (a.type === 'image' && a.file_path) {
                                html += '<img src="../../' + a.file_path + '" class="modal-image" onclick="window.open(this.src,\'_blank\')" title="Click to view full size" style="max-width:100%;max-height:300px;border-radius:8px;margin-top:8px;cursor:pointer;" />';
                            }
                        });
                        html += '</span></div>';
                    }
                } catch(e) {}
            }

            document.getElementById('modalBody').innerHTML = html;
            document.getElementById('viewModal').classList.add('active');
        }

        function closeViewModal() {
            document.getElementById('viewModal').classList.remove('active');
        }

        function esc(str) {
            if (!str) return 'N/A';
            return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        document.getElementById('viewModal').addEventListener('click', function(e) {
            if (e.target === this) closeViewModal();
        });

        function filterReports() {
            const status = document.getElementById('statusFilter').value;
            const url = new URL(window.location);
            url.searchParams.set('status', status);
            window.location.href = url.toString();
        }

        function resetFilters() {
            const url = new URL(window.location);
            url.searchParams.delete('status');
            window.location.href = url.toString();
        }

        // Deep-link support: auto-open the view modal when ?focus_report_id=X is
        // present (e.g. officer clicked "View Report" from a notification).
        // The notification's focus_report_id is the original PK, which we stored
        // as original_pk in each archive row.
        (function() {
            var params = new URLSearchParams(window.location.search);
            var focusId = parseInt(params.get('focus_report_id') || '0', 10);
            if (focusId > 0) {
                var row = archiveData.find(function(r) {
                    return (r.original_pk && parseInt(r.original_pk, 10) === focusId)
                        || (r.id && parseInt(r.id, 10) === focusId);
                });
                if (row) {
                    setTimeout(function() { viewArchive(row.id, row._source_table || 'transport'); }, 300);
                }
            }
        })();
    </script>
</body>
</html>
