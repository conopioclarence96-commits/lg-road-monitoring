<?php
require_once '../../includes/session_config.php';
require_once '../../includes/config.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/../api/progress_archive_helpers.php';

$archive_allowed_roles = ['system_admin', 'road_ops_supervisor', 'trans_ops_supervisor', 'trans_monitoring_officer', 'road_monitoring_officer'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $archive_allowed_roles, true)) {
    header('Location: ../../login.php');
    exit();
}

$user_role = $_SESSION['role'] ?? '';
$is_trans_role = in_array($user_role, ['trans_ops_supervisor', 'trans_monitoring_officer'], true);
$is_trans_officer = ($user_role === 'trans_monitoring_officer');
$is_road_supervisor = ($user_role === 'road_ops_supervisor');
$is_road_officer = ($user_role === 'road_monitoring_officer');

$conn->query("CREATE TABLE IF NOT EXISTS road_transportation_reports_archive LIKE road_transportation_reports");
rgmap_archive_ensure_table();

// Ensure archive table has the same columns as the source table
foreach (['report_category' => "ENUM('road','transportation') DEFAULT NULL AFTER report_type",
           'report_source' => "ENUM('local','external') DEFAULT 'local' AFTER report_category",
           'previous_status' => "VARCHAR(50) DEFAULT NULL",
           'archived_from' => "VARCHAR(100) DEFAULT NULL",
           'source_pk' => "INT NULL DEFAULT NULL"] as $col => $def) {
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

// Trans roles may only ever query the Road & Transportation (LGU Monitoring)
// and Citizen sources. Normalize any tampered source parameter (CIMM /
// Infrastructure) to 'all' BEFORE the filter switch below, so the per-source
// WHERE clause can never be built for the excluded sources.
if ($is_trans_role && ($source_filter === 'cimm' || $source_filter === 'infrastructure')) {
    $source_filter = 'all';
}

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

// Trans roles (trans_ops_supervisor, trans_monitoring_officer) may only see
// the Road & Transportation (LGU Monitoring) and Citizen archives. CIMM and
// Infrastructure reports are always excluded for them — both in the dropdown
// and, critically, in the query itself (so tampering with the source filter
// cannot surface those reports).
$trans_source_restrict = '';
if ($is_trans_role) {
    $trans_source_restrict = "report_type NOT IN ('infrastructure_issue','maintenance','maintenance_request') AND (report_source IS NULL OR report_source != 'external')";
}

// trans_monitoring_officer may only see the archived reports that were
// assigned to them (mirrors the officer archive's assignment join).
$trans_officer_restrict = '';
if ($is_trans_officer) {
    $trans_officer_restrict = "EXISTS (
        SELECT 1 FROM report_assignments ra
        WHERE ra.user_id = " . (int)$_SESSION['user_id'] . " AND ra.status = 'active'
          AND (road_transportation_reports_archive.id = ra.report_id
               OR (
                   road_transportation_reports_archive.report_id IS NOT NULL
                   AND road_transportation_reports_archive.report_id != ''
                   AND road_transportation_reports_archive.report_id = (SELECT r.report_id FROM road_transportation_reports r WHERE r.id = ra.report_id LIMIT 1)
               ))
    )";
}

// Build WHERE clause
$where_clauses = [];

// Status filter. The archive's classic entries are completed / rejected /
// cancelled reports, but the Road Supervisor portal can also archive a report
// while keeping its current status (approved / in-progress / pending), so the
// "All Status" view shows every status rather than only terminal ones.
if ($status_filter === 'completed') {
    $where_clauses[] = "status = 'completed'";
} elseif ($status_filter === 'rejected') {
    $where_clauses[] = "status = 'rejected'";
} elseif ($status_filter === 'cancelled') {
    $where_clauses[] = "status = 'cancelled'";
} elseif ($status_filter === 'approved') {
    $where_clauses[] = "status = 'approved'";
} elseif ($status_filter === 'in-progress') {
    $where_clauses[] = "status = 'in-progress'";
} elseif ($status_filter === 'pending') {
    $where_clauses[] = "status = 'pending'";
}

