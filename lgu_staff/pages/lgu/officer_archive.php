<?php
require_once '../../includes/session_config.php';
require_once '../../includes/config.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/../api/progress_archive_helpers.php';

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

// Ensure archive tables/columns exist (same helpers used by admin archive).
$conn->query("CREATE TABLE IF NOT EXISTS road_transportation_reports_archive LIKE road_transportation_reports");
rgmap_archive_ensure_table();

foreach (['report_category' => "ENUM('road','transportation') DEFAULT NULL AFTER report_type",
         'report_source' => "VARCHAR(50) DEFAULT NULL AFTER report_category",
         'previous_status' => "VARCHAR(50) DEFAULT NULL",
         'archived_from' => "VARCHAR(100) DEFAULT NULL",
         'source_pk' => "INT NULL DEFAULT NULL",
         'approval_status' => "VARCHAR(50) DEFAULT NULL",
         'start_address' => "VARCHAR(100) NULL DEFAULT NULL",
         'end_address' => "VARCHAR(100) NULL DEFAULT NULL",
         'ipms_polyline_json' => "LONGTEXT NULL DEFAULT NULL"] as $col => $def) {
    $chk = $conn->query("SHOW COLUMNS FROM road_transportation_reports_archive LIKE '$col'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE road_transportation_reports_archive ADD COLUMN $col $def");
    }
}

// AJAX: progress updates for Export from the View modal (same shape as admin archive).
if (isset($_GET['ajax']) && $_GET['ajax'] === 'export_updates') {
    header('Content-Type: application/json; charset=utf-8');
    $arch_id = intval($_GET['id'] ?? 0);
    $arch_table = (string)($_GET['table'] ?? 'road_transportation_reports_archive');
    if ($arch_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid archive ID.']);
        exit;
    }
    if (!rgmap_archive_allowed_table($arch_table)) {
        $arch_table = 'road_transportation_reports_archive';
    }
    $arch_row = rgmap_archive_fetch_row($conn, $arch_table, $arch_id);
    if (!$arch_row && $arch_table !== 'road_transportation_reports_archive') {
        $arch_row = rgmap_archive_fetch_row($conn, 'road_transportation_reports_archive', $arch_id);
        $arch_table = 'road_transportation_reports_archive';
    }
    if (!$arch_row) {
        echo json_encode(['success' => false, 'message' => 'Archived report not found.']);
        exit;
    }

    // Road officers may only export road-category archives.
    $cat = strtolower((string)($arch_row['report_category'] ?? ''));
    $archived_from = (string)($arch_row['archived_from'] ?? '');
    $is_road_archive = ($cat === 'road')
        || ($arch_table === 'cimm_verification_reports_archive')
        || ($arch_table === 'ipms_road_projects_archive')
        || ($archived_from === 'cimm_verification_reports')
        || ($archived_from === 'ipms_road_projects');
    if (!$is_road_archive && $arch_table === 'road_transportation_reports_archive' && $cat === 'transportation') {
        echo json_encode(['success' => false, 'message' => 'You are not authorized to export this archived report.']);
        exit;
    }

    $updates_report_id = null;
    $ref_code = (string)($arch_row['report_id'] ?? $arch_row['reference_code'] ?? '');
    $is_cimm = (($arch_row['report_source'] ?? '') === 'external'
        || $arch_table === 'cimm_verification_reports_archive'
        || stripos($ref_code, 'REQ-') === 0
        || stripos($ref_code, 'CIMM-') === 0);

    if (!empty($arch_row['source_pk'])) {
        $updates_report_id = (int)$arch_row['source_pk'];
    } elseif ($is_cimm) {
        if (preg_match('/^(REQ|CIMM)-(\d+)$/i', $ref_code, $m)) {
            $updates_report_id = (int)$m[2];
        } elseif (is_numeric($ref_code)) {
            $updates_report_id = (int)$ref_code;
        } else {
            $cimm_stmt = $conn->prepare("SELECT id FROM cimm_verification_reports WHERE reference_code = ? LIMIT 1");
            $cimm_stmt->bind_param('s', $ref_code);
            $cimm_stmt->execute();
            $cimm_row = $cimm_stmt->get_result()->fetch_assoc();
            if ($cimm_row) $updates_report_id = (int)$cimm_row['id'];
            $cimm_stmt->close();
        }
    } else {
        if ($ref_code !== '') {
            foreach (['road_transportation_reports', 'road_maintenance_reports'] as $t) {
                $stmt = $conn->prepare("SELECT id FROM $t WHERE report_id = ? LIMIT 1");
                $stmt->bind_param('s', $ref_code);
                $stmt->execute();
                $r = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($r) { $updates_report_id = (int)$r['id']; break; }
            }
        }
        if ($updates_report_id === null) {
            $updates_report_id = (int)$arch_row['id'];
        }
    }

    if ($updates_report_id === null || $updates_report_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'No export is available for this archived report.']);
        exit;
    }

    $updates = [];
    $q = "SELECT u.*, COALESCE(us.full_name, 'LGU Staff') AS admin_name
          FROM report_updates u
          LEFT JOIN users us ON u.user_id = us.id
          WHERE u.report_id = ?
          ORDER BY u.created_at ASC";
    $stmt = $conn->prepare($q);
    $stmt->bind_param('i', $updates_report_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($upd = $res->fetch_assoc()) {
        $upd['created_at_formatted'] = date('M d, Y h:i A', strtotime($upd['created_at']));
        $media = [];
        $m_stmt = $conn->prepare("SELECT id, file_path, file_type FROM report_update_media WHERE update_id = ? ORDER BY id ASC");
        $m_stmt->bind_param('i', $upd['id']);
        $m_stmt->execute();
        $m_res = $m_stmt->get_result();
        while ($m = $m_res->fetch_assoc()) $media[] = $m;
        $m_stmt->close();
        $upd['media'] = $media;
        $updates[] = $upd;
    }
    $stmt->close();
    echo json_encode(['success' => true, 'updates' => $updates]);
    exit;
}

