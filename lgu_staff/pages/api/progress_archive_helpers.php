<?php
/**
 * Shared archive helpers for the progress-update / report workflow.
 *
 * Moved out of progress_update_api.php so the supervisor monitoring portal
 * (road_transportation_monitoring.php) can drive the same archive routines
 * directly — including the 7-day auto-archive sweep for reports completed
 * through the portal's Complete button.
 *
 * Safe to require_once from any page that has already bootstrapped config.php
 * (which provides the global $conn) and functions.php (which provides
 * fetch_one / log_audit_action).
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';

// Make sure the archive table exists and carries every column of both live
// report tables, so copying ANY report row preserves all its data. Widens
// report_type so maintenance rows ('routine','emergency', etc.) can be archived
// without "Data truncated" errors. Mirrors ensure_archive_table() used by
// report_management.php.
function rgmap_archive_ensure_table() {
    global $conn;
    $conn->query("CREATE TABLE IF NOT EXISTS road_transportation_reports_archive LIKE road_transportation_reports");
    try {
        foreach (['road_transportation_reports', 'road_maintenance_reports'] as $src_table) {
            $arch_cols = [];
            $arch = $conn->query("SHOW COLUMNS FROM road_transportation_reports_archive");
            if ($arch) { while ($row = $arch->fetch_assoc()) { $arch_cols[$row['Field']] = true; } }
            $src = $conn->query("SHOW COLUMNS FROM $src_table");
            if ($src) { while ($row = $src->fetch_assoc()) {
                if (!isset($arch_cols[$row['Field']])) {
                    $conn->query("ALTER TABLE road_transportation_reports_archive ADD COLUMN `{$row['Field']}` {$row['Type']} NULL");
                }
            } }
        }
    } catch (Exception $e) { error_log('rgmap_archive_ensure_table sync: ' . $e->getMessage()); }
    try {
        $conn->query("ALTER TABLE road_transportation_reports_archive MODIFY report_type VARCHAR(255) NULL DEFAULT NULL");
    } catch (Exception $e) { error_log('rgmap_archive_ensure_table report_type widen: ' . $e->getMessage()); }
    try {
        $chk = $conn->query("SHOW COLUMNS FROM road_transportation_reports_archive LIKE 'previous_status'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE road_transportation_reports_archive ADD COLUMN previous_status VARCHAR(50) NULL DEFAULT NULL");
        }
    } catch (Exception $e) { error_log('rgmap_archive_ensure_table previous_status: ' . $e->getMessage()); }
    try {
        $chk = $conn->query("SHOW COLUMNS FROM road_transportation_reports_archive LIKE 'archived_from'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE road_transportation_reports_archive ADD COLUMN archived_from VARCHAR(100) NULL DEFAULT NULL");
        }
    } catch (Exception $e) { error_log('rgmap_archive_ensure_table archived_from: ' . $e->getMessage()); }
    try {
        $chk = $conn->query("SHOW COLUMNS FROM road_transportation_reports_archive LIKE 'approval_status'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE road_transportation_reports_archive ADD COLUMN approval_status VARCHAR(50) NULL DEFAULT NULL");
        }
    } catch (Exception $e) { error_log('rgmap_archive_ensure_table approval_status: ' . $e->getMessage()); }
    try {
        $chk = $conn->query("SHOW COLUMNS FROM road_transportation_reports_archive LIKE 'source_pk'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE road_transportation_reports_archive ADD COLUMN source_pk INT NULL DEFAULT NULL");
        }
        $conn->query("UPDATE road_transportation_reports_archive
                      SET source_pk = id
                      WHERE source_pk IS NULL
                        AND archived_from IN ('road_transportation_reports','road_maintenance_reports')");
        $conn->query("UPDATE road_transportation_reports_archive a
                      JOIN cimm_verification_reports c ON c.reference_code COLLATE utf8mb4_unicode_ci = a.report_id
                      SET a.source_pk = c.id
                      WHERE a.source_pk IS NULL
                        AND a.archived_from = 'cimm_verification_reports'");
    } catch (Exception $e) { error_log('rgmap_archive_ensure_table source_pk: ' . $e->getMessage()); }
    foreach ([
        'start_address' => 'VARCHAR(100) NULL DEFAULT NULL',
        'end_address' => 'VARCHAR(100) NULL DEFAULT NULL',
        'ipms_polyline_json' => 'LONGTEXT NULL DEFAULT NULL',
    ] as $col => $def) {
        try {
            $chk = $conn->query("SHOW COLUMNS FROM road_transportation_reports_archive LIKE '$col'");
            if ($chk && $chk->num_rows === 0) {
                $conn->query("ALTER TABLE road_transportation_reports_archive ADD COLUMN $col $def");
            }
        } catch (Exception $e) {
            error_log("rgmap_archive_ensure_table $col: " . $e->getMessage());
        }
    }
    rgmap_archive_ensure_split_tables();
}

function rgmap_archive_ensure_split_tables() {
    global $conn;
    try {
        require_once __DIR__ . '/cimm_verification_data.php';
        if (function_exists('rgmap_verification_pdo') && function_exists('rgmap_ensure_cimm_verification_table')) {
            rgmap_ensure_cimm_verification_table(rgmap_verification_pdo());
        }
    } catch (Throwable $e) {
        error_log('rgmap_archive_ensure_split_tables cimm live: ' . $e->getMessage());
    }
    try {
        require_once __DIR__ . '/ipms_road_projects_data.php';
        rgmap_ensure_ipms_road_projects_table(rgmap_ipms_pdo());
    } catch (Throwable $e) {
        error_log('rgmap_archive_ensure_split_tables ipms live: ' . $e->getMessage());
    }
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS cimm_verification_reports_archive LIKE cimm_verification_reports");
        $conn->query("ALTER TABLE cimm_verification_reports_archive ADD COLUMN IF NOT EXISTS previous_status VARCHAR(50) NULL DEFAULT NULL");
        $conn->query("ALTER TABLE cimm_verification_reports_archive ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL DEFAULT NULL");
        $conn->query("ALTER TABLE cimm_verification_reports_archive ADD COLUMN IF NOT EXISTS archive_status VARCHAR(50) NULL DEFAULT NULL");
        $conn->query("ALTER TABLE cimm_verification_reports_archive DROP INDEX IF EXISTS uq_cimm_req");
        $conn->query("ALTER TABLE cimm_verification_reports_archive ADD INDEX IF NOT EXISTS idx_cimm_req_arch (cimm_req_id)");
        $conn->query("ALTER TABLE cimm_verification_reports_archive ADD INDEX IF NOT EXISTS idx_archive_status (archive_status)");
        try {
            $conn->query("ALTER TABLE cimm_verification_reports_archive MODIFY priority VARCHAR(32) NULL DEFAULT 'medium'");
        } catch (Throwable $e) { /* already wide enough */ }
        $collRes = $conn->query("SHOW TABLE STATUS LIKE 'cimm_verification_reports_archive'");
        $collRow = $collRes ? $collRes->fetch_assoc() : null;
        if ($collRow && stripos((string)($collRow['Collation'] ?? ''), 'unicode') === false) {
            $conn->query("ALTER TABLE cimm_verification_reports_archive CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }
    } catch (Throwable $e) {
        error_log('rgmap_archive_ensure_split_tables cimm: ' . $e->getMessage());
    }
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS ipms_road_projects_archive LIKE ipms_road_projects");
        $conn->query("ALTER TABLE ipms_road_projects_archive ADD COLUMN IF NOT EXISTS previous_status VARCHAR(50) NULL DEFAULT NULL");
        $conn->query("ALTER TABLE ipms_road_projects_archive ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL DEFAULT NULL");
        $conn->query("ALTER TABLE ipms_road_projects_archive ADD COLUMN IF NOT EXISTS archive_status VARCHAR(50) NULL DEFAULT NULL");
        $conn->query("ALTER TABLE ipms_road_projects_archive ADD INDEX IF NOT EXISTS idx_ipms_arch_status (archive_status)");
    } catch (Throwable $e) {
        error_log('rgmap_archive_ensure_split_tables ipms: ' . $e->getMessage());
    }
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS ipms_sync_exclusions (
            project_id INT UNSIGNED NOT NULL,
            excluded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            excluded_by VARCHAR(180) NULL DEFAULT NULL,
            reason VARCHAR(100) NULL DEFAULT NULL,
            PRIMARY KEY (project_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('rgmap_archive_ensure_split_tables exclusions: ' . $e->getMessage());
    }
}

