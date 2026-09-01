<?php
require_once '../../includes/session_config.php';
require_once '../../includes/config.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/../api/progress_archive_helpers.php';

$archive_allowed_roles = ['system_admin', 'road_ops_supervisor', 'trans_ops_supervisor', 'trans_monitoring_officer', 'road_monitoring_officer'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $archive_allowed_roles, true)) {
    header('Location: ' . rgmap_url('login'));
    exit();
}

$user_role = $_SESSION['role'] ?? '';
$is_trans_role = in_array($user_role, ['trans_ops_supervisor', 'trans_monitoring_officer'], true);

// Transport Operations Supervisor flag (strict): scopes the mobile-fit CSS below to this portal only.
$is_trans_ops_supervisor = ($user_role === 'trans_ops_supervisor');
$is_trans_officer = ($user_role === 'trans_monitoring_officer');
$is_road_supervisor = ($user_role === 'road_ops_supervisor');
$is_road_officer = ($user_role === 'road_monitoring_officer');
$is_system_admin = ($user_role === 'system_admin');

$conn->query("CREATE TABLE IF NOT EXISTS road_transportation_reports_archive LIKE road_transportation_reports");
rgmap_ensure_restored_from_archive_column();
rgmap_archive_ensure_table();

// Ensure archive table has the same columns as the source table
foreach (['report_category' => "ENUM('road','transportation') DEFAULT NULL AFTER report_type",
           'report_source' => "ENUM('local','external') DEFAULT 'local' AFTER report_category",
           'previous_status' => "VARCHAR(50) DEFAULT NULL",
           'archived_from' => "VARCHAR(100) DEFAULT NULL",
           'approval_status' => "VARCHAR(50) DEFAULT NULL",
           'source_pk' => "INT NULL DEFAULT NULL",
           'start_address' => "VARCHAR(100) NULL DEFAULT NULL",
           'end_address' => "VARCHAR(100) NULL DEFAULT NULL",
           'ipms_polyline_json' => "LONGTEXT NULL DEFAULT NULL"] as $col => $def) {
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
$is_supervisor_role = in_array($_SESSION['role'] ?? '', ['road_ops_supervisor', 'trans_ops_supervisor'], true);
$your_reports_only = isset($_GET['mine'])
    ? ((string)$_GET['mine'] === '1')
    : $is_supervisor_role;

// Trans roles may only ever query the Road & Transportation (LGU Monitoring)
// and Citizen sources. Normalize any tampered source parameter (CIMM /
// Infrastructure) to 'all' BEFORE the filter switch below, so the per-source
// WHERE clause can never be built for the excluded sources.
if ($is_trans_role && ($source_filter === 'cimm' || $source_filter === 'infrastructure')) {
    $source_filter = 'all';
}

// Staff Information Changes is admin-only (lives on change_requests, not report archives).
if (!$is_system_admin && $source_filter === 'staff_info_changes') {
    $source_filter = 'all';
}

$is_staff_info_archive = ($is_system_admin && $source_filter === 'staff_info_changes');
if ($is_staff_info_archive && !in_array($status_filter, ['all', 'approved', 'rejected'], true)) {
    $status_filter = 'all';
}

$staff_change_archives = [];
$total_archives = 0;
$archives = false;
$archive_from_sql = '';
$where_sql = '';

if ($is_staff_info_archive) {
    // Existing change_requests rows with final status — no new archive table.
    $cr_where = ["cr.status IN ('approved', 'rejected')"];
    if ($status_filter === 'approved' || $status_filter === 'rejected') {
        $cr_where[] = "cr.status = '" . $conn->real_escape_string($status_filter) . "'";
    }
    if ($id_search !== '') {
        $esc = $conn->real_escape_string($id_search);
        $cr_where[] = "(CAST(cr.id AS CHAR) LIKE '%{$esc}%' OR u.full_name LIKE '%{$esc}%' OR u.email LIKE '%{$esc}%')";
    }
    $cr_where_sql = 'WHERE ' . implode(' AND ', $cr_where);
    $order_dir = ($sort_order === 'earliest') ? 'ASC' : 'DESC';
    try {
        $cr_sql = "
            SELECT cr.*, u.full_name AS user_name, u.email AS user_email,
                   u.department AS user_department, u.address AS user_address,
                   u.civil_status AS user_civil_status, u.birthday AS user_birthday,
                   u.phone_number AS user_phone_number, u.id_file_path AS user_id_file,
                   rv.full_name AS reviewed_by_name
            FROM change_requests cr
            LEFT JOIN users u ON cr.user_id = u.id
            LEFT JOIN users rv ON cr.reviewed_by = rv.id
            {$cr_where_sql}
            ORDER BY COALESCE(cr.reviewed_at, cr.created_at) {$order_dir}
        ";
        $cr_res = $conn->query($cr_sql);
        if ($cr_res) {
            $staff_change_archives = $cr_res->fetch_all(MYSQLI_ASSOC);
        }
    } catch (Exception $e) {
        error_log('archive.php staff change requests: ' . $e->getMessage());
        $staff_change_archives = [];
    }
    $total_archives = count($staff_change_archives);
} else {
$include_cimm = !$is_trans_role;
$include_ipms = !$is_trans_role;
$archive_from_sql = rgmap_archive_union_sql($include_cimm, $include_ipms);

switch ($source_filter) {
    case 'lgu':
        $source_where = "source_system = 'lgu'";
        break;
    case 'citizen':
        $source_where = "source_system = 'citizen'";
        break;
    case 'cimm':
        $source_where = "source_system = 'cimm'";
        break;
    case 'infrastructure':
        $source_where = "source_system = 'infrastructure'";
        break;
    default:
        $source_where = '';
        break;
}

$trans_source_restrict = '';
if ($is_trans_role) {
    $trans_source_restrict = "source_system IN ('lgu', 'citizen')";
}

$trans_officer_restrict = '';
if ($is_trans_officer) {
    $trans_officer_restrict = "EXISTS (
        SELECT 1 FROM report_assignments ra
        WHERE ra.user_id = " . (int)$_SESSION['user_id'] . " AND ra.status = 'active'
          AND (archive_rows.id = ra.report_id
               OR (
                   archive_rows.report_id IS NOT NULL
                   AND archive_rows.report_id != ''
                   AND archive_rows.report_id = (SELECT r.report_id FROM road_transportation_reports r WHERE r.id = ra.report_id LIMIT 1)
               ))
    )";
}

$where_clauses = [];

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

if (!empty($your_reports_only) && !$is_staff_info_archive) {
    $mine_sql = rgmap_your_reports_archive_sql((int)($_SESSION['user_id'] ?? 0), (string)$user_role);
    if ($mine_sql !== '') {
        $where_clauses[] = $mine_sql;
    }
}

if (!empty($id_search)) {
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

$where_sql = '';
if (!empty($where_clauses)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
}

$order_dir = ($sort_order === 'earliest') ? 'ASC' : 'DESC';

$count_result = $conn->query("SELECT COUNT(*) AS total FROM $archive_from_sql $where_sql");
$total_archives = $count_result ? (int)$count_result->fetch_assoc()['total'] : 0;

$sql = "SELECT * FROM $archive_from_sql $where_sql ORDER BY created_at $order_dir";
$archives = $conn->query($sql);
if ($archives === false) {
    error_log('archive.php union query failed: ' . $conn->error);
    $archives = $conn->query("SELECT *, 'road_transportation_reports_archive' AS archive_table, 'citizen' AS source_system FROM road_transportation_reports_archive WHERE 1 = 0");
}
} // end else (!staff_info_archive)

$source_labels = [
    'lgu'            => 'LGU Monitoring',
    'citizen'        => 'Citizen',
    'cimm'           => 'CIMM',
    'infrastructure' => 'Infrastructure',
];

$archive_summary = [
    'total'     => (int)$total_archives,
    'approved'  => 0,
    'rejected'  => 0,
    'completed' => 0,
    'cancelled' => 0,
];
if ($is_staff_info_archive) {
    foreach ($staff_change_archives as $__cr) {
        $__st = strtolower((string)($__cr['status'] ?? ''));
        if ($__st === 'approved') {
            $archive_summary['approved']++;
        } elseif ($__st === 'rejected') {
            $archive_summary['rejected']++;
        }
    }
} elseif (!empty($archive_from_sql)) {
    try {
        $sum_sql = "SELECT status, COUNT(*) AS c FROM {$archive_from_sql} {$where_sql} GROUP BY status";
        $sum_res = $conn->query($sum_sql);
        if ($sum_res) {
            while ($sum_row = $sum_res->fetch_assoc()) {
                $st = strtolower((string)($sum_row['status'] ?? ''));
                $c = (int)($sum_row['c'] ?? 0);
                if ($st === 'approved') {
                    $archive_summary['approved'] = $c;
                } elseif ($st === 'rejected') {
                    $archive_summary['rejected'] = $c;
                } elseif ($st === 'completed') {
                    $archive_summary['completed'] = $c;
                } elseif ($st === 'cancelled') {
                    $archive_summary['cancelled'] = $c;
                }
            }
        }
    } catch (Exception $e) {
        error_log('archive.php summary counts: ' . $e->getMessage());
    }
}

$source_filter_label = $is_staff_info_archive
    ? 'Staff Information Changes'
    : ($source_labels[$source_filter] ?? ($source_filter === 'all' ? 'All Systems' : ucfirst($source_filter)));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // trans_monitoring_officer and road_monitoring_officer are view-only
    // archive viewers: they may never restore or permanently delete archived
    // reports, regardless of the POST parameters they send.
    if (($is_trans_officer || $is_road_officer) && in_array($_POST['action'], ['restore', 'delete_forever'], true)) {
        $_SESSION['archive_message'] = 'You are not authorized to restore or delete archived reports.';
        header('Location: ' . rgmap_url('archive'));
        exit();
    }

    // Supervisors may only restore/delete archive rows for reports they own.
    if (in_array($user_role, ['road_ops_supervisor', 'trans_ops_supervisor'], true)
        && in_array($_POST['action'], ['restore', 'delete_forever'], true)
        && isset($_POST['archive_id'])) {
        $gate_id = (int)$_POST['archive_id'];
        $gate_table = (string)($_POST['archive_table'] ?? 'road_transportation_reports_archive');
        if (!rgmap_archive_allowed_table($gate_table)) {
            $gate_table = 'road_transportation_reports_archive';
        }
        $gate_row = rgmap_archive_fetch_row($conn, $gate_table, $gate_id);
        if ($gate_row) {
            $gate_row['archive_table'] = $gate_table;
            [$own_id, $own_type] = rgmap_archive_row_assignment_key($gate_row);
            if (!rgmap_supervisor_can_manage_report($conn, $own_id, $own_type)) {
                $owner = rgmap_get_report_owner_supervisor($conn, $own_id, $own_type);
                $owner_name = trim((string)($owner['name'] ?? '')) ?: 'another supervisor';
                $_SESSION['archive_message'] = "This archived report is managed by {$owner_name}. Only the supervisor who assigned it can restore or delete it.";
                header('Location: ' . rgmap_url('archive'));
                exit();
            }
        }
    }

    if ($_POST['action'] === 'restore' && isset($_POST['archive_id'])) {
        // Ownership / access invariant: restore returns the report to its prior
        // workflow/status only. It must NOT create assignments, change
        // assigned_by, or grant access to other supervisors. Officer
        // assignments stay as stored in report_assignments; if the live PK
        // changes, FKs are remapped via rgmap_preserve_ownership_after_restore().
        $archive_id = (int) $_POST['archive_id'];
        $archive_table = (string)($_POST['archive_table'] ?? 'road_transportation_reports_archive');
        if (!rgmap_archive_allowed_table($archive_table)) {
            $archive_table = 'road_transportation_reports_archive';
        }
        if ($is_trans_role && $archive_table !== 'road_transportation_reports_archive') {
            $_SESSION['archive_message'] = 'You are not authorized to restore this archived report.';
            header('Location: ' . rgmap_url('archive'));
            exit();
        }

        if ($archive_table === 'cimm_verification_reports_archive') {
            $row = rgmap_archive_fetch_row($conn, $archive_table, $archive_id);
            if (!$row) {
                $_SESSION['archive_message'] = 'Restore failed – record not found in archive.';
                header('Location: ' . rgmap_url('archive'));
                exit();
            }
            try {
                rgmap_restore_cimm_from_native_archive($conn, $row, $archive_id);
                $_SESSION['archive_message'] = 'Report restored successfully.';
            } catch (Throwable $e) {
                error_log('CIMM restore failed: ' . $e->getMessage());
                $_SESSION['archive_message'] = 'Restore failed – the CIMM report may already exist.';
            }
            header('Location: ' . rgmap_url('archive'));
            exit();
        }

        if ($archive_table === 'ipms_road_projects_archive') {
            $row = rgmap_archive_fetch_row($conn, $archive_table, $archive_id);
            if (!$row) {
                $_SESSION['archive_message'] = 'Restore failed – record not found in archive.';
                header('Location: ' . rgmap_url('archive'));
                exit();
            }
            try {
                if (rgmap_restore_ipms_from_native_archive($conn, $row, $archive_id)) {
                    $_SESSION['archive_message'] = 'Report restored successfully.';
                } else {
                    $_SESSION['archive_message'] = 'Restore failed – the infrastructure project may already exist.';
                }
            } catch (Throwable $e) {
                error_log('IPMS restore failed: ' . $e->getMessage());
                $_SESSION['archive_message'] = 'Restore failed – the infrastructure project may already exist.';
            }
            header('Location: ' . rgmap_url('archive'));
            exit();
        }

        $arch = $conn->prepare("SELECT * FROM road_transportation_reports_archive WHERE id = ?");
        $arch->bind_param('i', $archive_id);
        $arch->execute();
        $row = $arch->get_result()->fetch_assoc();
        if (!$row) {
            $_SESSION['archive_message'] = 'Restore failed – record not found in archive.';
            header('Location: ' . rgmap_url('archive'));
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
                'district' => $row['cimm_district'] ?? $row['district'],
                'starting_date' => $row['cimm_starting_date'] ?? $row['created_date'],
                'estimated_end_date' => $row['cimm_estimated_end_date'] ?? $row['due_date'],
                'engineer' => $row['cimm_engineer_name'] ?? $row['engineer'],
                'budget_allocation' => $row['budget_allocation'] ?? $row['cimm_budget'],
                'budget' => $row['cimm_budget'] ?? $row['budget_allocation'],
                'submitted_at' => $row['created_at'],
                'verified_at' => ($row['completed_at'] ?? null) ?: ($row['rejected_at'] ?? null),
                'verification_status' => $restore_status,
                'approval_status' => 'Approved',
                'cimm_req_id' => $cimm_req_id,
                // Mirrors the transport/maintenance restore behaviour: a
                // CANCELLED report restored from the Archive is marked so the
                // report_management CIMM panel shows it again (so it can later
                // be reopened to Approved/In Progress), while normally-cancelled
                // CIMM reports keep the 0 flag and stay hidden. Non-cancelled
                // restores never get the marker.
                'restored_from_archive' => (strtolower($restore_status) === 'cancelled') ? 1 : 0,
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
                $rfa = (strtolower($restore_status) === 'cancelled') ? 1 : 0;
                // Restore ALL original information (start/end dates, engineer,
                // budget, etc.) from the archived copy, not just the status, so
                // a reopened/re-completed CIMM report keeps everything it had.
                $upd = $conn->prepare(
                    "UPDATE cimm_verification_reports SET
                        infrastructure = ?, issue = ?, location = ?, coord_lat = ?, coord_lng = ?,
                        priority = ?, reporter_name = ?, district = ?, starting_date = ?, estimated_end_date = ?,
                        engineer = ?, budget_allocation = ?, budget = ?, submitted_at = ?, verified_at = ?,
                        verification_status = ?, approval_status = 'Approved', resolved_at = NULL,
                        updated_at = NOW(), restored_from_archive = ? WHERE id = ?"
                );
                $b_alloc = $row['budget_allocation'] ?? $row['cimm_budget'] ?? null;
                $b = $row['cimm_budget'] ?? $row['budget_allocation'] ?? null;
                $v_district = $row['cimm_district'] ?? $row['district'];
                $v_start = $row['cimm_starting_date'] ?? $row['created_date'];
                $v_end = $row['cimm_estimated_end_date'] ?? $row['due_date'];
                $v_engineer = $row['cimm_engineer_name'] ?? $row['engineer'];
                $v_verified_at = ($row['completed_at'] ?? null) ?: ($row['rejected_at'] ?? null);
                $upd->bind_param(
                    "sssddssssssddsssii",
                    $row['title'],
                    $row['description'],
                    $row['location'],
                    $row['latitude'],
                    $row['longitude'],
                    $row['priority'],
                    $row['reporter_name'],
                    $v_district,
                    $v_start,
                    $v_end,
                    $v_engineer,
                    $b_alloc,
                    $b,
                    $row['created_at'],
                    $v_verified_at,
                    $restore_status,
                    $rfa,
                    $existing['id']
                );
                $upd->execute();
                if ($upd->affected_rows >= 0) {
                    rgmap_preserve_ownership_after_restore(
                        $conn,
                        (int)$existing['id'],
                        $original_pk,
                        'cimm_verification_reports'
                    );
                    $delete = $conn->prepare("DELETE FROM road_transportation_reports_archive WHERE id = ?");
                    $delete->bind_param('i', $archive_id);
                    $delete->execute();
                    $_SESSION['archive_message'] = 'Report restored successfully.';
                } else {
                    $_SESSION['archive_message'] = 'Restore failed – the report may already exist.';
                }
                header('Location: ' . rgmap_url('archive'));
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
                header('Location: ' . rgmap_url('archive'));
                exit();
            }
            if ($stmt->affected_rows > 0) {
                $new_id = $insert_with_id ? $original_pk : (int)$conn->insert_id;
                rgmap_preserve_ownership_after_restore(
                    $conn,
                    $new_id,
                    $original_pk,
                    'cimm_verification_reports'
                );
                $delete = $conn->prepare("DELETE FROM road_transportation_reports_archive WHERE id = ?");
                $delete->bind_param('i', $archive_id);
                $delete->execute();
                $_SESSION['archive_message'] = 'Report restored successfully.';
            } else {
                $_SESSION['archive_message'] = 'Restore failed – the report may already exist.';
            }
            header('Location: ' . rgmap_url('archive'));
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
            header('Location: ' . rgmap_url('archive'));
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
            // Mark cancelled reports that are coming back from the Archive so
            // the monitoring panels can show them again (a normally-cancelled
            // report keeps restored_from_archive = 0 and stays hidden).
            if ($field === 'restored_from_archive') {
                $value = (strtolower($restore_status) === 'cancelled') ? 1 : 0;
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
            // Mark the restored-cancelled report so the monitoring panels show
            // it again; a normally-cancelled report keeps the 0 flag and stays
            // hidden.
            $sql .= ", restored_from_archive = " . ((strtolower($restore_status) === 'cancelled') ? 1 : 0);
            $sql .= " WHERE id = ?";
            $upd = $conn->prepare($sql);
            $upd->bind_param("si", $restore_status, $existing_id);
            $upd->execute();
            if ($upd->affected_rows >= 0) {
                rgmap_preserve_ownership_after_restore(
                    $conn,
                    (int)$existing_id,
                    $original_pk,
                    $target_table
                );
                $delete = $conn->prepare("DELETE FROM road_transportation_reports_archive WHERE id = ?");
                $delete->bind_param('i', $archive_id);
                $delete->execute();
                $_SESSION['archive_message'] = 'Report restored successfully.';
            } else {
                $_SESSION['archive_message'] = 'Restore failed – the record may already exist.';
            }
            header('Location: ' . rgmap_url('archive'));
            exit();
        }

        $insert = "INSERT INTO $target_table ($cols) VALUES ($place)";
        $stmt = $conn->prepare($insert);
        try {
            $stmt->execute($values);
        } catch (Exception $e) {
            $_SESSION['archive_message'] = 'Restore failed – the report may already exist (duplicate report_id).';
            header('Location: ' . rgmap_url('archive'));
            exit();
        }
        if ($stmt->affected_rows > 0) {
            $new_id = $insert_with_id ? $original_pk : (int)$conn->insert_id;
            rgmap_preserve_ownership_after_restore(
                $conn,
                $new_id,
                $original_pk,
                $target_table
            );
            $delete = $conn->prepare("DELETE FROM road_transportation_reports_archive WHERE id = ?");
            $delete->bind_param('i', $archive_id);
            $delete->execute();
            $_SESSION['archive_message'] = 'Report restored successfully.';
        } else {
            $_SESSION['archive_message'] = 'Restore failed – the record may already exist.';
        }
        header('Location: ' . rgmap_url('archive'));
        exit();
    }
    if ($_POST['action'] === 'delete_forever' && isset($_POST['archive_id'])) {
        $archive_id = (int) $_POST['archive_id'];
        $archive_table = (string)($_POST['archive_table'] ?? 'road_transportation_reports_archive');
        if (!rgmap_archive_allowed_table($archive_table)) {
            $archive_table = 'road_transportation_reports_archive';
        }
        if ($is_trans_role && $archive_table !== 'road_transportation_reports_archive') {
            $_SESSION['archive_message'] = 'You are not authorized to delete this archived report.';
            header('Location: ' . rgmap_url('archive'));
            exit();
        }
        if ($archive_table === 'ipms_road_projects_archive') {
            $ipms_row = rgmap_archive_fetch_row($conn, $archive_table, $archive_id);
            $project_id = (int)($ipms_row['project_id'] ?? 0);
            if ($project_id > 0) {
                rgmap_ipms_exclude_project($conn, $project_id, 'delete_forever');
            }
        }
        $delete = "DELETE FROM `$archive_table` WHERE id = ?";
        $stmt = $conn->prepare($delete);
        $stmt->bind_param('i', $archive_id);
        $stmt->execute();
        $_SESSION['archive_message'] = 'Report permanently deleted.';
        header('Location: ' . rgmap_url('archive'));
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
    <?php require_once __DIR__ . '/../../includes/page_head_base.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archive | LGU Staff</title>
    <link rel="icon" type="image/png" href="lgu_staff/assets/img/infra-gov-logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="lgu_staff/css/theme-tokens.css">
    <link rel="stylesheet" href="lgu_staff/css/theme-utilities.css">
    <link rel="stylesheet" href="lgu_staff/css/sidebar.css?v=6">
    <link rel="stylesheet" href="styles/transition.css">
    <?php if (!empty($_SESSION['darkmode'])): ?><link rel="stylesheet" href="lgu_staff/css/dark-mode.css"><?php endif; ?>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { background: #f5f3ee; min-height: 100vh; color: var(--text-primary); }
        body.dark-mode { background: var(--bg-page); }
        html { scroll-behavior: smooth; }

        .main-content.archive-dash {
            margin-left: 250px;
            padding: 28px 32px;
            max-width: 100%;
            overflow-x: hidden;
            position: relative;
            z-index: 1;
        }

        .archive-dash .dashboard-header {
            background: #f4f7fb;
            border-radius: 14px;
            padding: 20px 26px;
            margin-bottom: 22px;
            border: 1px solid #d5dce8;
            box-shadow: var(--shadow-card);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .archive-dash .welcome-text h1 {
            font-size: 22px; font-weight: 700; color: var(--text-primary);
            margin-bottom: 4px; display: flex; align-items: center; gap: 12px;
        }
        .archive-dash .header-icon {
            width: 40px; height: 40px; border-radius: 10px;
            display: inline-flex; align-items: center; justify-content: center;
            background: var(--color-primary-bg);
            color: var(--color-primary); font-size: 16px;
        }
        .archive-dash .welcome-text p { color: var(--text-secondary); font-size: 13px; }

        .dt-chip {
            display: flex; align-items: center; gap: 10px;
            background: var(--color-primary-bg, #eef2ff);
            border: 1px solid var(--border-default, #d5dce8);
            border-radius: 14px; padding: 10px 14px;
            flex-shrink: 0;
        }
        .dt-chip i {
            color: #fff; font-size: 16px; width: 28px; height: 28px; border-radius: 8px;
            display: inline-flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, #1e3c72, #0f274a);
        }
        .dt-chip #currentDate { font-weight: 600; color: var(--text-primary, #0f172a); font-size: 13px; }
        .dt-chip #currentTime { color: var(--text-secondary, #64748b); font-size: 12px; margin-top: 1px; }

        .summary-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 22px;
        }
        .summary-card {
            background: #f4f7fb;
            border-radius: 14px;
            padding: 18px 18px 16px;
            border: 1px solid #d5dce8;
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow-card);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .summary-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 22px rgba(30, 60, 114, 0.12);
        }
        .summary-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
        }
        .summary-card.blue::before { background: #1e3c72; }
        .summary-card.amber::before { background: #d97706; }
        .summary-card.emerald::before { background: #059669; }
        .summary-card.rose::before { background: #e11d48; }
        .summary-card.violet::before { background: #4c1d95; }
        .summary-card .card-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }
        .summary-card .card-icon {
            width: 42px; height: 42px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center; font-size: 16px;
        }
        .summary-card.blue .card-icon { background: rgba(55,98,200,0.14); color: #3762c8; }
        .summary-card.amber .card-icon { background: rgba(217,119,6,0.18); color: #b45309; }
        .summary-card.emerald .card-icon { background: rgba(5,150,105,0.18); color: #047857; }
        .summary-card.rose .card-icon { background: rgba(225,29,72,0.16); color: #be123c; }
        .summary-card.violet .card-icon { background: rgba(76,29,149,0.16); color: #4c1d95; }
        .summary-card .card-value {
            font-size: 28px; font-weight: 700; color: var(--text-primary); letter-spacing: -0.03em;
        }
        .summary-card .card-label {
            font-size: 12px; color: var(--text-secondary); font-weight: 600; margin-top: 2px;
        }

        .filters-section {
            background: #f4f7fb;
            border-radius: 14px;
            padding: 18px 20px;
            border: 1px solid #d5dce8;
            box-shadow: var(--shadow-card);
            margin-bottom: 20px;
            position: relative;
            overflow: hidden;
        }
        .filters-section::after {
            content: '';
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 4px;
            background: #3762c8;
        }
        .filters-section-title {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
        }
        .filters-section-title .title-icon {
            width: 28px; height: 28px; border-radius: 8px;
            display: inline-flex; align-items: center; justify-content: center;
            background: var(--color-primary-bg);
            color: var(--color-primary);
            font-size: 12px;
        }
        .filter-group {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: flex-end;
        }
        .filter-group > div {
            flex: 1;
            min-width: 150px;
        }
        .filter-group > div.filter-actions {
            flex: 0 0 auto;
            min-width: 0;
        }
        .filter-group .form-label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .filter-select {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid #d5dce8;
            border-radius: 10px;
            font-size: 13px;
            font-family: 'Poppins', sans-serif;
            background: #fff;
            color: var(--text-primary);
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .filter-select:focus {
            outline: none;
            border-color: #3762c8;
            box-shadow: 0 0 0 3px rgba(55, 98, 200, 0.15);
        }
        .btn-secondary-custom {
            padding: 9px 14px;
            border-radius: 10px;
            border: 1px solid #d5dce8;
            background: #fff;
            color: var(--text-secondary);
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-family: 'Poppins', sans-serif;
            transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
        }
        .btn-secondary-custom:hover {
            background: var(--color-primary-bg);
            border-color: #3762c8;
            color: #3762c8;
        }

        .btn-your-reports {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 9px 14px;
            min-height: 40px;
            border-radius: 10px;
            border: 1px solid #3762c8;
            background: #3762c8;
            color: #fff;
            font-size: 12px;
            font-weight: 600;
            line-height: 1.2;
            cursor: pointer;
            white-space: nowrap;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
            transition: background 0.15s ease, border-color 0.15s ease;
        }
        .btn-your-reports:hover {
            background: #1e3c72;
            border-color: #1e3c72;
            color: #fff;
        }
        .btn-your-reports i { pointer-events: none; }
        .filter-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            gap: 8px;
        }
        .filter-actions .btn-secondary-custom,
        .filter-actions .btn-your-reports {
            min-height: 40px;
            padding: 9px 14px;
            font-size: 12px;
            font-weight: 600;
            border-radius: 10px;
        }
        .filter-actions .btn-secondary-custom {
            border-color: #3762c8;
            background: #3762c8;
            color: #fff;
        }
        .filter-actions .btn-secondary-custom:hover {
            background: #1e3c72;
            border-color: #1e3c72;
            color: #fff;
        }

        .workflow-card {
            background: #f4f7fb;
            border-radius: 14px;
            padding: 0 0 12px;
            border: 1px solid #d5dce8;
            box-shadow: var(--shadow-card);
            position: relative;
            overflow: hidden;
            margin-bottom: 24px;
        }
        .workflow-card.panel-archive::after {
            content: '';
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 4px;
            background: #1e3c72;
        }
        .workflow-card.panel-staff::after { background: #4c1d95; }
        .workflow-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin: 0 0 12px;
            padding: 14px 18px 14px 20px;
            border-bottom: 1px solid var(--border-light);
            background: rgba(30, 60, 114, 0.08);
        }
        .workflow-card.panel-staff .workflow-header { background: rgba(76, 29, 149, 0.10); }
        .workflow-title {
            font-size: 14px;
            font-weight: 600;
            color: #1e3c72;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .workflow-card.panel-staff .workflow-title { color: #4c1d95; }
        .workflow-title .title-icon {
            width: 30px; height: 30px; border-radius: 9px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 13px; flex-shrink: 0;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: #fff;
        }
        .workflow-card.panel-staff .title-icon {
            background: linear-gradient(135deg, #7c3aed, #4c1d95);
        }
        .workflow-badge {
            background: #1e3c72;
            color: #fff;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .workflow-card.panel-staff .workflow-badge { background: #4c1d95; }
        .workflow-content {
            padding: 4px 14px 8px 18px;
            max-height: none;
        }

        .archive-item {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 16px 14px;
            margin-bottom: 10px;
            background: #f8fafc;
            border-radius: 12px;
            border: 1px solid #d5dce8;
            border-left: 4px solid #3762c8;
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
        }
        .archive-item:last-child { margin-bottom: 0; }
        .archive-item:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 22px rgba(30, 60, 114, 0.10);
            border-color: #c5d0e0;
            background: #f1f5f9;
        }
        .archive-item.status-approved { border-left-color: #059669; }
        .archive-item.status-completed { border-left-color: #059669; }
        .archive-item.status-rejected { border-left-color: #e11d48; }
        .archive-item.status-cancelled { border-left-color: #64748b; }
        .archive-item.status-pending { border-left-color: #d97706; }
        .archive-item.status-in-progress { border-left-color: #3762c8; }
        .archive-icon {
            width: 42px; height: 42px;
            background: var(--color-primary-bg);
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            color: var(--color-primary);
            font-size: 15px;
            flex-shrink: 0;
        }
        .archive-content { flex: 1; min-width: 0; }
        .archive-title-row {
            display: flex; align-items: flex-start; justify-content: space-between;
            gap: 12px; margin-bottom: 8px; flex-wrap: wrap;
        }
        .archive-title {
            font-size: 15px; font-weight: 600; color: var(--text-primary);
            line-height: 1.35; margin: 0; flex: 1; min-width: 180px;
        }
        .archive-meta { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }
        .meta-item {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 12px; color: var(--text-secondary);
            background: #f4f7fb; border: 1px solid #d5dce8;
            padding: 4px 10px; border-radius: 999px;
        }
        .meta-item i { color: #3762c8; font-size: 11px; }
        .archive-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 2px; }

        .btn-view, .btn-restore, .btn-delete-forever, .btn-export {
            padding: 8px 14px; border: none; border-radius: 8px; font-size: 12px; font-weight: 600;
            cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
            transition: transform 0.15s ease, box-shadow 0.15s ease, filter 0.15s ease;
            font-family: 'Poppins', sans-serif; text-decoration: none;
        }
        .btn-view:hover, .btn-restore:hover, .btn-delete-forever:hover { transform: translateY(-1px); }
        .btn-view {
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: #fff;
            box-shadow: 0 4px 12px rgba(30, 60, 114, 0.3);
        }
        .btn-view:hover { filter: brightness(1.06); color: #fff; }
        .btn-restore {
            background: linear-gradient(135deg, #10b981, #059669);
            color: #fff;
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3);
        }
        .btn-restore:hover { filter: brightness(1.06); color: #fff; }
        .btn-delete-forever {
            background: #fff;
            color: #e11d48;
            border: 1px solid #fecdd3;
        }
        .btn-delete-forever:hover { background: #fff1f2; }
        .btn-export {
            background: #fff;
            color: #0369a1;
            border: 1px solid #bae6fd;
        }

        .notification {
            position: fixed; top: 20px; right: 20px; padding: 14px 18px; border-radius: 10px;
            color: white; font-weight: 500; z-index: 10000; animation: slideIn 0.3s ease;
            box-shadow: 0 8px 24px rgba(0,0,0,0.18);
        }
        .notification.success { background: #059669; }
        .notification.error { background: #e11d48; }
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        .empty-state { text-align: center; padding: 64px 24px; color: var(--text-muted); }
        .empty-state i {
            width: 64px; height: 64px; border-radius: 18px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 26px; margin-bottom: 16px; color: var(--color-primary);
            background: var(--color-primary-bg); border: 1px solid #d5dce8;
        }
        .empty-state p { font-size: 14px; color: var(--text-secondary); margin: 0; }
        .empty-state-sub { font-size: 12px; color: var(--text-muted); margin-top: 6px; }

        /* Modal */
        .modal-overlay {
            display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.45); z-index: 10000; align-items: center;
            justify-content: center; padding: 20px; overflow-y: auto;
            backdrop-filter: blur(4px);
        }
        .modal-overlay.active { display: flex; }
        .modal-content {
            background: #fff; border-radius: 16px; padding: 24px;
            max-width: 900px; width: 100%; max-height: calc(100vh - 40px);
            position: relative; box-shadow: 0 16px 48px rgba(15, 23, 42, 0.18);
            margin: auto; display: flex; flex-direction: column;
            border: 1px solid #e2e8f0;
        }
        .modal-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 16px; padding-bottom: 14px;
            border-bottom: 1px solid #eef2f7; flex-shrink: 0;
        }
        .modal-header h2 { color: #1e3c72; font-size: 20px; margin: 0; flex: 1; }
        .modal-close {
            background: #f1f5f9; border: none; font-size: 18px; color: #64748b;
            cursor: pointer; width: 36px; height: 36px; display: flex;
            align-items: center; justify-content: center; border-radius: 10px;
            transition: background 0.2s, color 0.2s; flex-shrink: 0; margin-left: 15px;
        }
        .modal-close:hover { background: #fee2e2; color: #dc2626; }
        .modal-body { overflow-y: auto; flex: 1; min-height: 0; padding-right: 10px; margin-right: -10px; }
        .modal-body::-webkit-scrollbar { width: 8px; }
        .modal-body::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
        .modal-body::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .modal-body::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .detail-row {
            display: flex; margin-bottom: 12px; padding-bottom: 12px;
            border-bottom: 1px solid #f1f5f9;
        }
        .detail-label { font-weight: 600; color: #1e3c72; width: 150px; flex-shrink: 0; font-size: 13px; }
        .detail-value { color: #64748b; flex: 1; font-size: 13px; }
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
            border: 1px solid #e2e8f0;
        }

        .rm-modal-header {
            background: #fff;
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
            color: #3762c8;
            font-weight: 600;
            letter-spacing: 0.3px;
            margin-bottom: 4px;
        }

        .rm-modal-title {
            font-size: 20px;
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
            background: #f8fafc;
            border-radius: 12px;
            padding: 16px 18px;
            margin-bottom: 14px;
            box-shadow: none;
            border: 1px solid #e2e8f0;
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
            padding: 10px 20px;
            background: #3762c8;
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
            box-shadow: 0 2px 8px rgba(55, 98, 200, 0.2);
        }

        .rm-modal-btn-export:hover {
            background: #1e3c72;
            box-shadow: 0 4px 12px rgba(55, 98, 200, 0.28);
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

        .arch-view-map-btn {
            margin-left: auto;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            background: rgba(249, 115, 22, 0.1);
            color: #f97316;
            border: 1px solid rgba(249, 115, 22, 0.3);
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            transition: all 0.2s;
        }

        .arch-view-map-btn:hover {
            background: rgba(249, 115, 22, 0.2);
        }

        .road-map-container {
            display: none;
            width: 100%;
            height: 280px;
            margin-top: 12px;
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid rgba(55, 98, 200, 0.2);
        }

        .road-map-container.road-map-visible {
            display: block;
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
            color: #e4e6ea !important;
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
            background: #3b82f6 !important;
            color: #fff !important;
        }
        body.dark-mode .rm-modal-btn-export:hover {
            background: #2563eb !important;
        }
        body.dark-mode .rm-modal-body::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.05) !important;
        }
        body.dark-mode .rm-modal-body::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.15) !important;
        }

        /* Dark mode — match other admin dashboards */
        body.dark-mode { background: var(--bg-page) !important; color: var(--text-primary); }
        body.dark-mode .archive-dash .dashboard-header,
        body.dark-mode .summary-card,
        body.dark-mode .filters-section,
        body.dark-mode .workflow-card {
            background: #1c2432 !important;
            border-color: rgba(147, 179, 224, 0.22) !important;
            box-shadow: none !important;
        }
        body.dark-mode .archive-dash .header-icon {
            background: rgba(55, 98, 200, 0.22) !important;
            color: #93c5fd !important;
        }
        body.dark-mode .archive-dash .welcome-text h1,
        body.dark-mode .archive-title,
        body.dark-mode .workflow-title,
        body.dark-mode .summary-card .card-value,
        body.dark-mode .modal-header h2,
        body.dark-mode .detail-label,
        body.dark-mode .rm-modal-title {
            color: #e4e6ea !important;
        }
        body.dark-mode .archive-dash .welcome-text p,
        body.dark-mode .summary-card .card-label,
        body.dark-mode .meta-item,
        body.dark-mode .empty-state p,
        body.dark-mode .empty-state-sub,
        body.dark-mode .detail-value,
        body.dark-mode .filter-group .form-label,
        body.dark-mode .filters-section-title {
            color: #b0b7c3 !important;
        }
        body.dark-mode .workflow-header {
            background: rgba(255,255,255,0.03) !important;
            border-color: rgba(147, 179, 224, 0.22) !important;
        }
        body.dark-mode .archive-item {
            background: rgba(255, 255, 255, 0.04) !important;
            border-color: rgba(147, 179, 224, 0.18) !important;
        }
        body.dark-mode .archive-item:hover {
            background: rgba(255, 255, 255, 0.07) !important;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.35) !important;
        }
        body.dark-mode .archive-icon {
            background: rgba(55, 98, 200, 0.2) !important;
            color: #93c5fd !important;
        }
        body.dark-mode .meta-item {
            background: #1c2432 !important;
            border-color: rgba(147, 179, 224, 0.18) !important;
        }
        body.dark-mode .meta-item i { color: #93c5fd !important; }
        body.dark-mode .empty-state i {
            color: #93c5fd !important;
            background: rgba(55, 98, 200, 0.18) !important;
            border-color: rgba(147, 179, 224, 0.22) !important;
        }
        body.dark-mode .btn-view {
            background: linear-gradient(135deg, #3762c8, #1e3c72) !important;
            color: #fff !important;
        }
        body.dark-mode .btn-restore {
            background: linear-gradient(135deg, #10b981, #059669) !important;
            color: #fff !important;
        }
        body.dark-mode .btn-delete-forever {
            background: #22262e !important;
            color: #fca5a5 !important;
            border: 1px solid #7f1d1d !important;
        }
        body.dark-mode .btn-export {
            background: #22262e !important;
            color: #7dd3fc !important;
            border-color: #075985 !important;
        }
        body.dark-mode .modal-content,
        body.dark-mode .rm-modal-content {
            background: #1c2432 !important;
            border-color: rgba(147, 179, 224, 0.22) !important;
        }
        body.dark-mode .modal-header,
        body.dark-mode .rm-modal-header {
            background: #1c2432 !important;
            border-color: rgba(147, 179, 224, 0.22) !important;
        }
        body.dark-mode .modal-close,
        body.dark-mode .rm-modal-close {
            background: #22262e !important;
            color: #b0b7c3 !important;
        }
        body.dark-mode .modal-close:hover,
        body.dark-mode .rm-modal-close:hover {
            background: rgba(220, 53, 69, 0.2) !important;
            color: #fca5a5 !important;
        }
        body.dark-mode .detail-row { border-color: rgba(147, 179, 224, 0.18) !important; }
        body.dark-mode .rm-modal-report-id { color: #93c5fd !important; }
        body.dark-mode .filter-select {
            background: #22262e !important;
            color: #e4e6ea !important;
            border-color: #374151 !important;
            color-scheme: dark;
        }
        body.dark-mode .filter-select option {
            background: #1c2432;
            color: #e4e6ea;
        }
        body.dark-mode .btn-secondary-custom {
            background: #22262e !important;
            color: #e4e6ea !important;
            border: 1px solid #374151 !important;
        }
        body.dark-mode .filter-actions .btn-secondary-custom,
        body.dark-mode .btn-your-reports {
            background: #3762c8 !important;
            border-color: #3762c8 !important;
            color: #fff !important;
        }
        body.dark-mode .filter-actions .btn-secondary-custom:hover,
        body.dark-mode .btn-your-reports:hover {
            background: #1e3c72 !important;
            border-color: #1e3c72 !important;
            color: #fff !important;
        }

        .source-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            background: var(--color-primary-bg);
            color: var(--color-primary);
        }
        .source-transport,
        .source-maintenance,
        .source-lgu,
        .source-citizen,
        .source-cimm,
        .source-infrastructure {
            background: var(--color-primary-bg);
            color: var(--color-primary);
        }
        .status-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: capitalize;
        }
        .status-badge.status-completed,
        .status-badge.status-approved {
            background: rgba(5, 150, 105, 0.14);
            color: #047857;
        }
        .status-badge.status-pending {
            background: rgba(217, 119, 6, 0.16);
            color: #b45309;
        }
        .status-badge.status-in-progress {
            background: rgba(55, 98, 200, 0.14);
            color: #3762c8;
        }
        .status-badge.status-rejected {
            background: rgba(225, 29, 72, 0.14);
            color: #be123c;
        }
        .status-badge.status-cancelled {
            background: rgba(100, 116, 139, 0.16);
            color: #475569;
        }

        /* Staff change request Review modal (view-only archive) */
        #staffChangeArchiveModal.scr-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 10050;
            background: rgba(15, 23, 42, 0.45);
            align-items: center;
            justify-content: center;
            padding: 24px 12px;
        }
        #staffChangeArchiveModal .scr-modal-content {
            background: #f4f7fb;
            border-radius: 14px;
            max-width: 560px;
            width: 92vw;
            max-height: calc(100vh - 48px);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            box-shadow: var(--shadow-lg);
            border: 1px solid #d5dce8;
        }
        #staffChangeArchiveModal .scr-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 20px;
            border-bottom: 1px solid #e5e7eb;
            background: #f8fafc;
        }
        #staffChangeArchiveModal .scr-modal-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 15px;
            margin: 0;
            color: #0f172a;
        }
        #staffChangeArchiveModal .scr-modal-title-icon {
            width: 32px;
            height: 32px;
            border-radius: 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--color-primary-bg);
            color: var(--color-primary);
            font-size: 13px;
        }
        #staffChangeArchiveModal .scr-modal-close {
            border: none;
            background: transparent;
            font-size: 24px;
            line-height: 1;
            color: #64748b;
            cursor: pointer;
        }
        #staffChangeArchiveModal .scr-modal-body {
            padding: 16px 20px;
            overflow-y: auto;
            min-height: 0;
            flex: 1 1 auto;
        }
        #staffChangeArchiveModal .scr-modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 14px 20px;
            border-top: 1px solid #e5e7eb;
            background: #f8fafc;
        }
        #staffChangeArchiveModal .scr-staff-header {
            display: flex;
            align-items: center;
            gap: 14px;
            padding-bottom: 14px;
            border-bottom: 1px solid #e5e7eb;
            margin-bottom: 16px;
        }
        #staffChangeArchiveModal .scr-staff-avatar {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(30, 60, 114, 0.3);
        }
        #staffChangeArchiveModal .scr-staff-name {
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
        }
        #staffChangeArchiveModal .scr-staff-date {
            font-size: 12px;
            color: #64748b;
            margin-top: 2px;
        }
        #staffChangeArchiveModal .scr-section {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 14px;
            margin-bottom: 12px;
        }
        #staffChangeArchiveModal .scr-section.scr-requested {
            background: var(--color-primary-bg);
            border-color: #d5dce8;
        }
        #staffChangeArchiveModal .scr-section-title {
            font-size: 12px;
            font-weight: 700;
            color: #475569;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        #staffChangeArchiveModal .scr-compare-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        #staffChangeArchiveModal .scr-compare-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        #staffChangeArchiveModal .scr-compare-label {
            font-size: 10px;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        #staffChangeArchiveModal .scr-compare-old,
        #staffChangeArchiveModal .scr-compare-new {
            font-size: 12px;
            padding: 8px 12px;
            border-radius: 8px;
        }
        #staffChangeArchiveModal .scr-compare-old {
            color: #64748b;
            background: #f1f5f9;
            border-left: 3px solid #cbd5e1;
        }
        #staffChangeArchiveModal .scr-compare-new {
            color: #0f172a;
            background: rgba(55, 98, 200, 0.12);
            border-left: 3px solid #3762c8;
            font-weight: 500;
        }
        #staffChangeArchiveModal .scr-compare-new.no-change {
            color: #94a3b8;
            background: #f1f5f9;
            border-left-color: #cbd5e1;
            font-weight: 400;
        }
        #staffChangeArchiveModal .scr-media-preview img {
            max-width: 100%;
            max-height: 140px;
            border-radius: 8px;
            display: block;
        }
        #staffChangeArchiveModal .scr-media-label {
            display: block;
            font-size: 11px;
            color: #64748b;
            margin-top: 6px;
        }
        #staffChangeArchiveModal .scr-status-line {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            margin-top: 8px;
        }
        #staffChangeArchiveModal .scr-btn-close {
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            background: #64748b;
            color: #fff;
        }
        body.dark-mode #staffChangeArchiveModal .scr-modal-content,
        body.dark-mode #staffChangeArchiveModal .scr-modal-header,
        body.dark-mode #staffChangeArchiveModal .scr-modal-footer {
            background: #1c2432;
            border-color: rgba(147, 179, 224, 0.22);
        }
        body.dark-mode #staffChangeArchiveModal .scr-modal-title,
        body.dark-mode #staffChangeArchiveModal .scr-staff-name { color: #e4e6ea; }
        body.dark-mode #staffChangeArchiveModal .scr-section {
            background: rgba(255,255,255,0.03);
            border-color: rgba(147, 179, 224, 0.22);
        }
        body.dark-mode #staffChangeArchiveModal .scr-section.scr-requested {
            background: rgba(99, 102, 241, 0.12);
        }
        @media (max-width: 640px) {
            #staffChangeArchiveModal .scr-compare-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 1100px) {
            .summary-row { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .main-content.archive-dash { margin-left: 0; padding: 20px 14px 40px; }
            .summary-row { grid-template-columns: 1fr; }
            .archive-dash .dashboard-header { flex-direction: column; align-items: flex-start; }
            .archive-meta { gap: 6px; }
            .detail-row { flex-direction: column; }
            .detail-label { width: 100%; margin-bottom: 5px; }
            .filter-group > div { flex: 1 1 100%; }
            .filter-group > div.filter-actions { flex: 1 1 100%; }
            .btn-secondary-custom { width: 100%; }
        }
    </style>
    <?php if ($is_trans_ops_supervisor): ?>
    <!-- Transport Operations Supervisor only: keep all four archive summary
         cards in ONE row on phones. The generic rules collapse the grid to
         2x2 / a single column below 1100px; compact the tiles instead so the
         4-column grid fits. UI-only CSS scoping — other portals are
         unaffected and no behaviour changes. -->
    <style>
        @media (max-width: 768px) {
            body.trans-supervisor-view .summary-row {
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 8px;
                margin-bottom: 14px;
            }
            body.trans-supervisor-view .summary-card {
                padding: 10px 8px;
                border-radius: 10px;
                min-width: 0;
                max-width: 100%;
                box-sizing: border-box;
            }
            body.trans-supervisor-view .summary-card::before { height: 3px; }
            body.trans-supervisor-view .summary-card .card-top {
                margin-bottom: 6px;
            }
            body.trans-supervisor-view .summary-card .card-icon {
                width: 26px;
                height: 26px;
                border-radius: 8px;
                font-size: 12px;
            }
            body.trans-supervisor-view .summary-card .card-value { font-size: 16px; }
            body.trans-supervisor-view .summary-card .card-label {
                font-size: 7.5px;
                line-height: 1.25;
                overflow-wrap: anywhere;
                word-break: break-word;
            }
        }
    </style>
    <?php endif; ?>
    <?php if ($is_system_admin): ?>
    <style>
        @media (max-width: 768px) {
            body.system-admin-view .summary-row {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }
        }
    </style>
    <?php endif; ?>
    <?php if ($is_road_supervisor): ?>
    <style>
        @media (max-width: 768px) {
            body.road-supervisor-view .summary-row {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }
        }
    </style>
    <?php endif; ?>
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?><?php echo $is_trans_ops_supervisor ? ' trans-supervisor-view' : ''; ?><?php echo $is_system_admin ? ' system-admin-view' : ''; ?><?php echo $is_road_supervisor ? ' road-supervisor-view' : ''; ?>">
    <?php include '../../includes/sidebar_nav.php'; ?>

    <div class="main-content archive-dash">
        <div class="dashboard-header">
            <div class="welcome-text">
                <h1>
                    <span class="header-icon"><i class="fas fa-archive"></i></span>
                    Archive
                </h1>
                <p><?php echo $is_staff_info_archive
                    ? 'Approved and rejected staff information change requests.'
                    : 'Browse archived reports — filter by status or source, restore, or permanently remove.'; ?></p>
            </div>
            <div class="dt-chip">
                <i class="fas fa-calendar-day"></i>
                <div>
                    <div id="currentDate"></div>
                    <div id="currentTime"></div>
                </div>
            </div>
        </div>

        <div class="summary-row">
            <div class="summary-card blue">
                <div class="card-top">
                    <div class="card-icon"><i class="fas fa-box-archive"></i></div>
                </div>
                <div class="card-value"><?php echo (int)$archive_summary['total']; ?></div>
                <div class="card-label"><?php echo $is_staff_info_archive ? 'Total Requests' : 'Total Archived'; ?></div>
            </div>
            <?php if ($is_staff_info_archive): ?>
            <div class="summary-card emerald">
                <div class="card-top">
                    <div class="card-icon"><i class="fas fa-circle-check"></i></div>
                </div>
                <div class="card-value"><?php echo (int)$archive_summary['approved']; ?></div>
                <div class="card-label">Approved</div>
            </div>
            <div class="summary-card rose">
                <div class="card-top">
                    <div class="card-icon"><i class="fas fa-circle-xmark"></i></div>
                </div>
                <div class="card-value"><?php echo (int)$archive_summary['rejected']; ?></div>
                <div class="card-label">Rejected</div>
            </div>
            <div class="summary-card violet">
                <div class="card-top">
                    <div class="card-icon"><i class="fas fa-user-edit"></i></div>
                </div>
                <div class="card-value" style="font-size:16px;line-height:1.3;padding-top:6px;">Staff Info</div>
                <div class="card-label">Category</div>
            </div>
            <?php else: ?>
            <div class="summary-card emerald">
                <div class="card-top">
                    <div class="card-icon"><i class="fas fa-circle-check"></i></div>
                </div>
                <div class="card-value"><?php echo (int)$archive_summary['completed']; ?></div>
                <div class="card-label">Completed</div>
            </div>
            <div class="summary-card rose">
                <div class="card-top">
                    <div class="card-icon"><i class="fas fa-ban"></i></div>
                </div>
                <div class="card-value"><?php echo (int)$archive_summary['rejected']; ?></div>
                <div class="card-label">Rejected</div>
            </div>
            <div class="summary-card amber">
                <div class="card-top">
                    <div class="card-icon"><i class="fas fa-sitemap"></i></div>
                </div>
                <div class="card-value" style="font-size:15px;line-height:1.3;padding-top:6px;"><?php echo htmlspecialchars($source_filter_label); ?></div>
                <div class="card-label">Source Filter</div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Filters -->
        <div class="filters-section">
            <div class="filters-section-title">
                <span class="title-icon"><i class="fas fa-filter"></i></span>
                Filters
            </div>
            <div class="filter-group">
                <div>
                    <label class="form-label" for="statusFilter">Status</label>
                    <select class="filter-select" id="statusFilter" onchange="filterReports()">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <?php if ($is_staff_info_archive): ?>
                        <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <?php else: ?>
                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="in-progress" <?php echo $status_filter === 'in-progress' ? 'selected' : ''; ?>>In Progress</option>
                        <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="sourceFilter">Source</label>
                    <select class="filter-select" id="sourceFilter" onchange="filterReports()">
                        <option value="all" <?php echo $source_filter === 'all' ? 'selected' : ''; ?>>All Systems</option>
                        <option value="lgu" <?php echo $source_filter === 'lgu' ? 'selected' : ''; ?>>Road & Transportation (LGU Monitoring)</option>
                        <option value="citizen" <?php echo $source_filter === 'citizen' ? 'selected' : ''; ?>>Citizen</option>
                        <?php if (!$is_trans_role): ?>
                        <option value="cimm" <?php echo $source_filter === 'cimm' ? 'selected' : ''; ?>>CIMM</option>
                        <option value="infrastructure" <?php echo $source_filter === 'infrastructure' ? 'selected' : ''; ?>>Infrastructure</option>
                        <?php endif; ?>
                        <?php if ($is_system_admin): ?>
                        <option value="staff_info_changes" <?php echo $source_filter === 'staff_info_changes' ? 'selected' : ''; ?>>Staff Information Changes</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="sortFilter">Sort</label>
                    <select class="filter-select" id="sortFilter" onchange="filterReports()">
                        <option value="latest" <?php echo $sort_order === 'latest' ? 'selected' : ''; ?>>Newest first</option>
                        <option value="earliest" <?php echo $sort_order === 'earliest' ? 'selected' : ''; ?>>Oldest first</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="idSearch"><?php echo $is_staff_info_archive ? 'Search' : 'Search ID'; ?></label>
                    <input type="text" class="filter-select" id="idSearch" placeholder="<?php echo $is_staff_info_archive ? 'Name, email, or request ID…' : 'Report ID…'; ?>" value="<?php echo htmlspecialchars($id_search); ?>" onkeyup="if(event.key === 'Enter') filterReports()">
                </div>
                <div class="filter-actions">
                    <label class="form-label">&nbsp;</label>
                    <?php if (!$is_staff_info_archive): ?>
                    <button type="button"
                        class="btn-your-reports"
                        id="yourReportsBtn"
                        onclick="toggleYourReports()"
                        title="<?php echo !empty($your_reports_only) ? 'Show all reports' : 'Show only reports you are handling'; ?>">
                        <?php if (!empty($your_reports_only)): ?>
                        <i class="fas fa-list"></i> All Reports
                        <?php else: ?>
                        <i class="fas fa-user-check"></i> Your Reports
                        <?php endif; ?>
                    </button>
                    <?php endif; ?>
                    <button type="button" class="btn-secondary-custom" onclick="resetFilters()">
                        <i class="fas fa-arrow-rotate-left"></i> Reset
                    </button>
                </div>
            </div>
        </div>

        <div class="workflow-card <?php echo $is_staff_info_archive ? 'panel-staff' : 'panel-archive'; ?>">
            <div class="workflow-header">
                <h3 class="workflow-title">
                    <span class="title-icon"><i class="fas <?php echo $is_staff_info_archive ? 'fa-user-edit' : 'fa-folder-open'; ?>"></i></span>
                    <?php echo $is_staff_info_archive ? 'Staff Information Changes' : 'Archived Reports'; ?>
                </h3>
                <span class="workflow-badge"><?php echo (int)$total_archives; ?></span>
            </div>
            <div class="workflow-content">

            <?php if ($is_staff_info_archive): ?>
                <?php if (!empty($staff_change_archives)): ?>
                    <?php foreach ($staff_change_archives as $cr):
                        $status_slug = strtolower((string)($cr['status'] ?? ''));
                        $reviewed_display = !empty($cr['reviewed_at'])
                            ? date('M d, Y · g:i A', strtotime($cr['reviewed_at']))
                            : (!empty($cr['created_at']) ? date('M d, Y · g:i A', strtotime($cr['created_at'])) : '—');
                        $req_data = json_decode($cr['requested_data'] ?? '{}', true);
                        if (!is_array($req_data)) { $req_data = []; }
                        $change_labels = [];
                        foreach (['full_name' => 'Name', 'email' => 'Email', 'address' => 'Address', 'civil_status' => 'Civil Status', 'birthday' => 'Birthday', 'phone_number' => 'Phone'] as $fk => $fl) {
                            if (!empty($req_data[$fk])) { $change_labels[] = $fl; }
                        }
                        if (!empty($req_data['new_password']) || !empty($req_data['new_password_hash'])) { $change_labels[] = 'Password'; }
                        if (!empty($req_data['profile_picture'])) { $change_labels[] = 'Profile picture'; }
                        if (!empty($req_data['id_file_path'])) { $change_labels[] = 'ID photo'; }
                    ?>
                    <div class="archive-item status-<?php echo htmlspecialchars($status_slug); ?>">
                        <div class="archive-icon">
                            <i class="fas fa-user-edit"></i>
                        </div>
                        <div class="archive-content">
                            <div class="archive-title-row">
                                <h4 class="archive-title"><?php echo htmlspecialchars($cr['user_name'] ?? 'Unknown Staff'); ?></h4>
                                <span class="status-badge status-<?php echo htmlspecialchars($status_slug); ?>"><?php echo htmlspecialchars(ucfirst($status_slug)); ?></span>
                            </div>
                            <div class="archive-meta">
                                <span class="meta-item"><i class="fas fa-hashtag"></i> CR-<?php echo (int)$cr['id']; ?></span>
                                <span class="meta-item"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($cr['user_email'] ?? '—'); ?></span>
                                <?php if (!empty($cr['user_department'])): ?>
                                <span class="meta-item"><i class="fas fa-building"></i> <?php echo htmlspecialchars($cr['user_department']); ?></span>
                                <?php endif; ?>
                                <span class="meta-item"><i class="fas fa-calendar"></i> <?php echo htmlspecialchars($reviewed_display); ?></span>
                                <?php if (!empty($cr['reviewed_by_name'])): ?>
                                <span class="meta-item"><i class="fas fa-user-check"></i> <?php echo htmlspecialchars($cr['reviewed_by_name']); ?></span>
                                <?php endif; ?>
                                <span class="meta-item"><i class="fas fa-pen"></i> <?php echo htmlspecialchars(!empty($change_labels) ? implode(', ', $change_labels) : 'Staff info change'); ?></span>
                                <span class="meta-item"><i class="fas fa-sitemap"></i>
                                    <span class="source-badge">Staff Information Changes</span>
                                </span>
                            </div>
                            <div class="archive-actions">
                                <button type="button" class="btn-view" onclick="reviewStaffChangeArchive(<?php echo (int)$cr['id']; ?>)">
                                    <i class="fas fa-clipboard-check"></i> Review
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-user-edit"></i>
                    <p>No archived staff change requests</p>
                    <p class="empty-state-sub">Approved or rejected staff information changes will appear here.</p>
                </div>
                <?php endif; ?>
            <?php elseif ($archives && $archives->num_rows > 0): ?>
                <?php while ($row = $archives->fetch_assoc()):
                    $status_slug = strtolower(str_replace('_', '-', (string)($row['status'] ?? '')));
                    $created_display = !empty($row['created_at']) ? date('M d, Y · g:i A', strtotime($row['created_at'])) : '—';
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
                                    <span class="source-badge source-<?php echo htmlspecialchars($row['source_system'] ?? ''); ?>"><?php echo htmlspecialchars($source_labels[$row['source_system']] ?? ($row['source_system'] ?? 'Unknown')); ?></span>
                                </span>
                            </div>
                            <div class="archive-actions">
                                <button type="button" class="btn-view" onclick="viewArchive(<?php echo (int)$row['id']; ?>, '<?php echo htmlspecialchars($row['archive_table'] ?? 'road_transportation_reports_archive', ENT_QUOTES); ?>')">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <?php if (!$is_trans_officer && !$is_road_officer): ?>
                                <?php
                                    $arch_can_manage = true;
                                    if (in_array($user_role, ['road_ops_supervisor', 'trans_ops_supervisor'], true)) {
                                        $tmp_row = $row;
                                        $tmp_row['archive_table'] = $row['archive_table'] ?? 'road_transportation_reports_archive';
                                        [$oid, $otype] = rgmap_archive_row_assignment_key($tmp_row);
                                        $arch_can_manage = rgmap_supervisor_can_manage_report($conn, $oid, $otype);
                                    }
                                ?>
                                <?php if ($arch_can_manage): ?>
                                <?php if (strtolower((string)$row['status']) !== 'rejected'): ?>
                                <form method="POST" style="display: inline-flex;" onsubmit="return confirm('Restore this report back to active table?');">
                                    <input type="hidden" name="archive_id" value="<?php echo (int)$row['id']; ?>">
                                    <input type="hidden" name="archive_table" value="<?php echo htmlspecialchars($row['archive_table'] ?? 'road_transportation_reports_archive'); ?>">
                                    <button type="submit" name="action" value="restore" class="btn-restore">
                                        <i class="fas fa-undo"></i> Restore
                                    </button>
                                </form>
                                <?php endif; ?>
                                <form method="POST" style="display: inline-flex;" onsubmit="return confirm('Permanently delete this archived report? This cannot be undone.');">
                                    <input type="hidden" name="archive_id" value="<?php echo (int)$row['id']; ?>">
                                    <input type="hidden" name="archive_table" value="<?php echo htmlspecialchars($row['archive_table'] ?? 'road_transportation_reports_archive'); ?>">
                                    <button type="submit" name="action" value="delete_forever" class="btn-delete-forever">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </form>
                                <?php else: ?>
                                <span class="meta-item" title="Managed by another supervisor" style="opacity:.75;font-size:12px;">
                                    <i class="fas fa-lock"></i> Managed by another supervisor
                                </span>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-archive"></i>
                    <p>No archived reports found</p>
                    <p class="empty-state-sub">Try adjusting filters or check back after reports are archived.</p>
                </div>
            <?php endif; ?>
            </div>
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
                    <div class="rm-modal-section-title"><i class="fas fa-map-marker-alt"></i> Location
                        <button type="button" id="arch-view-map-btn" class="arch-view-map-btn" style="display:none;" onclick="openArchiveMap()">
                            <i class="fas fa-map-marked-alt"></i> View Map
                        </button>
                    </div>
                    <div class="rm-info-grid" id="rm-location-grid"></div>
                    <div class="road-map-container" id="arch-map-container"></div>
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
                <?php if (!$is_trans_officer): ?>
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

    <!-- Staff Information Change Review (view-only) -->
    <div id="staffChangeArchiveModal" class="scr-modal" onclick="if(event.target===this)closeStaffChangeArchiveModal()">
        <div class="scr-modal-content">
            <div class="scr-modal-header">
                <h2 class="scr-modal-title">
                    <span class="scr-modal-title-icon"><i class="fas fa-clipboard-check"></i></span>
                    Review Change Request
                </h2>
                <button type="button" class="scr-modal-close" onclick="closeStaffChangeArchiveModal()">&times;</button>
            </div>
            <div class="scr-modal-body">
                <div class="scr-staff-header">
                    <div class="scr-staff-avatar" id="scrStaffAvatar">S</div>
                    <div>
                        <div class="scr-staff-name" id="scrStaffName">Staff Name</div>
                        <div class="scr-staff-date" id="scrStaffDate">Submitted on --</div>
                        <div class="scr-status-line" id="scrStatusLine"></div>
                    </div>
                </div>
                <div class="scr-section">
                    <div class="scr-section-title"><i class="fas fa-user"></i> Staff Profile</div>
                    <div class="scr-compare-grid" id="scrCurrentGrid"></div>
                </div>
                <div class="scr-section scr-requested">
                    <div class="scr-section-title"><i class="fas fa-pen"></i> Requested Changes</div>
                    <div class="scr-compare-grid" id="scrRequestedGrid"></div>
                </div>
                <div class="scr-section" id="scrReasonSection" style="display:none;">
                    <div class="scr-section-title"><i class="fas fa-comment"></i> Reason / Notes</div>
                    <div id="scrReasonText" class="scr-compare-old">—</div>
                </div>
            </div>
            <div class="scr-modal-footer">
                <button type="button" class="scr-btn-close" onclick="closeStaffChangeArchiveModal()">
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
    <script src="lgu_staff/js/progress-updates.js?v=<?php echo filemtime(__DIR__ . '/../../js/progress-updates.js'); ?>"></script>
    <script src="lgu_staff/js/progress-updates-common.js?v=<?php echo filemtime(__DIR__ . '/../../js/progress-updates-common.js'); ?>"></script>

    <script>
        var staffChangeArchiveData = <?php echo json_encode($staff_change_archives ?: []); ?>;

        function escapeScr(str) {
            if (str === null || str === undefined || str === '') return 'N/A';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function reviewStaffChangeArchive(requestId) {
            var cr = (staffChangeArchiveData || []).find(function(r) {
                return String(r.id) === String(requestId);
            });
            if (!cr) return;

            var data = {};
            try { data = JSON.parse(cr.requested_data || '{}') || {}; } catch (e) { data = {}; }

            var initials = (cr.user_name || 'S').split(' ').map(function(w) { return w.charAt(0); }).join('').substring(0, 2).toUpperCase();
            document.getElementById('scrStaffAvatar').textContent = initials || 'S';
            document.getElementById('scrStaffName').textContent = cr.user_name || 'Unknown Staff';

            var submitted = cr.created_at ? new Date(cr.created_at) : null;
            var submittedText = (submitted && !isNaN(submitted))
                ? submitted.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' })
                : '—';
            document.getElementById('scrStaffDate').textContent = 'Submitted on ' + submittedText;

            var status = String(cr.status || '').toLowerCase();
            var statusLabel = status ? status.charAt(0).toUpperCase() + status.slice(1) : 'Unknown';
            var reviewed = cr.reviewed_at ? new Date(cr.reviewed_at) : null;
            var reviewedText = (reviewed && !isNaN(reviewed))
                ? reviewed.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' })
                : '';
            var statusHtml = '<span class="status-badge status-' + escapeScr(status) + '">' + escapeScr(statusLabel) + '</span>';
            if (cr.reviewed_by_name) {
                statusHtml += '<span class="meta-item"><i class="fas fa-user-check"></i> ' + escapeScr(cr.reviewed_by_name) + '</span>';
            }
            if (reviewedText) {
                statusHtml += '<span class="meta-item"><i class="fas fa-calendar"></i> ' + escapeScr(reviewedText) + '</span>';
            }
            document.getElementById('scrStatusLine').innerHTML = statusHtml;

            var currentFields = [
                { label: 'Full Name', value: cr.user_name },
                { label: 'Email', value: cr.user_email },
                { label: 'Address', value: cr.user_address },
                { label: 'Civil Status', value: cr.user_civil_status ? (cr.user_civil_status.charAt(0).toUpperCase() + cr.user_civil_status.slice(1)) : 'N/A' },
                { label: 'Birthday', value: cr.user_birthday },
                { label: 'Contact Number', value: cr.user_phone_number }
            ];
            var currentHtml = '';
            currentFields.forEach(function(f) {
                currentHtml += '<div class="scr-compare-item"><span class="scr-compare-label">' + f.label + '</span><div class="scr-compare-old">' + escapeScr(f.value || 'N/A') + '</div></div>';
            });
            if (cr.user_id_file) {
                var curExt = String(cr.user_id_file).split('.').pop().toLowerCase();
                if (['jpg', 'jpeg', 'png', 'gif', 'webp'].indexOf(curExt) !== -1) {
                    currentHtml += '<div class="scr-compare-item" style="grid-column:1/-1;"><span class="scr-compare-label">Current ID Photo</span><div class="scr-media-preview"><img src="../../' + escapeScr(cr.user_id_file) + '" alt="Current ID"><span class="scr-media-label">Uploaded ID file</span></div></div>';
                }
            }
            document.getElementById('scrCurrentGrid').innerHTML = currentHtml;

            function requestedCell(label, value, changed) {
                var cls = changed ? 'scr-compare-new' : 'scr-compare-new no-change';
                return '<div class="scr-compare-item"><span class="scr-compare-label">' + label + '</span><div class="' + cls + '">' + value + '</div></div>';
            }

            var reqHtml = '';
            var nameChanged = !!(data.full_name && String(data.full_name).trim() !== '');
            reqHtml += requestedCell('Full Name', escapeScr(data.full_name || 'N/A'), nameChanged);
            reqHtml += requestedCell('Email', escapeScr(data.email || 'N/A'), !!(data.email && String(data.email).trim() !== ''));
            reqHtml += requestedCell('Address', escapeScr(data.address || 'N/A'), !!(data.address && String(data.address).trim() !== ''));
            var csDisplay = data.civil_status ? (data.civil_status.charAt(0).toUpperCase() + data.civil_status.slice(1)) : 'N/A';
            reqHtml += requestedCell('Civil Status', escapeScr(csDisplay), !!(data.civil_status && String(data.civil_status).trim() !== ''));
            reqHtml += requestedCell('Birthday', escapeScr(data.birthday || 'N/A'), !!(data.birthday && String(data.birthday).trim() !== ''));
            reqHtml += requestedCell('Contact Number', escapeScr(data.phone_number || 'N/A'), !!(data.phone_number && String(data.phone_number).trim() !== ''));

            var hasPw = !!(data.new_password || data.new_password_hash);
            reqHtml += requestedCell('New Password', hasPw ? '<i class="fas fa-key" style="color:#f59e0b;margin-right:6px;"></i>New password requested' : 'No change', hasPw);

            if (data.id_file_path) {
                var idExt = String(data.id_file_path).split('.').pop().toLowerCase();
                var idPreview = (['jpg', 'jpeg', 'png', 'gif', 'webp'].indexOf(idExt) !== -1)
                    ? '<div class="scr-media-preview"><img src="../../' + escapeScr(data.id_file_path) + '" alt="New ID"><span class="scr-media-label">New ID photo uploaded</span></div>'
                    : '<a href="../../' + encodeURI(data.id_file_path) + '" target="_blank" rel="noopener">View uploaded file</a>';
                reqHtml += '<div class="scr-compare-item" style="grid-column:1/-1;"><span class="scr-compare-label">New ID Photo</span>' + idPreview + '</div>';
            }
            if (data.profile_picture) {
                reqHtml += '<div class="scr-compare-item" style="grid-column:1/-1;"><span class="scr-compare-label">New Profile Picture</span><div class="scr-media-preview"><img src="lgu_staff/uploads/profile_pictures/' + escapeScr(data.profile_picture) + '" alt="New Profile" style="border-radius:50%;"><span class="scr-media-label">New profile picture uploaded</span></div></div>';
            }
            document.getElementById('scrRequestedGrid').innerHTML = reqHtml;

            var reasonBits = [];
            if (cr.reason) reasonBits.push(cr.reason);
            if (cr.admin_notes) reasonBits.push('Admin notes: ' + cr.admin_notes);
            var reasonSection = document.getElementById('scrReasonSection');
            if (reasonBits.length) {
                reasonSection.style.display = 'block';
                document.getElementById('scrReasonText').textContent = reasonBits.join('\n\n');
            } else {
                reasonSection.style.display = 'none';
                document.getElementById('scrReasonText').textContent = '—';
            }

            document.getElementById('staffChangeArchiveModal').style.display = 'flex';
        }

        function closeStaffChangeArchiveModal() {
            document.getElementById('staffChangeArchiveModal').style.display = 'none';
        }

        var archiveData = <?php
            $rows = [];
            if ($archives) {
                $archives->data_seek(0);
                while ($r = $archives->fetch_assoc()) {
                    $rows[] = $r;
                }
            }
            // Resolve the staff member who created each report (users.full_name
            // joined to created_by) so the View modal can show "Created By" for
            // LGU Monitoring reports — the same detail shown before archiving.
            $arch_creator_map = [];
            $arch_creator_ids = [];
            foreach ($rows as $__ar) {
                $__acb = (int)($__ar['created_by'] ?? 0);
                if ($__acb > 0) $arch_creator_ids[$__acb] = true;
            }
            if (!empty($arch_creator_ids)) {
                try {
                    $__ain = implode(',', array_map('intval', array_keys($arch_creator_ids)));
                    $__ares = $conn->query("SELECT id, full_name FROM users WHERE id IN ({$__ain})");
                    if ($__ares) {
                        while ($__au = $__ares->fetch_assoc()) {
                            $arch_creator_map[(int)$__au['id']] = $__au['full_name'];
                        }
                    }
                } catch (Exception $e) {
                    error_log("Archive creator lookup error: " . $e->getMessage());
                }
            }
            foreach ($rows as &$__ar) {
                $__ar['created_by_name'] = $arch_creator_map[(int)($__ar['created_by'] ?? 0)] ?? null;
                if (($__ar['archived_from'] ?? '') === 'cimm_verification_reports' || ($__ar['archive_table'] ?? '') === 'cimm_verification_reports_archive') {
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
                if (($__ar['archived_from'] ?? '') === 'ipms_road_projects' || ($__ar['archive_table'] ?? '') === 'ipms_road_projects_archive') {
                    $__ar['start_date'] = $__ar['cimm_starting_date'] ?? null;
                    $__ar['end_date'] = $__ar['cimm_estimated_end_date'] ?? ($__ar['due_date'] ?? null);
                    $__ar['budget'] = $__ar['budget_allocation'] ?? ($__ar['cimm_budget'] ?? null);
                    $poly = $__ar['ipms_polyline_json'] ?? $__ar['polyline_json'] ?? '';
                    $__ar['polyline'] = $poly ? (json_decode((string)$poly, true) ?: []) : [];
                    $eng = json_decode((string)($__ar['assigned_engineers_json'] ?? '[]'), true);
                    if (is_array($eng) && empty($__ar['engineer'])) {
                        $__ar['engineer'] = implode(', ', array_filter(array_map('strval', $eng)));
                    }
                    $districts = json_decode((string)($__ar['districts_json'] ?? '[]'), true);
                    if (is_array($districts) && count($districts)) {
                        $__ar['district'] = implode(', ', array_filter(array_map('trim', array_map('strval', $districts)), static function ($d) {
                            return $d !== '';
                        }));
                    }
                }
            }
            unset($__ar);
            rgmap_annotate_archive_supervisor_ownership($conn, $rows);
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

        function formatCurrency(val) {
            if (val === null || val === undefined || val === '' || isNaN(Number(val))) return '—';
            return '₱' + Number(val).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        const ARCH_TOMTOM_API_KEY = '<?php echo defined('TOMTOM_API_KEY') ? TOMTOM_API_KEY : ''; ?>';
        let currentArchivePolyline = null;
        let archiveMapInstances = {};

        function openArchiveMap() {
            if (!currentArchivePolyline || currentArchivePolyline.length < 2) {
                alert('No map data available for this project.');
                return;
            }
            openArchiveRoadPathMap('arch-map-container', currentArchivePolyline, true);
        }

        function openArchiveRoadPathMap(containerId, points, asLine) {
            var container = document.getElementById(containerId);
            if (!container || !points || points.length === 0) return;
            if (typeof L === 'undefined') {
                alert('Map library failed to load.');
                return;
            }

            container.classList.add('road-map-visible');

            var map = archiveMapInstances[containerId];
            if (!map) {
                map = L.map(containerId, { zoomControl: true }).setView([14.6760, 121.0437], 12);
                L.tileLayer('https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?view=Unified&key=' + ARCH_TOMTOM_API_KEY, {
                    attribution: '© TomTom',
                    maxZoom: 18
                }).addTo(map);
                archiveMapInstances[containerId] = map;
            }

            map.eachLayer(function(layer) {
                if (layer instanceof L.Polyline || layer instanceof L.Marker) {
                    map.removeLayer(layer);
                }
            });

            if (asLine && points.length >= 2) {
                var latLngs = points.map(function(pt) { return [pt[0], pt[1]]; });
                var line = L.polyline(latLngs, { color: '#f97316', weight: 5, opacity: 0.9 }).addTo(map);
                map.fitBounds(line.getBounds(), { padding: [30, 30] });
            } else {
                map.setView([points[0][0], points[0][1]], 15);
                L.marker([points[0][0], points[0][1]]).addTo(map);
            }

            setTimeout(function() { map.invalidateSize(); }, 100);
        }

        function rmBadge(text, bg, color) {
            return '<span style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;background:' + bg + ';color:' + color + ';">' + text + '</span>';
        }

        function rmInfoItem(icon, label, value) {
            var displayVal = (value && value !== '—' && value !== null) ? value : '—';
            return '<div class="rm-info-item"><div class="rm-info-icon"><i class="fas fa-' + icon + '"></i></div><div><div class="rm-info-label">' + label + '</div><div class="rm-info-value">' + displayVal + '</div></div></div>';
        }

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
            var isIpmsArchive = (row.archived_from === 'ipms_road_projects');
            var infraTypeLabels = {
                'infrastructure_issue': 'Infrastructure Issue',
                'routine': 'Routine Maintenance',
                'emergency': 'Emergency Repair',
                'preventive': 'Preventive Maintenance',
                'corrective': 'Corrective Maintenance',
                'scheduled': 'Scheduled Maintenance'
            };

            // Header
            document.getElementById('rm-report-id').textContent = (isIpmsArchive ? 'Project #' : 'Report #') + (row.report_id || '—');
            document.getElementById('rm-title').textContent = row.title || '—';

            var st = (row.status || 'pending').toLowerCase();
            var ss = statusStyles[st] || {bg:'rgba(107,114,128,0.15)', color:'#6b7280'};
            var pp = (row.priority || 'medium').toLowerCase();
            var ps = pStyles[pp] || {bg:'rgba(107,114,128,0.15)', color:'#6b7280'};

            var badgesHtml = rmBadge(row.status || '—', ss.bg, ss.color);
            if (isIpmsArchive) {
                badgesHtml += '<span style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;background:rgba(249,115,22,0.12);color:#f97316;">Maintenance</span>';
            } else {
                badgesHtml += rmBadge(row.priority || '—', ps.bg, ps.color);
            }
            var src = row.source_system || 'citizen';
            var sourceLabel = sourceLabels[src] || src;
            if (sourceLabel !== '—' && !isIpmsArchive) {
                badgesHtml += '<span style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;background:rgba(55,98,200,0.12);color:#3762c8;">' + sourceLabel + '</span>';
            }
            document.getElementById('rm-badges').innerHTML = badgesHtml;

            // Report Information
            var reportGrid = '';
            var lguTypeLabels = {
                'traffic_jam': 'Traffic Jam',
                'accident': 'Accident',
                'road_damage': 'Road Damage',
                'flooding': 'Flooding',
                'potholes': 'Potholes',
                'road_closure': 'Road Closure',
                'infrastructure_issue': 'Infrastructure Issue',
                'street_light': 'Street Light',
                'other': 'Other'
            };
            if (src === 'lgu') {
                // LGU Monitoring reports: render the same complete details and
                // layout that were available before archiving (mirrors the LGU
                // View modal in verification_monitoring.php).
                reportGrid += rmInfoItem('folder', 'Report Type', lguTypeLabels[row.report_type] || row.report_type || '—');
                reportGrid += rmInfoItem('tag', 'Category', row.report_category);
                reportGrid += rmInfoItem('calendar-alt', 'Created Date', formatDate(row.created_at));
                reportGrid += rmInfoItem('sync-alt', 'Last Updated', formatDate(row.updated_at));
            } else if (src === 'cimm') {
                // CIMM reports: render the same complete details and layout
                // that were available before archiving (mirrors the CIMM View
                // modal in verification_monitoring.php).
                reportGrid += rmInfoItem('building', 'Infrastructure', row.title);
                reportGrid += rmInfoItem('folder', 'Report Type', row.report_type);
                reportGrid += rmInfoItem('calendar-alt', 'Start Date', formatDate(row.cimm_starting_date));
                reportGrid += rmInfoItem('calendar-check', 'End Date', formatDate(row.cimm_estimated_end_date));
                reportGrid += rmInfoItem('wallet', 'Budget', row.cimm_budget ? '₱' + parseFloat(row.cimm_budget).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2}) : '—');
                if (row.budget_allocation) {
                    reportGrid += rmInfoItem('wallet', 'Budget Allocation', '₱' + parseFloat(row.budget_allocation).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2}));
                }
                reportGrid += rmInfoItem('tag', 'Source System', sourceLabel);
            } else if (isIpmsArchive) {
                // IPMS Infrastructure Projects: mirror the verification_monitoring
                // viewInfraReport Project Information section.
                reportGrid += rmInfoItem('folder', 'Report Type', infraTypeLabels[row.report_type] || row.report_type || '—');
                reportGrid += rmInfoItem('building', 'Department', row.department || 'Engineering');
                reportGrid += rmInfoItem('calendar-alt', 'Created Date', formatDate(row.created_date || row.created_at));
                reportGrid += rmInfoItem('calendar-check', 'Due Date', formatDate(row.due_date || row.end_date));
                reportGrid += rmInfoItem('wallet', 'Est. Cost', row.estimation ? formatCurrency(row.estimation) : '—');
            } else {
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
                if (row.assigned_by) {
                    reportGrid += rmInfoItem('user-tie', 'Assigned By', row.assigned_by);
                }
            }
            document.getElementById('rm-report-grid').innerHTML = reportGrid;

            // Source & Department
            var sourceGrid = '';
            if (src === 'lgu') {
                sourceGrid += rmInfoItem('server', 'Source', sourceLabel);
                sourceGrid += rmInfoItem('building', 'Department', row.department);
                if (row.assigned_by) {
                    sourceGrid += rmInfoItem('user-tie', 'Assigned By', row.assigned_by);
                }
                if (row.created_by_name) {
                    sourceGrid += rmInfoItem('user', 'Created By', row.created_by_name);
                }
                if (row.approved_at) {
                    sourceGrid += rmInfoItem('thumbs-up', 'Approved At', formatDate(row.approved_at));
                }
                if (row.rejected_at) {
                    sourceGrid += rmInfoItem('thumbs-down', 'Rejected At', formatDate(row.rejected_at));
                }
                if ((row.report_category || '') === 'road') {
                    var lguEng = row.engineer || row.cimm_engineer_name;
                    var lguBud = row.budget_allocation || row.cimm_budget;
                    if (lguEng) {
                        sourceGrid += rmInfoItem('hard-hat', 'Engineer', lguEng);
                    }
                    if (lguBud) {
                        sourceGrid += rmInfoItem('money-bill-wave', 'Budget', '₱ ' + Number(lguBud).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
                    }
                    sourceGrid += rmInfoItem('calendar-plus', 'CIMM Starting Date', formatDate(row.cimm_starting_date));
                    sourceGrid += rmInfoItem('calendar-check', 'CIMM Estimated End Date', formatDate(row.cimm_estimated_end_date));
                }
                sourceGrid += rmInfoItem('history', 'Archived From', row.archived_from);
                if (row.previous_status) {
                    sourceGrid += rmInfoItem('undo', 'Previous Status', row.previous_status);
                }
            } else if (src === 'cimm') {
                // CIMM reports: mirror the Reporter & Engineer plus the status
                // details shown by the CIMM View modal before archiving.
                sourceGrid += rmInfoItem('server', 'Source', sourceLabel);
                sourceGrid += rmInfoItem('building', 'Department', row.department);
                if (row.assigned_by) {
                    sourceGrid += rmInfoItem('user-tie', 'Assigned By', row.assigned_by);
                }
                sourceGrid += rmInfoItem('user', 'Reported By', row.reporter_name);
                sourceGrid += rmInfoItem('hard-hat', 'Engineer', row.cimm_engineer_name || row.engineer);
                if (row.cimm_status) {
                    sourceGrid += rmInfoItem('clipboard-check', 'Verification', row.cimm_status);
                }
                if (row.approval_status) {
                    sourceGrid += rmInfoItem('thumbs-up', 'Approval', row.approval_status);
                }
                if (row.cimm_report_url) {
                    sourceGrid += '<div class="rm-info-item"><div class="rm-info-icon"><i class="fas fa-external-link-alt"></i></div><div><div class="rm-info-label">Portal Link</div><div class="rm-info-value"><a href="' + row.cimm_report_url + '" target="_blank" style="color:#3762c8;text-decoration:none;">Open in CIMM</a></div></div></div>';
                }
                sourceGrid += rmInfoItem('history', 'Archived From', row.archived_from);
                if (row.rejected_at) {
                    sourceGrid += rmInfoItem('thumbs-down', 'Rejected At', formatDate(row.rejected_at));
                }
            } else if (isIpmsArchive) {
                // IPMS Infrastructure Projects: mirror Engineer & Schedule plus
                // archive metadata from verification_monitoring viewInfraReport.
                if (row.assigned_by) {
                    sourceGrid += rmInfoItem('user-tie', 'Assigned By', row.assigned_by);
                }
                sourceGrid += rmInfoItem('hard-hat', 'Engineer', row.engineer || '—');
                sourceGrid += rmInfoItem('calendar-plus', 'Start Date', formatDate(row.start_date || row.cimm_starting_date));
                sourceGrid += rmInfoItem('calendar-minus', 'End Date', formatDate(row.end_date || row.cimm_estimated_end_date || row.due_date));
                sourceGrid += rmInfoItem('money-bill-wave', 'Budget', row.budget ? formatCurrency(row.budget) : '—');
                sourceGrid += rmInfoItem('users', 'Maintenance Team', row.maintenance_team || '—');
                sourceGrid += rmInfoItem('history', 'Archived From', row.archived_from);
                if (row.previous_status) {
                    sourceGrid += rmInfoItem('undo', 'Previous Status', row.previous_status);
                }
                if (row.rejected_at) {
                    sourceGrid += rmInfoItem('thumbs-down', 'Rejected At', formatDate(row.rejected_at));
                }
            } else {
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
            }
            document.getElementById('rm-source-grid').innerHTML = sourceGrid;

            // Location
            var locationGrid = '';
            if (isIpmsArchive) {
                locationGrid += rmInfoItem('map-marker-alt', 'Start Address', row.start_address || '—');
                locationGrid += rmInfoItem('map-marker', 'End Address', row.end_address || '—');
                locationGrid += rmInfoItem('map-pin', 'District', row.district || row.detected_district);
            } else {
                var locVal = row.location || '—';
                if (row.latitude && row.longitude && row.latitude != 0 && row.longitude != 0) {
                    locVal += '<br><a href="https://www.openstreetmap.org/?mlat=' + row.latitude + '&mlon=' + row.longitude + '&zoom=15" target="_blank" style="color:#3762c8;font-size:12px;text-decoration:none;"><i class="fas fa-external-link-alt" style="font-size:10px;"></i> View on Map (' + row.latitude + ', ' + row.longitude + ')</a>';
                }
                locationGrid += '<div class="rm-info-item rm-info-value-full"><div class="rm-info-icon"><i class="fas fa-map-marker-alt"></i></div><div><div class="rm-info-label">Location</div><div class="rm-info-value">' + locVal + '</div></div></div>';
                locationGrid += rmInfoItem('map-pin', 'District', row.detected_district || row.cimm_district || row.district);
            }
            document.getElementById('rm-location-grid').innerHTML = locationGrid;

            currentArchivePolyline = (isIpmsArchive && Array.isArray(row.polyline) && row.polyline.length >= 2)
                ? row.polyline.map(function(pt) { return [pt[0], pt[1]]; })
                : null;
            var archMapBtn = document.getElementById('arch-view-map-btn');
            if (archMapBtn) {
                archMapBtn.style.display = currentArchivePolyline ? '' : 'none';
            }
            var archMapContainer = document.getElementById('arch-map-container');
            if (archMapContainer) {
                archMapContainer.classList.remove('road-map-visible');
            }

            // Description
            document.getElementById('rm-description').textContent = row.description || 'No description provided.';

            // Attachments
            var images = [];
            var seenPaths = new Set();
            function absPath(p) {
                return (/^https?:\/\//i.test(p) || p.indexOf('data:') === 0) ? p : ('../../' + p);
            }
            if (row.image_path && row.image_path !== '0' && row.image_path !== 'null') {
                images.push(absPath(row.image_path));
                seenPaths.add(row.image_path);
            }
            if (row.attachments) {
                try {
                    var attachments = JSON.parse(row.attachments);
                    if (Array.isArray(attachments)) {
                        attachments.forEach(function(a) {
                            if ((a.type === 'image' || !a.type) && a.file_path && !seenPaths.has(a.file_path)) {
                                images.push(absPath(a.file_path));
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
            if (src === 'cimm') {
                timelineGrid += rmInfoItem('calendar-alt', 'Start Date', formatDate(row.cimm_starting_date));
                timelineGrid += rmInfoItem('calendar-check', 'End Date', formatDate(row.cimm_estimated_end_date));
                if (row.cimm_status) {
                    timelineGrid += rmInfoItem('clipboard-check', 'Verification', row.cimm_status);
                }
                if (row.approval_status) {
                    timelineGrid += rmInfoItem('thumbs-up', 'Approval', row.approval_status);
                }
                if (row.rejected_at) {
                    timelineGrid += rmInfoItem('thumbs-down', 'Rejected', formatDate(row.rejected_at));
                }
            } else if (isIpmsArchive) {
                timelineGrid += rmInfoItem('calendar-plus', 'Created', formatDate(row.created_at));
                timelineGrid += rmInfoItem('calendar-alt', 'Created Date', formatDate(row.created_date || row.created_at));
                timelineGrid += rmInfoItem('calendar-check', 'Due Date', formatDate(row.due_date || row.end_date));
                if (row.updated_at) {
                    timelineGrid += rmInfoItem('edit', 'Last Updated', formatDate(row.updated_at));
                }
                if (row.approved_at) {
                    timelineGrid += rmInfoItem('thumbs-up', 'Approved', formatDate(row.approved_at));
                }
                if (row.rejected_at) {
                    timelineGrid += rmInfoItem('thumbs-down', 'Rejected', formatDate(row.rejected_at));
                }
            } else {
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
            <?php if (!empty($your_reports_only)): ?>
            url.searchParams.set('mine', '1');
            <?php endif; ?>
            window.location.href = url.toString();
        }

        function toggleYourReports() {
            const url = new URL(window.location);
            if (url.searchParams.get('mine') === '1' || url.searchParams.get('mine') === null) {
                url.searchParams.set('mine', '0');
            } else {
                url.searchParams.set('mine', '1');
            }
            window.location.href = url.toString();
        }

        function resetFilters() {
            const url = new URL(window.location);
            url.searchParams.delete('status');
            url.searchParams.delete('source');
            url.searchParams.delete('sort');
            url.searchParams.delete('id');
            url.searchParams.delete('mine');
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

        function closeModalAndRefresh() {
            closeViewModal();
            location.reload();
        }
    </script>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

    <script>
        function updateDateTime() {
            const now = new Date();
            const dateOptions = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            const timeOptions = { hour: '2-digit', minute: '2-digit', second: '2-digit' };
            const dateEl = document.getElementById('currentDate');
            const timeEl = document.getElementById('currentTime');
            if (dateEl) dateEl.textContent = now.toLocaleDateString('en-US', dateOptions);
            if (timeEl) timeEl.textContent = now.toLocaleTimeString('en-US', timeOptions);
        }
        updateDateTime();
        setInterval(updateDateTime, 1000);
    </script>

</body>
</html>