// Filters
$status_filter = $_GET['status'] ?? 'all';
$sort_order = $_GET['sort'] ?? 'latest';
$id_search = trim($_GET['id'] ?? '');

$where_clauses = ["report_category = 'road'"];
if ($status_filter === 'completed') {
    $where_clauses[] = "status = 'completed'";
} elseif ($status_filter === 'cancelled') {
    $where_clauses[] = "status = 'cancelled'";
} elseif ($status_filter === 'rejected') {
    $where_clauses[] = "status = 'rejected'";
} elseif ($status_filter === 'approved') {
    $where_clauses[] = "status = 'approved'";
} elseif ($status_filter === 'in-progress') {
    $where_clauses[] = "status = 'in-progress'";
} elseif ($status_filter === 'pending') {
    $where_clauses[] = "status = 'pending'";
}

if ($id_search !== '') {
    $esc_id = $conn->real_escape_string($id_search);
    $where_clauses[] = "("
        . "(archive_table = 'cimm_verification_reports_archive' AND report_id LIKE '%{$esc_id}%')"
        . " OR (archive_table = 'ipms_road_projects_archive' AND ("
            . "CAST(source_pk AS CHAR) LIKE '%{$esc_id}%'"
            . " OR report_id LIKE '%{$esc_id}%'"
        . "))"
        . " OR (archive_table = 'road_transportation_reports_archive' AND report_id LIKE '%{$esc_id}%')"
        . ")";
}

$where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
$order_dir = ($sort_order === 'earliest') ? 'ASC' : 'DESC';

$source_labels = [
    'lgu'            => 'LGU Monitoring',
    'citizen'        => 'Citizen',
    'cimm'           => 'CIMM',
    'infrastructure' => 'Infrastructure',
];

// All archived road reports (LGU/Citizen road + CIMM + IPMS), view-only for RMO.
$archive_from_sql = rgmap_archive_union_sql(true, true);
$count_result = $conn->query("SELECT COUNT(*) AS total FROM $archive_from_sql $where_sql");
$total_archives = $count_result ? (int)$count_result->fetch_assoc()['total'] : 0;