function rgmap_archive_table_columns($conn, $table) {
    $cols = [];
    $res = $conn->query("SHOW COLUMNS FROM `$table`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $cols[$row['Field']] = true;
        }
    }
    return $cols;
}

function rgmap_archive_insert_native($conn, $dest_table, array $row, array $exclude = []) {
    $dest_cols = rgmap_archive_table_columns($conn, $dest_table);
    $fields = [];
    $values = [];
    foreach ($row as $field => $value) {
        if (isset($exclude[$field]) || $field === '') {
            continue;
        }
        if (!isset($dest_cols[$field])) {
            continue;
        }
        $fields[] = $field;
        $values[] = $value;
    }
    if (empty($fields)) {
        throw new Exception("No matching columns for $dest_table");
    }
    $field_list = '`' . implode('`, `', $fields) . '`';
    $placeholders = implode(', ', array_fill(0, count($fields), '?'));
    $stmt = $conn->prepare("INSERT INTO `$dest_table` ($field_list) VALUES ($placeholders)");
    $stmt->execute($values);
    $stmt->close();
}

function rgmap_archive_normalize_status($status, $fallback = 'rejected') {
    $valid = ['pending', 'in-progress', 'completed', 'cancelled', 'approved', 'rejected'];
    $archive_status = strtolower(trim((string)$status));
    if (!in_array($archive_status, $valid, true)) {
        return $fallback;
    }
    return $archive_status;
}

function rgmap_ipms_exclude_project($conn, $project_id, $reason = 'delete_forever') {
    $project_id = (int)$project_id;
    if ($project_id <= 0) {
        return;
    }
    rgmap_archive_ensure_split_tables();
    $by = $_SESSION['email'] ?? ($_SESSION['full_name'] ?? null);
    $stmt = $conn->prepare("INSERT INTO ipms_sync_exclusions (project_id, excluded_by, reason) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE excluded_at = excluded_at");
    $stmt->bind_param('iss', $project_id, $by, $reason);
    $stmt->execute();
    $stmt->close();
}

function rgmap_ipms_is_sync_skipped($conn, $project_id) {
    $project_id = (int)$project_id;
    if ($project_id <= 0) {
        return false;
    }
    rgmap_archive_ensure_split_tables();
    $stmt = $conn->prepare("SELECT project_id FROM ipms_sync_exclusions WHERE project_id = ? LIMIT 1");
    $stmt->bind_param('i', $project_id);
    $stmt->execute();
    $found = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($found) {
        return true;
    }
    $stmt = $conn->prepare("SELECT project_id FROM ipms_road_projects_archive WHERE project_id = ? LIMIT 1");
    $stmt->bind_param('i', $project_id);
    $stmt->execute();
    $found = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $found;
}

// Ensure the restored_from_archive marker column exists on every live report
// table. It is set to 1 only when a CANCELLED report is restored from the
// Archive (see archive.php), so report_management.php and
// road_transportation_monitoring.php can make restored-cancelled projects
// visible again on the panels they returned to — while normally-cancelled
// reports (flag 0) keep their existing invisible behavior unchanged.
function rgmap_ensure_restored_from_archive_column() {
    global $conn;
    foreach (['road_transportation_reports', 'road_maintenance_reports', 'cimm_verification_reports'] as $t) {
        try {
            $conn->query("ALTER TABLE $t ADD COLUMN IF NOT EXISTS restored_from_archive TINYINT(1) NOT NULL DEFAULT 0");
        } catch (Exception $e) {
            error_log("restored_from_archive add ($t): " . $e->getMessage());
        }
    }
}

