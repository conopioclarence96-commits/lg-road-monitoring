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
                      JOIN cimm_verification_reports c ON c.reference_code = a.report_id
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

// Archive a CIMM report (stored in cimm_verification_reports). The row is
// mapped into the archive table with report_source 'external' (so archive.php
// labels it CIMM) and the given terminal status, then removed from the CIMM
// table — mirroring archive_cimm_rejected_report().
function rgmap_archive_cimm_report($conn, $cimm_req_id, $status) {
    try {
        rgmap_archive_ensure_table();

        $stmt = $conn->prepare("SELECT * FROM cimm_verification_reports WHERE id = ?");
        $stmt->bind_param("i", $cimm_req_id);
        $stmt->execute();
        $cimm_report = $stmt->get_result()->fetch_assoc();
        if (!$cimm_report) return false;

        $now = date('Y-m-d H:i:s');
        $conn->begin_transaction();

        // Normalise the free-text CIMM status/priority down to values the
        // archive enum columns accept. CIMM stores title-case and non-enum
        // values ('Pending Review', 'Verified', 'Critical', ...) that would
        // otherwise trigger "Data truncated for column ..." and roll back the
        // whole archive — which surfaced as a broken Archive button.
        $archive_priority = strtolower(trim((string)($cimm_report['priority'] ?? 'medium')));
        if (!in_array($archive_priority, ['high', 'medium', 'low'], true)) {
            $archive_priority = ($archive_priority === 'critical') ? 'high' : 'medium';
        }
        $valid_archive_statuses = ['pending', 'in-progress', 'completed', 'cancelled', 'approved', 'rejected'];
        $archive_status = strtolower(trim((string)$status));
        if (!in_array($archive_status, $valid_archive_statuses, true)) {
            $archive_status = in_array($archive_status, ['approved', 'verified'], true) ? 'approved' : 'completed';
        }

$timestamp_key = ($archive_status === 'cancelled') ? 'rejected_at' : 'completed_at';
            // Preserve the CIMM evidence photos (absolute URLs in
            // evidence_json) in the archive's attachments column so the
            // archive View modal can render the same evidence photos.
            $this_cimm_archive_attachments_json = null;
            $this_cimm_archive_evidence = json_decode((string)($cimm_report['evidence_json'] ?? '[]'), true);
            if (is_array($this_cimm_archive_evidence) && count($this_cimm_archive_evidence) > 0) {
                $this_cimm_archive_items = [];
                foreach ($this_cimm_archive_evidence as $this_cimm_archive_url) {
                    if (is_string($this_cimm_archive_url) && $this_cimm_archive_url !== '') {
                        $this_cimm_archive_items[] = ['type' => 'image', 'file_path' => $this_cimm_archive_url];
                    }
                }
                if (count($this_cimm_archive_items) > 0) {
                    $this_cimm_archive_attachments_json = json_encode($this_cimm_archive_items, JSON_UNESCAPED_SLASHES);
                }
            }
            // Preserve ALL original CIMM information so a later restore brings
            // the report back exactly as it was — start/end dates, engineer,
            // budget and the rest are stored in the archive's cimm_* columns.
            $insert_fields = [
                'report_id' => $cimm_report['reference_code'] ?? ('CIMM-' . $cimm_req_id),
                'title' => $cimm_report['infrastructure'] ?? 'CIMM Report',
                'report_type' => 'infrastructure_issue',
                'report_category' => 'road',
                'report_source' => 'external',
                'department' => 'engineering',
                'priority' => $archive_priority,
                'status' => $archive_status,
                'previous_status' => $cimm_report['verification_status'] ?? null,
                'archived_from' => 'cimm_verification_reports',
                'source_pk' => (int)$cimm_req_id,
                'created_date' => (!empty($cimm_report['submitted_at'])) ? date('Y-m-d', strtotime($cimm_report['submitted_at'])) : date('Y-m-d'),
                'description' => $cimm_report['issue'] ?? '',
                'location' => $cimm_report['location'] ?? '',
                'latitude' => $cimm_report['coord_lat'] ?? null,
                'longitude' => $cimm_report['coord_lng'] ?? null,
                'reporter_name' => $cimm_report['reporter_name'] ?? null,
                'district' => $cimm_report['district'] ?? null,
                'created_at' => $cimm_report['submitted_at'] ?? $now,
                'updated_at' => $now,
                $timestamp_key => $now,
                'approved_at' => null,
                'engineer' => $cimm_report['engineer'] ?? null,
                'budget_allocation' => $cimm_report['budget_allocation'] ?? null,
                'cimm_engineer_name' => $cimm_report['engineer'] ?? null,
                'cimm_budget' => $cimm_report['budget'] ?? $cimm_report['budget_allocation'] ?? null,
                'cimm_starting_date' => $cimm_report['starting_date'] ?? null,
                'cimm_estimated_end_date' => $cimm_report['estimated_end_date'] ?? null,
                'cimm_status' => $cimm_report['verification_status'] ?? null,
                'cimm_district' => $cimm_report['district'] ?? null,
                'approval_status' => $cimm_report['approval_status'] ?? null,
                'cimm_report_url' => $cimm_report['portal_url'] ?? null,
                'reporter_email' => $cimm_report['email'] ?? null,
                'reporter_phone' => $cimm_report['contact_number'] ?? null,
                'attachments' => $this_cimm_archive_attachments_json,
            ];

        $fields = array_keys($insert_fields);
        $placeholders = array_fill(0, count($fields), '?');
        $field_list = '`' . implode('`, `', $fields) . '`';
        $placeholder_list = implode(', ', $placeholders);

        $insert = "INSERT INTO road_transportation_reports_archive ($field_list) VALUES ($placeholder_list)";
        $stmt = $conn->prepare($insert);
        $stmt->execute(array_values($insert_fields));

        $delete = $conn->prepare("DELETE FROM cimm_verification_reports WHERE id = ?");
        $delete->execute([$cimm_req_id]);

        $conn->commit();
        return true;
    } catch (Exception $e) {
        try { $conn->rollback(); } catch (Throwable $rollback_error) { /* No active transaction */ }
        error_log("rgmap_archive_cimm_report failed: " . $e->getMessage());
        return false;
    }
}