$sql = "SELECT * FROM $archive_from_sql $where_sql ORDER BY created_at $order_dir LIMIT 500";
$archives = $conn->query($sql);
$rows = [];
if ($archives) {
    while ($r = $archives->fetch_assoc()) {
        $rows[] = $r;
    }
} else {
    error_log('officer_archive.php union query failed: ' . $conn->error);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archive | LGU Staff</title>
    <link rel="icon" type="image/png" href="../../assets/img/infra-gov-logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/theme-tokens.css">
    <link rel="stylesheet" href="../../css/theme-utilities.css">
    <link rel="stylesheet" href="../../css/sidebar.css?v=6">
    <link rel="stylesheet" href="../../../styles/transition.css">
    <?php if (!empty($_SESSION['darkmode'])): ?><link rel="stylesheet" href="../../css/dark-mode.css"><?php endif; ?>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { background: #f6f7fb; min-height: 100vh; color: #0f172a; }
        html { scroll-behavior: smooth; }
        .main-content {
            margin-left: 250px;
            margin-right: 0;
            max-width: none;
            width: auto;
            box-sizing: border-box;
            padding: 36px 36px 56px;
            position: relative;
            z-index: 1;
            overflow-x: hidden;
        }
        .arch-shell {
            width: 100%;
            max-width: 1040px;
            margin-left: auto;
            margin-right: auto;
        }
        .archive-header {
            background: #fff;
            padding: 20px 22px 18px;
            border-radius: 16px;
            margin-bottom: 14px;
            box-shadow: 0 4px 18px rgba(15, 23, 42, 0.06);
            border: 1px solid #e9edf3;
            display: flex;
            align-items: flex-start;
            gap: 14px;
        }
        .archive-header .header-icon {
            flex: 0 0 44px;
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: #eef2ff;
            color: #4f46e5;
            font-size: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .archive-header-text { flex: 1; min-width: 0; }
        .archive-header h1 {
            color: #0f172a;
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 4px;
            letter-spacing: -0.02em;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .archive-header h1 i { display: none; }
        .archive-header-count {
            background: #4f46e5;
            color: #fff;
            font-size: 12px;
            font-weight: 600;
            padding: 2px 10px;
            border-radius: 999px;
            line-height: 1.6;
        }
        .archive-header p { color: #64748b; font-size: 13px; margin: 0; line-height: 1.45; }
        .archive-card {
            background: #fff;
            border-radius: 16px;
            padding: 8px 10px 12px;
            box-shadow: 0 4px 18px rgba(15, 23, 42, 0.06);
            border: 1px solid #e9edf3;
        }
        .archive-card-header {
            display: flex; justify-content: space-between; align-items: center;
            margin: 0 0 8px;
            padding: 12px 12px 10px;
            border-bottom: 1px solid #eef2f7;
        }
        .archive-card-title {
            font-size: 12px; font-weight: 700; color: #64748b;
            display: flex; align-items: center; gap: 8px;
            text-transform: uppercase; letter-spacing: 0.06em;
        }
        .archive-card-title > i {
            width: 26px; height: 26px; border-radius: 8px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 11px;
            background: #eef2ff;
            color: #4f46e5;
        }
        .archive-badge {
            background: #eef1f6; color: #64748b;
            padding: 2px 9px; border-radius: 999px;
            font-size: 11px; font-weight: 700;
            text-transform: none; letter-spacing: 0;
        }
        .archive-item {
            display: flex; align-items: flex-start; gap: 14px;
            padding: 16px 14px; margin-bottom: 8px;
            background: #fff; border-radius: 14px;
            border: 1px solid #e9edf3;
            border-left: 4px solid #4f46e5;
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
        }
        .archive-item:last-child { margin-bottom: 0; }
        .archive-item:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.08);
            border-color: #e2e8f0;
            border-left-color: #4f46e5;
        }
        .archive-icon {
            width: 40px; height: 40px;
            background: #f1f5f9;
            border-radius: 11px; display: flex; align-items: center; justify-content: center;
            color: #64748b; font-size: 15px; flex-shrink: 0;
        }
        .archive-content { flex: 1; min-width: 0; }
        .archive-title-row {
            display: flex; align-items: flex-start; justify-content: space-between;
            gap: 12px; margin-bottom: 8px; flex-wrap: wrap;
        }
        .archive-title {
            font-size: 15px; font-weight: 600; color: #0f172a;
            line-height: 1.35; margin: 0; flex: 1; min-width: 180px;
        }
        .archive-meta { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }
        .meta-item {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 12px; color: #64748b;
            background: #f8fafc; border: 1px solid #eef2f7;
            padding: 4px 10px; border-radius: 999px;
        }
        .meta-item i { color: #94a3b8; font-size: 11px; }
        .archive-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 2px; }
        .btn-view {
            padding: 8px 14px; background: #4f46e5; color: white; border: none;
            border-radius: 9px; font-size: 12px; font-weight: 600;
            cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
            transition: background 0.2s, box-shadow 0.2s, transform 0.2s;
            font-family: 'Poppins', sans-serif;
            box-shadow: 0 2px 8px rgba(79, 70, 229, 0.22);
        }
        .btn-view:hover { background: #4338ca; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(79,70,229,0.3); }
        .empty-state { text-align: center; padding: 64px 24px; color: #94a3b8; }
        .empty-state i {
            width: 64px; height: 64px; border-radius: 18px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 26px; margin-bottom: 16px; color: #94a3b8;
            background: #f1f5f9; border: 1px solid #e9edf3;
        }
        .empty-state p { font-size: 14px; color: #64748b; margin: 0; }
        .empty-state-sub { font-size: 12px; color: #94a3b8; margin-top: 6px; }

        /* View Details Modal (mirrors road_transportation_monitoring.php rm modal) */
        .rm-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.45);
            z-index: 10000;
            align-items: center;
            justify-content: center;
            padding: 20px;
            box-sizing: border-box;
            overflow-y: auto;
            backdrop-filter: blur(4px);
        }

        .rm-modal-overlay.active {
            display: flex;
        }

        .rm-modal-content {
            background: #fff;
            border-radius: 16px;
            max-width: 860px;
            width: 100%;
            max-height: calc(100vh - 40px);
            position: relative;
            box-shadow: 0 16px 48px rgba(15, 23, 42, 0.18);
            margin: auto;
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
            border: 1px solid #e9edf3;
        }

        .rm-modal-header {
            background: white;
            border-radius: 16px 16px 0 0;
            padding: 20px 22px 16px;
            border-bottom: 1px solid #eef2f7;
            flex-shrink: 0;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .rm-modal-header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .rm-modal-title-area {
            flex: 1;
            min-width: 0;
        }

        .rm-modal-report-id {
            font-size: 13px;
            color: #4f46e5;
            font-weight: 600;
            letter-spacing: 0.3px;
            margin-bottom: 4px;
        }

        .rm-modal-title {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 10px 0;
            line-height: 1.3;
        }

        .rm-modal-badges {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .rm-modal-close {
            background: #f1f5f9;
            border: none;
            font-size: 18px;
            color: #64748b;
            cursor: pointer;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            transition: background 0.2s, color 0.2s;
            flex-shrink: 0;
            margin-left: 15px;
        }

        .rm-modal-close:hover {
            background: #fee2e2;
            color: #dc2626;
        }

        .rm-modal-body {
            overflow-y: auto;
            flex: 1;
            min-height: 0;
            padding: 20px 22px;
        }

        .rm-modal-body::-webkit-scrollbar { width: 8px; }
        .rm-modal-body::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
        .rm-modal-body::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .rm-modal-body::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        .rm-modal-section {
            background: #f8fafc;
            border-radius: 12px;
            padding: 16px 18px;
            margin-bottom: 14px;
            border: 1px solid #e9edf3;
        }

        .rm-modal-section-title {
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 14px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eef2f7;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .rm-modal-section-title i {
            color: #4f46e5;
            font-size: 15px;
        }

        .rm-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .rm-info-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 6px 0;
        }

        .rm-info-icon {
            width: 28px;
            height: 28px;
            background: #eef2ff;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #4f46e5;
            font-size: 13px;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .rm-info-label {
            font-size: 11px;
            color: #6b7280;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 2px;
        }

        .rm-info-value {
            font-size: 14px;
            color: #1f2937;
            font-weight: 500;
            line-height: 1.4;
            word-break: break-word;
        }

        .rm-info-value-full {
            grid-column: 1 / -1;
        }

        .rm-description-text {
            font-size: 14px;
            color: #374151;
            line-height: 1.7;
            padding: 8px 0;
            white-space: pre-wrap;
        }

        .rm-modal-footer {
            background: white;
            border-radius: 0 0 16px 16px;
            padding: 16px 28px;
            border-top: 1px solid #eef2f7;
            flex-shrink: 0;
            display: flex;
            justify-content: flex-end;
        }

        .rm-modal-btn-close {
            padding: 10px 24px;
            background: #eef2ff;
            color: #4f46e5;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            transition: background 0.2s;
        }

        .rm-modal-btn-close:hover {
            background: #e0e7ff;
        }

        .rm-modal-btn-export {
            padding: 10px 20px;
            background: #4f46e5;
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-right: 10px;
            transition: background 0.2s, box-shadow 0.2s;
            box-shadow: 0 2px 8px rgba(79, 70, 229, 0.2);
        }
        .rm-modal-btn-export:hover {
            background: #4338ca;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.28);
        }
        .rm-modal-footer {
            background: white;
            border-radius: 0 0 16px 16px;
            padding: 16px 28px;
            border-top: 1px solid #eef2f7;
            flex-shrink: 0;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        .source-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .source-lgu,
        .source-citizen,
        .source-cimm,
        .source-infrastructure { background: #eef1f6; color: #475569; }
        .notification {
            position: fixed; top: 20px; right: 20px; padding: 14px 18px; border-radius: 10px;
            color: white; font-weight: 500; z-index: 10000;
            box-shadow: 0 8px 24px rgba(0,0,0,0.18);
        }
        @media (max-width: 640px) {
            .rm-info-grid { grid-template-columns: 1fr; }
            .rm-modal-header { padding: 18px 16px; }
            .rm-modal-body { padding: 16px; }
            .rm-modal-content { max-width: 100%; border-radius: 0; }
            .rm-modal-overlay { padding: 0; }
        }
        .filters-section {
            background: #fff;
            border-radius: 16px;
            padding: 16px 18px;
            border: 1px solid #e9edf3;
            box-shadow: 0 4px 18px rgba(15, 23, 42, 0.06);
            margin-bottom: 14px;
        }
        .filter-group {
            display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;
        }
        .filter-group > div { flex: 1; min-width: 180px; }
        .filter-group > div.filter-actions { flex: 0 0 auto; min-width: 0; }
        .filter-group .form-label {
            display: block; font-size: 11px; font-weight: 600;
            color: #64748b; margin-bottom: 6px;
            text-transform: uppercase; letter-spacing: 0.04em;
        }
        .filter-select {
            width: 100%; padding: 9px 12px; border: 1px solid #e9edf3;
            border-radius: 10px; font-size: 13px; background: white; color: #0f172a;
            font-family: 'Poppins', sans-serif;
            transition: border-color 0.2s, box-shadow 0.2s; cursor: pointer;
        }
        .filter-select:focus {
            border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.12); outline: none;
        }
        .btn-secondary-custom {
            padding: 9px 16px; background: #fff; color: #475569;
            border: 1px solid #e9edf3; border-radius: 10px; font-size: 13px;
            font-weight: 600; cursor: pointer; display: inline-flex; align-items: center;
            gap: 6px; width: auto; justify-content: center; white-space: nowrap;
            font-family: 'Poppins', sans-serif;
        }
        .btn-secondary-custom:hover { background: #f8fafc; border-color: #cbd5e1; }

        .status-badge {
            display: inline-block; padding: 2px 10px; border-radius: 12px;
            font-size: 11px; font-weight: 600; text-transform: capitalize;
        }
        .status-completed,
        .status-approved,
        .status-pending,
        .status-in-progress,
        .status-rejected,
        .status-cancelled { background: #eef1f6; color: #475569; }

        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 20px 14px 40px; }
            .arch-shell { max-width: 100%; }
            .filter-group > div { flex: 1 1 100%; }
            .filter-group > div.filter-actions { flex: 1 1 100%; }
            .btn-secondary-custom { width: 100%; }
        }

        /* Dark mode */
        body.dark-mode { background: #12141a; }
        body.dark-mode .archive-header,
        body.dark-mode .archive-card,
        body.dark-mode .filters-section {
            background: #1a1d24 !important; border-color: #2d323b !important;
        }
        body.dark-mode .archive-header .header-icon {
            background: rgba(129, 140, 248, 0.2) !important;
            color: #a5b4fc !important;
        }
        body.dark-mode .archive-header h1,
        body.dark-mode .archive-card-title,
        body.dark-mode .archive-title { color: #e4e6ea !important; }
        body.dark-mode .archive-header-count { background: #6366f1 !important; color: #fff !important; }
        body.dark-mode .archive-header p { color: #9ca3af !important; }
        body.dark-mode .archive-item {
            background: #1a1d24 !important; border-color: #2d323b !important;
            border-left-color: #6366f1 !important;
        }
        body.dark-mode .archive-item:hover { background: #22262e !important; border-left-color: #6366f1 !important; }
        body.dark-mode .archive-title { color: #e4e6ea !important; }
        body.dark-mode .meta-item {
            background: #22262e !important; border-color: #2d323b !important; color: #9ca3af !important;
        }
        body.dark-mode .meta-item i,
        body.dark-mode .empty-state,
        body.dark-mode .empty-state-sub { color: #9ca3af !important; }
        body.dark-mode .archive-card-header { border-color: #2d323b !important; }
        body.dark-mode .archive-badge { background: rgba(148,163,184,0.18) !important; color: #94a3b8 !important; }
        body.dark-mode .archive-icon { background: rgba(148,163,184,0.12) !important; color: #94a3b8 !important; }
        body.dark-mode .empty-state i {
            background: #22262e !important; border-color: #2d323b !important; color: #6b7280 !important;
        }
        body.dark-mode .btn-view { background: #6366f1 !important; }
        body.dark-mode .rm-modal-content { background: #1a1d24 !important; border-color: #2d323b !important; }
        body.dark-mode .rm-modal-header { background: #1a1d24 !important; border-bottom-color: #2d323b !important; }
        body.dark-mode .rm-modal-title { color: #e4e6ea !important; }
        body.dark-mode .rm-modal-report-id { color: #a5b4fc !important; }
        body.dark-mode .rm-modal-close { background: #22262e !important; color: #9ca3af !important; }
        body.dark-mode .rm-modal-close:hover { background: rgba(220,53,69,0.2) !important; color: #ef4444 !important; }
        body.dark-mode .rm-modal-section { background: #22262e !important; border-color: #2d323b !important; }
        body.dark-mode .rm-modal-section-title { color: #e4e6ea !important; border-bottom-color: #2d323b !important; }
        body.dark-mode .rm-info-icon { background: rgba(129,140,248,0.15) !important; color: #a5b4fc !important; }
        body.dark-mode .rm-info-value { color: #d1d5db !important; }
        body.dark-mode .rm-info-label { color: #9ca3af !important; }
        body.dark-mode .rm-description-text { color: #cbd5e1 !important; }
        body.dark-mode .rm-modal-footer { background: #1a1d24 !important; border-top-color: #2d323b !important; }
        body.dark-mode .rm-modal-btn-close { background: rgba(129,140,248,0.15) !important; color: #a5b4fc !important; }
        body.dark-mode .rm-modal-btn-export { background: #6366f1 !important; color: #fff !important; }
        body.dark-mode .filter-group .form-label { color: #9ca3af; }
        body.dark-mode .filter-select { background: #22262e; color: #e4e6ea; border-color: #374151; }
        body.dark-mode .btn-secondary-custom { background: #22262e; color: #e4e6ea; border-color: #374151; }
    </style>
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?>">
    <?php include '../../includes/sidebar_nav.php'; ?>

    <div class="main-content">
        <div class="arch-shell">
        <div class="archive-header">
            <div class="header-icon"><i class="fas fa-archive"></i></div>
            <div class="archive-header-text">
                <h1>
                    Archive
                    <span class="archive-header-count"><?php echo (int)$total_archives; ?></span>
                </h1>
                <p>View-only archive of all road reports — open details and export from the View modal.</p>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-section">
            <div class="filter-group">
                <div>
                    <label class="form-label">Status</label>
                    <select class="filter-select" id="statusFilter" onchange="filterReports()">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="in-progress" <?php echo $status_filter === 'in-progress' ? 'selected' : ''; ?>>In Progress</option>
                        <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Sort</label>
                    <select class="filter-select" id="sortFilter" onchange="filterReports()">
                        <option value="latest" <?php echo $sort_order === 'latest' ? 'selected' : ''; ?>>Newest first</option>
                        <option value="earliest" <?php echo $sort_order === 'earliest' ? 'selected' : ''; ?>>Oldest first</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Search ID</label>
                    <input type="text" class="filter-select" id="idSearch" placeholder="Report ID…" value="<?php echo htmlspecialchars($id_search); ?>" onkeyup="if(event.key === 'Enter') filterReports()">
                </div>
                <div class="filter-actions">
                    <label class="form-label">&nbsp;</label>
                    <button type="button" class="btn-secondary-custom" onclick="resetFilters()">
                        <i class="fas fa-arrow-rotate-left"></i> Reset
                    </button>
                </div>
            </div>
        </div>

        <div class="archive-card">
            <div class="archive-card-header">
                <h3 class="archive-card-title">
                    <i class="fas fa-folder-open"></i>
                    Archived Road Reports
                    <span class="archive-badge"><?php echo (int)$total_archives; ?></span>
                </h3>
            </div>

            <?php if (count($rows) > 0): ?>
                <?php foreach ($rows as $row):
                    $status_slug = strtolower(str_replace('_', '-', (string)($row['status'] ?? '')));
                    $created_display = !empty($row['created_at']) ? date('M d, Y · g:i A', strtotime($row['created_at'])) : '—';
                    $archive_table = $row['archive_table'] ?? 'road_transportation_reports_archive';
                    $src_key = $row['source_system'] ?? '';
                ?>
                    <div class="archive-item status-<?php echo htmlspecialchars($status_slug); ?>">
                        <div class="archive-icon">
                            <i class="fas fa-box-archive"></i>
                        </div>
                        <div class="archive-content">
                            <div class="archive-title-row">
                                <h4 class="archive-title"><?php echo htmlspecialchars($row['title'] ?? 'Untitled'); ?></h4>
                                <span class="status-badge status-<?php echo htmlspecialchars($status_slug); ?>"><?php echo htmlspecialchars(ucfirst(str_replace(['_', '-'], ' ', (string)$row['status']))); ?></span>
                            </div>
                            <div class="archive-meta">
                                <?php if (!empty($row['report_id'])): ?>
                                <span class="meta-item"><i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($row['report_id']); ?></span>
                                <?php endif; ?>
                                <span class="meta-item"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($row['report_type'] ?? '—'); ?></span>
                                <?php if (!empty($row['department'])): ?>
                                <span class="meta-item"><i class="fas fa-building"></i> <?php echo htmlspecialchars($row['department']); ?></span>
                                <?php endif; ?>
                                <span class="meta-item"><i class="fas fa-calendar"></i> <?php echo htmlspecialchars($created_display); ?></span>
                                <span class="meta-item"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($row['location'] ?? 'N/A'); ?></span>
                                <span class="meta-item"><i class="fas fa-sitemap"></i>
                                    <span class="source-badge source-<?php echo htmlspecialchars($src_key); ?>"><?php echo htmlspecialchars($source_labels[$src_key] ?? ($src_key ?: 'Unknown')); ?></span>
                                </span>
                            </div>
                            <div class="archive-actions">
                                <button type="button" class="btn-view" onclick="viewArchive(<?php echo (int)$row['id']; ?>, '<?php echo htmlspecialchars($archive_table, ENT_QUOTES); ?>')">
                                    <i class="fas fa-eye"></i> View
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-archive"></i>
                    <p>No archived road reports found</p>
                    <p class="empty-state-sub">Try adjusting filters or check back after reports are archived.</p>
                </div>
            <?php endif; ?>
        </div>
        </div>
    </div>

    <!-- View Details Modal (rm-modal design, mirrors road_transportation_monitoring.php) -->
    <div class="rm-modal-overlay" id="viewModal" onclick="if(event.target===this)closeViewModal()">
        <div class="rm-modal-content">
            <div class="rm-modal-header">
                <div class="rm-modal-header-top">
                    <div class="rm-modal-title-area">
                        <div class="rm-modal-report-id" id="rm-report-id">—</div>
                        <h3 class="rm-modal-title" id="rm-title">—</h3>
                        <div class="rm-modal-badges" id="rm-badges"></div>
                    </div>
                    <button type="button" class="rm-modal-close" onclick="closeViewModal()">&times;</button>
                </div>
            </div>
            <div class="rm-modal-body">
                <div class="rm-modal-section">
                    <div class="rm-modal-section-title"><i class="fas fa-info-circle"></i> Report Information</div>
                    <div class="rm-info-grid" id="rm-report-grid"></div>
                </div>
                <div class="rm-modal-section">
                    <div class="rm-modal-section-title"><i class="fas fa-building"></i> Source &amp; Department</div>
                    <div class="rm-info-grid" id="rm-source-grid"></div>
                </div>
                <div class="rm-modal-section">
                    <div class="rm-modal-section-title"><i class="fas fa-map-marker-alt"></i> Location</div>
                    <div class="rm-info-grid" id="rm-location-grid"></div>
                </div>
                <div class="rm-modal-section">
                    <div class="rm-modal-section-title"><i class="fas fa-align-left"></i> Description</div>
                    <div class="rm-description-text" id="rm-description">—</div>
                </div>
                <div class="rm-modal-section">
                    <div class="rm-modal-section-title"><i class="fas fa-paperclip"></i> Attachments</div>
                    <div id="rm-attachments"></div>
                </div>
                <div class="rm-modal-section">
                    <div class="rm-modal-section-title"><i class="fas fa-clock"></i> Timeline &amp; Updates</div>
                    <div class="rm-info-grid" id="rm-timeline-grid"></div>
                </div>
            </div>
            <div class="rm-modal-footer">
                <button type="button" class="rm-modal-btn-export" onclick="exportArchivedReport()">
                    <i class="fas fa-file-export"></i> Export
                </button>
                <button type="button" class="rm-modal-btn-close" onclick="closeViewModal()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <!-- Hidden timeline container used by progress-updates export -->
    <div id="updatesTimeline" style="display:none;"></div>

    <script src="../../js/progress-updates.js?v=<?php echo @filemtime(__DIR__ . '/../../js/progress-updates.js') ?: time(); ?>"></script>
    <script src="../../js/progress-updates-common.js?v=<?php echo @filemtime(__DIR__ . '/../../js/progress-updates-common.js') ?: time(); ?>"></script>

    <script>
        var archiveData = <?php
            // Normalize CIMM evidence URLs for the View modal attachments renderer.
            foreach ($rows as &$__ar) {
                if (($__ar['archived_from'] ?? '') === 'cimm_verification_reports'
                    || ($__ar['archive_table'] ?? '') === 'cimm_verification_reports_archive') {
                    $ev = json_decode((string)($__ar['attachments'] ?? '[]'), true);
                    if (is_array($ev) && isset($ev[0]) && is_string($ev[0])) {
                        $items = [];
                        foreach ($ev as $url) {
                            if (is_string($url) && $url !== '') {
                                $items[] = ['type' => 'image', 'file_path' => $url];
                            }
                        }
                        $__ar['attachments'] = json_encode($items, JSON_UNESCAPED_SLASHES);
                    }
                }
            }
            unset($__ar);
            echo json_encode($rows);
        ?>;
        var currentArchiveRow = null;
        var currentUpdatesReportId = null;
        var currentUpdatesReportDetails = null;

        function viewArchive(id, archiveTable) {
            archiveTable = archiveTable || 'road_transportation_reports_archive';
            var row = archiveData.find(function(r) {
                return r.id == id && (r.archive_table || 'road_transportation_reports_archive') === archiveTable;
            });
            if (!row) {
                row = archiveData.find(function(r) { return r.id == id; });
            }
            if (!row) return;
            currentArchiveRow = row;

            // Header
            document.getElementById('rm-report-id').textContent = 'Report #' + (row.report_id || '—');
            document.getElementById('rm-title').textContent = row.title || '—';

            // Status / priority badges
            var statusStyles = {
                'pending': { bg: 'rgba(251,191,36,0.15)', color: '#b45309' },
                'approved': { bg: 'rgba(34,197,94,0.15)', color: '#15803d' },
                'in-progress': { bg: 'rgba(59,130,246,0.15)', color: '#1d4ed8' },
                'completed': { bg: 'rgba(34,197,94,0.15)', color: '#15803d' },
                'resolved': { bg: 'rgba(34,197,94,0.15)', color: '#15803d' },
                'rejected': { bg: 'rgba(249,115,22,0.15)', color: '#c2410c' },
                'cancelled': { bg: 'rgba(239,68,68,0.15)', color: '#b91c1c' }
            };
            var priorityStyles = {
                'high': { bg: 'rgba(239,68,68,0.15)', color: '#b91c1c' },
                'medium': { bg: 'rgba(251,191,36,0.15)', color: '#b45309' },
                'low': { bg: 'rgba(34,197,94,0.15)', color: '#15803d' }
            };
            var sourceLabels = { lgu: 'LGU Monitoring', citizen: 'Citizen', cimm: 'CIMM', infrastructure: 'Infrastructure' };
            var st = (row.status || 'pending').toLowerCase();
            var pp = (row.priority || 'medium').toLowerCase();
            var ss = statusStyles[st] || { bg: 'rgba(107,114,128,0.15)', color: '#374151' };
            var ps = priorityStyles[pp] || { bg: 'rgba(107,114,128,0.15)', color: '#374151' };
            var src = row.source_system || '';
            var badgesHtml = rmBadge((row.status || '—'), ss.bg, ss.color)
                + rmBadge((row.priority || '—'), ps.bg, ps.color);
            if (sourceLabels[src]) {
                badgesHtml += rmBadge(sourceLabels[src], 'rgba(55,98,200,0.12)', '#3762c8');
            }
            document.getElementById('rm-badges').innerHTML = badgesHtml;

            // Report Information
            var reportGrid = '';
            reportGrid += rmInfoItem('folder', 'Report Type', row.report_type);
            reportGrid += rmInfoItem('tag', 'Category', row.report_category);
            reportGrid += rmInfoItem('building', 'Department', row.department);
            reportGrid += rmInfoItem('calendar-alt', 'Submitted', row.created_at);
            reportGrid += rmInfoItem('calendar-check', 'Updated', row.updated_at);
            if (row.approved_at) reportGrid += rmInfoItem('thumbs-up', 'Approved', row.approved_at);
            if (row.rejected_at) reportGrid += rmInfoItem('thumbs-down', 'Rejected', row.rejected_at);
            if (row.completed_at) reportGrid += rmInfoItem('check-circle', 'Completed', row.completed_at);
            document.getElementById('rm-report-grid').innerHTML = reportGrid || '<div class="rm-info-value">—</div>';

            // Source & Department
            var sourceGrid = '';
            sourceGrid += rmInfoItem('sitemap', 'Source System', sourceLabels[src] || src || '—');
            sourceGrid += rmInfoItem('history', 'Archived From', row.archived_from || archiveTable);
            sourceGrid += rmInfoItem('undo', 'Previous Status', row.previous_status);
            if (row.source_pk) sourceGrid += rmInfoItem('fingerprint', 'Original ID', row.source_pk);
            document.getElementById('rm-source-grid').innerHTML = sourceGrid || '<div class="rm-info-value">—</div>';

            // Location
            var locationGrid = '';
            var locVal = row.location || '—';
            if (row.latitude && row.longitude && row.latitude != 0 && row.longitude != 0) {
                locVal += '<br><a href="https://www.openstreetmap.org/?mlat=' + row.latitude + '&mlon=' + row.longitude + '&zoom=15" target="_blank" style="color:#3762c8;font-size:12px;text-decoration:none;"><i class="fas fa-external-link-alt" style="font-size:10px;"></i> View on Map (' + row.latitude + ', ' + row.longitude + ')</a>';
            }
            locationGrid += '<div class="rm-info-item rm-info-value-full"><div class="rm-info-icon"><i class="fas fa-map-marker-alt"></i></div><div><div class="rm-info-label">Location</div><div class="rm-info-value">' + locVal + '</div></div></div>';
            document.getElementById('rm-location-grid').innerHTML = locationGrid;

            // Description
            document.getElementById('rm-description').textContent = row.description || 'No description provided.';

            // Attachments
            var images = [];
            var seenPaths = {};
            if (row.image_path && row.image_path !== '0' && row.image_path !== 'null') {
                images.push('../../' + row.image_path);
                seenPaths[row.image_path] = true;
            }
            if (row.attachments) {
                try {
                    var attachments = typeof row.attachments === 'string' ? JSON.parse(row.attachments) : row.attachments;
                    if (Array.isArray(attachments)) {
                        attachments.forEach(function(a) {
                            var path = (typeof a === 'string') ? a : (a.file_path || '');
                            if (!path || seenPaths[path]) return;
                            if (typeof a === 'object' && a.type && a.type !== 'image') return;
                            var isRemote = /^https?:\/\//i.test(path);
                            images.push(isRemote ? path : ('../../' + path.replace(/^\//, '')));
                            seenPaths[path] = true;
                        });
                    }
                } catch(e) {}
            }
            var attachHtml = '';
            if (images.length > 0) {
                attachHtml = '<div style="display:flex;gap:12px;flex-wrap:wrap;">';
                images.forEach(function(path) {
                    attachHtml += '<div style="border-radius:8px;overflow:hidden;max-width:200px;"><img src="' + path + '" alt="Archived Photo" style="width:100%;height:auto;cursor:pointer;" onclick="window.open(this.src,\'_blank\')" loading="lazy" onerror="this.style.display=\'none\'"></div>';
                });
                attachHtml += '</div>';
            } else {
                attachHtml = '<div style="padding:8px 0;color:#9ca3af;font-size:14px;">No attachments.</div>';
            }
            document.getElementById('rm-attachments').innerHTML = attachHtml;

            var timelineEl = document.getElementById('rm-timeline-grid');
            if (timelineEl) {
                timelineEl.innerHTML = '<div style="padding:8px 0;color:#9ca3af;font-size:13px;">Open Export to download progress updates for this report.</div>';
            }

            document.getElementById('viewModal').classList.add('active');
        }

        function rmBadge(text, bg, color) {
            if (!text || text === '—' || text === 'N/A') return '';
            return '<span style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;background:' + bg + ';color:' + color + ';">' + esc(String(text)) + '</span>';
        }

        function rmInfoItem(icon, label, value) {
            var displayVal = (value && value !== '—' && value !== null) ? value : '—';
            return '<div class="rm-info-item"><div class="rm-info-icon"><i class="fas fa-' + icon + '"></i></div><div><div class="rm-info-label">' + label + '</div><div class="rm-info-value">' + displayVal + '</div></div></div>';
        }

        function closeViewModal() {
            document.getElementById('viewModal').classList.remove('active');
            currentArchiveRow = null;
        }

        function esc(str) {
            if (!str) return 'N/A';
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function showNotification(message, type) {
            type = type || 'info';
            var notification = document.createElement('div');
            notification.className = 'notification ' + type;
            notification.textContent = message;
            notification.style.background = type === 'success' ? '#10b981' : (type === 'error' ? '#dc3545' : '#3762c8');
            document.body.appendChild(notification);
            setTimeout(function() {
                notification.style.opacity = '0';
                notification.style.transition = 'opacity 0.3s ease';
                setTimeout(function() { notification.remove(); }, 300);
            }, 3000);
        }

        function exportArchivedReport() {
            if (!currentArchiveRow) {
                showNotification('No archived report is selected to export.', 'error');
                return;
            }
            if (typeof exportUpdatesToExcel !== 'function' || typeof renderTimeline !== 'function') {
                showNotification('Export scripts failed to load. Please refresh and try again.', 'error');
                return;
            }
            var archiveId = currentArchiveRow.id;
            var archiveTable = encodeURIComponent(currentArchiveRow.archive_table || 'road_transportation_reports_archive');
            fetch('?ajax=export_updates&id=' + archiveId + '&table=' + archiveTable)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data && data.success) {
                        if (!data.updates || data.updates.length === 0) {
                            showNotification('This archived report has no progress updates available to export.', 'error');
                            return;
                        }
                        currentUpdatesReportId = currentArchiveRow.report_id || currentArchiveRow.id;
                        currentUpdatesReportDetails = currentArchiveRow;
                        renderTimeline(data.updates);
                        exportUpdatesToExcel();
                    } else {
                        showNotification((data && data.message) ? data.message : 'No export is available for this archived report.', 'error');
                    }
                })
                .catch(function(e) {
                    console.error('Archive export error', e);
                    showNotification('Failed to prepare the export for this archived report.', 'error');
                });
        }

        document.getElementById('viewModal').addEventListener('click', function(e) {
            if (e.target === this) closeViewModal();
        });

        function filterReports() {
            const status = document.getElementById('statusFilter').value;
            const sort = document.getElementById('sortFilter') ? document.getElementById('sortFilter').value : 'latest';
            const idSearch = document.getElementById('idSearch') ? document.getElementById('idSearch').value.trim() : '';
            const url = new URL(window.location);
            url.searchParams.set('status', status);
            url.searchParams.set('sort', sort);
            if (idSearch) url.searchParams.set('id', idSearch);
            else url.searchParams.delete('id');
            window.location.href = url.toString();
        }

        function resetFilters() {
            const url = new URL(window.location);
            url.searchParams.delete('status');
            url.searchParams.delete('sort');
            url.searchParams.delete('id');
            window.location.href = url.toString();
        }

        // Deep-link support: auto-open the view modal when ?focus_report_id=X is
        // present (e.g. officer clicked "View Report" from a notification).
        (function() {
            var params = new URLSearchParams(window.location.search);
            var focusId = parseInt(params.get('focus_report_id') || '0', 10);
            if (!focusId || !archiveData.length) return;
            var row = archiveData.find(function(r) {
                return parseInt(r.source_pk || 0, 10) === focusId
                    || parseInt(r.id || 0, 10) === focusId
                    || String(r.report_id || '') === String(focusId);
            });
            if (row) {
                setTimeout(function() {
                    viewArchive(row.id, row.archive_table || 'road_transportation_reports_archive');
                }, 300);
            }
        })();
    </script>
</body>
</html>