// True when $id is not already used as the primary key of $table.
function rgmap_pk_is_free($conn, $table, $id) {
    $id = (int)$id;
    if ($id <= 0 || !preg_match('/^[a-z_]+$/', $table)) return false;
    $stmt = $conn->prepare("SELECT id FROM `$table` WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return !$row;
}

// Repoint child rows after a restore that could not keep the original PK.
function rgmap_remap_report_fk($conn, $from_id, $to_id) {
    $from_id = (int)$from_id;
    $to_id = (int)$to_id;
    if ($from_id <= 0 || $to_id <= 0 || $from_id === $to_id) return;
    foreach (['report_updates', 'report_assignments', 'report_notifications'] as $table) {
        try {
            $stmt = $conn->prepare("UPDATE `$table` SET report_id = ? WHERE report_id = ?");
            $stmt->bind_param("ii", $to_id, $from_id);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            error_log("rgmap_remap_report_fk $table: " . $e->getMessage());
        }
    }
    try {
        $exists = $conn->prepare("SELECT report_id FROM rgmap_cimm_push_log WHERE report_id = ? LIMIT 1");
        $exists->bind_param("i", $to_id);
        $exists->execute();
        $to_exists = (bool)$exists->get_result()->fetch_assoc();
        $exists->close();
        if ($to_exists) {
            $del = $conn->prepare("DELETE FROM rgmap_cimm_push_log WHERE report_id = ?");
            $del->bind_param("i", $from_id);
            $del->execute();
            $del->close();
        } else {
            $upd = $conn->prepare("UPDATE rgmap_cimm_push_log SET report_id = ? WHERE report_id = ?");
            $upd->bind_param("ii", $to_id, $from_id);
            $upd->execute();
            $upd->close();
        }
    } catch (Throwable $e) {
        error_log("rgmap_remap_report_fk push_log: " . $e->getMessage());
    }
}

// Return the live report table that actually contains the given id, or null.
function rgmap_resolve_report_table($conn, $report_id) {
    foreach (['road_transportation_reports', 'road_maintenance_reports'] as $table) {
        $stmt = $conn->prepare("SELECT id FROM $table WHERE id = ?");
        $stmt->bind_param("i", $report_id);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($r) return $table;
    }
    return null;
}

// Move a report into the archive — copy every column into
// road_transportation_reports_archive, then remove it from the live table —
// carrying the given terminal status. Works for rows in either
// road_transportation_reports or road_maintenance_reports.
function rgmap_archive_report($conn, $table, $report_id, $status) {
    try {
        rgmap_archive_ensure_table();
        $conn->begin_transaction();

        // Capture the report's status BEFORE applying the terminal status so it
        // can be restored exactly where its last action happened.
        $prev_stmt = $conn->prepare("SELECT status, report_id FROM $table WHERE id = ?");
        $prev_stmt->bind_param("i", $report_id);
        $prev_stmt->execute();
        $prev_row = $prev_stmt->get_result()->fetch_assoc();
        $prev_stmt->close();
        if (!$prev_row) { throw new Exception("Report not found in $table"); }
        $previous_status = $prev_row['status'] ?? null;
        $live_report_code = $prev_row['report_id'] ?? null;

        // Mark the live row with the terminal status first so the archived copy
        // below carries that status while preserving all other columns.
        $stmt = $conn->prepare("UPDATE $table SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("si", $status, $report_id);
        $stmt->execute();

        $fields = [];
        $col_res = $conn->query("SHOW COLUMNS FROM $table");
        if ($col_res) { while ($col_row = $col_res->fetch_assoc()) { $fields[] = "`{$col_row['Field']}`"; } }
        if (empty($fields)) { throw new Exception("No columns found for table $table"); }

        // The archive may already hold this same report (same report_id) when a
        // previously archived report was restored or re-synced back into the
        // live table. Refresh that existing archival copy from the live row and
        // drop the live row so Archive still moves the report out of Recent
        // Submissions instead of dying on the archive's UNIQUE report_id key.
        if ($live_report_code !== null && $live_report_code !== '') {
            $rid = $conn->prepare("SELECT id FROM road_transportation_reports_archive WHERE report_id = ? LIMIT 1");
            $rid->bind_param("s", $live_report_code);
            $rid->execute();
            $existing = $rid->get_result()->fetch_assoc();
            $rid->close();
            if ($existing) {
                $arch_id = (int)$existing['id'];
                $set_parts = ["a.status = ?", "a.previous_status = a.status", "a.archived_from = ?", "a.source_pk = ?", "a.updated_at = NOW()"];
                foreach ($fields as $f) {
                    if ($f === '`id`') continue;
                    $set_parts[] = "a.$f = l.$f";
                }
                $upd = "UPDATE road_transportation_reports_archive a
                        JOIN $table l ON l.report_id = a.report_id
                        SET " . implode(', ', $set_parts) . "
                        WHERE a.id = ?";
                $stmt = $conn->prepare($upd);
                $stmt->bind_param("ssii", $status, $table, $report_id, $arch_id);
                $stmt->execute();

                $stmt = $conn->prepare("DELETE FROM $table WHERE id = ?");
                $stmt->bind_param("i", $report_id);
                $stmt->execute();
                $conn->commit();
                return true;
            }
        }

        $cols = implode(', ', $fields);

        // A different report may already occupy this numeric id in the archive
        // (transport and maintenance rows share one archive id space), so the
        // id-preserving INSERT below would collide. In that case drop the id
        // and let the archive auto-generate a fresh primary key.
        $id_chk = $conn->prepare("SELECT id FROM road_transportation_reports_archive WHERE id = ? LIMIT 1");
        $id_chk->bind_param("i", $report_id);
        $id_chk->execute();
        $id_exists = $id_chk->get_result()->fetch_assoc();
        $id_chk->close();

        if ($id_exists) {
            $no_id = [];
            foreach ($fields as $f) { if ($f === '`id`') continue; $no_id[] = $f; }
            $cols2 = implode(', ', $no_id);
            $stmt = $conn->prepare("INSERT INTO road_transportation_reports_archive ($cols2) SELECT $cols2 FROM $table WHERE id = ?");
            $stmt->bind_param("i", $report_id);
            $stmt->execute();
            $arch_insert_id = $conn->insert_id;
        } else {
            $stmt = $conn->prepare("INSERT INTO road_transportation_reports_archive ($cols) SELECT $cols FROM $table WHERE id = ?");
            $stmt->bind_param("i", $report_id);
            $stmt->execute();
            $arch_insert_id = $report_id;
        }

        if ($previous_status !== null) {
            $ps = $conn->prepare("UPDATE road_transportation_reports_archive SET previous_status = ?, archived_from = ?, source_pk = ? WHERE id = ?");
            $ps->bind_param("ssii", $previous_status, $table, $report_id, $arch_insert_id);
            $ps->execute();
        } else {
            $ps = $conn->prepare("UPDATE road_transportation_reports_archive SET archived_from = ?, source_pk = ? WHERE id = ?");
            $ps->bind_param("sii", $table, $report_id, $arch_insert_id);
            $ps->execute();
        }

        $stmt = $conn->prepare("DELETE FROM $table WHERE id = ?");
        $stmt->bind_param("i", $report_id);
        $stmt->execute();

        $conn->commit();
        return true;
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $rollback_error) { /* No active transaction */ }
        error_log("rgmap_archive_report failed: " . $e->getMessage());
        return false;
    }
}

// Archive a CIMM report: native copy into cimm_verification_reports_archive,
// then remove the live row. $lookup may be the local PK or cimm_req_id.
function rgmap_archive_cimm_report($conn, $lookup, $status, $rejection_reason = null) {
    try {
        rgmap_archive_ensure_table();
        $cimm_report = rgmap_archive_load_cimm_live($conn, $lookup);
        if (!$cimm_report) {
            return false;
        }

        $archive_status = rgmap_archive_normalize_status($status, 'rejected');
        if ($archive_status === 'rejected' && in_array(strtolower(trim((string)$status)), ['approved', 'verified'], true)) {
            $archive_status = 'approved';
        }
        $now = date('Y-m-d H:i:s');
        $live_id = (int)$cimm_report['id'];
        $cimm_req = (string)($cimm_report['cimm_req_id'] ?? '');

        $conn->begin_transaction();

        if ($archive_status === 'rejected') {
            $reason = ($rejection_reason !== null && $rejection_reason !== '')
                ? $rejection_reason
                : (string)($cimm_report['rejection_reason'] ?? 'Rejected by admin');
            $upd = $conn->prepare("UPDATE cimm_verification_reports SET verification_status = 'Rejected', rejection_reason = ?, verified_at = NOW() WHERE id = ?");
            $upd->bind_param('si', $reason, $live_id);
            $upd->execute();
            $upd->close();
            $cimm_report['rejection_reason'] = $reason;
        }

        $row = $cimm_report;
        // Keep the live id. Archive.id is NOT NULL without AUTO_INCREMENT, so
        // omitting it makes the INSERT fail ("Field 'id' doesn't have a default value").
        $row['previous_status'] = $cimm_report['verification_status'] ?? null;
        if ($archive_status === 'rejected') {
            $row['verification_status'] = 'Rejected';
        }
        $row['archived_at'] = $now;
        $row['archive_status'] = $archive_status;

        $dup = $conn->prepare("DELETE FROM cimm_verification_reports_archive WHERE id = ? OR (cimm_req_id IS NOT NULL AND cimm_req_id = ? AND cimm_req_id != '')");
        $dup->bind_param('is', $live_id, $cimm_req);
        $dup->execute();
        $dup->close();

        rgmap_archive_insert_native($conn, 'cimm_verification_reports_archive', $row);

        $delete = $conn->prepare("DELETE FROM cimm_verification_reports WHERE id = ?");
        $delete->bind_param('i', $live_id);
        $delete->execute();
        $delete->close();

        $conn->commit();
        return true;
    } catch (Exception $e) {
        try { $conn->rollback(); } catch (Throwable $rollback_error) { /* No active transaction */ }
        error_log("rgmap_archive_cimm_report failed: " . $e->getMessage());
        return false;
    }
}

function rgmap_archive_load_cimm_live($conn, $lookup) {
    $lookup_int = (int)$lookup;
    if ($lookup_int > 0) {
        $stmt = $conn->prepare("SELECT * FROM cimm_verification_reports WHERE id = ?");
        $stmt->bind_param('i', $lookup_int);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return $row;
        }
        $stmt = $conn->prepare("SELECT * FROM cimm_verification_reports WHERE cimm_req_id = ?");
        $lookup_str = (string)$lookup;
        $stmt->bind_param('s', $lookup_str);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return $row;
        }
    }
    $lookup_str = (string)$lookup;
    $stmt = $conn->prepare("SELECT * FROM cimm_verification_reports WHERE cimm_req_id = ? OR reference_code = ? LIMIT 1");
    $stmt->bind_param('ss', $lookup_str, $lookup_str);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// Archive an IPMS infrastructure project: native copy into