// Archive an IPMS infrastructure project: copy every field the verification
// View modal needs into road_transportation_reports_archive, then remove the
// row from ipms_road_projects. Used when a project is rejected on
// verification_monitoring.php or deleted on report_management.php.
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

        $now = date('Y-m-d H:i:s');
        $valid_archive_statuses = ['pending', 'in-progress', 'completed', 'cancelled', 'approved', 'rejected'];
        $archive_status = strtolower(trim((string)$status));
        if (!in_array($archive_status, $valid_archive_statuses, true)) {
            $archive_status = 'rejected';
        }

        $archive_priority = strtolower(trim((string)($row['priority'] ?? 'medium')));
        if (!in_array($archive_priority, ['high', 'medium', 'low'], true)) {
            $archive_priority = 'medium';
        }

        $engineers = json_decode((string)($row['assigned_engineers_json'] ?? '[]'), true) ?: [];
        $engineer = implode(', ', array_filter(
            array_map('trim', array_map('strval', is_array($engineers) ? $engineers : [])),
            static fn($n) => $n !== ''
        ));

        $barangays = json_decode((string)($row['barangays_json'] ?? '[]'), true) ?: [];
        $location = '';
        if (is_array($barangays) && count($barangays) > 0) {
            $location = implode(', ', array_filter(
                array_map('trim', array_map('strval', $barangays)),
                static fn($b) => $b !== ''
            ));
        }
        if ($location === '') {
            $location = trim((string)($row['road_name'] ?? ''));
        }

        $road_type = trim((string)($row['road_type'] ?? ''));
        $report_type = $road_type !== '' ? $road_type : 'infrastructure_issue';
        $start_date = $row['start_date'] ?? null;
        $end_date = $row['end_date'] ?? null;
        $report_code = 'IPMS-' . $project_id;

        $insert_fields = [
            'report_id' => $report_code,
            'title' => $row['project_name'] ?? 'Infrastructure Project',
            'report_type' => $report_type,
            'report_category' => 'road',
            'report_source' => 'local',
            'department' => 'engineering',
            'priority' => $archive_priority,
            'status' => $archive_status,
            'previous_status' => $row['status'] ?? null,
            'archived_from' => 'ipms_road_projects',
            'source_pk' => $project_id,
            'created_date' => (!empty($start_date)) ? date('Y-m-d', strtotime($start_date)) : date('Y-m-d'),
            'due_date' => $end_date,
            'description' => $row['road_status'] ?? '',
            'location' => $location,
            'latitude' => $row['start_lat'] ?? null,
            'longitude' => $row['start_lng'] ?? null,
            'created_at' => $row['created_at'] ?? $now,
            'updated_at' => $now,
            'rejected_at' => in_array($archive_status, ['rejected', 'cancelled'], true) ? $now : null,
            'engineer' => ($engineer !== '') ? $engineer : null,
            'assigned_to' => ($engineer !== '') ? $engineer : null,
            'budget_allocation' => $row['budget'] ?? null,
            'cimm_budget' => $row['budget'] ?? null,
            'cimm_starting_date' => $start_date,
            'cimm_estimated_end_date' => $end_date,
            'start_address' => $row['start_address'] ?? null,
            'end_address' => $row['end_address'] ?? null,
            'ipms_polyline_json' => $row['polyline_json'] ?? null,
        ];

        $conn->begin_transaction();

        $dup = $conn->prepare("SELECT id FROM road_transportation_reports_archive WHERE report_id = ? LIMIT 1");
        $dup->bind_param('s', $report_code);
        $dup->execute();
        $existing = $dup->get_result()->fetch_assoc();
        $dup->close();

        if ($existing) {
            $set_parts = [];
            $vals = [];
            foreach ($insert_fields as $field => $val) {
                $set_parts[] = "`$field` = ?";
                $vals[] = $val;
            }
            $vals[] = (int)$existing['id'];
            $upd_sql = "UPDATE road_transportation_reports_archive SET " . implode(', ', $set_parts) . " WHERE id = ?";
            $upd = $conn->prepare($upd_sql);
            $upd->execute($vals);
            $upd->close();
        } else {
            $fields = array_keys($insert_fields);
            $placeholders = array_fill(0, count($fields), '?');
            $field_list = '`' . implode('`, `', $fields) . '`';
            $insert = "INSERT INTO road_transportation_reports_archive ($field_list) VALUES (" . implode(', ', $placeholders) . ")";
            $ins = $conn->prepare($insert);
            $ins->execute(array_values($insert_fields));
            $ins->close();
        }

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

        $stmt = $conn->prepare("SELECT * FROM cimm_verification_reports WHERE id = ?");
        $stmt->bind_param("i", $cimm_req_id);
        $stmt->execute();
        $cimm_report = $stmt->get_result()->fetch_assoc();
        if (!$cimm_report) return false;

        $now = date('Y-m-d H:i:s');
        $timestamp_key = ($status === 'cancelled') ? 'rejected_at' : 'completed_at';
        // Preserve ALL original CIMM information so a later restore brings
        // the report back exactly as it was — start/end dates, engineer,
        // budget and the rest are stored in the archive's cimm_* columns.
        $insert_fields = [
            'report_id' => $cimm_report['reference_code'] ?? ('CIMM-' . $cimm_req_id),
            'title' => $cimm_report['infrastructure'] ?? 'CIMM Report',
            'report_type' => 'infrastructure_issue',
            'report_category' => 'road',
            'report_source' => 'external',
            'department' => 'engineering',
            'priority' => $cimm_report['priority'] ?? 'medium',
            'status' => $status,
            'previous_status' => $cimm_report['verification_status'] ?? null,
            'archived_from' => 'cimm_verification_reports',
            'source_pk' => (int)$cimm_req_id,
            'created_date' => (!empty($cimm_report['submitted_at'])) ? date('Y-m-d', strtotime($cimm_report['submitted_at'])) : date('Y-m-d'),
            'description' => $cimm_report['issue'] ?? '',
            'location' => $cimm_report['location'] ?? '',
            'latitude' => $cimm_report['coord_lat'] ?? null,
            'longitude' => $cimm_report['coord_lng'] ?? null,
            'reporter_name' => $cimm_report['reporter_name'] ?? null,
            'district' => $cimm_report['district'] ?? null,
            'created_at' => $cimm_report['submitted_at'] ?? $now,
            'updated_at' => $now,
            $timestamp_key => $now,
            'approved_at' => null,
            'engineer' => $cimm_report['engineer'] ?? null,
            'budget_allocation' => $cimm_report['budget_allocation'] ?? null,
            'cimm_engineer_name' => $cimm_report['engineer'] ?? null,
            'cimm_budget' => $cimm_report['budget'] ?? $cimm_report['budget_allocation'] ?? null,
            'cimm_starting_date' => $cimm_report['starting_date'] ?? null,
            'cimm_estimated_end_date' => $cimm_report['estimated_end_date'] ?? null,
            'cimm_status' => $cimm_report['verification_status'] ?? null,
            'cimm_district' => $cimm_report['district'] ?? null,
        ];

        $fields = array_keys($insert_fields);
        $placeholders = array_fill(0, count($fields), '?');
        $field_list = '`' . implode('`, `', $fields) . '`';
        $placeholder_list = implode(', ', $placeholders);

        $conn->begin_transaction();
        $insert = "INSERT INTO road_transportation_reports_archive ($field_list) VALUES ($placeholder_list)";
        $stmt = $conn->prepare($insert);
        $stmt->execute(array_values($insert_fields));
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
