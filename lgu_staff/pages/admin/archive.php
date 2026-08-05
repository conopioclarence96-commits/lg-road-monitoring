<?php
require_once '../../includes/session_config.php';
require_once '../../includes/config.php';
require_once '../../includes/functions.php';

$archive_allowed_roles = ['system_admin', 'road_ops_supervisor', 'trans_ops_supervisor'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $archive_allowed_roles, true)) {
    header('Location: ../../login.php');
    exit();
}

$conn->query("CREATE TABLE IF NOT EXISTS road_transportation_reports_archive LIKE road_transportation_reports");

// Ensure archive table has the same columns as the source table
foreach (['report_category' => "ENUM('road','transportation') DEFAULT NULL AFTER report_type",
           'report_source' => "ENUM('local','external') DEFAULT 'local' AFTER report_category",
           'previous_status' => "VARCHAR(50) DEFAULT NULL",
           'archived_from' => "VARCHAR(100) DEFAULT NULL"] as $col => $def) {
    $chk = $conn->query("SHOW COLUMNS FROM road_transportation_reports_archive LIKE '$col'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE road_transportation_reports_archive ADD COLUMN $col $def");
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$source_filter = $_GET['source'] ?? 'all';
$sort_order = $_GET['sort'] ?? 'latest';
$id_search = trim($_GET['id'] ?? '');

// Source system classification. Every archived report is assigned to exactly
// one source bucket using the existing archive columns.
//   - cimm           : report_source = external (CIMM rows also carry a
//                       report_type of 'infrastructure_issue', so this is the
//                       distinguishing marker and takes precedence)
//   - infrastructure : report_type in (infrastructure_issue, maintenance, maintenance_request)
//   - lgu            : report_source = local AND created_by != 0  (LGU Monitoring)
//   - citizen        : everything else (created_by = 0 / public submissions)
$source_case = "CASE
        WHEN report_source = 'external' THEN 'cimm'
        WHEN report_type IN ('infrastructure_issue','maintenance','maintenance_request') THEN 'infrastructure'
        WHEN report_source = 'local' AND COALESCE(created_by, 0) != 0 THEN 'lgu'
        ELSE 'citizen'
    END";

// Per-source WHERE condition (matches the Source System dropdown values)
switch ($source_filter) {
    case 'lgu':
        $source_where = "report_type NOT IN ('infrastructure_issue','maintenance','maintenance_request') AND report_source = 'local' AND COALESCE(created_by, 0) != 0";
        break;
    case 'citizen':
        $source_where = "report_type NOT IN ('infrastructure_issue','maintenance','maintenance_request') AND COALESCE(created_by, 0) = 0";
        break;
    case 'cimm':
        $source_where = "report_source = 'external'";
        break;
    case 'infrastructure':
        $source_where = "report_type IN ('infrastructure_issue','maintenance','maintenance_request') AND (report_source IS NULL OR report_source != 'external')";
        break;
    default:
        $source_where = '';
        break;
}

// Build WHERE clause
$where_clauses = [];

// Status filter. The archive only ever contains completed / rejected / cancelled
// reports, so every selection (including "All Status") is restricted to those
// three statuses — any other status can never appear here.
if ($status_filter === 'completed') {
    $where_clauses[] = "status = 'completed'";
} elseif ($status_filter === 'rejected') {
    $where_clauses[] = "status = 'rejected'";
} elseif ($status_filter === 'cancelled') {
    $where_clauses[] = "status = 'cancelled'";
} else {
    // All Status
    $where_clauses[] = "status IN ('completed','rejected','cancelled')";
}

if ($source_where !== '') {
    $where_clauses[] = $source_where;
}

// ID search filter
if (!empty($id_search)) {
    $where_clauses[] = "(id = " . (int)$id_search . " OR report_id LIKE '%" . $conn->real_escape_string($id_search) . "%' OR id LIKE '%" . $conn->real_escape_string($id_search) . "%')";
}

$where_sql = '';
if (!empty($where_clauses)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
}

$order_dir = ($sort_order === 'earliest') ? 'ASC' : 'DESC';

// Total count of the *filtered* result set (used for the badge)
$count_result = $conn->query("SELECT COUNT(*) AS total FROM road_transportation_reports_archive $where_sql");
$total_archives = $count_result ? (int)$count_result->fetch_assoc()['total'] : 0;

// Display labels for the four source systems
$source_labels = [
    'lgu'            => 'LGU Monitoring',
    'citizen'        => 'Citizen',
    'cimm'           => 'CIMM',
    'infrastructure' => 'Infrastructure',
];

$sql = "SELECT *, $source_case AS source_system FROM road_transportation_reports_archive $where_sql ORDER BY created_at $order_dir";
$archives = $conn->query($sql);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'restore' && isset($_POST['archive_id'])) {
        $archive_id = (int) $_POST['archive_id'];
        $arch = $conn->prepare("SELECT * FROM road_transportation_reports_archive WHERE id = ?");
        $arch->bind_param('i', $archive_id);
        $arch->execute();
        $row = $arch->get_result()->fetch_assoc();
        if (!$row) {
            $_SESSION['archive_message'] = 'Restore failed – record not found in archive.';
            header('Location: archive.php');
            exit();
        }

        // Route the report back to the module where its last action happened.
        // archived_from records the exact live table it was moved out of, so a
        // report always returns to the exact table it came from (fall back to
        // a report_type/report_source heuristic for rows archived before the
        // column existed).
        $maintenance_types = ['routine', 'emergency', 'preventive', 'corrective', 'scheduled'];
        $archived_from = $row['archived_from'] ?? '';
        if ($archived_from === 'cimm_verification_reports') {
            $module = 'cimm';
        } elseif ($archived_from === 'road_maintenance_reports') {
            $module = 'maintenance';
        } elseif ($archived_from === 'road_transportation_reports') {
            $module = 'transport';
        } elseif (in_array(($row['report_source'] ?? ''), ['external', 'cimm'], true)) {
            $module = 'cimm';
        } elseif (in_array($row['report_type'], $maintenance_types, true)) {
            $module = 'maintenance';
        } else {
            $module = 'transport';
        }

        // Restore to its previous (pre-archive) status when it was recorded,
        // so the report reappears in the active list where its last action
        // happened instead of staying hidden with a terminal status.
        $restore_status = (!empty($row['previous_status'])) ? $row['previous_status'] : $row['status'];

        // A completed or cancelled transportation/maintenance report is
        // restored as 'approved' first so it returns to report_management.php
        // as an Approved report. It only moves to 'in-progress' once a progress
        // update is uploaded (handled in progress_update_api.php on the first
        // update).
        if ($module === 'transport' && $restore_status === 'completed') {
            $restore_status = 'approved';
        }

        // A cancelled transportation or maintenance report is brought back as
        // 'approved' first so it returns to report_management.php as an approved
        // project instead of staying cancelled and hidden; it can move to
        // in-progress afterwards through the normal flow.
        if (in_array($module, ['transport', 'maintenance'], true) && strtolower((string)($row['status'] ?? '')) === 'cancelled') {
            $restore_status = 'approved';
        }

        // A rejected CITIZEN report (transportation + local + created_by = 0) is
        // restored to 'pending' so it returns to the Pending Citizen Reports
        // section on verification_monitoring.php with its Approve/Reject buttons
        // available again — instead of coming back permanently rejected (the
        // buttons only render for 'pending' reports).
        if ($module === 'transport'
            && $restore_status === 'rejected'
            && (int)($row['created_by'] ?? 0) === 0
            && ($row['report_source'] ?? '') === 'local'
            && strtolower((string)($row['report_category'] ?? '')) === 'transportation') {
            $restore_status = 'pending';
        }

        // A rejected CIMM report is restored to 'Pending Review' (with
        // approval_status 'Approved') so it returns to the Pending Dept/CIMM
        // section on verification_monitoring.php with its Approve/Reject buttons
        // available again — that panel only lists reports whose
        // verification_status is 'Pending Review'.
        if ($module === 'cimm' && strtolower((string)$restore_status) === 'rejected') {
            $restore_status = 'Pending Review';
        }

        // A completed CIMM report is restored as 'Approved' first so it returns
        // to report_management.php, road_transportation_monitoring.php and the
        // active project list (all of which list CIMM reports whose
        // verification_status is 'Approved' or 'In Progress') instead of staying
        // finished and hidden. It only moves to 'In Progress' once a progress
        // update is uploaded.
        if ($module === 'cimm' && strtolower((string)$restore_status) === 'completed') {
            $restore_status = 'Approved';
        }

        // A cancelled CIMM report is restored as 'Approved' first so it returns
        // to report_management.php, road_transportation_monitoring.php and the
        // active project list instead of staying cancelled and hidden; it can
        // move to In Progress afterwards through the normal flow.
        if ($module === 'cimm' && strtolower((string)($row['status'] ?? '')) === 'cancelled') {
            $restore_status = 'Approved';
        }

        // CIMM reports: map the archived row back into cimm_verification_reports.
        if ($module === 'cimm') {
            // Reconstruct the CIMM request id (reference_code is formatted
            // 'REQ-<id>') so the Approve/Reject buttons post a valid
            // cimm_req_id after restore.
            $cimm_req_id = null;
            $ref_code = (string)($row['report_id'] ?? '');
            if (preg_match('/^REQ-(.+)$/i', $ref_code, $m)) {
                $cimm_req_id = $m[1];
            } elseif ($ref_code !== '') {
                $cimm_req_id = $ref_code;
            }
            $cimm_fields = [
                'reference_code' => $row['report_id'],
                'infrastructure' => $row['title'],
                'issue' => $row['description'],
                'location' => $row['location'],
                'coord_lat' => $row['latitude'],
                'coord_lng' => $row['longitude'],
                'priority' => $row['priority'],
                'reporter_name' => $row['reporter_name'],
                'submitted_at' => $row['created_at'],
                'verified_at' => ($row['completed_at'] ?? null) ?: ($row['rejected_at'] ?? null),
                'verification_status' => $restore_status,
                'approval_status' => 'Approved',
                'cimm_req_id' => $cimm_req_id,
            ];

            // The Complete/Reject flows (and the Resolve flow) file a COPY into
            // the archive while the live CIMM row stays in place, so the report
            // may already exist here. In that case reopen it in place (restore
            // its status/data, clear the terminal timestamps) instead of
            // inserting a duplicate, then drop the archive copy.
            $existing = null;
            if ($cimm_req_id !== null && $cimm_req_id !== '') {
                $dup = $conn->prepare("SELECT id FROM cimm_verification_reports WHERE cimm_req_id = ?");
                $dup->bind_param("s", $cimm_req_id);
                $dup->execute();
                $existing = $dup->get_result()->fetch_assoc() ?? null;
                $dup->close();
            }

            if ($existing) {
                $upd = $conn->prepare("UPDATE cimm_verification_reports SET verification_status = ?, approval_status = 'Approved', resolved_at = NULL, updated_at = NOW() WHERE id = ?");
                $upd->bind_param("si", $restore_status, $existing['id']);
                $upd->execute();
                if ($upd->affected_rows >= 0) {
                    $delete = $conn->prepare("DELETE FROM road_transportation_reports_archive WHERE id = ?");
                    $delete->bind_param('i', $archive_id);
                    $delete->execute();
                    $_SESSION['archive_message'] = 'Report restored successfully.';
                } else {
                    $_SESSION['archive_message'] = 'Restore failed – the report may already exist.';
                }
                header('Location: archive.php');
                exit();
            }

            $cols = '`' . implode('`, `', array_keys($cimm_fields)) . '`';
            $place = implode(', ', array_fill(0, count($cimm_fields), '?'));
            $stmt = $conn->prepare("INSERT INTO cimm_verification_reports ($cols) VALUES ($place)");
            try {
                $stmt->execute(array_values($cimm_fields));
            } catch (Exception $e) {
                $_SESSION['archive_message'] = 'Restore failed – the CIMM report may already exist.';
                header('Location: archive.php');
                exit();
            }
            if ($stmt->affected_rows > 0) {
                $delete = $conn->prepare("DELETE FROM road_transportation_reports_archive WHERE id = ?");
                $delete->bind_param('i', $archive_id);
                $delete->execute();
                $_SESSION['archive_message'] = 'Report restored successfully.';
            } else {
                $_SESSION['archive_message'] = 'Restore failed – the report may already exist.';
            }
            header('Location: archive.php');
            exit();
        }

        $target_table = ($module === 'maintenance') ? 'road_maintenance_reports' : 'road_transportation_reports';

        // Build the column list from only the columns the target table actually
        // has, and drop the auto-increment id so a restored row always gets a
        // fresh id (avoids collisions with rows already present).
        $dest_cols = [];
        $col_res = $conn->query("SHOW COLUMNS FROM $target_table");
        if ($col_res) { while ($c = $col_res->fetch_assoc()) { $dest_cols[$c['Field']] = true; } }
        $fields = [];
        foreach ($row as $field => $value) {
            if ($field === 'id') continue;
            if (isset($dest_cols[$field])) $fields[] = $field;
        }
        if (empty($fields)) {
            $_SESSION['archive_message'] = 'Restore failed – could not map the archived record.';
            header('Location: archive.php');
            exit();
        }

        $cols = implode(', ', array_map(function ($f) { return "`$f`"; }, $fields));
        $place = implode(', ', array_fill(0, count($fields), '?'));
        $values = [];
        foreach ($fields as $field) {
            $value = $row[$field];
            if ($field === 'status') {
                $value = $restore_status;
            }
            // If the report is no longer terminal, clear the terminal timestamps
            // so the restored row looks like the report it was before archiving.
            // A rejected report keeps its rejected_at; a cancelled one keeps
            // its rejected_at (used as the cancel timestamp) and cancelled_at.
            if ($restore_status !== 'rejected' && $restore_status !== 'cancelled' && in_array($field, ['rejected_at', 'cancelled_at'], true)) {
                $value = null;
            }
            if ($restore_status !== 'completed' && $field === 'completed_at') {
                $value = null;
            }
            $values[] = $value;
        }

        // The Complete flow files a COPY into the archive while the live report
        // stays in place, so the live report may already exist here. In that
        // case reopen it in place (restore its status/data) instead of inserting
        // a duplicate, then drop the archive copy.
        $dup_check = $conn->prepare("SELECT id FROM $target_table WHERE report_id = ?");
        $dup_check->bind_param("s", $row['report_id']);
        $dup_check->execute();
        $existing_id = $dup_check->get_result()->fetch_assoc()['id'] ?? null;
        $dup_check->close();

        if ($existing_id) {
            $sql = "UPDATE $target_table SET status = ?, updated_at = NOW()";
            if ($restore_status !== 'completed') {
                $sql .= ", completed_at = NULL";
            }
            $sql .= " WHERE id = ?";
            $upd = $conn->prepare($sql);
            $upd->bind_param("si", $restore_status, $existing_id);
            $upd->execute();
            if ($upd->affected_rows >= 0) {
                $delete = $conn->prepare("DELETE FROM road_transportation_reports_archive WHERE id = ?");
                $delete->bind_param('i', $archive_id);
                $delete->execute();
                $_SESSION['archive_message'] = 'Report restored successfully.';
            } else {
                $_SESSION['archive_message'] = 'Restore failed – the record may already exist.';
            }
            header('Location: archive.php');
            exit();
        }

        $insert = "INSERT INTO $target_table ($cols) VALUES ($place)";
        $stmt = $conn->prepare($insert);
        try {
            $stmt->execute($values);
        } catch (Exception $e) {
            $_SESSION['archive_message'] = 'Restore failed – the report may already exist (duplicate report_id).';
            header('Location: archive.php');
            exit();
        }
        if ($stmt->affected_rows > 0) {
            $delete = $conn->prepare("DELETE FROM road_transportation_reports_archive WHERE id = ?");
            $delete->bind_param('i', $archive_id);
            $delete->execute();
            $_SESSION['archive_message'] = 'Report restored successfully.';
        } else {
            $_SESSION['archive_message'] = 'Restore failed – the record may already exist.';
        }
        header('Location: archive.php');
        exit();
    }
    if ($_POST['action'] === 'delete_forever' && isset($_POST['archive_id'])) {
        $archive_id = (int) $_POST['archive_id'];
        $delete = "DELETE FROM road_transportation_reports_archive WHERE id = ?";
        $stmt = $conn->prepare($delete);
        $stmt->bind_param('i', $archive_id);
        $stmt->execute();
        $_SESSION['archive_message'] = 'Report permanently deleted.';
        header('Location: archive.php');
        exit();
    }
}