// ipms_road_projects_archive, then remove the live mirror row.
function rgmap_archive_ipms_project($conn, $project_id, $status) {
    $project_id = (int)$project_id;
    if ($project_id <= 0) {
        return false;
    }

    require_once __DIR__ . '/ipms_road_projects_data.php';

    try {
        rgmap_archive_ensure_table();
        $pdo = rgmap_ipms_pdo();
        $stmt = $pdo->prepare("SELECT * FROM ipms_road_projects WHERE project_id = ?");
        $stmt->execute([$project_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }

        $archive_status = rgmap_archive_normalize_status($status, 'rejected');
        $now = date('Y-m-d H:i:s');
        $previous_status = $row['status'] ?? null;
        unset($row['id']);
        $row['previous_status'] = $previous_status;
        $row['archived_at'] = $now;
        $row['archive_status'] = $archive_status;
        $row['status'] = $archive_status;

        $conn->begin_transaction();

        $dup = $conn->prepare("SELECT id FROM ipms_road_projects_archive WHERE project_id = ? LIMIT 1");
        $dup->bind_param('i', $project_id);
        $dup->execute();
        $existing = $dup->get_result()->fetch_assoc();
        $dup->close();
        if ($existing) {
            $del_old = $conn->prepare("DELETE FROM ipms_road_projects_archive WHERE id = ?");
            $del_old->bind_param('i', $existing['id']);
            $del_old->execute();
            $del_old->close();
        }
        rgmap_archive_insert_native($conn, 'ipms_road_projects_archive', $row);

        $del = $pdo->prepare("DELETE FROM ipms_road_projects WHERE project_id = ?");
        $del->execute([$project_id]);

        $conn->commit();
        return true;
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $rollback_error) { /* No active transaction */ }
        error_log('rgmap_archive_ipms_project failed: ' . $e->getMessage());
        return false;
    }
}