if ($source_where !== '') {
    $where_clauses[] = $source_where;
}

if ($trans_source_restrict !== '') {
    $where_clauses[] = $trans_source_restrict;
}

if ($trans_officer_restrict !== '') {
    $where_clauses[] = $trans_officer_restrict;
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
    // trans_monitoring_officer and road_monitoring_officer are view-only
    // archive viewers: they may never restore or permanently delete archived
    // reports, regardless of the POST parameters they send.
    if (($is_trans_officer || $is_road_officer) && in_array($_POST['action'], ['restore', 'delete_forever'], true)) {
        $_SESSION['archive_message'] = 'You are not authorized to restore or delete archived reports.';
        header('Location: archive.php');
        exit();
    }
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

        // Keep the archived status as-is (Completed stays Completed, Cancelled
        // stays Cancelled). previous_status is only a fallback when status is empty.
        $restore_status = (string)($row['status'] ?? '');
        if ($restore_status === '' && !empty($row['previous_status'])) {
            $restore_status = (string)$row['previous_status'];
        }

        // A rejected CITIZEN report (transportation + local + created_by = 0) is
        // restored to 'pending' so it returns to the Pending Citizen Reports
        // section on verification_monitoring.php with its Approve/Reject buttons
        // available again — instead of coming back permanently rejected (the
        // buttons only render for 'pending' reports).
        if ($module === 'transport'
            && strtolower($restore_status) === 'rejected'
            && (int)($row['created_by'] ?? 0) === 0
            && ($row['report_source'] ?? '') === 'local'
            && strtolower((string)($row['report_category'] ?? '')) === 'transportation') {
            $restore_status = 'pending';
        }

        // CIMM stores title-case verification_status. Map the lowercase archive
        // status back. Rejected still returns to Pending Review so Approve/Reject
        // buttons work on verification_monitoring.php.
        if ($module === 'cimm') {
            $cimm_status_map = [
                'completed' => 'Completed',
                'cancelled' => 'Cancelled',
                'rejected' => 'Pending Review',
                'approved' => 'Approved',
                'pending' => 'Pending Review',
                'in-progress' => 'In Progress',
                'in progress' => 'In Progress',
                'pending review' => 'Pending Review',
                'verified' => 'Verified',
            ];
            $cimm_key = strtolower(trim($restore_status));
            if (isset($cimm_status_map[$cimm_key])) {
                $restore_status = $cimm_status_map[$cimm_key];
            } elseif (!empty($row['previous_status'])) {
                $restore_status = (string)$row['previous_status'];
            }
        }

        $original_pk = (int)($row['source_pk'] ?? 0);
        // LGU/Citizen/maintenance move-archives usually keep the live id as
        // archive.id. CIMM archives do not — only source_pk is the local CIMM PK.
        if ($original_pk <= 0 && $module !== 'cimm') {
            $original_pk = (int)($row['id'] ?? 0);
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
                if (strtolower($restore_status) === 'pending review') {
                    $upd = $conn->prepare("UPDATE cimm_verification_reports SET verification_status = ?, approval_status = 'Approved', resolved_at = NULL, updated_at = NOW() WHERE id = ?");
                } else {
                    $upd = $conn->prepare("UPDATE cimm_verification_reports SET verification_status = ?, updated_at = NOW() WHERE id = ?");
                }
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

            $insert_with_id = ($original_pk > 0 && rgmap_pk_is_free($conn, 'cimm_verification_reports', $original_pk));
            if ($insert_with_id) {
                $cimm_fields = ['id' => $original_pk] + $cimm_fields;
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
                $new_id = $insert_with_id ? $original_pk : (int)$conn->insert_id;
                if ($original_pk > 0) {
                    rgmap_remap_report_fk($conn, $original_pk, $new_id);
                }
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
        // has. Prefer restoring with the original live id so report_updates
        // (keyed by that INT PK) stay attached.
        $dest_cols = [];
        $col_res = $conn->query("SHOW COLUMNS FROM $target_table");
        if ($col_res) { while ($c = $col_res->fetch_assoc()) { $dest_cols[$c['Field']] = true; } }
        $fields = [];
        foreach ($row as $field => $value) {
            if ($field === 'id' || $field === 'source_pk' || $field === 'source_system') continue;
            if (isset($dest_cols[$field])) $fields[] = $field;
        }
        if (empty($fields)) {
            $_SESSION['archive_message'] = 'Restore failed – could not map the archived record.';
            header('Location: archive.php');
            exit();
        }

        $insert_with_id = ($original_pk > 0 && rgmap_pk_is_free($conn, $target_table, $original_pk));
        if ($insert_with_id) {
            array_unshift($fields, 'id');
        }

        $cols = implode(', ', array_map(function ($f) { return "`$f`"; }, $fields));
        $place = implode(', ', array_fill(0, count($fields), '?'));
        $values = [];
        foreach ($fields as $field) {
            if ($field === 'id') {
                $values[] = $original_pk;
                continue;
            }
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
            $new_id = $insert_with_id ? $original_pk : (int)$conn->insert_id;
            if ($original_pk > 0) {
                rgmap_remap_report_fk($conn, $original_pk, $new_id);
            }
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

// AJAX: return the progress updates for an archived report so the existing
// export flow (progress-updates-common.js -> exportUpdatesToExcel) can produce
// the same per-report export inside the View modal. It reads directly from the
// report_updates table because an archived report may no longer exist in the
// live tables that progress_update_api.php's get_updates action checks.
if (isset($_GET['ajax']) && $_GET['ajax'] === 'export_updates') {
    header('Content-Type: application/json; charset=utf-8');
    $arch_id = intval($_GET['id'] ?? 0);
    if ($arch_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid archive ID.']);
        exit;
    }
    $arch_stmt = $conn->prepare("SELECT * FROM road_transportation_reports_archive WHERE id = ?");
    $arch_stmt->bind_param('i', $arch_id);
    $arch_stmt->execute();
    $arch_row = $arch_stmt->get_result()->fetch_assoc();
    if (!$arch_row) {
        echo json_encode(['success' => false, 'message' => 'Archived report not found.']);
        exit;
    }

    // Resolve the numeric report id that report_updates is keyed by.
    //   - CIMM archives carry the original CIMM numeric id inside the reference
    //     code (REQ-<id> / CIMM-<id>); otherwise match by reference_code.
    //   - Copied archives keep the live report, so look it up by its display
    //     report_id first.
    //   - Moved archives preserve the live id as the archive row's own id, so
    //     fall back to that when the live report no longer exists.
    $updates_report_id = null;
    $ref_code = (string)($arch_row['report_id'] ?? '');
    $is_cimm = (($arch_row['report_source'] ?? '') === 'external'
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

    // Pull the progress updates (same shape progress_update_api.php returns).
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
        $upd['media'] = $media;
        $updates[] = $upd;
    }
    echo json_encode(['success' => true, 'updates' => $updates]);
    exit;
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
        .btn-export {
            padding: 8px 16px; background: linear-gradient(135deg,#17a2b8,#0d6efd);
            color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 500;
            cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
            text-decoration: none; transition: all 0.3s ease;
        }
        .btn-export:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(23,162,184,0.3); }
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

        /* View Archive Modal (btn-view viewport — mirrors report_management rm modal) */
        .rm-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 10000;
            align-items: center;
            justify-content: center;
            padding: 20px;
            box-sizing: border-box;
            overflow-y: auto;
        }

        .rm-modal-overlay.active {
            display: flex;
        }

        .rm-modal-content {
            background: #f0f4fa;
            border-radius: 16px;
            max-width: 860px;
            width: 100%;
            max-height: calc(100vh - 40px);
            position: relative;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            margin: auto;
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
            border: 1px solid #c8d0e0;
        }

        .rm-modal-header {
            background: white;
            border-radius: 16px 16px 0 0;
            padding: 24px 28px 18px;
            border-bottom: 2px solid rgba(55, 98, 200, 0.15);
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
            color: #3762c8;
            font-weight: 600;
            letter-spacing: 0.3px;
            margin-bottom: 4px;
        }

        .rm-modal-title {
            font-size: 22px;
            font-weight: 700;
            color: #1e3c72;
            margin: 0 0 10px 0;
            line-height: 1.3;
        }

        .rm-modal-badges {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .rm-modal-close {
            background: none;
            border: none;
            font-size: 28px;
            color: #666;
            cursor: pointer;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s;
            flex-shrink: 0;
            margin-left: 15px;
        }

        .rm-modal-close:hover {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }

        .rm-modal-body {
            overflow-y: auto;
            flex: 1;
            min-height: 0;
            padding: 24px 28px;
        }

        .rm-modal-body::-webkit-scrollbar {
            width: 8px;
        }

        .rm-modal-body::-webkit-scrollbar-track {
            background: rgba(55, 98, 200, 0.08);
            border-radius: 4px;
        }

        .rm-modal-body::-webkit-scrollbar-thumb {
            background: rgba(55, 98, 200, 0.2);
            border-radius: 4px;
        }

        .rm-modal-body::-webkit-scrollbar-thumb:hover {
            background: rgba(55, 98, 200, 0.35);
        }

        .rm-modal-section {
            background: white;
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(55, 98, 200, 0.1);
        }

        .rm-modal-section-title {
            font-size: 14px;
            font-weight: 700;
            color: #1e3c72;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 14px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(55, 98, 200, 0.1);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .rm-modal-section-title i {
            color: #3762c8;
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
            background: rgba(55, 98, 200, 0.1);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #3762c8;
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
            border-top: 1px solid rgba(55, 98, 200, 0.1);
            flex-shrink: 0;
            display: flex;
            justify-content: flex-end;
        }

        .rm-modal-btn-close {
            padding: 10px 24px;
            background: rgba(55, 98, 200, 0.1);
            color: #3762c8;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            transition: all 0.2s;
        }

        .rm-modal-btn-close:hover {
            background: rgba(55, 98, 200, 0.2);
        }

        .rm-modal-btn-export {
            padding: 10px 24px;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-right: 10px;
            transition: all 0.2s;
        }

        .rm-modal-btn-export:hover {
            background: linear-gradient(135deg, #2f55b0, #172c55);
            box-shadow: 0 4px 12px rgba(55, 98, 200, 0.3);
        }

        @media (max-width: 640px) {
            .rm-info-grid {
                grid-template-columns: 1fr;
            }
            .rm-modal-header {
                padding: 18px 16px;
            }
            .rm-modal-body {
                padding: 16px;
            }
            .rm-modal-content {
                max-width: 100%;
                border-radius: 0;
            }
            .rm-modal-overlay {
                padding: 0;
            }
        }

        /* View Archive Modal Dark Mode */
        body.dark-mode .rm-modal-content {
            background: #1a1d24 !important;
            border-color: #2d323b !important;
        }
        body.dark-mode .rm-modal-header {
            background: #1a1d24 !important;
            border-bottom-color: #2d323b !important;
        }
        body.dark-mode .rm-modal-title {
            color: #60a5fa !important;
        }
        body.dark-mode .rm-modal-report-id {
            color: #60a5fa !important;
        }
        body.dark-mode .rm-modal-close {
            color: #9ca3af !important;
        }
        body.dark-mode .rm-modal-section {
            background: #22262e !important;
            border-color: #2d323b !important;
        }
        body.dark-mode .rm-modal-section-title {
            color: #60a5fa !important;
            border-bottom-color: #2d323b !important;
        }
        body.dark-mode .rm-info-icon {
            background: rgba(55,98,200,0.15) !important;
        }
        body.dark-mode .rm-info-label {
            color: #9ca3af !important;
        }
        body.dark-mode .rm-info-value {
            color: #e4e6ea !important;
        }
        body.dark-mode .rm-description-text {
            color: #c0c8d8 !important;
        }
        body.dark-mode .rm-modal-footer {
            background: #1a1d24 !important;
            border-top-color: #2d323b !important;
        }
        body.dark-mode .rm-modal-btn-close {
            background: rgba(55,98,200,0.15) !important;
            color: #60a5fa !important;
        }
        body.dark-mode .rm-modal-btn-export {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8) !important;
            color: #fff !important;
        }
        body.dark-mode .rm-modal-btn-export:hover {
            background: linear-gradient(135deg, #2563eb, #1e40af) !important;
        }
        body.dark-mode .rm-modal-body::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.05) !important;
        }
        body.dark-mode .rm-modal-body::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.15) !important;
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
        .status-approved {
            background: rgba(34,197,94,0.15);
            color: #22c55e;
        }
        .status-pending {
            background: rgba(251,191,36,0.15);
            color: #f59e0b;
        }
        .status-in-progress {
            background: rgba(59,130,246,0.15);
            color: #3b82f6;
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
                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="in-progress" <?php echo $status_filter === 'in-progress' ? 'selected' : ''; ?>>In Progress</option>
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
                        <?php if (!$is_trans_role): ?>
                        <option value="cimm" <?php echo $source_filter === 'cimm' ? 'selected' : ''; ?>>CIMM</option>
                        <option value="infrastructure" <?php echo $source_filter === 'infrastructure' ? 'selected' : ''; ?>>Infrastructure</option>
                        <?php endif; ?>
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
                                <?php if (!$is_trans_officer && !$is_road_officer): ?>
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
                                <?php if ($is_road_supervisor): ?>
                                <a class="btn-export" href="../api/export_archive_word.php?id=<?php echo (int)$row['id']; ?>" title="Export this archived report as a Word document">
                                    <i class="fas fa-file-word"></i> Export
                                </a>
                                <?php endif; ?>
                                <?php endif; ?>
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

    <!-- View Archive Modal (btn-view viewport) -->
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
                <!-- Report Information -->
                <div class="rm-modal-section">
                    <div class="rm-modal-section-title"><i class="fas fa-info-circle"></i> Report Information</div>
                    <div class="rm-info-grid" id="rm-report-grid"></div>
                </div>
                <!-- Source & Department -->
                <div class="rm-modal-section">
                    <div class="rm-modal-section-title"><i class="fas fa-building"></i> Source &amp; Department</div>
                    <div class="rm-info-grid" id="rm-source-grid"></div>
                </div>
                <!-- Location -->
                <div class="rm-modal-section">
                    <div class="rm-modal-section-title"><i class="fas fa-map-marker-alt"></i> Location</div>
                    <div class="rm-info-grid" id="rm-location-grid"></div>
                </div>
                <!-- Description -->
                <div class="rm-modal-section">
                    <div class="rm-modal-section-title"><i class="fas fa-align-left"></i> Description</div>
                    <div class="rm-description-text" id="rm-description">—</div>
                </div>
                <!-- Attachments -->
                <div class="rm-modal-section">
                    <div class="rm-modal-section-title"><i class="fas fa-paperclip"></i> Attachments</div>
                    <div id="rm-attachments"></div>
                </div>
                <!-- Timeline -->
                <div class="rm-modal-section">
                    <div class="rm-modal-section-title"><i class="fas fa-clock"></i> Timeline &amp; Updates</div>
                    <div class="rm-info-grid" id="rm-timeline-grid"></div>
                </div>
            </div>
            <div class="rm-modal-footer">
                <?php if (!$is_road_officer): ?>
                <button type="button" class="rm-modal-btn-export" onclick="exportArchivedReport()">
                    <i class="fas fa-file-export"></i> Export
                </button>
                <?php endif; ?>
                <button type="button" class="rm-modal-btn-close" onclick="closeViewModal()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <!-- Hidden timeline container used by the existing progress-updates export -->
    <div id="updatesTimeline" style="display:none;"></div>

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

    <!-- Shared progress-updates scripts: provide the existing report export
         (exportUpdatesToExcel) and timeline rendering used by report_management.php -->
    <script src="../../js/progress-updates.js"></script>
    <script src="../../js/progress-updates-common.js"></script>

    <script>
        var archiveData = <?php
            $archives->data_seek(0);
            $rows = [];
            while ($r = $archives->fetch_assoc()) {
                $rows[] = $r;
            }
            echo json_encode($rows);
        ?>;

        var currentArchiveRow = null;

        // View Archive Modal helpers (mirrors report_management rm modal)
        function formatDate(dateStr) {
            if (!dateStr) return '—';
            var d = new Date(dateStr);
            if (isNaN(d.getTime())) return dateStr;
            var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            return months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
        }

        function rmBadge(text, bg, color) {
            return '<span style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;background:' + bg + ';color:' + color + ';">' + text + '</span>';
        }

        function rmInfoItem(icon, label, value) {
            var displayVal = (value && value !== '—' && value !== null) ? value : '—';
            return '<div class="rm-info-item"><div class="rm-info-icon"><i class="fas fa-' + icon + '"></i></div><div><div class="rm-info-label">' + label + '</div><div class="rm-info-value">' + displayVal + '</div></div></div>';
        }

        function viewArchive(id) {
            var row = archiveData.find(function(r) { return r.id == id; });
            if (!row) return;
            currentArchiveRow = row;

            var statusStyles = {
                'pending':    {bg:'rgba(251,191,36,0.15)', color:'#f59e0b'},
                'approved':   {bg:'rgba(34,197,94,0.15)',  color:'#22c55e'},
                'completed':  {bg:'rgba(34,197,94,0.15)',  color:'#22c55e'},
                'resolved':   {bg:'rgba(34,197,94,0.15)',  color:'#22c55e'},
                'cancelled':  {bg:'rgba(220,53,69,0.15)',  color:'#ef4444'},
                'rejected':   {bg:'rgba(249,115,22,0.15)', color:'#f97316'},
                'in-progress':{bg:'rgba(59,130,246,0.15)', color:'#3b82f6'}
            };
            var pStyles = {
                'high':   {bg:'rgba(220,53,69,0.15)', color:'#ef4444'},
                'medium': {bg:'rgba(251,191,36,0.15)', color:'#f59e0b'},
                'low':    {bg:'rgba(34,197,94,0.15)',  color:'#22c55e'}
            };

            var sourceLabels = { 'lgu': 'LGU Monitoring', 'citizen': 'Citizen', 'cimm': 'CIMM', 'infrastructure': 'Infrastructure' };

            // Header
            document.getElementById('rm-report-id').textContent = 'Report #' + (row.report_id || '—');
            document.getElementById('rm-title').textContent = row.title || '—';

            var st = (row.status || 'pending').toLowerCase();
            var ss = statusStyles[st] || {bg:'rgba(107,114,128,0.15)', color:'#6b7280'};
            var pp = (row.priority || 'medium').toLowerCase();
            var ps = pStyles[pp] || {bg:'rgba(107,114,128,0.15)', color:'#6b7280'};

            var badgesHtml = rmBadge(row.status || '—', ss.bg, ss.color);
            badgesHtml += rmBadge(row.priority || '—', ps.bg, ps.color);
            var src = row.source_system || 'citizen';
            var sourceLabel = sourceLabels[src] || src;
            if (sourceLabel !== '—') {
                badgesHtml += '<span style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;background:rgba(55,98,200,0.12);color:#3762c8;">' + sourceLabel + '</span>';
            }
            document.getElementById('rm-badges').innerHTML = badgesHtml;

            // Report Information
            var reportGrid = '';
            reportGrid += rmInfoItem('folder', 'Report Type', row.report_type);
            reportGrid += rmInfoItem('calendar-alt', 'Created Date', row.created_date);
            reportGrid += rmInfoItem('calendar-check', 'Due Date', row.due_date);
            reportGrid += rmInfoItem('tag', 'Source System', sourceLabel);
            if (row.estimation) {
                reportGrid += rmInfoItem('dollar-sign', 'Estimation', row.estimation ? '₱' + parseFloat(row.estimation).toLocaleString('en-PH', {minimumFractionDigits:2}) : '—');
            }
            if (row.assigned_to) {
                reportGrid += rmInfoItem('user-cog', 'Assigned To', row.assigned_to);
            }
            document.getElementById('rm-report-grid').innerHTML = reportGrid;

            // Source & Department
            var sourceGrid = '';
            sourceGrid += rmInfoItem('building', 'Department', row.department);
            sourceGrid += rmInfoItem('history', 'Archived From', row.archived_from);
            if (row.previous_status) {
                sourceGrid += rmInfoItem('undo', 'Previous Status', row.previous_status);
            }
            if (row.approved_at) {
                sourceGrid += rmInfoItem('thumbs-up', 'Approved At', formatDate(row.approved_at));
            }
            if (row.rejected_at) {
                sourceGrid += rmInfoItem('thumbs-down', 'Rejected At', formatDate(row.rejected_at));
            }
            document.getElementById('rm-source-grid').innerHTML = sourceGrid;

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
            var seenPaths = new Set();
            if (row.image_path && row.image_path !== '0' && row.image_path !== 'null') {
                images.push('../../' + row.image_path);
                seenPaths.add(row.image_path);
            }
            if (row.attachments) {
                try {
                    var attachments = JSON.parse(row.attachments);
                    if (Array.isArray(attachments)) {
                        attachments.forEach(function(a) {
                            if ((a.type === 'image' || !a.type) && a.file_path && !seenPaths.has(a.file_path)) {
                                images.push('../../' + a.file_path);
                                seenPaths.add(a.file_path);
                            }
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

            // Timeline
            var timelineGrid = '';
            timelineGrid += rmInfoItem('calendar-check', 'Created', formatDate(row.created_at));
            if (row.approved_at) {
                timelineGrid += rmInfoItem('thumbs-up', 'Approved', formatDate(row.approved_at));
            }
            if (row.rejected_at) {
                timelineGrid += rmInfoItem('thumbs-down', 'Rejected', formatDate(row.rejected_at));
            }
            if (row.updated_at) {
                timelineGrid += rmInfoItem('edit', 'Last Updated', formatDate(row.updated_at));
            }
            document.getElementById('rm-timeline-grid').innerHTML = timelineGrid;

            document.getElementById('viewModal').classList.add('active');
        }

        function closeViewModal() {
            document.getElementById('viewModal').classList.remove('active');
            document.body.style.overflow = '';
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

        function showNotification(message, type) {
            type = type || 'info';
            var notification = document.createElement('div');
            notification.className = 'notification ' + type;
            notification.innerHTML = '<i class="fas fa-' + (type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle') + '"></i> ' + message;
            notification.style.cssText = 'position:fixed;top:20px;right:20px;padding:15px 20px;background:' + (type === 'success' ? '#10b981' : type === 'error' ? '#dc3545' : '#3762c8') + ';color:white;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.15);z-index:10000;animation:slideIn 0.3s ease;';
            document.body.appendChild(notification);
            setTimeout(function() {
                notification.style.opacity = '0';
                notification.style.transition = 'opacity 0.3s ease';
                setTimeout(function() { notification.remove(); }, 300);
            }, 3000);
        }

        // Reuse the existing progress-updates export (exportUpdatesToExcel in
        // progress-updates-common.js) for the archived report currently open in
        // the View modal. The updates for THIS report are fetched from this page
        // itself (see the ?ajax=export_updates handler) and rendered into the
        // hidden timeline container so the existing export reads them — the same
        // timeline structure and .doc output used on report_management.php.
        function exportArchivedReport() {
            if (!currentArchiveRow) {
                showNotification('No archived report is selected to export.', 'error');
                return;
            }
            var archiveId = currentArchiveRow.id;
            fetch('?ajax=export_updates&id=' + archiveId)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data && data.success) {
                        if (!data.updates || data.updates.length === 0) {
                            showNotification('This archived report has no progress updates available to export.', 'error');
                            return;
                        }
                        currentUpdatesReportId = currentArchiveRow.report_id || currentArchiveRow.id;
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

        function closeModalAndRefresh() {
            closeViewModal();
            location.reload();
        }
    </script>


</body>
</html>