$message = '';
if (isset($_SESSION['archive_message'])) {
    $message = $_SESSION['archive_message'];
    unset($_SESSION['archive_message']);
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
        .btn-restore {
            padding: 8px 16px; background: linear-gradient(135deg,#28a745,#20c997);
            color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 500;
            cursor: pointer; display: flex; align-items: center; gap: 6px; transition: all 0.3s ease;
        }
        .btn-restore:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(40,167,69,0.3); }
        .btn-delete-forever {
            padding: 8px 16px; background: linear-gradient(135deg,#dc3545,#c82333);
            color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 500;
            cursor: pointer; display: flex; align-items: center; gap: 6px; transition: all 0.3s ease;
        }
        .btn-delete-forever:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(220,53,69,0.3); }
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
        body.dark-mode .archive-item:hover {
            background: rgba(255,255,255,0.08) !important;
        }
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
        body.dark-mode .modal-header { border-color: #2d323b !important; }

        /* Filters section */
        .filters-section {
            background: #f0f4fa;
            backdrop-filter: blur(15px);
            border-radius: 16px;
            padding: 20px 25px;
            border: 1px solid rgba(55,98,200,0.1);
            box-shadow: 0 8px 32px rgba(0,0,0,0.08);
        }
        .filter-group {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            align-items: flex-end;
        }
        .filter-group > div {
            flex: 1;
            min-width: 180px;
        }
        .filter-group .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #1e3c72;
            margin-bottom: 6px;
        }
        .filter-select {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid rgba(55,98,200,0.2);
            border-radius: 10px;
            font-size: 14px;
            background: white;
            color: #333;
            transition: all 0.3s ease;
            cursor: pointer;
            appearance: auto;
            -webkit-appearance: auto;
        }
        .filter-select:focus {
            border-color: #3762c8;
            box-shadow: 0 0 0 3px rgba(55,98,200,0.15);
            outline: none;
        }
        .btn-secondary-custom {
            padding: 10px 20px;
            background: linear-gradient(135deg, #6c757d, #495057);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            width: 100%;
            justify-content: center;
        }
        .btn-secondary-custom:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(108,117,125,0.3);
        }
        body.dark-mode .filters-section {
            background: #22262e;
            border-color: #2d323b;
        }
        body.dark-mode .filter-group .form-label {
            color: #e4e6ea;
        }
        body.dark-mode .filter-select {
            background: #2a2e36;
            color: #e4e6ea;
            border-color: #3a3f4a;
        }
        .source-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .source-transport {
            background: rgba(40,167,69,0.15);
            color: #28a745;
        }
        .source-maintenance {
            background: rgba(55,98,200,0.15);
            color: #3762c8;
        }
        .source-lgu {
            background: rgba(40,167,69,0.15);
            color: #28a745;
        }
        .source-citizen {
            background: rgba(23,162,184,0.15);
            color: #17a2b8;
        }
        .source-cimm {
            background: rgba(255,193,7,0.15);
            color: #d39e00;
        }
        .source-infrastructure {
            background: rgba(55,98,200,0.15);
            color: #3762c8;
        }
        .status-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: capitalize;
        }
        .status-completed {
            background: rgba(34,197,94,0.15);
            color: #22c55e;
        }
        .status-rejected {
            background: rgba(249,115,22,0.15);
            color: #f97316;
        }
        .status-cancelled {
            background: rgba(239,68,68,0.15);
            color: #ef4444;
        }

        @media (max-width: 768px) {
            .main-content { margin-left: 0; }
            .archive-meta { flex-direction: column; gap: 8px; }
            .detail-row { flex-direction: column; }
            .detail-label { width: 100%; margin-bottom: 5px; }
        }
    </style>
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?>">
    <?php include '../../includes/sidebar_nav.php'; ?>

    <div class="main-content">
        <div class="archive-header">
            <h1><i class="fas fa-archive"></i> Archive</h1>
            <p>View, filter, sort, restore, and permanently delete archived reports</p>
        </div>

        <!-- Filters -->
        <div class="filters-section" style="margin-bottom:24px;">
            <div class="filter-group">
                <div>
                    <label class="form-label">Status Filter</label>
                    <select class="filter-select" id="statusFilter" onchange="filterReports()">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Source System</label>
                    <select class="filter-select" id="sourceFilter" onchange="filterReports()">
                        <option value="all" <?php echo $source_filter === 'all' ? 'selected' : ''; ?>>All Systems</option>
                        <option value="lgu" <?php echo $source_filter === 'lgu' ? 'selected' : ''; ?>>Road & Transportation (LGU Monitoring)</option>
                        <option value="citizen" <?php echo $source_filter === 'citizen' ? 'selected' : ''; ?>>Citizen</option>
                        <option value="cimm" <?php echo $source_filter === 'cimm' ? 'selected' : ''; ?>>CIMM</option>
                        <option value="infrastructure" <?php echo $source_filter === 'infrastructure' ? 'selected' : ''; ?>>Infrastructure</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Sort Order</label>
                    <select class="filter-select" id="sortFilter" onchange="filterReports()">
                        <option value="latest" <?php echo $sort_order === 'latest' ? 'selected' : ''; ?>>Newest to Oldest</option>
                        <option value="earliest" <?php echo $sort_order === 'earliest' ? 'selected' : ''; ?>>Oldest to Newest</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Search ID</label>
                    <input type="text" class="filter-select" id="idSearch" placeholder="Enter report ID..." value="<?php echo htmlspecialchars($id_search); ?>" onkeyup="if(event.key === 'Enter') filterReports()">
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
                    <span class="archive-badge"><?php echo (int)$total_archives; ?></span>
                </h3>
            </div>

            <?php if ($archives->num_rows > 0): ?>
                <?php while ($row = $archives->fetch_assoc()): ?>
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
                                    <span class="status-badge status-<?php echo htmlspecialchars(strtolower($row['status'])); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $row['status']))); ?></span>
                                </span>
                                <span class="meta-item"><i class="fas fa-calendar"></i> <?php echo htmlspecialchars($row['created_at']); ?></span>
                                <span class="meta-item"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($row['location'] ?? 'N/A'); ?></span>
                                <span class="meta-item"><i class="fas fa-sitemap"></i>
                                    <span class="source-badge source-<?php echo $row['source_system']; ?>"><?php echo htmlspecialchars($source_labels[$row['source_system']] ?? ($row['source_system'] ?? 'Unknown')); ?></span>
                                </span>
                            </div>
                            <div class="archive-actions">
                                <button type="button" class="btn-view" onclick="viewArchive(<?php echo $row['id']; ?>)">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <form method="POST" style="display: inline-flex;" onsubmit="return confirm('Restore this report back to active table?');">
                                    <input type="hidden" name="archive_id" value="<?php echo $row['id']; ?>">
                                    <button type="submit" name="action" value="restore" class="btn-restore">
                                        <i class="fas fa-undo"></i> Restore
                                    </button>
                                </form>
                                <form method="POST" style="display: inline-flex;" onsubmit="return confirm('Permanently delete this archived report? This cannot be undone.');">
                                    <input type="hidden" name="archive_id" value="<?php echo $row['id']; ?>">
                                    <button type="submit" name="action" value="delete_forever" class="btn-delete-forever">
                                        <i class="fas fa-trash"></i> Delete Forever
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-archive"></i>
                    <p>No archived reports yet.</p>
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

    <?php if ($message): ?>
    <script>
        (function() {
            var n = document.createElement('div');
            n.className = 'notification success';
            n.textContent = <?php echo json_encode($message); ?>;
            document.body.appendChild(n);
            setTimeout(function() { n.remove(); }, 3000);
        })();
    </script>
    <?php endif; ?>

    <script>
        var archiveData = <?php
            $archives->data_seek(0);
            $rows = [];
            while ($r = $archives->fetch_assoc()) {
                $rows[] = $r;
            }
            echo json_encode($rows);
        ?>;

        function viewArchive(id) {
            var row = archiveData.find(function(r) { return r.id == id; });
            if (!row) return;

            document.getElementById('modalTitle').textContent = row.title || 'Report Details';

            var html = '';
            var fields = [
                { label: 'Report ID', value: row.report_id },
                { label: 'Title', value: row.title },
                { label: 'Report Type', value: row.report_type },
                { label: 'Department', value: row.department },
                { label: 'Priority', value: row.priority },
                { label: 'Status', value: row.status },
                { label: 'Location', value: row.location },
                { label: 'Description', value: row.description },
                { label: 'Created Date', value: row.created_date },
                { label: 'Due Date', value: row.due_date },
                { label: 'Latitude', value: row.latitude },
                { label: 'Longitude', value: row.longitude },
                { label: 'Created At', value: row.created_at },
                { label: 'Updated At', value: row.updated_at },
                { label: 'Approved At', value: row.approved_at },
                { label: 'Rejected At', value: row.rejected_at }
            ];

            var sourceLabels = { 'lgu': 'LGU Monitoring', 'citizen': 'Citizen', 'cimm': 'CIMM', 'infrastructure': 'Infrastructure' };
            var sourceIcons = { 'lgu': 'fa-users', 'citizen': 'fa-user', 'cimm': 'fa-handshake', 'infrastructure': 'fa-hard-hat' };
            var src = row.source_system || 'citizen';
            var sourceLabel = sourceLabels[src] || src;
            var sourceIcon = sourceIcons[src] || 'fa-file-archive';

            html += '<div class="detail-row"><span class="detail-label">Source System</span><span class="detail-value"><i class="fas ' + sourceIcon + '"></i> ' + sourceLabel + '</span></div>';
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

        // Filter functionality
        function filterReports() {
            const status = document.getElementById('statusFilter').value;
            const source = document.getElementById('sourceFilter').value;
            const sort = document.getElementById('sortFilter').value;
            const id = document.getElementById('idSearch').value.trim();
            const url = new URL(window.location);
            url.searchParams.set('status', status);
            url.searchParams.set('source', source);
            url.searchParams.set('sort', sort);
            if (id) {
                url.searchParams.set('id', id);
            } else {
                url.searchParams.delete('id');
            }
            window.location.href = url.toString();
        }

        function resetFilters() {
            const url = new URL(window.location);
            url.searchParams.delete('status');
            url.searchParams.delete('source');
            url.searchParams.delete('sort');
            url.searchParams.delete('id');
            window.location.href = url.toString();
        }

        function closeModalAndRefresh() {
            closeModal('viewModal');
            location.reload();
        }
    </script>


</body>
</html>