// File a COPY of a report into the archive with the given status, WITHOUT
// moving or deleting anything — the live row stays exactly as it is. The
// archive auto-generates its own id so repeated copies never collide.
function rgmap_archive_report_copy($conn, $table, $report_id, $status) {
    try {
        rgmap_archive_ensure_table();

        $fields = [];
        $col_res = $conn->query("SHOW COLUMNS FROM $table");
        if ($col_res) {
            while ($col_row = $col_res->fetch_assoc()) {
                if (strtolower($col_row['Field']) === 'id') continue;
                $fields[] = "`{$col_row['Field']}`";
            }
        }
        if (empty($fields)) { throw new Exception("No columns found for table $table"); }
        $cols = implode(', ', $fields);

        $prev_stmt = $conn->prepare("SELECT status FROM $table WHERE id = ?");
        $prev_stmt->bind_param("i", $report_id);
        $prev_stmt->execute();
        $prev_row = $prev_stmt->get_result()->fetch_assoc();
        $previous_status = ($prev_row && isset($prev_row['status'])) ? $prev_row['status'] : null;
        $prev_stmt->close();

        $conn->begin_transaction();
        $stmt = $conn->prepare("INSERT INTO road_transportation_reports_archive ($cols) SELECT $cols FROM $table WHERE id = ?");
        $stmt->bind_param("i", $report_id);
        $stmt->execute();

        // Set the archived copy to the terminal status and bump its updated_at.
        $arch_id = $conn->insert_id;
        $stmt = $conn->prepare("UPDATE road_transportation_reports_archive SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("si", $status, $arch_id);
        $stmt->execute();

        if ($previous_status !== null) {
            $ps = $conn->prepare("UPDATE road_transportation_reports_archive SET previous_status = ?, archived_from = ?, source_pk = ? WHERE id = ?");
            $ps->bind_param("ssii", $previous_status, $table, $report_id, $arch_id);
            $ps->execute();
        } else {
            $ps = $conn->prepare("UPDATE road_transportation_reports_archive SET archived_from = ?, source_pk = ? WHERE id = ?");
            $ps->bind_param("sii", $table, $report_id, $arch_id);
            $ps->execute();
        }

        $conn->commit();
        return true;
    } catch (Exception $e) {
        try { $conn->rollback(); } catch (Throwable $rollback_error) { /* No active transaction */ }
        error_log("rgmap_archive_report_copy failed: " . $e->getMessage());
        return false;
    }
}

// True when the report lives in the live transportation table with a
// transportation category (not an infrastructure/maintenance project).
// Used to scope duplicate-notification protection to the Transportation
// module only — Road/CIMM/maintenance behavior stays untouched.
function rgmap_is_transportation_report($conn, $report_id) {
    try {
        $stmt = $conn->prepare(
            "SELECT id FROM road_transportation_reports
             WHERE id = ? AND report_category = 'transportation' AND report_type != 'infrastructure_issue'
             LIMIT 1"
        );
        $stmt->bind_param("i", $report_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (bool)$row;
    } catch (Exception $e) {
        return false;
    }
}

// Notify the officer who submitted a completion/cancellation request when the
// supervisor processes it (approve or reject).  Called from complete_archive,
// complete_archive_move, cancel_archive and complete_status after the report
// has been handled.
//
// Logic:
//   - Find the pending review-request notification (type 'completion' or
//     'cancellation') that was created by submit_review_request for this
//     report_id.  Its recipient_email holds the requestor's user_id.
//   - Determine approve vs. reject:
//       notification type 'completion' + action 'complete' → APPROVED
//       notification type 'cancellation' + action 'cancel'  → APPROVED
//       notification type 'completion' + action 'cancel'    → REJECTED
//       notification type 'cancellation' + action 'complete' → REJECTED
//   - Fetch the requestor's email from users table.
//   - Insert a new notification (recipient_email = requestor email) so the
//     officer sees it in their "Recent Activity" panel.
//   - Mark the original review-request notification as read.
function rgmap_notify_requestor($conn, $report_id, $action, $supervisor_id, $report_code) {
    try {
        // Look up the pending review-request notification for this report.
        $stmt = $conn->prepare(
            "SELECT id, type, recipient_email, recipient_role
             FROM report_notifications
             WHERE report_id = ? AND type IN ('completion','cancellation')
               AND recipient_role IS NOT NULL
             ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->bind_param("i", $report_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return;  // No pending review request — nothing to notify.

        $request_type = $row['type'];
        $requestor_uid = (int)$row['recipient_email'];  // user_id stored by submit_review_request
        $supervisor = fetch_one("SELECT full_name FROM users WHERE id = ?", [$supervisor_id], "i");
        $supervisor_name = $supervisor['full_name'] ?? 'Supervisor';

        $report_label = $report_code ?? ('#' . $report_id);

        // Determine approve vs reject based on request type + supervisor action.
        $approved = false;
        if ($request_type === 'completion' && $action === 'complete') $approved = true;
        if ($request_type === 'cancellation' && $action === 'cancel')  $approved = true;

        $type_label    = ($request_type === 'completion') ? 'completion request' : 'cancellation request';
        $result_label  = $approved ? 'approved' : 'rejected';
        $status_label  = ($request_type === 'completion')
            ? ($approved ? 'completed' : 'still open')
            : ($approved ? 'cancelled' : 'still open');

        $message = "Your {$type_label} for report {$report_label} was {$result_label} by {$supervisor_name}. The report is now {$status_label}.";

        // Fetch the requestor's email so the notification is visible to them.
        $req_user = fetch_one("SELECT email FROM users WHERE id = ?", [$requestor_uid], "i");
        $requestor_email = $req_user['email'] ?? null;

        $notif_type = $approved ? 'approve_request' : 'reject_request';

        // Transportation reports only: never file a second outcome notification
        // for the same report/request if one is still pending (unread) — a
        // supervisor reprocessing the request would otherwise spam the officer
        // with duplicate approve/reject notices. Road/CIMM/maintenance behavior
        // is unchanged.
        if ($requestor_email !== null && rgmap_is_transportation_report($conn, $report_id)) {
            $dup = $conn->prepare("SELECT id FROM report_notifications WHERE report_id = ? AND type = ? AND recipient_email = ? AND is_read = 0 ORDER BY id DESC LIMIT 1");
            $dup->bind_param("iss", $report_id, $notif_type, $requestor_email);
            $dup->execute();
            $dup_row = $dup->get_result()->fetch_assoc();
            $dup->close();
            if ($dup_row) {
                // Still retire the original review-request notification so it
                // does not linger in the supervisor's pending list.
                $mk = $conn->prepare("UPDATE report_notifications SET is_read = 1 WHERE id = ?");
                $mk->bind_param("i", $row['id']);
                $mk->execute();
                $mk->close();
                log_audit_action($supervisor_id,
                    "Duplicate {$type_label} outcome blocked",
                    "Report ID: {$report_id}, Result: {$result_label}");
                return;
            }
        }

        $stmt = $conn->prepare(
            "INSERT INTO report_notifications (report_id, type, message, recipient_email) VALUES (?, ?, ?, ?)"
        );
        $stmt->bind_param("isss", $report_id, $notif_type, $message, $requestor_email);
        $stmt->execute();
        $stmt->close();

        // Mark the original review-request notification as read so it doesn't
        // linger in the supervisor's pending list.
        $stmt = $conn->prepare("UPDATE report_notifications SET is_read = 1 WHERE id = ?");
        $stmt->bind_param("i", $row['id']);
        $stmt->execute();
        $stmt->close();

        log_audit_action($supervisor_id,
            "Processed {$type_label}",
            "Report ID: {$report_id}, Action: {$action}, Result: {$result_label}, Report code: {$report_label}");
    } catch (Exception $e) {
        error_log("rgmap_notify_requestor error: " . $e->getMessage());
    }
}

// Notify the supervisor who performed a Complete/Cancel action on the
// monitoring portal. Unlike rgmap_notify_requestor (which targets the officer
// who submitted the review request), this targets the acting supervisor by
// email so the completion/cancellation result appears in their own
// notifications feed (notifications.php).
function rgmap_notify_supervisor_action($conn, $report_id, $action, $supervisor_id, $report_code) {
    try {
        if (!in_array($action, ['complete', 'cancel'], true)) return;

        $supervisor = fetch_one("SELECT full_name, email, role FROM users WHERE id = ?", [$supervisor_id], "i");
        if (!$supervisor) return;

        $report_label = $report_code ?? ('#' . $report_id);
        $action_label = ($action === 'complete') ? 'completed' : 'cancelled';
        $notif_type   = ($action === 'complete') ? 'complete_report' : 'cancel_report';
        $message      = "You {$action_label} report {$report_label}. The report is now marked as {$action_label}.";

        // Road Operations Supervisors only: a complete/cancel result must never
        // stack duplicates in notification.php. Retire every older identical
        // result notification (mark it read, keeping the newest), then skip the
        // insert entirely when one already exists for this report/type — even
        // if it was already read. Transportation report behavior is unchanged
        // (unread-only dedup).
        $is_road_supervisor = ($supervisor['role'] === 'road_ops_supervisor');
        if ($is_road_supervisor) {
            $max = $conn->prepare("SELECT COALESCE(MAX(id), 0) AS mid FROM report_notifications WHERE report_id = ? AND type = ? AND recipient_email = ?");
            $max->bind_param("iss", $report_id, $notif_type, $supervisor['email']);
            $max->execute();
            $mid = (int)$max->get_result()->fetch_assoc()['mid'];
            $max->close();
            if ($mid > 0) {
                $retire = $conn->prepare("UPDATE report_notifications SET is_read = 1 WHERE report_id = ? AND type = ? AND recipient_email = ? AND is_read = 0 AND id != ?");
                $retire->bind_param("issi", $report_id, $notif_type, $supervisor['email'], $mid);
                $retire->execute();
                $retire->close();
            }
        }

        // Transportation reports only: skip when an identical result notification
        // is already pending (unread) for the acting supervisor, so reprocessing
        // the same report does not stack duplicate confirmations. Road
        // supervisors skip on ANY existing identical notification (handled above).
        if ($is_road_supervisor || rgmap_is_transportation_report($conn, $report_id)) {
            $dup = $conn->prepare("SELECT id FROM report_notifications WHERE report_id = ? AND type = ? AND recipient_email = ?" . ($is_road_supervisor ? '' : ' AND is_read = 0') . " ORDER BY id DESC LIMIT 1");
            $dup->bind_param("iss", $report_id, $notif_type, $supervisor['email']);
            $dup->execute();
            $dup_row = $dup->get_result()->fetch_assoc();
            $dup->close();
            if ($dup_row) return;
        }

        $stmt = $conn->prepare(
            "INSERT INTO report_notifications (report_id, type, message, recipient_email, recipient_role) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("issss", $report_id, $notif_type, $message, $supervisor['email'], $supervisor['role']);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        error_log("rgmap_notify_supervisor_action error: " . $e->getMessage());
    }
}

// File a COPY of a CIMM report into the archive with the given status, WITHOUT
// deleting it from cimm_verification_reports.
function rgmap_archive_copy_cimm_report($conn, $cimm_req_id, $status) {
    try {
        rgmap_archive_ensure_table();
        $cimm_report = rgmap_archive_load_cimm_live($conn, $cimm_req_id);
        if (!$cimm_report) {
            return false;
        }

        $archive_status = rgmap_archive_normalize_status($status, 'completed');
        $now = date('Y-m-d H:i:s');
        unset($cimm_report['id']);
        $cimm_report['previous_status'] = $cimm_report['verification_status'] ?? null;
        $cimm_report['archived_at'] = $now;
        $cimm_report['archive_status'] = $archive_status;

        $conn->begin_transaction();
        rgmap_archive_insert_native($conn, 'cimm_verification_reports_archive', $cimm_report);
        $conn->commit();
        return true;
    } catch (Exception $e) {
        try { $conn->rollback(); } catch (Throwable $rollback_error) { /* No active transaction */ }
        error_log("rgmap_archive_copy_cimm_report failed: " . $e->getMessage());
        return false;
    }
}

// Ensure the auto_archive_at column exists on every live report table. This
// column holds the timestamp at which a report completed through the
// supervisor portal's Complete button is automatically moved to the archive.
// Reports completed through report_management.php never get this value, so the
// sweep below never touches them (scoping the auto-archive to the portal).
function rgmap_ensure_auto_archive_column() {
    global $conn;
    foreach (['road_transportation_reports', 'road_maintenance_reports'] as $t) {
        try {
            $conn->query("ALTER TABLE $t ADD COLUMN IF NOT EXISTS auto_archive_at TIMESTAMP NULL DEFAULT NULL AFTER completed_at");
        } catch (Exception $e) {
            error_log("auto_archive_at add ($t): " . $e->getMessage());
        }
    }
    try {
        $conn->query("ALTER TABLE cimm_verification_reports ADD COLUMN IF NOT EXISTS auto_archive_at TIMESTAMP NULL DEFAULT NULL");
    } catch (Exception $e) {
        error_log("auto_archive_at add (cimm): " . $e->getMessage());
    }
}

// Completed projects stay on Completed Projects until an administrator or
// supervisor clicks Archive. Automatic 7-day moves are disabled; this helper
// is kept so existing callers do not break.
function rgmap_auto_archive_completed($conn) {
    return 0;
}

function rgmap_archive_union_sql($include_cimm, $include_ipms) {
    $source_case = "CASE
        WHEN report_source = 'external' THEN 'cimm'
        WHEN report_type IN ('infrastructure_issue','maintenance','maintenance_request') THEN 'infrastructure'
        WHEN report_source = 'local' AND COALESCE(created_by, 0) != 0 THEN 'lgu'
        ELSE 'citizen'
    END";

    $road = "
        SELECT
            id,
            'road_transportation_reports_archive' AS archive_table,
            $source_case AS source_system,
            report_id,
            title,
            report_type,
            report_category,
            report_source,
            created_by,
            department,
            priority,
            status,
            created_date,
            due_date,
            description,
            location,
            attachments,
            latitude,
            longitude,
            created_at,
            updated_at,
            approved_at,
            rejected_at,
            completed_at,
            previous_status,
            archived_from,
            source_pk,
            engineer,
            budget_allocation,
            approval_status,
            start_address,
            end_address,
            ipms_polyline_json,
            reporter_name,
            district,
            cimm_starting_date,
            cimm_estimated_end_date,
            cimm_budget,
            cimm_engineer_name,
            cimm_district,
            cimm_status,
            cimm_report_url,
            reporter_email,
            reporter_phone,
            assigned_to,
            NULL AS assigned_engineers_json,
            NULL AS polyline_json
        FROM road_transportation_reports_archive
    ";

    $parts = [$road];

    if ($include_cimm) {
        $parts[] = "
            SELECT
                id,
                'cimm_verification_reports_archive' AS archive_table,
                'cimm' AS source_system,
                reference_code AS report_id,
                infrastructure AS title,
                'infrastructure_issue' AS report_type,
                'road' AS report_category,
                'external' AS report_source,
                0 AS created_by,
                'engineering' AS department,
                LOWER(COALESCE(priority, 'medium')) AS priority,
                COALESCE(archive_status, 'rejected') AS status,
                DATE(COALESCE(submitted_at, created_at)) AS created_date,
                estimated_end_date AS due_date,
                issue AS description,
                location,
                evidence_json AS attachments,
                coord_lat AS latitude,
                coord_lng AS longitude,
                COALESCE(submitted_at, created_at) AS created_at,
                COALESCE(synced_at, created_at) AS updated_at,
                verified_at AS approved_at,
                CASE WHEN COALESCE(archive_status, '') IN ('rejected', 'cancelled') THEN archived_at ELSE NULL END AS rejected_at,
                CASE WHEN COALESCE(archive_status, '') = 'completed' THEN archived_at ELSE NULL END AS completed_at,
                previous_status,
                'cimm_verification_reports' AS archived_from,
                id AS source_pk,
                engineer,
                budget AS budget_allocation,
                approval_status,
                NULL AS start_address,
                NULL AS end_address,
                NULL AS ipms_polyline_json,
                reporter_name,
                district,
                starting_date AS cimm_starting_date,
                estimated_end_date AS cimm_estimated_end_date,
                budget AS cimm_budget,
                engineer AS cimm_engineer_name,
                district AS cimm_district,
                verification_status AS cimm_status,
                portal_url AS cimm_report_url,
                email AS reporter_email,
                contact_number AS reporter_phone,
                NULL AS assigned_to,
                NULL AS assigned_engineers_json,
                NULL AS polyline_json
            FROM cimm_verification_reports_archive
        ";
    }

    if ($include_ipms) {
        $parts[] = "
            SELECT
                id,
                'ipms_road_projects_archive' AS archive_table,
                'infrastructure' AS source_system,
                CONCAT('IPMS-', project_id) AS report_id,
                project_name AS title,
                COALESCE(NULLIF(road_type, ''), 'infrastructure_issue') AS report_type,
                'road' AS report_category,
                'local' AS report_source,
                0 AS created_by,
                'engineering' AS department,
                LOWER(COALESCE(priority, 'medium')) AS priority,
                COALESCE(archive_status, status, 'rejected') AS status,
                start_date AS created_date,
                end_date AS due_date,
                road_status AS description,
                COALESCE(NULLIF(road_name, ''), project_name) AS location,
                NULL AS attachments,
                start_lat AS latitude,
                start_lng AS longitude,
                created_at,
                synced_at AS updated_at,
                NULL AS approved_at,
                CASE WHEN COALESCE(archive_status, status, '') IN ('rejected', 'cancelled') THEN archived_at ELSE NULL END AS rejected_at,
                CASE WHEN COALESCE(archive_status, status, '') = 'completed' THEN archived_at ELSE NULL END AS completed_at,
                previous_status,
                'ipms_road_projects' AS archived_from,
                project_id AS source_pk,
                NULL AS engineer,
                budget AS budget_allocation,
                NULL AS approval_status,
                start_address,
                end_address,
                polyline_json AS ipms_polyline_json,
                NULL AS reporter_name,
                NULL AS district,
                start_date AS cimm_starting_date,
                end_date AS cimm_estimated_end_date,
                budget AS cimm_budget,
                NULL AS cimm_engineer_name,
                NULL AS cimm_district,
                NULL AS cimm_status,
                NULL AS cimm_report_url,
                NULL AS reporter_email,
                NULL AS reporter_phone,
                NULL AS assigned_to,
                assigned_engineers_json,
                polyline_json
            FROM ipms_road_projects_archive
        ";
    }

    return '(' . implode(' UNION ALL ', $parts) . ') AS archive_rows';
}

function rgmap_archive_allowed_table($table) {
    return in_array($table, [
        'road_transportation_reports_archive',
        'cimm_verification_reports_archive',
        'ipms_road_projects_archive',
    ], true);
}

function rgmap_archive_fetch_row($conn, $table, $id) {
    $id = (int)$id;
    if ($id <= 0 || !rgmap_archive_allowed_table($table)) {
        return null;
    }
    $stmt = $conn->prepare("SELECT * FROM `$table` WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function rgmap_restore_cimm_from_native_archive($conn, array $row, $archive_id) {
    $archive_status = strtolower(trim((string)($row['archive_status'] ?? $row['verification_status'] ?? '')));
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
    $restore_status = $cimm_status_map[$archive_status] ?? ($row['previous_status'] ?? ($row['verification_status'] ?? 'Pending Review'));
    $rfa = (strtolower($restore_status) === 'cancelled') ? 1 : 0;

    $live = $row;
    unset($live['id'], $live['previous_status'], $live['archived_at'], $live['archive_status']);
    $live['verification_status'] = $restore_status;
    $live['restored_from_archive'] = $rfa;

    $cimm_req_id = $row['cimm_req_id'] ?? null;
    $existing = null;
    if ($cimm_req_id !== null && $cimm_req_id !== '') {
        $dup = $conn->prepare("SELECT id FROM cimm_verification_reports WHERE cimm_req_id = ? LIMIT 1");
        $dup->bind_param('s', $cimm_req_id);
        $dup->execute();
        $existing = $dup->get_result()->fetch_assoc();
        $dup->close();
    }

    if ($existing) {
        $live_cols = rgmap_archive_table_columns($conn, 'cimm_verification_reports');
        $sets = [];
        $vals = [];
        foreach ($live as $field => $value) {
            if (!isset($live_cols[$field]) || $field === 'id') {
                continue;
            }
            $sets[] = "`$field` = ?";
            $vals[] = $value;
        }
        $vals[] = (int)$existing['id'];
        $upd = $conn->prepare("UPDATE cimm_verification_reports SET " . implode(', ', $sets) . " WHERE id = ?");
        $upd->execute($vals);
        $upd->close();
    } else {
        $original_pk = (int)($row['id'] ?? 0);
        if ($original_pk > 0 && rgmap_pk_is_free($conn, 'cimm_verification_reports', $original_pk)) {
            $live = ['id' => $original_pk] + $live;
        }
        rgmap_archive_insert_native($conn, 'cimm_verification_reports', $live);
    }

    $delete = $conn->prepare("DELETE FROM cimm_verification_reports_archive WHERE id = ?");
    $delete->bind_param('i', $archive_id);
    $delete->execute();
    $delete->close();
    return true;
}

function rgmap_restore_ipms_from_native_archive($conn, array $row, $archive_id) {
    require_once __DIR__ . '/ipms_road_projects_data.php';
    $pdo = rgmap_ipms_pdo();
    $project_id = (int)($row['project_id'] ?? 0);
    if ($project_id <= 0) {
        return false;
    }

    $restore_status = trim((string)($row['archive_status'] ?? ''));
    if ($restore_status === '') {
        $restore_status = trim((string)($row['status'] ?? ''));
    }
    if ($restore_status === '' && !empty($row['previous_status'])) {
        $restore_status = (string)$row['previous_status'];
    }

    $chk = $pdo->prepare("SELECT project_id FROM ipms_road_projects WHERE project_id = ? LIMIT 1");
    $chk->execute([$project_id]);
    if ($chk->fetch()) {
        if ($restore_status !== '') {
            $upd = $pdo->prepare("UPDATE ipms_road_projects SET status = ? WHERE project_id = ?");
            $upd->execute([$restore_status, $project_id]);
        }
        $del = $conn->prepare("DELETE FROM ipms_road_projects_archive WHERE id = ?");
        $del->bind_param('i', $archive_id);
        $del->execute();
        $del->close();
        return true;
    }

    $live = $row;
    unset($live['id'], $live['previous_status'], $live['archived_at'], $live['archive_status']);
    if ($restore_status !== '') {
        $live['status'] = $restore_status;
    }

    $dest_cols = [];
    $col_res = $pdo->query("SHOW COLUMNS FROM ipms_road_projects");
    foreach ($col_res as $c) {
        $dest_cols[$c['Field']] = true;
    }
    $fields = [];
    $values = [];
    foreach ($live as $field => $value) {
        if (!isset($dest_cols[$field]) || $field === 'id') {
            continue;
        }
        $fields[] = $field;
        $values[] = $value;
    }
    if (empty($fields)) {
        return false;
    }
    $field_list = '`' . implode('`, `', $fields) . '`';
    $placeholders = implode(', ', array_fill(0, count($fields), '?'));
    $ins = $pdo->prepare("INSERT INTO ipms_road_projects ($field_list) VALUES ($placeholders)");
    $ins->execute($values);

    $delete = $conn->prepare("DELETE FROM ipms_road_projects_archive WHERE id = ?");
    $delete->bind_param('i', $archive_id);
    $delete->execute();
    $delete->close();
    return true;
}
