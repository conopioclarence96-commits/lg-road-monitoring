<?php
require_once '../../includes/session_config.php';
require_once '../../includes/config.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/../../api/cimm_verification_data.php';
require_once __DIR__ . '/../api/ipms_road_projects_data.php';
require_once __DIR__ . '/../api/progress_archive_helpers.php';
require_once __DIR__ . '/verification_panel_pagination.php';

// Session timeout configuration
$session_timeout = 30 * 60; // 30 minutes in seconds

// Check if session has expired
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout)) {
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/');
    header('Location: ../../login.php?timeout=1');
    exit();
}

// Update last activity time
$_SESSION['last_activity'] = time();

// NOTE: cimm_reports (the local mock table) has been retired. CIMM reports now
// come live from the real cimm_verification_reports table, populated by the
// webhook/pull sync (see /lgu_staff/pages/api/cimm-reports-webhook.php and
// cimm-reports-pull.php) and read through rgmap_fetch_cimm_verification_reports()
// in cimm_verification_data.php.

// Ensure required columns exist in report tables
foreach (['road_transportation_reports', 'road_maintenance_reports'] as $tbl) {
    $check = $conn->query("SHOW COLUMNS FROM $tbl LIKE 'approved_at'");
    if ($check && $check->num_rows === 0) {
        $conn->query("ALTER TABLE $tbl ADD COLUMN approved_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at");
    }
    $check2 = $conn->query("SHOW COLUMNS FROM $tbl LIKE 'rejected_at'");
    if ($check2 && $check2->num_rows === 0) {
        $conn->query("ALTER TABLE $tbl ADD COLUMN rejected_at TIMESTAMP NULL DEFAULT NULL AFTER approved_at");
    }
}

// Ensure report_category and report_source columns exist in road_transportation_reports
$check = $conn->query("SHOW COLUMNS FROM road_transportation_reports LIKE 'report_category'");
if ($check && $check->num_rows === 0) {
    $conn->query("ALTER TABLE road_transportation_reports ADD COLUMN report_category ENUM('road','transportation') DEFAULT NULL AFTER report_type");
}
$check2 = $conn->query("SHOW COLUMNS FROM road_transportation_reports LIKE 'report_source'");
if ($check2 && $check2->num_rows === 0) {
    $conn->query("ALTER TABLE road_transportation_reports ADD COLUMN report_source ENUM('local','external') DEFAULT 'local' AFTER report_category");
}

// Ensure engineer and budget_allocation columns exist in road_transportation_reports
// (CIMM syncs these for road reports via the verify webhook).
$check_engineer = $conn->query("SHOW COLUMNS FROM road_transportation_reports LIKE 'engineer'");
if ($check_engineer && $check_engineer->num_rows === 0) {
    $conn->query("ALTER TABLE road_transportation_reports ADD COLUMN engineer VARCHAR(150) NULL DEFAULT NULL");
}
$check_budget = $conn->query("SHOW COLUMNS FROM road_transportation_reports LIKE 'budget_allocation'");
if ($check_budget && $check_budget->num_rows === 0) {
    $conn->query("ALTER TABLE road_transportation_reports ADD COLUMN budget_allocation DECIMAL(15,2) NULL DEFAULT NULL");
}

// Ensure reporter_phone / image_path exist — getCitizenReports() below
// selects them, and without this guard a DB that predates those columns
// (e.g. a fresh/older local install) throws "Unknown column" and takes
// down the whole page before it ever reaches the CIMM panel further down.
$check_phone = $conn->query("SHOW COLUMNS FROM road_transportation_reports LIKE 'reporter_phone'");
if ($check_phone && $check_phone->num_rows === 0) {
    $conn->query("ALTER TABLE road_transportation_reports ADD COLUMN reporter_phone VARCHAR(30) NULL DEFAULT NULL AFTER reporter_email");
}
$check_imgpath = $conn->query("SHOW COLUMNS FROM road_transportation_reports LIKE 'image_path'");
if ($check_imgpath && $check_imgpath->num_rows === 0) {
    $conn->query("ALTER TABLE road_transportation_reports ADD COLUMN image_path VARCHAR(500) NULL DEFAULT NULL AFTER attachments");
}

// Ensure the cimm_engineer_name/cimm_budget/cimm_starting_date/
// cimm_estimated_end_date/cimm_status/cimm_district columns exist —
// getAllReports() below selects them for the LGU Monitoring Reports panel.
// Normally created lazily by cimm-reports-webhook.php the first time a
// synced report arrives, but this page must not 500 on "Unknown column" if
// it's opened before that's ever happened (e.g. a fresh install).
require_once __DIR__ . '/../api/rgmap_cimm_sync.php';
rgmap_cimm_ensure_schema($conn);

// ── Backfill: self-heal CIMM verification rows that were written while the
//    webhook's parameter-count bug was live (see git history on
//    cimm-reports-webhook.php / rgmap_apply_cimm_report_payload() in
//    cimm_verification_data.php) — those rows are missing their district,
//    and any linked road_transportation_reports row never got its
//    cimm_status/cimm_district columns populated either, which is what kept
//    showing the stale "Pushed" badge instead of CIMM's real, current
//    status. Re-fetches each affected report fresh from CIMM and replays it
//    through the now-fixed write path. Capped and best-effort — a CIMM
//    outage here must never break this page load; any remainder is picked
//    up on the next one. ──────────────────────────────────────────────────
try {
    rgmap_backfill_stale_cimm_reports(rgmap_verification_pdo(), $conn, 5);
} catch (\Throwable $e) {
    error_log('CIMM verification backfill failed: ' . $e->getMessage());
}

// Ensure the archive table exists — normally created lazily by archive.php,
// but this page also queries it below (delete/archive action) and reads its
// columns, so it must exist before landing here first.
$conn->query("CREATE TABLE IF NOT EXISTS road_transportation_reports_archive LIKE road_transportation_reports");

// Ensure the archive table has the same columns
$check_arch = $conn->query("SHOW COLUMNS FROM road_transportation_reports_archive LIKE 'report_category'");
if ($check_arch && $check_arch->num_rows === 0) {
    $conn->query("ALTER TABLE road_transportation_reports_archive ADD COLUMN report_category ENUM('road','transportation') DEFAULT NULL AFTER report_type");
}
$check_arch2 = $conn->query("SHOW COLUMNS FROM road_transportation_reports_archive LIKE 'report_source'");
if ($check_arch2 && $check_arch2->num_rows === 0) {
    $conn->query("ALTER TABLE road_transportation_reports_archive ADD COLUMN report_source ENUM('local','external') DEFAULT 'local' AFTER report_category");
}

// Ensure archive table mirrors the engineer/budget_allocation columns
$check_arch_engineer = $conn->query("SHOW COLUMNS FROM road_transportation_reports_archive LIKE 'engineer'");
if ($check_arch_engineer && $check_arch_engineer->num_rows === 0) {
    $conn->query("ALTER TABLE road_transportation_reports_archive ADD COLUMN engineer VARCHAR(150) NULL DEFAULT NULL");
}
$check_arch_budget = $conn->query("SHOW COLUMNS FROM road_transportation_reports_archive LIKE 'budget_allocation'");
if ($check_arch_budget && $check_arch_budget->num_rows === 0) {
    $conn->query("ALTER TABLE road_transportation_reports_archive ADD COLUMN budget_allocation DECIMAL(15,2) NULL DEFAULT NULL");
}
$check_arch_approval = $conn->query("SHOW COLUMNS FROM road_transportation_reports_archive LIKE 'approval_status'");
if ($check_arch_approval && $check_arch_approval->num_rows === 0) {
    $conn->query("ALTER TABLE road_transportation_reports_archive ADD COLUMN approval_status VARCHAR(50) NULL DEFAULT NULL");
}

// Ensure reports table exists (from reports.sql)
$conn->query("CREATE TABLE IF NOT EXISTS reports (
    rep_id int(10) unsigned NOT NULL AUTO_INCREMENT,
    res_id int(10) unsigned NOT NULL,
    starting_date date NOT NULL,
    estimated_end_date date NOT NULL,
    engineer_id int(10) unsigned DEFAULT NULL,
    report_by int(10) unsigned NOT NULL,
    priority_lvl varchar(50) DEFAULT NULL,
    budget decimal(15,2) NOT NULL DEFAULT 0.00,
    created_at timestamp NOT NULL DEFAULT current_timestamp(),
    engineer_accepted tinyint(1) NOT NULL DEFAULT 0,
    decline_reason text DEFAULT NULL,
    decline_reviewed tinyint(1) DEFAULT NULL COMMENT '1=valid,0=invalid',
    decline_review_note text DEFAULT NULL,
    PRIMARY KEY (rep_id),
    KEY fk_report_res (res_id),
    KEY fk_report_engineer (engineer_id),
    KEY fk_report_reporter (report_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Check if user is logged in and has proper role
$allowed_roles = ['system_admin', 'road_ops_supervisor', 'trans_ops_supervisor'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowed_roles)) {
    header('Location: ../../login.php');
    exit();
}

// Transportation Operations supervisors only see LGU Monitoring
// Transportation reports and Citizen reports (no CIMM, infrastructure,
// or LGU Road reports).
$user_role = $_SESSION['role'] ?? 'citizen';
$is_transport_supervisor = ($user_role === 'trans_ops_supervisor');

// Road Operations Supervisors see only Road-relevant reports: Road reports in
// the LGU Monitoring panel, all CIMM reports, and no Transportation reports.
$is_road_supervisor = ($user_role === 'road_ops_supervisor');

// Function to get verification statistics
function getVerificationStatistics($conn) {
    $stats = [];
    
    // Pending verifications from both tables
    $result = $conn->query("SELECT COUNT(*) as pending FROM road_transportation_reports WHERE status = 'pending'");
    $transport_pending = $result->fetch_assoc()['pending'];
    
    $result = $conn->query("SELECT COUNT(*) as pending FROM road_maintenance_reports WHERE status = 'pending'");
    $maintenance_pending = $result->fetch_assoc()['pending'];
    $stats['pending'] = $transport_pending + $maintenance_pending;
    
    // Debug: Add individual counts for display
    $stats['transport_pending'] = $transport_pending;
    $stats['maintenance_pending'] = $maintenance_pending;
    
    // In progress from both tables
    $result = $conn->query("SELECT COUNT(*) as in_progress FROM road_transportation_reports WHERE status = 'in-progress'");
    $transport_progress = $result->fetch_assoc()['in_progress'];
    
    $result = $conn->query("SELECT COUNT(*) as in_progress FROM road_maintenance_reports WHERE status = 'in-progress'");
    $maintenance_progress = $result->fetch_assoc()['in_progress'];
    $stats['in_review'] = $transport_progress + $maintenance_progress;
    
    // Approved (completed) from both tables
    $result = $conn->query("SELECT COUNT(*) as approved FROM road_transportation_reports WHERE status = 'approved'");
    $transport_completed = $result->fetch_assoc()['approved'];
    
    $result = $conn->query("SELECT COUNT(*) as approved FROM road_maintenance_reports WHERE status = 'approved'");
    $maintenance_completed = $result->fetch_assoc()['approved'];
    $stats['approved'] = $transport_completed + $maintenance_completed;
    
    return $stats;
}

// Function to get pending verifications
function getPendingVerifications($conn) {
    $query = "(SELECT 'transport' as source, id, report_id, title, report_type, report_category, report_source,
                     department, priority, status, created_date, due_date, description, location, attachments, latitude, longitude, created_at, updated_at, approved_at, rejected_at 
              FROM road_transportation_reports WHERE status = 'pending')
              UNION ALL
              (SELECT 'maintenance' as source, id, report_id, title, report_type, NULL as report_category, NULL as report_source, department, priority, status, created_date, due_date, description, location, NULL as attachments, NULL as latitude, NULL as longitude, created_at, updated_at, approved_at, rejected_at FROM road_maintenance_reports WHERE status = 'pending')
              ORDER BY created_at DESC";
    $result = $conn->query($query);
    if (!$result) {
        error_log("Query error in getPendingVerifications: " . $conn->error);
    }
    return $result;
}

// Function to get approved reports
function getApprovedReports($conn) {
    $query = "(SELECT 'transport' as source, id, report_id, title, report_type, report_category, report_source,
                     department, priority, status, created_date, due_date, description, location, attachments, latitude, longitude, created_at, updated_at, approved_at, rejected_at 
               FROM road_transportation_reports WHERE status = 'approved')
               UNION ALL
               (SELECT 'maintenance' as source, id, report_id, title, report_type, NULL as report_category, NULL as report_source, department, priority, status, created_date, due_date, description, location, NULL as attachments, NULL as latitude, NULL as longitude, created_at, updated_at, approved_at, rejected_at FROM road_maintenance_reports WHERE status = 'approved')
               ORDER BY updated_at DESC";
    $result = $conn->query($query);
    if (!$result) {
        error_log("Query error in getApprovedReports: " . $conn->error);
    }
    return $result;
}

// Function to get rejected reports
function getRejectedReports($conn) {
    $query = "(SELECT 'transport' as source, id, report_id, title, report_type, report_category, report_source,
                     department, priority, status, created_date, due_date, description, location, attachments, latitude, longitude, created_at, updated_at, approved_at, rejected_at 
              FROM road_transportation_reports WHERE status = 'cancelled')
              UNION ALL
              (SELECT 'maintenance' as source, id, report_id, title, report_type, NULL as report_category, NULL as report_source, department, priority, status, created_date, due_date, description, location, NULL as attachments, NULL as latitude, NULL as longitude, created_at, updated_at, approved_at, rejected_at FROM road_maintenance_reports WHERE status = 'cancelled')
              ORDER BY updated_at DESC";
    $result = $conn->query($query);
    if (!$result) {
        error_log("Query error in getRejectedReports: " . $conn->error);
    }
    return $result;
}

// Function to get all reports (for filtering)
function getAllReports($conn, $status_filter = 'all', $source_filter = 'all', $transport_only = false, $road_only = false) {
    $parts = [];
    // This page shows reports still pending verification, plus reports that
    // were rejected and then restored from the archive — those come back with
    // their previous 'rejected' status so they are visible here again.
    $transport_where = " WHERE status IN ('pending','rejected')";
    $maintenance_where = " WHERE status IN ('pending','rejected')";
    $infra_exclude = "report_type != 'infrastructure_issue'";
    $citizen_exclude = "(report_source IS NULL OR report_source != 'local' OR report_category IS NULL OR report_category != 'transportation' OR created_by IS NULL OR created_by != 0)";
    // Transportation Operations Supervisors only see Transportation reports —
    // Road reports (report_category = 'road') and maintenance/infrastructure
    // reports are excluded at the query level.
    $transport_category_filter = $transport_only ? " AND report_category = 'transportation'" : '';
    // Road Operations Supervisors only see Road reports — Transportation
    // reports are excluded at the query level.
    $road_category_filter = $road_only ? " AND report_category = 'road'" : '';
    if ($source_filter === 'transport') {
        $where = $transport_where ? "{$transport_where} AND {$infra_exclude} AND {$citizen_exclude}{$transport_category_filter}{$road_category_filter}" : " WHERE {$infra_exclude} AND {$citizen_exclude}{$transport_category_filter}{$road_category_filter}";
        $source_case = "CASE WHEN report_source = 'external' THEN 'external' ELSE 'lgu' END as source";
        $q = "(SELECT {$source_case}, id, report_id, title, report_type, report_category, report_source, department, priority, status, created_date, due_date, description, location, attachments, latitude, longitude, detected_district, created_at, updated_at, approved_at, rejected_at, cimm_engineer_name, cimm_budget, cimm_starting_date, cimm_estimated_end_date, cimm_status, cimm_district, created_by FROM road_transportation_reports{$where})";
        $parts[] = $q;
    } elseif ($source_filter === 'maintenance') {
        if (!$transport_only) {
            $q = "(SELECT 'maintenance' as source, id, report_id, title, report_type, NULL as report_category, NULL as report_source, department, priority, status, created_date, due_date, description, location, NULL as attachments, NULL as latitude, NULL as longitude, NULL as detected_district, created_at, updated_at, approved_at, rejected_at, NULL as cimm_engineer_name, NULL as cimm_budget, NULL as cimm_starting_date, NULL as cimm_estimated_end_date, NULL as cimm_status, NULL as cimm_district, NULL as created_by FROM road_maintenance_reports{$maintenance_where})";
            $parts[] = $q;
        }
    } elseif ($source_filter === 'lgu_reports') {
        // LGU Monitoring Reports filter (road_ops_supervisor only): show ONLY
        // LGU monitoring reports — no maintenance/infrastructure rows.
        $where = $transport_where ? "{$transport_where} AND {$infra_exclude} AND {$citizen_exclude}{$transport_category_filter}{$road_category_filter}" : " WHERE {$infra_exclude} AND {$citizen_exclude}{$transport_category_filter}{$road_category_filter}";
        $source_case = "CASE WHEN report_source = 'external' THEN 'external' ELSE 'lgu' END as source";
        $parts[] = "(SELECT {$source_case}, id, report_id, title, report_type, report_category, report_source, department, priority, status, cimm_sync_status, created_date, due_date, description, location, attachments, latitude, longitude, detected_district, created_at, updated_at, approved_at, rejected_at, cimm_engineer_name, cimm_budget, cimm_starting_date, cimm_estimated_end_date, cimm_status, cimm_district, created_by FROM road_transportation_reports{$where})";
    } else {
        $where = $transport_where ? "{$transport_where} AND {$infra_exclude} AND {$citizen_exclude}{$transport_category_filter}{$road_category_filter}" : " WHERE {$infra_exclude} AND {$citizen_exclude}{$transport_category_filter}{$road_category_filter}";
        $source_case = "CASE WHEN report_source = 'external' THEN 'external' ELSE 'lgu' END as source";
        $parts[] = "(SELECT {$source_case}, id, report_id, title, report_type, report_category, report_source, department, priority, status, cimm_sync_status, created_date, due_date, description, location, attachments, latitude, longitude, detected_district, created_at, updated_at, approved_at, rejected_at, cimm_engineer_name, cimm_budget, cimm_starting_date, cimm_estimated_end_date, cimm_status, cimm_district, created_by FROM road_transportation_reports{$where})";
        if (!$transport_only) {
            $parts[] = "(SELECT 'maintenance' as source, id, report_id, title, report_type, NULL as report_category, NULL as report_source, department, priority, status, NULL as cimm_sync_status, created_date, due_date, description, location, NULL as attachments, NULL as latitude, NULL as longitude, NULL as detected_district, created_at, updated_at, approved_at, rejected_at, NULL as cimm_engineer_name, NULL as cimm_budget, NULL as cimm_starting_date, NULL as cimm_estimated_end_date, NULL as cimm_status, NULL as cimm_district, NULL as created_by FROM road_maintenance_reports{$maintenance_where})";
        }
    }
    if (empty($parts)) {
        $query = "(SELECT 'transport' as source, 0 as id, '' as report_id, '' as title, '' as report_type, '' as report_category, '' as report_source, '' as department, '' as priority, '' as status, NULL as created_date, NULL as due_date, '' as description, '' as location, NULL as attachments, NULL as latitude, NULL as longitude, NULL as detected_district, NULL as created_at, NULL as updated_at, NULL as approved_at, NULL as rejected_at, NULL as cimm_sync_status, NULL as cimm_engineer_name, NULL as cimm_budget, NULL as cimm_starting_date, NULL as cimm_estimated_end_date, NULL as cimm_status, NULL as cimm_district, NULL as created_by FROM road_transportation_reports WHERE 1 = 0)";
    } else {
        $query = implode(' UNION ALL ', $parts) . " ORDER BY created_at DESC";
    }
    $result = $conn->query($query);
    if (!$result) {
        error_log("Query error in getAllReports: " . $conn->error);
    }
    return $result;
}

// Function to get recent approvals (for timeline)
function getRecentApprovals($conn) {
    $query = "(SELECT 'transport' as source, id, report_id, title, report_type, report_category, report_source, department, priority, status, created_date, due_date, description, location, attachments, latitude, longitude, created_at, updated_at, approved_at, rejected_at FROM road_transportation_reports WHERE status = 'approved')
              UNION ALL
              (SELECT 'maintenance' as source, id, report_id, title, report_type, NULL as report_category, NULL as report_source, department, priority, status, created_date, due_date, description, location, NULL as attachments, NULL as latitude, NULL as longitude, created_at, updated_at, approved_at, rejected_at FROM road_maintenance_reports WHERE status = 'approved')
              ORDER BY updated_at DESC LIMIT 10";
    $result = $conn->query($query);
    if (!$result) {
        error_log("Query error in getRecentApprovals: " . $conn->error);
    }
    return $result;
}

// Function to get activity timeline
function getActivityTimeline($conn) {
    $query = "(SELECT 'transport' as source, id, report_id, title, report_type, report_category, report_source,
                     department, priority, status, created_date, due_date, description, location, attachments, latitude, longitude, created_at, updated_at, approved_at, rejected_at 
              FROM road_transportation_reports)
              UNION ALL
              (SELECT 'maintenance' as source, id, report_id, title, report_type, NULL as report_category, NULL as report_source, department, priority, status, created_date, due_date, description, location, NULL as attachments, NULL as latitude, NULL as longitude, created_at, updated_at, approved_at, rejected_at FROM road_maintenance_reports)
              ORDER BY updated_at DESC LIMIT 5";
    $result = $conn->query($query);
    if (!$result) {
        error_log("Query error in getActivityTimeline: " . $conn->error);
    }
    return $result;
}

// Map a synced cimm_verification_reports row (from cimm_verification_data.php)
// into the flat shape the CIMM/Dept tables on this page already render.
//
// One remaining known gap vs. the old mock data, left explicit rather than
// guessed:
//  - "report_type" (staff vs dept) — CIMM's sync payload has no staff/dept
//    category today, so every synced row is bucketed as 'staff' for now.
//    Update this mapping once CIMM adds a category field to the payload.
//
// "engineer" used to read cprf_facility_name (a CPRF-integration-only field,
// unrelated to who's assigned) because CIMM's sync payload never actually
// carried an engineer name at the time this was written. CIMM's
// cimm_rgmap_fetch_report() now sends one (reports.engineer_id -> employees),
// cimm-reports-webhook.php stores it in the engineer column, so this reads
// that column directly.
function rgmap_map_cimm_row_for_display(array $row): array {
    $verification = $row['verification_status'] ?? 'Pending Review';

    // verification_status mixes two unrelated things and this used to treat
    // them as one:
    //  - RGMAO's own admin-review state: 'Pending Review' / 'Flagged' /
    //    'Verified' / 'Dismissed'. This only says whether an RGMAO admin has
    //    reviewed the incoming CIMM report as legitimate — it says nothing
    //    about whether the actual road work is done. 'Verified' used to map
    //    straight to 'completed' here, so a report showed as Completed the
    //    moment it was verified, then never changed again.
    //  - A manual local override: report_management.php's handle_update_cimm_report()
    //    lets road-monitoring staff write a CIMM-style progress value
    //    ('Pending'/'Approved'/'In Progress'/'Completed'/'Cancelled') straight
    //    into this same column. That IS a deliberate progress override and
    //    should still be honored as-is.
    // Fix: only 'Dismissed' (a real RGMAO-side terminal state, badge below)
    // and the manual-override values are read directly off verification_status.
    // Everything else ('Pending Review', 'Flagged', 'Verified') falls back to
    // CIMM's real, continuously-synced resolution_status via
    // cimm_resolution_status_to_display(), so the status shown here tracks
    // CIMM's actual progress and keeps updating as CIMM pushes changes.
    $localOverrideMap = [
        'Pending'     => 'pending',
        'Approved'    => 'approved',
        'In Progress' => 'in-progress',
        'Completed'   => 'completed',
        'Cancelled'   => 'cancelled',
    ];

    if ($verification === 'Dismissed') {
        $status = 'resolved';
    } elseif (isset($localOverrideMap[$verification])) {
        $status = $localOverrideMap[$verification];
    } else {
        $status = cimm_resolution_status_to_display($row['resolution_status'] ?? null, $row['approval_status'] ?? null);
    }

    return [
        'id'            => $row['id'] ?? $row['cimm_req_id'] ?? 0,
        'rep_number'    => $row['reference_code'] ?? ('REQ-' . ($row['cimm_req_id'] ?? '')),
        'infrastructure'=> $row['infrastructure'] ?? '',
        'location'      => $row['location'] ?? '',
        'issue_notes'   => $row['issue'] ?? '',
        'latitude'      => $row['coord_lat'] ?? null,
        'longitude'     => $row['coord_lng'] ?? null,
        'engineer'      => $row['engineer'] ?? '—',
        'reported_by'   => $row['reporter_name'] ?? '—',
        'report_type'   => 'staff', // see gap #2 above
        'start_date'    => $row['starting_date'] ?? null,
        'end_date'      => $row['estimated_end_date'] ?? null,
        'priority'      => strtolower((string)($row['priority'] ?? 'medium')),
        'budget'        => $row['budget_allocation'] ?? $row['budget'] ?? null,
        'budget_allocation' => $row['budget_allocation'] ?? null,
        'status'        => $status,
        'approval_status'      => $row['approval_status'] ?? null,
        'verification_status'  => $verification,
        'cimm_req_id'          => $row['cimm_req_id'] ?? null,
        'contact_number'       => $row['contact_number'] ?? null,
        'email'                => $row['email'] ?? null,
        'district'             => $row['district'] ?? null,
        'resolution_status'    => $row['resolution_status'] ?? null,
        'resolution_note'      => $row['resolution_note'] ?? null,
        'submitted_at'         => $row['submitted_at'] ?? null,
        'portal_url'           => $row['portal_url'] ?? null,
        // Evidence photos CIMM's sync pushed for this report — see
        // cimm_rgmap_fetch_report() in the CIMM repo (evidence_images table)
        // and the evidence_json column populated by cimm-reports-webhook.php.
        'evidence_urls'        => is_array($row['evidence_urls'] ?? null) ? $row['evidence_urls'] : [],
        'ai'                   => is_array($row['ai'] ?? null) ? $row['ai'] : [],
    ];
}

// Function to get CIMM reports by filter (live data from CIMM via RGMAO sync)
function getCimmReports($filter = 'all') {
    $pdo = rgmap_verification_pdo();
    $rows = rgmap_fetch_cimm_verification_reports($pdo, [
        'limit' => 500,
        'infrastructure' => 'Roads',
        'verification_status' => 'Pending Review',
        'approval_status' => 'Approved'
    ]);

    $mapped = array_map('rgmap_map_cimm_row_for_display', $rows);

    if ($filter === 'staff' || $filter === 'dept') {
        $mapped = array_values(array_filter($mapped, function ($r) use ($filter) {
            return $r['report_type'] === $filter;
        }));
    }

    // This page only lists reports still pending — anything approved,
    // completed, resolved, or dismissed is no longer shown here.
    $mapped = array_values(array_filter($mapped, function ($r) {
        return ($r['status'] ?? '') === 'pending';
    }));

    return $mapped;
}

// Function to get CIMM report counts by type (live data from CIMM via RGMAO sync)
function getCimmReportCounts() {
    $pdo = rgmap_verification_pdo();
    $rows = rgmap_fetch_cimm_verification_reports($pdo, ['limit' => 500]);
    $mapped = array_map('rgmap_map_cimm_row_for_display', $rows);

    $counts = ['all' => count($mapped), 'staff' => 0, 'dept' => 0];
    foreach ($mapped as $r) {
        $counts[$r['report_type']] = ($counts[$r['report_type']] ?? 0) + 1;
    }

    return $counts;
}

// Function to get reports from reports.sql table
function getSqlReports($conn) {
    $query = "SELECT r.rep_id, r.res_id, r.starting_date, r.estimated_end_date,
                     r.engineer_id, r.report_by, r.priority_lvl, r.budget, r.created_at,
                     r.engineer_accepted, r.decline_reason, r.decline_reviewed, r.decline_review_note,
                     u.full_name as reporter_name
              FROM reports r
              LEFT JOIN users u ON r.report_by = u.id
              WHERE r.engineer_accepted = 0
                AND (r.decline_reason IS NULL OR r.decline_reason = '')
                AND (r.decline_reviewed IS NULL OR r.decline_reviewed = 0)
              ORDER BY r.created_at DESC";
    $result = $conn->query($query);
    if (!$result) {
        error_log("Query error in getSqlReports: " . $conn->error);
    }
    return $result;
}

// Function to get citizen-submitted reports (report_source=local, report_category=transportation)
function getCitizenReports($conn) {
    $query = "SELECT id, report_id, title, report_type, report_category, report_source,
                     department, priority, status, created_date, due_date, description, location, 
                     attachments, latitude, longitude, created_at, updated_at, approved_at, rejected_at,
                     reporter_name, reporter_email, reporter_phone, image_path, created_by
              FROM road_transportation_reports 
              WHERE report_source = 'local' AND report_category = 'transportation' AND created_by = 0
                AND status IN ('pending','rejected')
              ORDER BY created_at DESC";
    $result = $conn->query($query);
    if (!$result) {
        error_log("Query error in getCitizenReports: " . $conn->error);
    }
    return $result;
}

// Function to get infrastructure-only reports (road_transportation_reports where report_type = 'infrastructure_issue' + road_maintenance_reports)
function getInfraReports($conn, $road_only = false) {
    $road_category_filter = $road_only ? " AND report_category = 'road'" : '';
    $query = "(SELECT 'transport' as source, id, report_id, title, report_type, report_category, report_source,
                     department, priority, status, created_date, due_date, description, location, attachments,
                     latitude, longitude, created_at, updated_at, approved_at, rejected_at,
                     reporter_name, reporter_email
              FROM road_transportation_reports WHERE report_type = 'infrastructure_issue'
                AND status IN ('pending','rejected'){$road_category_filter})
              UNION ALL
              (SELECT 'maintenance' as source, id, report_id, title, report_type, NULL as report_category, NULL as report_source,
                     department, priority, status, created_date, due_date, description, location, NULL as attachments,
                     NULL as latitude, NULL as longitude, created_at, updated_at, approved_at, rejected_at,
                     NULL as reporter_name, NULL as reporter_email
              FROM road_maintenance_reports WHERE status IN ('pending','rejected'))
              ORDER BY created_at DESC";
    $result = $conn->query($query);
    if (!$result) {
        error_log("Query error in getInfraReports: " . $conn->error);
    }
    return $result;
}

// Ensure the archive table exists and carries every column of both live report
// tables, so copying ANY report row preserves all its data (including reporter,
// verification, and timestamp columns). Widens report_type so maintenance rows
// ('routine','emergency', etc.) can be archived without truncation.
function ensure_archive_for_archive_cancel($conn) {
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
    } catch (Exception $e) { error_log('archive ensure sync: ' . $e->getMessage()); }
    try {
        $conn->query("ALTER TABLE road_transportation_reports_archive MODIFY report_type VARCHAR(255) NULL DEFAULT NULL");
    } catch (Exception $e) { error_log('archive report_type widen: ' . $e->getMessage()); }
    foreach (['previous_status' => "VARCHAR(50) DEFAULT NULL",
              'archived_from' => "VARCHAR(100) DEFAULT NULL",
              'approval_status' => "VARCHAR(50) DEFAULT NULL"] as $col => $def) {
        $chk = $conn->query("SHOW COLUMNS FROM road_transportation_reports_archive LIKE '$col'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE road_transportation_reports_archive ADD COLUMN $col $def");
        }
    }
}

// Move a cancelled report into the archive atomically: copy every column, then
// delete the report from the active table plus its related notification / progress
// / analytics records so no orphan references remain.
function archive_cancelled_report($conn, $table, $report_id) {
    try {
        // Schema setup is idempotent DDL; run it before BEGIN so it does not
        // force an implicit COMMIT that would break the transaction below.
        ensure_archive_for_archive_cancel($conn);

        $conn->begin_transaction();

        $fields = [];
        $col_res = $conn->query("SHOW COLUMNS FROM $table");
        if ($col_res) { while ($col_row = $col_res->fetch_assoc()) { $fields[] = "`{$col_row['Field']}`"; } }
        if (empty($fields)) { throw new Exception("No columns found for table $table"); }
        $cols = implode(', ', $fields);

        // Copy ALL report information into the archive.
        $stmt = $conn->prepare("INSERT INTO road_transportation_reports_archive ($cols) SELECT $cols FROM $table WHERE id = ?");
        $stmt->bind_param('i', $report_id);
        $stmt->execute();

        // Stamp which live table this came from so Restore returns it to the
        // exact same module. (previous_status is intentionally NOT recorded —
        // rejected reports must stay 'rejected' when restored.)
        $ps = $conn->prepare("UPDATE road_transportation_reports_archive SET archived_from = ? WHERE id = ?");
        $ps->bind_param('si', $table, $report_id);
        $ps->execute();

        // Remove related active records so the cancelled report leaves
        // pending/progress notifications and has no orphan references.
        $del = $conn->prepare("DELETE FROM report_notifications WHERE report_id = ?");
        $del->bind_param('i', $report_id);
        $del->execute();

        $del = $conn->prepare("DELETE FROM report_updates WHERE report_id = ?");
        $del->bind_param('i', $report_id);
        $del->execute();

        $del = $conn->prepare("DELETE FROM project_analytics WHERE report_id = ? AND report_table = ?");
        $del->bind_param('is', $report_id, $table);
        $del->execute();

        // Remove the report from the active table.
        $del = $conn->prepare("DELETE FROM $table WHERE id = ?");
        $del->bind_param('i', $report_id);
        $del->execute();

        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        error_log('archive_cancelled_report failed: ' . $e->getMessage());
        return false;
    }
}

// Archive a rejected CIMM report by copying it to cimm_verification_reports_archive
function archive_cimm_rejected_report($conn, $cimm_req_id, $rejection_reason = null) {
    return rgmap_archive_cimm_report($conn, $cimm_req_id, 'rejected', $rejection_reason);
}

// Handle verification actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && isset($_POST['report_id']) && isset($_POST['source'])) {
        $report_id = (int) $_POST['report_id'];
        $source = $_POST['source'];
        $action = $_POST['action'];
        $table = in_array($source, ['transport', 'lgu', 'external']) ? 'road_transportation_reports' : 'road_maintenance_reports';

        // Infrastructure Projects (IPMS mirror): approve updates local workflow
        // status; reject archives the full project then removes it from
        // ipms_road_projects (same detail preserved for archive View modal).
        if ($source === 'infra' && in_array($action, ['approve', 'reject'])) {
            if ($action === 'approve') {
                // ✓ Approve: mark local status approved (hides from this panel).
                // Stay in ipms_road_projects only — do not copy into report tables.
                $infra_pdo = rgmap_ipms_pdo();
                $infra_upd = $infra_pdo->prepare("UPDATE ipms_road_projects SET status = 'approved' WHERE project_id = ?");
                $infra_upd->execute([$report_id]);
                $vm_message = 'Infrastructure project approved.';
            } else {
                // X Reject: copy every project field into the archive, then drop
                // the live IPMS row so it leaves this panel.
                $archived = rgmap_archive_ipms_project($conn, $report_id, 'rejected');
                if ($archived) {
                    $vm_message = 'Infrastructure project rejected and moved to archive.';
                } else {
                    $vm_message = 'Infrastructure project rejected, but archiving failed.';
                }
            }

            $audit_query = "INSERT INTO audit_trails (audit_id, title, audit_type, status, auditor, description, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, NOW())";
            $audit_stmt = $conn->prepare($audit_query);
            $audit_id = 'VR-' . date('Y-m-d-His');
            $title = ucfirst($action) . ' Infrastructure Project #' . $report_id;
            $audit_type = 'compliance';
            $auditor = $_SESSION['email'] ?? 'Unknown';
            $description = "Infrastructure project #$report_id has been " . $action . "d by $auditor";
            $audit_status = ($action === 'approve') ? 'approved' : 'rejected';
            $audit_stmt->bind_param('ssssss', $audit_id, $title, $audit_type, $audit_status, $auditor, $description);
            $audit_stmt->execute();

            $_SESSION['verification_message'] = $vm_message;
            header('Location: ../admin/verification_monitoring.php');
            exit();
        }
        
        // Archive report then remove from active table
        if ($action === 'delete') {
            $insert = "INSERT INTO road_transportation_reports_archive (id, report_id, title, report_type, report_category, report_source, created_by, department, priority, status, created_date, due_date, description, location, attachments, latitude, longitude, created_at, updated_at, approved_at, rejected_at) SELECT id, report_id, title, report_type, report_category, report_source, created_by, department, priority, status, created_date, due_date, description, location, attachments, latitude, longitude, created_at, updated_at, approved_at, rejected_at FROM $table WHERE id = ?";
            $stmt = $conn->prepare($insert);
            $stmt->bind_param('i', $report_id);
            if (!$stmt->execute()) {
                $_SESSION['verification_message'] = 'Failed to archive report: ' . $conn->error;
                header('Location: ../admin/verification_monitoring.php');
                exit();
            }
            $query = "DELETE FROM $table WHERE id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param('i', $report_id);
            if (!$stmt->execute()) {
                $_SESSION['verification_message'] = 'Failed to delete report after archiving: ' . $conn->error;
                header('Location: ../admin/verification_monitoring.php');
                exit();
            }
            $_SESSION['verification_message'] = 'Report archived successfully.';
            header('Location: verification_monitoring.php');
            exit();
        }
        
        // Road+local LGU reports: allow approve/reject only when cimm_status is exactly Scheduled.
        if (in_array($action, ['approve', 'cimm_approve', 'reject']) && in_array($source, ['transport', 'lgu', 'external'])) {
            $check = $conn->prepare("SELECT report_category, report_source, cimm_status FROM road_transportation_reports WHERE id = ?");
            $check->bind_param('i', $report_id);
            $check->execute();
            $r = $check->get_result()->fetch_assoc();
            if ($r
                && ($r['report_category'] ?? '') === 'road'
                && !canVerifyReport($r['report_category'], $r['report_source'])
                && !vm_lgu_road_cimm_status_is_scheduled($r['cimm_status'] ?? null)
            ) {
                $_SESSION['verification_message'] = 'This road report cannot be verified yet. It must be scheduled by the external Engineering Office first.';
                header('Location: ../admin/verification_monitoring.php');
                exit();
            }
        }

        // Update report status
        $status = '';
        $audit_status = '';
        switch ($action) {
            case 'approve':
            case 'cimm_approve':
                $status = 'approved';
                $audit_status = 'approved';
                break;
            case 'reject':
                $status = 'rejected';
                $audit_status = 'rejected';
                break;
            case 'review':
                $status = 'in-progress';
                $audit_status = 'pending';
                break;
        }
        
        if ($status) {
            if (in_array($action, ['approve', 'cimm_approve'])) {
                $query = "UPDATE $table SET status = ?, approved_at = NOW(), updated_at = NOW() WHERE id = ?";
            } elseif ($action === 'reject') {
                $query = "UPDATE $table SET status = ?, rejected_at = NOW(), updated_at = NOW() WHERE id = ?";
            } else {
                $query = "UPDATE $table SET status = ?, updated_at = NOW() WHERE id = ?";
            }
            $stmt = $conn->prepare($query);
            $stmt->bind_param('si', $status, $report_id);
            $stmt->execute();
            
            // Log the action
            $audit_query = "INSERT INTO audit_trails (audit_id, title, audit_type, status, auditor, description, created_at) 
                           VALUES (?, ?, ?, ?, ?, ?, NOW())";
            $audit_stmt = $conn->prepare($audit_query);
            $audit_id = 'VR-' . date('Y-m-d-His');
            $title = ucfirst($action) . ' Report #' . $report_id;
            $audit_type = 'compliance'; // verification actions logged under compliance
            $auditor = $_SESSION['email'] ?? 'Unknown';
            $description = "Report #$report_id from $source table has been " . $action . "ed by $auditor";
            
            $audit_stmt->bind_param('ssssss', $audit_id, $title, $audit_type, $audit_status, $auditor, $description);
            $audit_stmt->execute();
            
            // When a report is cancelled/rejected, automatically move it to the
            // archive and remove it from every active report list. The archive
            // copy preserves all report data (status becomes/remains 'cancelled').
            if ($action === 'reject') {
                $archived = archive_cancelled_report($conn, $table, $report_id);
                if ($archived) {
                    $_SESSION['verification_message'] = 'Report rejected and moved to archive.';
                } else {
                    $_SESSION['verification_message'] = 'Report status updated, but archiving failed.';
                }
            } else {
                // Return success message
                $_SESSION['verification_message'] = 'Report ' . $action . 'd successfully!';
            }
        }
        
        header('Location: ../admin/verification_monitoring.php');
        exit();
    }
}

// Handle CIMM report verification/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['approve_cimm', 'reject_cimm']) && isset($_POST['cimm_req_id'])) {
    $cimm_req_id = trim((string)$_POST['cimm_req_id']);
    $action = $_POST['action'];
    $pdo = rgmap_verification_pdo();

    if ($action === 'approve_cimm') {
        $ok = rgmap_update_verification_status($pdo, $cimm_req_id, 'Approved', null, $_SESSION['user_id'] ?? null);
        if ($ok) {
            $_SESSION['verification_message'] = 'CIMM report #' . $cimm_req_id . ' approved successfully.';
        } else {
            $_SESSION['verification_message'] = 'Failed to approve CIMM report #' . $cimm_req_id . '.';
        }
    } else {
        $reason = trim($_POST['rejection_reason'] ?? 'Rejected by admin');
        $archived = archive_cimm_rejected_report($conn, $cimm_req_id, $reason);
        if ($archived) {
            $_SESSION['verification_message'] = 'CIMM report #' . $cimm_req_id . ' rejected and moved to archive.';
        } else {
            $_SESSION['verification_message'] = 'Failed to reject CIMM report #' . $cimm_req_id . '.';
        }
    }

    header('Location: ../admin/verification_monitoring.php');
    exit();
}

// Show success message if set
if (isset($_SESSION['verification_message'])) {
    $success_message = $_SESSION['verification_message'];
    unset($_SESSION['verification_message']);
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$source_filter = $_GET['source'] ?? 'all';

$panel_per_page = 10;

// AJAX panel pagination — return rows + controls without a full page reload.
if (($_GET['ajax'] ?? '') === 'panel_page') {
    header('Content-Type: application/json; charset=utf-8');
    $panel = preg_replace('/[^a-z_]/', '', strtolower((string)($_GET['panel'] ?? '')));
    $ajax_page = max(1, (int)($_GET['page'] ?? 1));

    if ($panel === 'lgu') {
        $search_q = trim((string)($_GET['q'] ?? ''));
        $lgu_result = getLguReportsForVerification(
            $conn,
            $is_transport_supervisor,
            $is_road_supervisor,
            $panel_per_page,
            ($ajax_page - 1) * $panel_per_page,
            $search_q
        );
        $total = (int)$lgu_result['total'];
        $max_page = max(1, (int)ceil($total / max(1, $panel_per_page)));
        if ($ajax_page > $max_page) {
            $ajax_page = $max_page;
            $lgu_result = getLguReportsForVerification(
                $conn,
                $is_transport_supervisor,
                $is_road_supervisor,
                $panel_per_page,
                ($ajax_page - 1) * $panel_per_page,
                $search_q
            );
            $total = (int)$lgu_result['total'];
        }
        $rows = $lgu_result['rows'];
        $creator_map = vm_lookup_creator_map($conn, $rows);
        $pagination_html = ($total > $panel_per_page)
            ? vm_build_panel_pagination('lgu', $ajax_page, $panel_per_page, $total)['html']
            : '';
        echo json_encode([
            'success' => true,
            'panel' => 'lgu',
            'page' => $ajax_page,
            'total' => $total,
            'per_page' => $panel_per_page,
            'q' => $search_q,
            'rows_html' => vm_render_lgu_panel_tbody($rows, $is_transport_supervisor),
            'rows_json' => vm_build_lgu_rows_json($rows, $creator_map, $is_transport_supervisor, $is_road_supervisor),
            'pagination_html' => $pagination_html,
            'badge_text' => $total . ' Reports',
        ]);
        exit;
    }

    if ($panel === 'citizen') {
        if ($is_road_supervisor) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Forbidden']);
            exit;
        }
        $search_q = trim((string)($_GET['q'] ?? ''));
        $citizen_result = getCitizenReportsForVerification(
            $conn,
            $panel_per_page,
            ($ajax_page - 1) * $panel_per_page,
            $search_q
        );
        $total = (int)$citizen_result['total'];
        $max_page = max(1, (int)ceil($total / max(1, $panel_per_page)));
        if ($ajax_page > $max_page) {
            $ajax_page = $max_page;
            $citizen_result = getCitizenReportsForVerification(
                $conn,
                $panel_per_page,
                ($ajax_page - 1) * $panel_per_page,
                $search_q
            );
            $total = (int)$citizen_result['total'];
        }
        $rows = $citizen_result['rows'];
        $pagination_html = ($total > $panel_per_page)
            ? vm_build_panel_pagination('citizen', $ajax_page, $panel_per_page, $total)['html']
            : '';
        echo json_encode([
            'success' => true,
            'panel' => 'citizen',
            'page' => $ajax_page,
            'total' => $total,
            'per_page' => $panel_per_page,
            'q' => $search_q,
            'rows_html' => vm_render_citizen_panel_tbody($rows),
            'rows_json' => vm_build_citizen_rows_json($rows),
            'pagination_html' => $pagination_html,
            'badge_text' => $total . ' Reports',
        ]);
        exit;
    }

    if ($panel === 'cimm') {
        if ($is_transport_supervisor) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Forbidden']);
            exit;
        }
        $search_q = trim((string)($_GET['q'] ?? ''));
        $cimm_filter = $_GET['cimm_filter'] ?? 'all';
        $cimm_result = getCimmReportsPaginated(
            $cimm_filter,
            $panel_per_page,
            ($ajax_page - 1) * $panel_per_page,
            $search_q
        );
        $total = (int)$cimm_result['total'];
        $max_page = max(1, (int)ceil($total / max(1, $panel_per_page)));
        if ($ajax_page > $max_page) {
            $ajax_page = $max_page;
            $cimm_result = getCimmReportsPaginated(
                $cimm_filter,
                $panel_per_page,
                ($ajax_page - 1) * $panel_per_page,
                $search_q
            );
            $total = (int)$cimm_result['total'];
        }
        $rows = $cimm_result['rows'];
        $sql_reports_ajax = ($ajax_page === 1) ? getSqlReports($conn) : null;
        $pagination_html = ($total > $panel_per_page)
            ? vm_build_panel_pagination('cimm', $ajax_page, $panel_per_page, $total)['html']
            : '';
        echo json_encode([
            'success' => true,
            'panel' => 'cimm',
            'page' => $ajax_page,
            'total' => $total,
            'per_page' => $panel_per_page,
            'q' => $search_q,
            'rows_html' => vm_render_cimm_panel_tbody($rows, $sql_reports_ajax, $ajax_page === 1),
            'rows_json' => vm_build_cimm_rows_json($rows),
            'pagination_html' => $pagination_html,
            'badge_text' => $total . ' Reports',
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unknown panel']);
    exit;
}

// Get data
$stats = getVerificationStatistics($conn);
$pending_verifications = getPendingVerifications($conn);
$approved_reports = getApprovedReports($conn);
$rejected_reports = getRejectedReports($conn);
$recent_approvals = getRecentApprovals($conn);
$activity_timeline = getActivityTimeline($conn);

// LGU Monitoring panel — independent query + LIMIT/OFFSET per panel.
$lgu_page = vm_panel_page('lgu');
$lgu_search = trim((string)($_GET['lgu_q'] ?? ''));
$lgu_pagination_html = '';
$lgu_result = getLguReportsForVerification(
    $conn,
    $is_transport_supervisor,
    $is_road_supervisor,
    $panel_per_page,
    vm_panel_offset('lgu', $panel_per_page),
    $lgu_search
);
$lgu_reports_total = (int)$lgu_result['total'];
$lgu_reports_list = $lgu_result['rows'];
$lgu_max_page = max(1, (int)ceil($lgu_reports_total / max(1, $panel_per_page)));
if ($lgu_page > $lgu_max_page) {
    $lgu_page = $lgu_max_page;
    $lgu_result = getLguReportsForVerification(
        $conn,
        $is_transport_supervisor,
        $is_road_supervisor,
        $panel_per_page,
        ($lgu_page - 1) * $panel_per_page,
        $lgu_search
    );
    $lgu_reports_total = (int)$lgu_result['total'];
    $lgu_reports_list = $lgu_result['rows'];
}
if ($lgu_reports_total > $panel_per_page) {
    $lgu_pagination_html = vm_build_panel_pagination('lgu', $lgu_page, $panel_per_page, $lgu_reports_total)['html'];
}
$lgu_badge_count = $lgu_reports_total;
$lgu_has_reports = $lgu_reports_total > 0;
$lgu_creator_map = vm_lookup_creator_map($conn, $lgu_reports_list);

// CIMM reports data (live, via RGMAO sync) — paginated independently.
$cimm_filter = $_GET['cimm_filter'] ?? 'all';
$cimm_page = vm_panel_page('cimm');
$cimm_search = trim((string)($_GET['cimm_q'] ?? ''));
$cimm_pagination_html = '';
$cimm_reports = [];
$cimm_reports_total = 0;
if (!$is_transport_supervisor) {
    $cimm_result = getCimmReportsPaginated(
        $cimm_filter,
        $panel_per_page,
        vm_panel_offset('cimm', $panel_per_page),
        $cimm_search
    );
    $cimm_reports_total = (int)$cimm_result['total'];
    $cimm_reports = $cimm_result['rows'];
    $cimm_max_page = max(1, (int)ceil($cimm_reports_total / max(1, $panel_per_page)));
    if ($cimm_page > $cimm_max_page) {
        $cimm_page = $cimm_max_page;
        $cimm_result = getCimmReportsPaginated(
            $cimm_filter,
            $panel_per_page,
            ($cimm_page - 1) * $panel_per_page,
            $cimm_search
        );
        $cimm_reports_total = (int)$cimm_result['total'];
        $cimm_reports = $cimm_result['rows'];
    }
    if ($cimm_reports_total > $panel_per_page) {
        $cimm_pagination_html = vm_build_panel_pagination('cimm', $cimm_page, $panel_per_page, $cimm_reports_total)['html'];
    }
}
$cimm_counts = getCimmReportCounts();

// Reports from reports.sql table (legacy rows appended on CIMM page 1)
$sql_reports = getSqlReports($conn);

// Citizen-submitted reports — paginated independently.
$citizen_page = vm_panel_page('citizen');
$citizen_search = trim((string)($_GET['citizen_q'] ?? ''));
$citizen_pagination_html = '';
$citizen_reports_list = [];
$citizen_reports_total = 0;
if (!$is_road_supervisor) {
    $citizen_result = getCitizenReportsForVerification(
        $conn,
        $panel_per_page,
        vm_panel_offset('citizen', $panel_per_page),
        $citizen_search
    );
    $citizen_reports_total = (int)$citizen_result['total'];
    $citizen_reports_list = $citizen_result['rows'];
    $citizen_max_page = max(1, (int)ceil($citizen_reports_total / max(1, $panel_per_page)));
    if ($citizen_page > $citizen_max_page) {
        $citizen_page = $citizen_max_page;
        $citizen_result = getCitizenReportsForVerification(
            $conn,
            $panel_per_page,
            ($citizen_page - 1) * $panel_per_page,
            $citizen_search
        );
        $citizen_reports_total = (int)$citizen_result['total'];
        $citizen_reports_list = $citizen_result['rows'];
    }
    if ($citizen_reports_total > $panel_per_page) {
        $citizen_pagination_html = vm_build_panel_pagination('citizen', $citizen_page, $panel_per_page, $citizen_reports_total)['html'];
    }
}

// Infrastructure project records come from the read-only ipms_road_projects
// mirror (see lgu_staff/pages/api/ipms_road_projects_data.php), not the empty
// road_maintenance_reports source previously used here.
require_once __DIR__ . '/../api/ipms_road_projects_data.php';
$infra_reports = [];
try {
    $infra_reports = rgmap_infra_panel_rows();
} catch (Exception $e) {
    error_log("IPMS road projects fetch failed: " . $e->getMessage());
}

// Deep-link focus: ?source= + ?id= (or the notifications-specific
// ?focus_report_id=, see below) from a notification "View" button. The
// backend verifies the record still exists in the correct table before the
// frontend attempts to scroll to / highlight it. The frontend uses $focus_target
// to reveal the right panel, reveal the row (filters are client-side here),
// scroll to it and briefly highlight it — or show a friendly message when the
// record no longer exists.
$focus_source = $_GET['source'] ?? '';
$focus_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
// The Notifications "Pending Reports from Departments" panel links here with
// ?focus_report_id=<primary key>. The id is always the
// road_transportation_reports primary key, so we can classify it into the
// panel that renders it without a source parameter.
$focus_report_id = isset($_GET['focus_report_id']) ? (int)$_GET['focus_report_id'] : 0;
if ($focus_report_id > 0) {
    $focus_id = $focus_report_id;
    $focus_source = 'auto';
}
$focus_target = [
    'found'       => false,
    'id'          => $focus_id,
    'source'      => $focus_source,
    'table'       => '',
    'filterValue' => '',
];

if ($focus_id > 0) {
    try {
        if ($focus_source === 'auto') {
            // Source-agnostic deep-link. Fetch the report from
            // road_transportation_reports, then classify it into the section
            // that renders it so JS only scrolls + highlights (never a
            // JS-only lookup). All pending reports are already rendered in
            // their section by the queries above.
            $auto_report = fetch_one(
                "SELECT id, report_type, report_category, report_source, created_by
                 FROM road_transportation_reports WHERE id = ?",
                [$focus_id], 'i'
            );
            if ($auto_report) {
                if ($is_road_supervisor && ($auto_report['report_category'] ?? '') === 'transportation') {
                    // Road supervisors never see Transportation reports —
                    // do not reveal them even via a deep-link.
                } else {
                $focus_target['found'] = true;
                if (($auto_report['report_type'] ?? '') === 'infrastructure_issue') {
                    // Infrastructure Issue -> Infrastructure Projects panel.
                    $focus_target['table'] = 'infraTable';
                    $focus_target['filterValue'] = 'maintenance';
                } elseif (empty($auto_report['created_by'])
                          && ($auto_report['report_source'] ?? '') === 'local'
                          && ($auto_report['report_category'] ?? '') === 'transportation') {
                    // Citizen Report -> Citizen Reports panel.
                    $focus_target['table'] = 'citizenTable';
                    $focus_target['filterValue'] = 'transport';
                } else {
                    // LGU Monitoring Report (staff-created, incl. road
                    // categories and external reports) -> LGU Monitoring panel.
                    $focus_target['table'] = 'lguTable';
                    $focus_target['filterValue'] = 'all';
                }
                }
            }
        } else {
        switch ($focus_source) {
            case 'citizen':
            case 'transport':
                // The Citizen Reports panel is Transportation-only and is
                // hidden for Road Operations Supervisors.
                if (!$is_road_supervisor
                    && fetch_one("SELECT id FROM road_transportation_reports WHERE id = ?", [$focus_id], 'i')) {
                    $focus_target['found'] = true;
                    $focus_target['table'] = 'citizenTable';
                    $focus_target['filterValue'] = 'transport';
                }
                break;

            case 'lgu':
                $lgu_road = $is_road_supervisor ? " AND report_category = 'road'" : '';
                if (fetch_one("SELECT id FROM road_transportation_reports WHERE id = ?{$lgu_road}", [$focus_id], 'i')) {
                    $focus_target['found'] = true;
                    $focus_target['table'] = 'lguTable';
                    $focus_target['filterValue'] = 'all';
                }
                break;

            case 'cimm':
                $pdo = rgmap_verification_pdo();
                rgmap_ensure_cimm_verification_table($pdo);
                $cstmt = $pdo->prepare("SELECT id FROM cimm_verification_reports WHERE id = ?");
                $cstmt->execute([$focus_id]);
                if ($cstmt->fetch()) {
                    $focus_target['found'] = true;
                    $focus_target['table'] = 'deptTable';
                    $focus_target['filterValue'] = 'cimm';
                }
                break;

            case 'maintenance':
                $f = fetch_one("SELECT id FROM road_maintenance_reports WHERE id = ?", [$focus_id], 'i');
                if (!$f) {
                    $infra_road = $is_road_supervisor ? " AND report_category = 'road'" : '';
                    $f = fetch_one("SELECT id FROM road_transportation_reports WHERE id = ? AND report_type = 'infrastructure_issue'{$infra_road}", [$focus_id], 'i');
                }
                if ($f) {
                    $focus_target['found'] = true;
                    $focus_target['table'] = 'infraTable';
                    $focus_target['filterValue'] = 'maintenance';
                }
                break;

            default:
                // Legacy ?id= deep-link without a source: search the tables that
                // feed each panel to figure out where the report lives.
                $legacy_road = $is_road_supervisor ? " AND report_category = 'road'" : '';
                if (fetch_one("SELECT id FROM road_transportation_reports WHERE id = ?{$legacy_road}", [$focus_id], 'i')) {
                    $focus_target['found'] = true;
                    $focus_target['table'] = 'lguTable';
                    $focus_target['filterValue'] = 'all';
                } elseif (fetch_one("SELECT id FROM road_maintenance_reports WHERE id = ?", [$focus_id], 'i')) {
                    $focus_target['found'] = true;
                    $focus_target['table'] = 'infraTable';
                    $focus_target['filterValue'] = 'maintenance';
                } else {
                    try {
                        $pdo = rgmap_verification_pdo();
                        rgmap_ensure_cimm_verification_table($pdo);
                        $cstmt = $pdo->prepare("SELECT id FROM cimm_verification_reports WHERE id = ?");
                        $cstmt->execute([$focus_id]);
                        if ($cstmt->fetch()) {
                            $focus_target['found'] = true;
                            $focus_target['table'] = 'deptTable';
                            $focus_target['filterValue'] = 'cimm';
                        }
                    } catch (Exception $e) {}
                }
                break;
        }
        }
    } catch (Exception $e) {
        error_log("Verification monitoring focus lookup failed: " . $e->getMessage());
    }
}

// Handle AJAX request for report details
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_report_details') {
    header('Content-Type: application/json');
    $report_id = isset($_GET['report_id']) ? (int)$_GET['report_id'] : 0;
    $source = isset($_GET['source']) ? $_GET['source'] : '';
    
    if ($report_id && $source) {
        $table = ($source === 'transport') ? 'road_transportation_reports' : 'road_maintenance_reports';
        $query = "SELECT * FROM $table WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $report_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $report = $result->fetch_assoc();
        
        if ($report) {
            echo json_encode(['success' => true, 'report' => $report]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Report not found']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    }
    exit;
}

// Publish completed project to Public Transparency (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'publish_completed_project') {
    header('Content-Type: application/json');
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $completed_date = trim($_POST['completed_date'] ?? '');
    $cost = isset($_POST['cost']) ? (float) $_POST['cost'] : 0;
    $completed_by = trim($_POST['completed_by'] ?? '');
    $photo = trim($_POST['photo'] ?? '');
    $before_photo = trim($_POST['before_photo'] ?? '');
    if ($title === '') {
        echo json_encode(['success' => false, 'message' => 'Title is required.']);
        exit;
    }
    // Ensure table exists
    $conn->query("CREATE TABLE IF NOT EXISTS published_completed_projects (
        id int(11) unsigned NOT NULL AUTO_INCREMENT,
        title varchar(255) NOT NULL,
        description text,
        location varchar(255) DEFAULT NULL,
        completed_date date DEFAULT NULL,
        cost decimal(12,2) DEFAULT NULL,
        completed_by varchar(255) DEFAULT NULL,
        photo varchar(500) DEFAULT NULL,
        before_photo varchar(500) DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $stmt = $conn->prepare("INSERT INTO published_completed_projects (title, description, location, completed_date, cost, completed_by, photo, before_photo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $date_val = ($completed_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $completed_date)) ? $completed_date : null;
    $stmt->bind_param('ssssdsss', $title, $description, $location, $date_val, $cost, $completed_by, $photo, $before_photo);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Published to Public Transparency.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to publish: ' . $conn->error]);
    }
    exit;
}

// Upload photo for completed project (AJAX, multipart)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_completed_project_photo' && !empty($_FILES['photo'])) {
    header('Content-Type: application/json');
    $upload_dir = __DIR__ . '/../../../uploads/completed_projects';
    $upload_dir = str_replace('\\', '/', $upload_dir);
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    $result = handle_file_upload($_FILES['photo'], $upload_dir, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
    if ($result['success']) {
        $relative_path = 'uploads/completed_projects/' . $result['filename'];
        echo json_encode(['success' => true, 'path' => $relative_path]);
    } else {
        echo json_encode(['success' => false, 'message' => $result['error'] ?? 'Upload failed']);
    }
    exit;
}

// Upload before photo for completed project (AJAX, multipart)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_before_photo' && !empty($_FILES['before_photo'])) {
    header('Content-Type: application/json');
    $upload_dir = __DIR__ . '/../../../uploads/completed_projects';
    $upload_dir = str_replace('\\', '/', $upload_dir);
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    $result = handle_file_upload($_FILES['before_photo'], $upload_dir, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
    if ($result['success']) {
        $relative_path = 'uploads/completed_projects/' . $result['filename'];
        echo json_encode(['success' => true, 'path' => $relative_path]);
    } else {
        echo json_encode(['success' => false, 'message' => $result['error'] ?? 'Upload failed']);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verification & Monitoring Reports | LGU Staff</title>
    <link rel="icon" type="image/png" href="../../assets/img/logocityhall.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="../../css/theme-tokens.css">
    <link rel="stylesheet" href="../../css/theme-utilities.css">
    <link rel="stylesheet" href="../../css/sidebar.css?v=3">
    <link rel="stylesheet" href="../../../styles/transition.css">
    <?php if (!empty($_SESSION['darkmode'])): ?><link rel="stylesheet" href="../../css/dark-mode.css"><?php endif; ?>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: #f7f5f0;
            min-height: 100vh;
            margin: 0;
            padding: 0;
        }

        html { scroll-behavior: smooth; }

        .main-content {
            margin-left: 250px;
            padding: 20px;
            position: relative;
            z-index: 1;
        }
        .verification-header {
            background: #f0f4fa;
            padding: 25px 30px;
            border-radius: 16px;
            margin-bottom: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid #e0e0e0;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .header-title h1 {
            color: #1e3c72;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .header-title p {
            color: #666;
            font-size: 14px;
        }

        .workflow-stats {
            display: flex;
            gap: 20px;
        }

        .workflow-stat {
            text-align: center;
            padding: 15px 20px;
            background: rgba(55, 98, 200, 0.05);
            border-radius: 10px;
            border: 1px solid rgba(55, 98, 200, 0.1);
        }

        .workflow-number {
            font-size: 24px;
            font-weight: 700;
            color: #3762c8;
            margin-bottom: 5px;
        }

        .workflow-label {
            font-size: 12px;
            color: #666;
            font-weight: 500;
        }

        .workflow-container {
            display: grid;
            grid-template-columns: 1fr;
            gap: 25px;
            margin-bottom: 25px;
        }

        .workflow-card {
            background: #f0f4fa;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid #e0e0e0;
        }

        .workflow-content {
            max-height: 400px;
            overflow-y: auto;
            padding-right: 10px;
        }

        .workflow-content::-webkit-scrollbar {
            width: 6px;
        }

        .workflow-content::-webkit-scrollbar-track {
            background: rgba(55, 98, 200, 0.1);
            border-radius: 3px;
        }

        .workflow-content::-webkit-scrollbar-thumb {
            background: rgba(55, 98, 200, 0.3);
            border-radius: 3px;
        }

        .workflow-content::-webkit-scrollbar-thumb:hover {
            background: rgba(55, 98, 200, 0.5);
        }

        .workflow-header {
            display: flex;
            justify-content: flex-start;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(55, 98, 200, 0.1);
            flex-wrap: wrap;
            gap: 15px;
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

        .workflow-badge.pending {
            background: #ffc107;
        }

        .workflow-badge.approved {
            background: #28a745;
        }

        .workflow-badge.rejected {
            background: #dc3545;
        }

        .btn-maintenance {
            padding: 8px 16px;
            background: linear-gradient(135deg, #17a2b8, #138496);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .btn-maintenance:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(23, 162, 184, 0.4);
        }

        .verification-item {
            display: flex;
            align-items: flex-start;
            padding: 20px;
            margin-bottom: 15px;
            background: rgba(255, 255, 255, 0.7);
            border-radius: 12px;
            border: 1px solid rgba(55, 98, 200, 0.1);
            transition: all 0.3s ease;
            position: relative;
        }

        .verification-item:hover {
            background: rgba(55, 98, 200, 0.05);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(55, 98, 200, 0.1);
        }

        .verification-priority {
            position: absolute;
            top: 15px;
            right: 15px;
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }

        .priority-high {
            background: #dc3545;
        }

        .priority-medium {
            background: #ffc107;
        }

        .priority-low {
            background: #28a745;
        }

        .verification-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            margin-right: 20px;
            flex-shrink: 0;
        }

        .verification-content {
            flex: 1;
        }

        .verification-title {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }

        .verification-meta {
            display: flex;
            gap: 20px;
            margin-bottom: 12px;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: #666;
        }

        .meta-item i {
            color: #3762c8;
        }

        .verification-description {
            font-size: 14px;
            color: #666;
            line-height: 1.5;
            margin-bottom: 15px;
        }
        
        .expanded-details {
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                max-height: 0;
            }
            to {
                opacity: 1;
                max-height: 2000px;
            }
        }
        
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
        
        .detail-item {
            padding: 12px;
            background: rgba(255, 255, 255, 0.7);
            border-radius: 8px;
            border: 1px solid rgba(55, 98, 200, 0.1);
        }
        
        .detail-item.full-width {
            grid-column: 1 / -1;
        }
        
        .detail-item strong {
            color: #1e3c72;
            display: block;
            margin-bottom: 5px;
            font-size: 13px;
        }

        body.dark-mode .detail-item {
            background: #1e2229;
            border-color: #2d323b;
            color: #d1d5db;
        }
        body.dark-mode .detail-item strong {
            color: #f0f2f5;
        }
        body.dark-mode .expanded-details {
            border-top-color: rgba(59, 130, 246, 0.2);
        }
        body.dark-mode .detail-item [style*="background: rgba(55, 98, 200, 0.05)"] {
            background: rgba(59, 130, 246, 0.1) !important;
        }
        body.dark-mode .detail-item a[style*="color: #3762c8"] {
            color: #93c5fd !important;
        }
        body.dark-mode .detail-item img {
            border-color: rgba(59, 130, 246, 0.3) !important;
        }

        .verification-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        .verification-actions form {
            display: inline-flex;
            gap: 10px;
            margin: 0;
            padding: 0;
        }

        .btn-verify {
            padding: 8px 16px;
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-verify:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }

        .btn-reject {
            padding: 8px 16px;
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-reject:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
        }

        .btn-review {
            padding: 8px 16px;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-review:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(55, 98, 200, 0.3);
        }

        .btn-remove {
            padding: 8px 16px;
            background: rgba(108, 117, 125, 0.9);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-remove:hover {
            background: #dc3545;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
        }

        .timeline-section {
            background: #f0f4fa;
            backdrop-filter: blur(15px);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .timeline-header {
            font-size: 18px;
            font-weight: 600;
            color: #1e3c72;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .timeline {
            position: relative;
            padding-left: 30px;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 8px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: rgba(55, 98, 200, 0.2);
        }

        .timeline-item {
            position: relative;
            padding-bottom: 25px;
        }

        .timeline-item:last-child {
            padding-bottom: 0;
        }

        .timeline-marker {
            position: absolute;
            left: -22px;
            top: 5px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: #3762c8;
            border: 3px solid white;
            box-shadow: 0 2px 8px rgba(55, 98, 200, 0.3);
        }

        .timeline-marker.approved {
            background: #28a745;
        }

        .timeline-marker.rejected {
            background: #dc3545;
        }

        .timeline-marker.pending {
            background: #ffc107;
        }

        .timeline-content {
            background: rgba(255, 255, 255, 0.7);
            padding: 15px;
            border-radius: 8px;
            border: 1px solid rgba(55, 98, 200, 0.1);
        }

        .timeline-title {
            font-size: 14px;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }

        .timeline-description {
            font-size: 13px;
            color: #666;
            margin-bottom: 8px;
        }

        .timeline-time {
            font-size: 11px;
            color: #999;
        }

        body.dark-mode .timeline-section {
            background: #1e2229;
            border-color: #2d323b;
        }
        body.dark-mode .timeline-header {
            color: #f0f2f5;
        }
        body.dark-mode .timeline::before {
            background: rgba(59, 130, 246, 0.25);
        }
        body.dark-mode .timeline-marker {
            border-color: #1a1d23;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.2);
        }
        body.dark-mode .timeline-marker.approved {
            background: #059669;
        }
        body.dark-mode .timeline-marker.rejected {
            background: #dc2626;
        }
        body.dark-mode .timeline-marker.pending {
            background: #d97706;
        }
        body.dark-mode .timeline-content {
            background: #22262e;
            border-color: #2d323b;
        }
        body.dark-mode .timeline-title {
            color: #f0f2f5;
        }
        body.dark-mode .timeline-description {
            color: #d1d5db;
        }
        body.dark-mode .timeline-time {
            color: #6b7280;
        }

        .filters-section {
            background: #f0f4fa;
            backdrop-filter: blur(15px);
            padding: 20px 25px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .filter-group {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }

        .filter-group .form-label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filter-select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: white;
            font-size: 13px;
            min-width: 180px;
        }

        .btn-secondary-custom {
            padding: 8px 16px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: white;
            font-size: 13px;
            cursor: pointer;
            color: #64748b;
            transition: all 0.2s;
        }

        .btn-secondary-custom:hover {
            background: #f0f4fa;
            border-color: #3762c8;
            color: #3762c8;
        }

        body.dark-mode .filters-section {
            background: #1e2229;
            border-color: rgba(255,255,255,0.08);
        }
        body.dark-mode .filter-group .form-label {
            color: #9ca3af;
        }
        body.dark-mode .filter-select {
            background: #2d323b;
            border-color: rgba(255,255,255,0.12);
            color: #e4e6ea;
        }
        body.dark-mode .btn-secondary-custom {
            background: #2d323b;
            border-color: rgba(255,255,255,0.12);
            color: #9ca3af;
        }
        body.dark-mode .btn-secondary-custom:hover {
            border-color: #60a5fa;
            color: #60a5fa;
        }

        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            z-index: 10000;
            animation: slideIn 0.3s ease;
        }

        .notification.success {
            background: #28a745;
        }

        .notification.error {
            background: #dc3545;
        }

        .notification.info {
            background: #17a2b8;
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @media (max-width: 1200px) {
            .workflow-container {
                grid-template-columns: 1fr;
            }
        }

        /* CIMM Received Reports Panel */

        .cimm-search-bar {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
        }

        .cimm-search-input {
            flex: 1;
            padding: 12px 16px;
            padding-left: 42px;
            background: #ffffff;
            border: 1px solid #ddd;
            border-radius: 10px;
            color: #333;
            font-size: 14px;
            outline: none;
            transition: border-color 0.3s;
            position: relative;
        }

        .cimm-search-wrapper {
            position: relative;
            flex: 1;
        }

        .cimm-search-wrapper i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #6b7280;
            font-size: 14px;
        }

        .cimm-search-input::placeholder {
            color: #6b7280;
        }

        .cimm-search-input:focus {
            border-color: #3762c8;
        }

        .cimm-sort-btn {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 10px 18px;
            background: #3762c8;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            white-space: nowrap;
        }

        .cimm-sort-btn:hover {
            background: #2b4fa3;
        }

        .cimm-table-wrapper {
            overflow-x: auto;
        }

        .cimm-table {
            width: 100%;
            border-collapse: collapse;
        }

        .cimm-table thead th {
            background: #f97316;
            color: white;
            padding: 12px 14px;
            font-size: 12px;
            font-weight: 700;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }

        .cimm-table thead th:first-child {
            border-radius: 8px 0 0 0;
        }

        .cimm-table thead th:last-child {
            border-radius: 0 8px 0 0;
        }

        .cimm-table tbody tr {
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            transition: background 0.2s;
        }

        .cimm-table tbody tr:hover {
            background: rgba(55, 98, 200, 0.05);
        }

        .cimm-table tbody td {
            padding: 14px;
            color: #333;
            font-size: 13px;
            white-space: nowrap;
        }

        .cimm-empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }

        .cimm-empty-state .refresh-icon {
            width: 60px;
            height: 60px;
            background: rgba(55, 98, 200, 0.15);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }

        .cimm-empty-state .refresh-icon i {
            font-size: 28px;
            color: #6b7280;
        }

        .cimm-empty-state p {
            font-size: 14px;
            font-weight: 500;
            color: #8892a4;
        }

        .cimm-status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: capitalize;
        }

        .cimm-status-badge.pending {
            background: rgba(251, 191, 36, 0.15);
            color: #fbbf24;
        }

        .cimm-status-badge.in-progress {
            background: rgba(59, 130, 246, 0.15);
            color: #60a5fa;
        }

        .cimm-status-badge.completed,
        .cimm-status-badge.resolved {
            background: rgba(34, 197, 94, 0.15);
            color: #22c55e;
        }

        .cimm-action-btn {
            padding: 6px 12px;
            background: rgba(55, 98, 200, 0.1);
            color: #3762c8;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .cimm-action-btn:hover {
            background: rgba(55, 98, 200, 0.2);
        }

        body.dark-mode .cimm-search-input {
            background: #1a2332;
            border-color: rgba(255, 255, 255, 0.1);
            color: #f0f4fa;
        }

        body.dark-mode .cimm-table tbody tr {
            border-bottom-color: rgba(255, 255, 255, 0.05);
        }

        body.dark-mode .cimm-table tbody tr:hover {
            background: rgba(55, 98, 200, 0.08);
        }

        body.dark-mode .cimm-table tbody td {
            color: #c0c8d8;
        }

        body.dark-mode .cimm-action-btn {
            background: rgba(55, 98, 200, 0.15);
            color: #60a5fa;
        }

        @media (max-width: 768px) {
            .cimm-search-bar {
                flex-direction: column;
            }
        }

        /* Section Panel Wrappers */
        .section-panel {
            background: #f0f4fa;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid #e0e0e0;
            margin-bottom: 25px;
            overflow: hidden;
        }

        body.dark-mode .section-panel {
            background: #1e2229;
            border-color: #2d323b;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }

        /* Dept Reports Panel */
        .dept-reports-panel {
            background: #f0f4fa;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid #e0e0e0;
            margin-bottom: 25px;
            overflow: hidden;
        }

        body.dark-mode .dept-reports-panel {
            background: #1e2229;
            border-color: #2d323b;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }

        .dept-reports-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 25px;
            border-bottom: 2px solid rgba(55, 98, 200, 0.1);
        }

        .dept-reports-header-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .dept-reports-icon {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
        }

        .dept-reports-title-group {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .dept-reports-title {
            font-size: 20px;
            font-weight: 700;
            color: #1e3c72;
            margin: 0;
        }

        body.dark-mode .dept-reports-title {
            color: #f0f4fa;
        }

        .dept-reports-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: #3762c8;
            color: white;
        }

        .dept-reports-badge.pending {
            background: rgba(251, 191, 36, 0.15);
            color: #f59e0b;
        }

        .dept-reports-badge.in-progress {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
        }

        .dept-reports-badge.completed {
            background: rgba(34, 197, 94, 0.15);
            color: #22c55e;
        }

        .dept-reports-subtitle {
            font-size: 13px;
            color: #6b7280;
            margin: 2px 0 0 0;
        }

        body.dark-mode .dept-reports-subtitle {
            color: #9ca3af;
        }

        .dept-reports-search {
            display: flex;
            gap: 12px;
            padding: 18px 25px;
            border-bottom: 1px solid rgba(55, 98, 200, 0.08);
        }

        .dept-search-wrapper {
            position: relative;
            flex: 1;
        }

        .dept-search-wrapper i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #6b7280;
            font-size: 14px;
        }

        .dept-search-input {
            width: 100%;
            padding: 11px 16px 11px 40px;
            background: #ffffff;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            color: #333;
            font-size: 13px;
            font-family: 'Poppins', sans-serif;
            outline: none;
            transition: border-color 0.3s;
        }

        body.dark-mode .dept-search-input {
            background: #2d323b;
            border-color: rgba(255, 255, 255, 0.1);
            color: #e4e6ea;
        }

        .dept-search-input::placeholder {
            color: #9ca3af;
        }

        .dept-search-input:focus {
            border-color: #3762c8;
            box-shadow: 0 0 0 3px rgba(55, 98, 200, 0.1);
        }

        .dept-sort-btn {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 10px 20px;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            transition: all 0.3s;
            white-space: nowrap;
        }

        .dept-sort-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(55, 98, 200, 0.3);
        }

        .dept-table-wrapper {
            overflow-x: auto;
            padding: 0;
        }

        .dept-table {
            width: 100%;
            border-collapse: collapse;
        }

        .dept-table thead th {
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: white;
            padding: 14px 16px;
            font-size: 12px;
            font-weight: 700;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .dept-table thead th:first-child {
            border-radius: 0;
        }

        .dept-table thead th:last-child {
            border-radius: 0;
        }

        .dept-table tbody tr {
            border-bottom: 1px solid rgba(55, 98, 200, 0.08);
            transition: background 0.2s;
        }

        .dept-table tbody tr:hover {
            background: rgba(55, 98, 200, 0.05);
        }

        .dept-table tbody td {
            padding: 14px 16px;
            color: #333;
            font-size: 13px;
            white-space: nowrap;
        }

        body.dark-mode .dept-table tbody td {
            color: #c0c8d8;
        }

        body.dark-mode .dept-table tbody tr {
            border-bottom-color: rgba(255, 255, 255, 0.05);
        }

        body.dark-mode .dept-table tbody tr:hover {
            background: rgba(55, 98, 200, 0.08);
        }

        .dept-action-btn {
            padding: 6px 12px;
            background: rgba(55, 98, 200, 0.1);
            color: #3762c8;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .dept-action-btn:hover {
            background: rgba(55, 98, 200, 0.2);
        }

        body.dark-mode .dept-action-btn {
            background: rgba(55, 98, 200, 0.15);
            color: #60a5fa;
        }

        .dept-action-group {
            display: inline-flex;
            gap: 4px;
            align-items: center;
        }

        .dept-verify-btn {
            padding: 5px 10px;
            background: rgba(34, 197, 94, 0.1);
            color: #16a34a;
            border: none;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .dept-verify-btn:hover {
            background: rgba(34, 197, 94, 0.2);
        }

        body.dark-mode .dept-verify-btn {
            background: rgba(34, 197, 94, 0.15);
            color: #4ade80;
        }

        .dept-reject-btn {
            padding: 5px 10px;
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border: none;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .dept-reject-btn:hover {
            background: rgba(220, 53, 69, 0.2);
        }

        body.dark-mode .dept-reject-btn {
            background: rgba(220, 53, 69, 0.15);
            color: #f87171;
        }

        .dept-action-form {
            display: inline;
        }

        .dept-status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: capitalize;
        }

        .dept-status-badge.pending {
            background: rgba(251, 191, 36, 0.15);
            color: #f59e0b;
        }

        .dept-status-badge.in-progress {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
        }

        .dept-status-badge.completed,
        .dept-status-badge.resolved {
            background: rgba(34, 197, 94, 0.15);
            color: #22c55e;
        }

        .dept-empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }

        .dept-empty-icon {
            width: 56px;
            height: 56px;
            background: rgba(55, 98, 200, 0.12);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }

        .dept-empty-icon i {
            font-size: 26px;
            color: #3762c8;
        }

        body.dark-mode .dept-empty-icon {
            background: rgba(96, 165, 250, 0.12);
        }

        body.dark-mode .dept-empty-icon i {
            color: #60a5fa;
        }

        .dept-empty-state h4 {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 6px;
        }

        body.dark-mode .dept-empty-state h4 {
            color: #e4e6ea;
        }

        .dept-empty-state p {
            font-size: 14px;
            color: #9ca3af;
            font-weight: 500;
        }

        /* Infra Reports Panel */
        .infra-reports-panel {
            background: #fff8f0;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid #f0e0cc;
            margin-bottom: 25px;
            overflow: hidden;
        }

        body.dark-mode .infra-reports-panel {
            background: #1e2229;
            border-color: #3d3226;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }

        .infra-reports-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 25px;
            border-bottom: 2px solid rgba(249, 115, 22, 0.15);
        }

        .infra-reports-header-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .infra-reports-icon {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, #f97316, #ea580c);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
        }

        .infra-reports-title-group {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .infra-reports-title {
            font-size: 20px;
            font-weight: 700;
            color: #c2410c;
            margin: 0;
        }

        body.dark-mode .infra-reports-title {
            color: #fdba74;
        }

        .infra-reports-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: #f97316;
            color: white;
        }

        .infra-reports-badge.pending {
            background: rgba(251, 191, 36, 0.15);
            color: #f59e0b;
        }

        .infra-reports-badge.in-progress {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
        }

        .infra-reports-badge.completed {
            background: rgba(34, 197, 94, 0.15);
            color: #22c55e;
        }

        .infra-reports-subtitle {
            font-size: 13px;
            color: #92400e;
            margin: 2px 0 0 0;
        }

        body.dark-mode .infra-reports-subtitle {
            color: #d6a564;
        }

        .infra-reports-search {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 25px;
            border-bottom: 1px solid rgba(249, 115, 22, 0.08);
        }

        .infra-search-wrapper {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 10px;
            background: white;
            border: 1px solid rgba(249, 115, 22, 0.2);
            border-radius: 10px;
            padding: 10px 16px;
            transition: border-color 0.2s;
        }

        body.dark-mode .infra-search-wrapper {
            background: #2a2e37;
            border-color: rgba(249, 115, 22, 0.3);
        }

        .infra-search-wrapper:focus-within {
            border-color: #f97316;
            box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.1);
        }

        .infra-search-wrapper i {
            color: #9ca3af;
            font-size: 14px;
        }

        .infra-search-input {
            flex: 1;
            border: none;
            outline: none;
            font-size: 13px;
            color: #333;
            background: transparent;
        }

        body.dark-mode .infra-search-input {
            color: #e4e6ea;
        }

        .infra-search-input::placeholder {
            color: #9ca3af;
        }

        .infra-sort-btn {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 10px 18px;
            background: linear-gradient(135deg, #f97316, #ea580c);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            white-space: nowrap;
        }

        .infra-sort-btn:hover {
            background: linear-gradient(135deg, #ea580c, #c2410c);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(249, 115, 22, 0.3);
        }

        .infra-sync-btn {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 10px 18px;
            background: linear-gradient(135deg, #f97316, #ea580c);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            white-space: nowrap;
        }

        .infra-sync-btn:hover:not(:disabled) {
            background: linear-gradient(135deg, #ea580c, #c2410c);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(249, 115, 22, 0.3);
        }

        .infra-sync-btn:disabled {
            opacity: 0.75;
            cursor: wait;
            transform: none;
            box-shadow: none;
        }

        .infra-table-wrapper {
            overflow-x: auto;
            padding: 0;
        }

        .infra-table {
            width: 100%;
            border-collapse: collapse;
        }

        .infra-table thead th {
            background: linear-gradient(135deg, #f97316, #ea580c);
            color: white;
            padding: 14px 16px;
            font-size: 12px;
            font-weight: 700;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .infra-table thead th:first-child {
            border-radius: 0;
        }

        .infra-table thead th:last-child {
            border-radius: 0;
        }

        .infra-table tbody tr {
            border-bottom: 1px solid rgba(249, 115, 22, 0.08);
            transition: background 0.2s;
        }

        .infra-table tbody tr:hover {
            background: rgba(249, 115, 22, 0.05);
        }

        .infra-table tbody td {
            padding: 14px 16px;
            color: #333;
            font-size: 13px;
            white-space: nowrap;
        }

        body.dark-mode .infra-table tbody td {
            color: #c0c8d8;
        }

        body.dark-mode .infra-table tbody tr {
            border-bottom-color: rgba(255, 255, 255, 0.05);
        }

        body.dark-mode .infra-table tbody tr:hover {
            background: rgba(249, 115, 22, 0.08);
        }

        .vm-row-focus {
            animation: vmFocusPulse 1.2s ease-in-out 4;
            box-shadow: 0 0 0 3px #3762c8, 0 8px 32px rgba(55, 98, 200, 0.35);
            border-left: 4px solid #3762c8;
            background: rgba(55, 98, 200, 0.12);
        }

        @keyframes vmFocusPulse {
            0%, 100% { background-color: rgba(55, 98, 200, 0.12); }
            50% { background-color: rgba(55, 98, 200, 0.28); }
        }

        body.dark-mode .vm-row-focus {
            box-shadow: 0 0 0 3px #6a9bff, 0 8px 32px rgba(106, 155, 255, 0.35);
            border-left: 4px solid #6a9bff;
            background: rgba(106, 155, 255, 0.14);
        }

        .infra-action-btn {
            padding: 6px 12px;
            background: rgba(249, 115, 22, 0.1);
            color: #f97316;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .infra-action-btn:hover {
            background: rgba(249, 115, 22, 0.2);
        }

        body.dark-mode .infra-action-btn {
            background: rgba(249, 115, 22, 0.15);
            color: #fb923c;
        }

        .infra-action-group {
            display: inline-flex;
            gap: 4px;
            align-items: center;
        }

        .infra-verify-btn {
            padding: 5px 10px;
            background: rgba(34, 197, 94, 0.1);
            color: #16a34a;
            border: none;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .infra-verify-btn:hover {
            background: rgba(34, 197, 94, 0.2);
        }

        body.dark-mode .infra-verify-btn {
            background: rgba(34, 197, 94, 0.15);
            color: #4ade80;
        }

        .infra-reject-btn {
            padding: 5px 10px;
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border: none;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .infra-reject-btn:hover {
            background: rgba(220, 53, 69, 0.2);
        }

        body.dark-mode .infra-reject-btn {
            background: rgba(220, 53, 69, 0.15);
            color: #f87171;
        }

        .infra-action-form {
            display: inline;
        }

        .infra-status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: capitalize;
        }

        .infra-status-badge.pending {
            background: rgba(251, 191, 36, 0.15);
            color: #f59e0b;
        }

        .infra-status-badge.in-progress {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
        }

        .infra-status-badge.completed,
        .infra-status-badge.approved,
        .infra-status-badge.resolved {
            background: rgba(34, 197, 94, 0.15);
            color: #22c55e;
        }

        .infra-status-badge.cancelled {
            background: rgba(220, 53, 69, 0.15);
            color: #ef4444;
        }

        .infra-empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #92400e;
        }

        .infra-empty-icon {
            width: 56px;
            height: 56px;
            background: rgba(249, 115, 22, 0.12);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }

        .infra-empty-icon i {
            font-size: 26px;
            color: #f97316;
        }

        body.dark-mode .infra-empty-icon {
            background: rgba(251, 146, 60, 0.12);
        }

        body.dark-mode .infra-empty-icon i {
            color: #fb923c;
        }

        .infra-empty-state h4 {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 6px;
        }

        body.dark-mode .infra-empty-state h4 {
            color: #e4e6ea;
        }

        .infra-empty-state p {
            font-size: 14px;
            color: #9ca3af;
            font-weight: 500;
        }

        /* LGU Monitoring Reports Panel */
        .lgu-reports-panel {
            background: #f0f4fa;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid #c8d0e0;
            margin-bottom: 25px;
            overflow: hidden;
        }

        body.dark-mode .lgu-reports-panel {
            background: #1e2229;
            border-color: #1a2a3d;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }

        .lgu-reports-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 25px;
            border-bottom: 2px solid rgba(30, 60, 114, 0.15);
        }

        .lgu-reports-header-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .lgu-reports-icon {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, #1e3c72, #0f274a);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
        }

        .lgu-reports-title-group {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .lgu-reports-title {
            font-size: 20px;
            font-weight: 700;
            color: #1e3c72;
            margin: 0;
        }

        body.dark-mode .lgu-reports-title {
            color: #93b3e0;
        }

        .lgu-reports-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: #3762c8;
            color: white;
        }

        .lgu-reports-subtitle {
            font-size: 13px;
            color: #4a5b82;
            margin: 2px 0 0 0;
        }

        body.dark-mode .lgu-reports-subtitle {
            color: #8aa3c8;
        }

        .lgu-reports-search {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 25px;
            border-bottom: 1px solid rgba(30, 60, 114, 0.08);
        }

        .lgu-search-wrapper {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 10px;
            background: white;
            border: 1px solid rgba(30, 60, 114, 0.2);
            border-radius: 10px;
            padding: 10px 16px;
            transition: border-color 0.2s;
        }

        body.dark-mode .lgu-search-wrapper {
            background: #2a2e37;
            border-color: rgba(30, 60, 114, 0.3);
        }

        .lgu-search-wrapper:focus-within {
            border-color: #3762c8;
            box-shadow: 0 0 0 3px rgba(55, 98, 200, 0.1);
        }

        .lgu-search-wrapper i {
            color: #9ca3af;
            font-size: 14px;
        }

        .lgu-search-input {
            flex: 1;
            border: none;
            outline: none;
            font-size: 13px;
            color: #333;
            background: transparent;
        }

        body.dark-mode .lgu-search-input {
            color: #e4e6ea;
        }

        .lgu-search-input::placeholder {
            color: #9ca3af;
        }

        .lgu-sort-btn {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 10px 18px;
            background: linear-gradient(135deg, #1e3c72, #0f274a);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            white-space: nowrap;
        }

        .lgu-sort-btn:hover {
            background: linear-gradient(135deg, #0f274a, #0a1d35);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(30, 60, 114, 0.3);
        }

        .lgu-table-wrapper {
            overflow-x: auto;
            padding: 0;
        }

        .lgu-table {
            width: 100%;
            border-collapse: collapse;
        }

        .lgu-table thead th {
            background: linear-gradient(135deg, #1e3c72, #0f274a);
            color: white;
            padding: 14px 16px;
            font-size: 12px;
            font-weight: 700;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .lgu-table thead th:first-child { border-radius: 0; }
        .lgu-table thead th:last-child { border-radius: 0; }

        .lgu-table tbody tr {
            border-bottom: 1px solid rgba(30, 60, 114, 0.08);
            transition: background 0.2s;
        }

        .lgu-table tbody tr:hover {
            background: rgba(55, 98, 200, 0.05);
        }

        .lgu-table tbody td {
            padding: 14px 16px;
            color: #333;
            font-size: 13px;
            white-space: nowrap;
        }

        body.dark-mode .lgu-table tbody td { color: #c0c8d8; }
        body.dark-mode .lgu-table tbody tr { border-bottom-color: rgba(255,255,255,0.05); }
        body.dark-mode .lgu-table tbody tr:hover { background: rgba(55,98,200,0.08); }

        .lgu-action-btn {
            padding: 6px 12px;
            background: rgba(55, 98, 200, 0.1);
            color: #3762c8;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .lgu-action-btn:hover { background: rgba(55, 98, 200, 0.2); }
        body.dark-mode .lgu-action-btn { background: rgba(55,98,200,0.15); color: #60a5fa; }

        .lgu-action-group {
            display: inline-flex;
            gap: 4px;
            align-items: center;
        }

        .lgu-verify-btn {
            padding: 5px 10px;
            background: rgba(34, 197, 94, 0.1);
            color: #16a34a;
            border: none;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .lgu-verify-btn:hover { background: rgba(34, 197, 94, 0.2); }
        body.dark-mode .lgu-verify-btn { background: rgba(34,197,94,0.15); color: #4ade80; }

        .lgu-reject-btn {
            padding: 5px 10px;
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border: none;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .lgu-reject-btn:hover { background: rgba(220, 53, 69, 0.2); }
        body.dark-mode .lgu-reject-btn { background: rgba(220,53,69,0.15); color: #f87171; }

        .lgu-action-form { display: inline; }

        .lgu-status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: capitalize;
        }

        .lgu-status-badge.pending { background: rgba(251,191,36,0.15); color: #f59e0b; }
        .lgu-status-badge.in-progress { background: rgba(59,130,246,0.15); color: #3b82f6; }
        .lgu-status-badge.completed,
        .lgu-status-badge.approved,
        .lgu-status-badge.resolved { background: rgba(34,197,94,0.15); color: #22c55e; }
        .lgu-status-badge.cancelled { background: rgba(220,53,69,0.15); color: #ef4444; }

        .lgu-empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #4a5b82;
        }

        .lgu-empty-icon {
            width: 56px;
            height: 56px;
            background: rgba(55, 98, 200, 0.12);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }

        .lgu-empty-icon i { font-size: 26px; color: #3762c8; }
        body.dark-mode .lgu-empty-icon { background: rgba(55,98,200,0.12); }
        body.dark-mode .lgu-empty-icon i { color: #60a5fa; }

        /* Citizen Reports Panel */
        .citizen-reports-panel {
            background: #f0f8f4;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid #cce0d4;
            margin-bottom: 25px;
            overflow: hidden;
        }

        body.dark-mode .citizen-reports-panel {
            background: #1e2229;
            border-color: #1a3d2a;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }

        .citizen-reports-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 25px;
            border-bottom: 2px solid rgba(22, 163, 74, 0.15);
        }

        .citizen-reports-header-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .citizen-reports-icon {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, #16a34a, #15803d);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
        }

        .citizen-reports-title-group {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .citizen-reports-title {
            font-size: 20px;
            font-weight: 700;
            color: #15803d;
            margin: 0;
        }

        body.dark-mode .citizen-reports-title {
            color: #86efac;
        }

        .citizen-reports-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: #16a34a;
            color: white;
        }

        .citizen-reports-subtitle {
            font-size: 13px;
            color: #166534;
            margin: 2px 0 0 0;
        }

        body.dark-mode .citizen-reports-subtitle {
            color: #6ee7b7;
        }

        .citizen-reports-search {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 25px;
            border-bottom: 1px solid rgba(22, 163, 74, 0.08);
        }

        .citizen-search-wrapper {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 10px;
            background: white;
            border: 1px solid rgba(22, 163, 74, 0.2);
            border-radius: 10px;
            padding: 10px 16px;
            transition: border-color 0.2s;
        }

        body.dark-mode .citizen-search-wrapper {
            background: #2a2e37;
            border-color: rgba(22, 163, 74, 0.3);
        }

        .citizen-search-wrapper:focus-within {
            border-color: #16a34a;
            box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.1);
        }

        .citizen-search-wrapper i {
            color: #9ca3af;
            font-size: 14px;
        }

        .citizen-search-input {
            flex: 1;
            border: none;
            outline: none;
            font-size: 13px;
            color: #333;
            background: transparent;
        }

        body.dark-mode .citizen-search-input {
            color: #e4e6ea;
        }

        .citizen-search-input::placeholder {
            color: #9ca3af;
        }

        .citizen-sort-btn {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 10px 18px;
            background: linear-gradient(135deg, #16a34a, #15803d);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            white-space: nowrap;
        }

        .citizen-sort-btn:hover {
            background: linear-gradient(135deg, #15803d, #166534);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(22, 163, 74, 0.3);
        }

        .citizen-table-wrapper {
            overflow-x: auto;
            padding: 0;
        }

        .citizen-table {
            width: 100%;
            border-collapse: collapse;
        }

        .citizen-table thead th {
            background: linear-gradient(135deg, #16a34a, #15803d);
            color: white;
            padding: 14px 16px;
            font-size: 12px;
            font-weight: 700;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .citizen-table thead th:first-child { border-radius: 0; }
        .citizen-table thead th:last-child { border-radius: 0; }

        .citizen-table tbody tr {
            border-bottom: 1px solid rgba(22, 163, 74, 0.08);
            transition: background 0.2s;
        }

        .citizen-table tbody tr:hover {
            background: rgba(22, 163, 74, 0.05);
        }

        .citizen-table tbody td {
            padding: 14px 16px;
            color: #333;
            font-size: 13px;
            white-space: nowrap;
        }

        body.dark-mode .citizen-table tbody td { color: #c0c8d8; }
        body.dark-mode .citizen-table tbody tr { border-bottom-color: rgba(255,255,255,0.05); }
        body.dark-mode .citizen-table tbody tr:hover { background: rgba(22,163,74,0.08); }

        .citizen-action-btn {
            padding: 6px 12px;
            background: rgba(22, 163, 74, 0.1);
            color: #16a34a;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .citizen-action-btn:hover { background: rgba(22, 163, 74, 0.2); }
        body.dark-mode .citizen-action-btn { background: rgba(22,163,74,0.15); color: #4ade80; }

        .citizen-action-group {
            display: inline-flex;
            gap: 4px;
            align-items: center;
        }

        .citizen-verify-btn {
            padding: 5px 10px;
            background: rgba(34, 197, 94, 0.1);
            color: #16a34a;
            border: none;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .citizen-verify-btn:hover { background: rgba(34, 197, 94, 0.2); }
        body.dark-mode .citizen-verify-btn { background: rgba(34,197,94,0.15); color: #4ade80; }

        .citizen-reject-btn {
            padding: 5px 10px;
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border: none;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .citizen-reject-btn:hover { background: rgba(220, 53, 69, 0.2); }
        body.dark-mode .citizen-reject-btn { background: rgba(220,53,69,0.15); color: #f87171; }

        .citizen-action-form { display: inline; }

        .citizen-status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: capitalize;
        }

        .citizen-status-badge.pending { background: rgba(251,191,36,0.15); color: #f59e0b; }
        .citizen-status-badge.in-progress { background: rgba(59,130,246,0.15); color: #3b82f6; }
        .citizen-status-badge.completed,
        .citizen-status-badge.approved,
        .citizen-status-badge.resolved { background: rgba(34,197,94,0.15); color: #22c55e; }
        .citizen-status-badge.cancelled { background: rgba(220,53,69,0.15); color: #ef4444; }

        .citizen-empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #166534;
        }

        .citizen-empty-icon {
            width: 56px;
            height: 56px;
            background: rgba(22, 163, 74, 0.12);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }

        .citizen-empty-icon i { font-size: 26px; color: #16a34a; }
        body.dark-mode .citizen-empty-icon { background: rgba(22,163,74,0.12); }
        body.dark-mode .citizen-empty-icon i { color: #4ade80; }

        @media (max-width: 768px) {
            .dept-reports-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }

            .infra-reports-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }

            .citizen-reports-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }

            .lgu-reports-search,
            .dept-reports-search,
            .infra-reports-search,
            .citizen-reports-search {
                flex-direction: row;
            }
        }

        /* Modal Styles */
        .modal-overlay {
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
        
        .modal-overlay.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 16px;
            padding: 30px;
            max-width: 900px;
            width: 100%;
            max-height: calc(100vh - 40px);
            position: relative;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            margin: auto;
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
        }
        
        .modal-body {
            overflow-y: auto;
            flex: 1;
            min-height: 0;
            padding-right: 10px;
            margin-right: -10px;
        }
        
        .modal-body::-webkit-scrollbar {
            width: 8px;
        }
        
        .modal-body::-webkit-scrollbar-track {
            background: rgba(55, 98, 200, 0.1);
            border-radius: 4px;
        }
        
        .modal-body::-webkit-scrollbar-thumb {
            background: rgba(55, 98, 200, 0.3);
            border-radius: 4px;
        }
        
        .modal-body::-webkit-scrollbar-thumb:hover {
            background: rgba(55, 98, 200, 0.5);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(55, 98, 200, 0.1);
            flex-shrink: 0;
        }
        
        .modal-header h2 {
            color: #1e3c72;
            font-size: 24px;
            margin: 0;
            flex: 1;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 28px;
            color: #666;
            cursor: pointer;
            padding: 0;
            width: 35px;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s;
            flex-shrink: 0;
            margin-left: 15px;
        }
        
        .modal-close:hover {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }
        
        .detail-row {
            display: flex;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }
        
        .detail-label {
            font-weight: 600;
            color: #333;
            width: 150px;
            flex-shrink: 0;
        }
        
        .detail-value {
            color: #666;
            flex: 1;
        }
        
        .modal-image {
            max-width: 100%;
            max-height: 400px;
            border-radius: 8px;
            margin-top: 10px;
            cursor: pointer;
        }

        .citizen-photo-gallery {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 4px;
        }

        .citizen-photo-item {
            width: 120px;
            height: 120px;
            border-radius: 8px;
            overflow: hidden;
            border: 2px solid rgba(55, 98, 200, 0.2);
            cursor: pointer;
            transition: border-color 0.2s, transform 0.2s;
            flex-shrink: 0;
        }

        .citizen-photo-item:hover {
            border-color: #3762c8;
            transform: scale(1.05);
        }

        .citizen-photo-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .lightbox-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.9);
            z-index: 11000;
            align-items: center;
            justify-content: center;
            padding: 30px;
            box-sizing: border-box;
            cursor: pointer;
        }

        .lightbox-overlay.active {
            display: flex;
        }

        .lightbox-overlay img {
            max-width: 100%;
            max-height: 100%;
            border-radius: 8px;
            object-fit: contain;
            cursor: default;
        }

        .lightbox-close {
            position: fixed;
            top: 20px;
            right: 30px;
            color: white;
            font-size: 36px;
            font-weight: 300;
            cursor: pointer;
            z-index: 11001;
            line-height: 1;
            opacity: 0.8;
            transition: opacity 0.2s;
            background: none;
            border: none;
        }

        .lightbox-close:hover {
            opacity: 1;
        }

        /* Citizen Report Detail Modal */
        .citizen-modal-overlay {
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

        .citizen-modal-overlay.active {
            display: flex;
        }

        .citizen-modal-content {
            background: #f0f8f4;
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
            border: 1px solid #cce0d4;
        }

        .citizen-modal-header {
            background: white;
            border-radius: 16px 16px 0 0;
            padding: 24px 28px 18px;
            border-bottom: 2px solid rgba(22, 163, 74, 0.15);
            flex-shrink: 0;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .citizen-modal-header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .citizen-modal-title-area {
            flex: 1;
            min-width: 0;
        }

        .citizen-modal-report-id {
            font-size: 13px;
            color: #16a34a;
            font-weight: 600;
            letter-spacing: 0.3px;
            margin-bottom: 4px;
        }

        .citizen-modal-title {
            font-size: 22px;
            font-weight: 700;
            color: #15803d;
            margin: 0 0 10px 0;
            line-height: 1.3;
        }

        .citizen-modal-badges {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .citizen-modal-close {
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

        .citizen-modal-close:hover {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }

        .citizen-modal-body {
            overflow-y: auto;
            flex: 1;
            min-height: 0;
            padding: 24px 28px;
        }

        .citizen-modal-body::-webkit-scrollbar {
            width: 8px;
        }

        .citizen-modal-body::-webkit-scrollbar-track {
            background: rgba(22, 163, 74, 0.08);
            border-radius: 4px;
        }

        .citizen-modal-body::-webkit-scrollbar-thumb {
            background: rgba(22, 163, 74, 0.2);
            border-radius: 4px;
        }

        .citizen-modal-body::-webkit-scrollbar-thumb:hover {
            background: rgba(22, 163, 74, 0.35);
        }

        .citizen-modal-section {
            background: white;
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(22, 163, 74, 0.1);
        }

        .citizen-modal-section-title {
            font-size: 14px;
            font-weight: 700;
            color: #15803d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 14px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(22, 163, 74, 0.1);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .citizen-modal-section-title i {
            color: #16a34a;
            font-size: 15px;
        }

        .citizen-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .citizen-info-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 6px 0;
        }

        .citizen-info-icon {
            width: 28px;
            height: 28px;
            background: rgba(22, 163, 74, 0.1);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #16a34a;
            font-size: 13px;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .citizen-info-label {
            font-size: 11px;
            color: #6b7280;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 2px;
        }

        .citizen-info-value {
            font-size: 14px;
            color: #1f2937;
            font-weight: 500;
            line-height: 1.4;
            word-break: break-word;
        }

        .citizen-info-value-full {
            grid-column: 1 / -1;
        }

        .citizen-description-text {
            font-size: 14px;
            color: #374151;
            line-height: 1.7;
            padding: 8px 0;
            white-space: pre-wrap;
        }

        .citizen-modal-footer {
            background: white;
            border-radius: 0 0 16px 16px;
            padding: 16px 28px;
            border-top: 1px solid rgba(22, 163, 74, 0.1);
            flex-shrink: 0;
            display: flex;
            justify-content: flex-end;
        }

        .citizen-modal-btn-close {
            padding: 10px 24px;
            background: rgba(22, 163, 74, 0.1);
            color: #16a34a;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            transition: all 0.2s;
        }

        .citizen-modal-btn-close:hover {
            background: rgba(22, 163, 74, 0.2);
        }

        @media (max-width: 640px) {
            .citizen-info-grid {
                grid-template-columns: 1fr;
            }
            .citizen-modal-header {
                padding: 18px 16px;
            }
            .citizen-modal-body {
                padding: 16px;
            }
            .citizen-modal-content {
                max-width: 100%;
                border-radius: 0;
            }
            .citizen-modal-overlay {
                padding: 0;
            }
        }

        /* CIMM Report Detail Modal */
        .cimm-modal-overlay {
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

        .cimm-modal-overlay.active {
            display: flex;
        }

        .cimm-modal-content {
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

        .cimm-modal-header {
            background: white;
            border-radius: 16px 16px 0 0;
            padding: 24px 28px 18px;
            border-bottom: 2px solid rgba(55, 98, 200, 0.15);
            flex-shrink: 0;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .cimm-modal-header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .cimm-modal-title-area {
            flex: 1;
            min-width: 0;
        }

        .cimm-modal-report-id {
            font-size: 13px;
            color: #3762c8;
            font-weight: 600;
            letter-spacing: 0.3px;
            margin-bottom: 4px;
        }

        .cimm-modal-title {
            font-size: 22px;
            font-weight: 700;
            color: #1e3c72;
            margin: 0 0 10px 0;
            line-height: 1.3;
        }

        .cimm-modal-badges {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .cimm-modal-close {
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

        .cimm-modal-close:hover {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }

        .cimm-modal-body {
            overflow-y: auto;
            flex: 1;
            min-height: 0;
            padding: 24px 28px;
        }

        .cimm-modal-body::-webkit-scrollbar {
            width: 8px;
        }

        .cimm-modal-body::-webkit-scrollbar-track {
            background: rgba(55, 98, 200, 0.08);
            border-radius: 4px;
        }

        .cimm-modal-body::-webkit-scrollbar-thumb {
            background: rgba(55, 98, 200, 0.2);
            border-radius: 4px;
        }

        .cimm-modal-body::-webkit-scrollbar-thumb:hover {
            background: rgba(55, 98, 200, 0.35);
        }

        .cimm-modal-section {
            background: white;
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(55, 98, 200, 0.1);
        }

        .cimm-modal-section-title {
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

        .cimm-modal-section-title i {
            color: #3762c8;
            font-size: 15px;
        }

        .cimm-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .cimm-info-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 6px 0;
        }

        .cimm-info-icon {
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

        .cimm-info-label {
            font-size: 11px;
            color: #6b7280;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 2px;
        }

        .cimm-info-value {
            font-size: 14px;
            color: #1f2937;
            font-weight: 500;
            line-height: 1.4;
            word-break: break-word;
        }

        .cimm-info-value-full {
            grid-column: 1 / -1;
        }

        .cimm-description-text {
            font-size: 14px;
            color: #374151;
            line-height: 1.7;
            padding: 8px 0;
            white-space: pre-wrap;
        }

        .cimm-modal-footer {
            background: white;
            border-radius: 0 0 16px 16px;
            padding: 16px 28px;
            border-top: 1px solid rgba(55, 98, 200, 0.1);
            flex-shrink: 0;
            display: flex;
            justify-content: flex-end;
        }

        .cimm-modal-btn-close {
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

        .cimm-modal-btn-close:hover {
            background: rgba(55, 98, 200, 0.2);
        }

        @media (max-width: 640px) {
            .cimm-info-grid {
                grid-template-columns: 1fr;
            }
            .cimm-modal-header {
                padding: 18px 16px;
            }
            .cimm-modal-body {
                padding: 16px;
            }
            .cimm-modal-content {
                max-width: 100%;
                border-radius: 0;
            }
            .cimm-modal-overlay {
                padding: 0;
            }
        }

        /* Infra Report Detail Modal */
        .infra-modal-overlay {
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

        .infra-modal-overlay.active {
            display: flex;
        }

        .infra-modal-content {
            background: #fff8f0;
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
            border: 1px solid #f0e0cc;
        }

        .infra-modal-header {
            background: white;
            border-radius: 16px 16px 0 0;
            padding: 24px 28px 18px;
            border-bottom: 2px solid rgba(249, 115, 22, 0.15);
            flex-shrink: 0;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .infra-modal-header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .infra-modal-title-area {
            flex: 1;
            min-width: 0;
        }

        .infra-modal-report-id {
            font-size: 13px;
            color: #f97316;
            font-weight: 600;
            letter-spacing: 0.3px;
            margin-bottom: 4px;
        }

        .infra-modal-title {
            font-size: 22px;
            font-weight: 700;
            color: #c2410c;
            margin: 0 0 10px 0;
            line-height: 1.3;
        }

        .infra-modal-badges {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .infra-modal-close {
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

        .infra-modal-close:hover {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }

        .infra-modal-body {
            overflow-y: auto;
            flex: 1;
            min-height: 0;
            padding: 24px 28px;
        }

        .infra-modal-body::-webkit-scrollbar {
            width: 8px;
        }

        .infra-modal-body::-webkit-scrollbar-track {
            background: rgba(249, 115, 22, 0.08);
            border-radius: 4px;
        }

        .infra-modal-body::-webkit-scrollbar-thumb {
            background: rgba(249, 115, 22, 0.2);
            border-radius: 4px;
        }

        .infra-modal-body::-webkit-scrollbar-thumb:hover {
            background: rgba(249, 115, 22, 0.35);
        }

        .infra-modal-section {
            background: white;
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(249, 115, 22, 0.1);
        }

        .infra-modal-section-title {
            font-size: 14px;
            font-weight: 700;
            color: #c2410c;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 14px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(249, 115, 22, 0.1);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .infra-modal-section-title i {
            color: #f97316;
            font-size: 15px;
        }

        .infra-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .infra-info-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 6px 0;
        }

        .infra-info-icon {
            width: 28px;
            height: 28px;
            background: rgba(249, 115, 22, 0.1);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #f97316;
            font-size: 13px;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .infra-info-label {
            font-size: 11px;
            color: #92400e;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 2px;
        }

        .infra-info-value {
            font-size: 14px;
            color: #1f2937;
            font-weight: 500;
            line-height: 1.4;
            word-break: break-word;
        }

        .infra-info-value-full {
            grid-column: 1 / -1;
        }

        .infra-description-text {
            font-size: 14px;
            color: #374151;
            line-height: 1.7;
            padding: 8px 0;
            white-space: pre-wrap;
        }

        .infra-modal-footer {
            background: white;
            border-radius: 0 0 16px 16px;
            padding: 16px 28px;
            border-top: 1px solid rgba(249, 115, 22, 0.1);
            flex-shrink: 0;
            display: flex;
            justify-content: flex-end;
        }

        .infra-modal-btn-close {
            padding: 10px 24px;
            background: rgba(249, 115, 22, 0.1);
            color: #f97316;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            transition: all 0.2s;
        }

        .infra-modal-btn-close:hover {
            background: rgba(249, 115, 22, 0.2);
        }

        .infra-view-map-btn,
        .cimm-view-map-btn,
        .citizen-view-map-btn,
        .lgu-view-map-btn {
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

        .infra-view-map-btn:hover,
        .cimm-view-map-btn:hover,
        .citizen-view-map-btn:hover,
        .lgu-view-map-btn:hover {
            background: rgba(249, 115, 22, 0.2);
        }

        .road-map-container {
            display: none;
            margin-top: 12px;
            height: 320px;
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid rgba(249, 115, 22, 0.15);
        }

        .road-map-container.road-map-visible {
            display: block;
        }

        @media (max-width: 640px) {
            .infra-info-grid {
                grid-template-columns: 1fr;
            }
            .infra-modal-header {
                padding: 18px 16px;
            }
            .infra-modal-body {
                padding: 16px;
            }
            .infra-modal-content {
                max-width: 100%;
                border-radius: 0;
            }
            .infra-modal-overlay {
                padding: 0;
            }
        }

        /* LGU Report Detail Modal */
        .lgu-modal-overlay {
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

        .lgu-modal-overlay.active {
            display: flex;
        }

        .lgu-modal-content {
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

        .lgu-modal-header {
            background: white;
            border-radius: 16px 16px 0 0;
            padding: 24px 28px 18px;
            border-bottom: 2px solid rgba(55, 98, 200, 0.15);
            flex-shrink: 0;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .lgu-modal-header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .lgu-modal-title-area {
            flex: 1;
            min-width: 0;
        }

        .lgu-modal-report-id {
            font-size: 13px;
            color: #3762c8;
            font-weight: 600;
            letter-spacing: 0.3px;
            margin-bottom: 4px;
        }

        .lgu-modal-title {
            font-size: 22px;
            font-weight: 700;
            color: #1e3c72;
            margin: 0 0 10px 0;
            line-height: 1.3;
        }

        .lgu-modal-badges {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .lgu-modal-close {
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

        .lgu-modal-close:hover {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }

        .lgu-modal-body {
            overflow-y: auto;
            flex: 1;
            min-height: 0;
            padding: 24px 28px;
        }

        .lgu-modal-body::-webkit-scrollbar {
            width: 8px;
        }

        .lgu-modal-body::-webkit-scrollbar-track {
            background: rgba(55, 98, 200, 0.08);
            border-radius: 4px;
        }

        .lgu-modal-body::-webkit-scrollbar-thumb {
            background: rgba(55, 98, 200, 0.2);
            border-radius: 4px;
        }

        .lgu-modal-body::-webkit-scrollbar-thumb:hover {
            background: rgba(55, 98, 200, 0.35);
        }

        .lgu-modal-section {
            background: white;
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(55, 98, 200, 0.1);
        }

        .lgu-modal-section-title {
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

        .lgu-modal-section-title i {
            color: #3762c8;
            font-size: 15px;
        }

        .lgu-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .lgu-info-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 6px 0;
        }

        .lgu-info-icon {
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

        .lgu-info-label {
            font-size: 11px;
            color: #6b7280;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 2px;
        }

        .lgu-info-value {
            font-size: 14px;
            color: #1f2937;
            font-weight: 500;
            line-height: 1.4;
            word-break: break-word;
        }

        .lgu-info-value-full {
            grid-column: 1 / -1;
        }

        .lgu-description-text {
            font-size: 14px;
            color: #374151;
            line-height: 1.7;
            padding: 8px 0;
            white-space: pre-wrap;
        }

        .lgu-modal-footer {
            background: white;
            border-radius: 0 0 16px 16px;
            padding: 16px 28px;
            border-top: 1px solid rgba(55, 98, 200, 0.1);
            flex-shrink: 0;
            display: flex;
            justify-content: flex-end;
        }

        .lgu-modal-btn-close {
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

        .lgu-modal-btn-close:hover {
            background: rgba(55, 98, 200, 0.2);
        }

        @media (max-width: 640px) {
            .lgu-info-grid {
                grid-template-columns: 1fr;
            }
            .lgu-modal-header {
                padding: 18px 16px;
            }
            .lgu-modal-body {
                padding: 16px;
            }
            .lgu-modal-content {
                max-width: 100%;
                border-radius: 0;
            }
            .lgu-modal-overlay {
                padding: 0;
            }
        }
        
        .modal-footer {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid rgba(55, 98, 200, 0.1);
            flex-shrink: 0;
        }



        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .workflow-stats {
                width: 100%;
                justify-content: space-between;
            }
            
            .verification-actions {
                flex-wrap: wrap;
            }
            
            .verification-actions form {
                flex-wrap: wrap;
            }
            
            .modal-overlay {
                padding: 10px;
            }
            
            .modal-content {
                width: 100%;
                max-width: 100%;
                padding: 20px;
                max-height: calc(100vh - 20px);
            }
            
            .modal-header h2 {
                font-size: 20px;
            }
            
            .detail-row {
                flex-direction: column;
            }
            
            .detail-label {
                width: 100%;
                margin-bottom: 5px;
            }
            
            .detail-grid {
                grid-template-columns: 1fr;
            }
        }

        /* ===== Modal Dark Mode Overrides ===== */
        body.dark-mode .modal-content {
            background: #1a1d24 !important;
            border-color: #2d323b !important;
        }
        body.dark-mode .modal-header {
            background: linear-gradient(135deg, #0f1f3d, #0a1628) !important;
            border-bottom-color: #2d323b !important;
        }
        body.dark-mode .modal-header h2 {
            color: #e4e6ea !important;
        }
        body.dark-mode .modal-close {
            color: #9ca3af !important;
        }
        body.dark-mode .modal-body {
            color: #e4e6ea !important;
        }
        body.dark-mode .modal-footer {
            border-top-color: #2d323b !important;
        }
        body.dark-mode .detail-row {
            border-bottom-color: #2d323b !important;
        }
        body.dark-mode .detail-label {
            color: #e4e6ea !important;
        }
        body.dark-mode .detail-value {
            color: #9ca3af !important;
        }
        body.dark-mode .modal-body::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.05) !important;
        }
        body.dark-mode .modal-body::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.15) !important;
        }

        /* Citizen Modal Dark Mode */
        body.dark-mode .citizen-modal-content {
            background: #1a1d24 !important;
            border-color: #2d323b !important;
        }
        body.dark-mode .citizen-modal-header {
            background: #1a1d24 !important;
            border-bottom-color: #2d323b !important;
        }
        body.dark-mode .citizen-modal-title {
            color: #4ade80 !important;
        }
        body.dark-mode .citizen-modal-report-id {
            color: #4ade80 !important;
        }
        body.dark-mode .citizen-modal-close {
            color: #9ca3af !important;
        }
        body.dark-mode .citizen-modal-section {
            background: #22262e !important;
            border-color: #2d323b !important;
        }
        body.dark-mode .citizen-modal-section-title {
            color: #4ade80 !important;
            border-bottom-color: #2d323b !important;
        }
        body.dark-mode .citizen-info-icon {
            background: rgba(34,197,94,0.15) !important;
        }
        body.dark-mode .citizen-info-label {
            color: #9ca3af !important;
        }
        body.dark-mode .citizen-info-value {
            color: #e4e6ea !important;
        }
        body.dark-mode .citizen-description-text {
            color: #c0c8d8 !important;
        }
        body.dark-mode .citizen-modal-footer {
            background: #1a1d24 !important;
            border-top-color: #2d323b !important;
        }
        body.dark-mode .citizen-modal-btn-close {
            background: rgba(34,197,94,0.15) !important;
            color: #4ade80 !important;
        }
        body.dark-mode .citizen-modal-body::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.05) !important;
        }
        body.dark-mode .citizen-modal-body::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.15) !important;
        }

        /* CIMM Modal Dark Mode */
        body.dark-mode .cimm-modal-content {
            background: #1a1d24 !important;
            border-color: #2d323b !important;
        }
        body.dark-mode .cimm-modal-header {
            background: #1a1d24 !important;
            border-bottom-color: #2d323b !important;
        }
        body.dark-mode .cimm-modal-title {
            color: #60a5fa !important;
        }
        body.dark-mode .cimm-modal-report-id {
            color: #60a5fa !important;
        }
        body.dark-mode .cimm-modal-close {
            color: #9ca3af !important;
        }
        body.dark-mode .cimm-modal-section {
            background: #22262e !important;
            border-color: #2d323b !important;
        }
        body.dark-mode .cimm-modal-section-title {
            color: #60a5fa !important;
            border-bottom-color: #2d323b !important;
        }
        body.dark-mode .cimm-info-icon {
            background: rgba(55,98,200,0.15) !important;
        }
        body.dark-mode .cimm-info-label {
            color: #9ca3af !important;
        }
        body.dark-mode .cimm-info-value {
            color: #e4e6ea !important;
        }
        body.dark-mode .cimm-description-text {
            color: #c0c8d8 !important;
        }
        body.dark-mode .cimm-modal-footer {
            background: #1a1d24 !important;
            border-top-color: #2d323b !important;
        }
        body.dark-mode .cimm-modal-btn-close {
            background: rgba(55,98,200,0.15) !important;
            color: #60a5fa !important;
        }
        body.dark-mode .cimm-modal-body::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.05) !important;
        }
        body.dark-mode .cimm-modal-body::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.15) !important;
        }

        /* Infra Modal Dark Mode */
        body.dark-mode .infra-modal-content {
            background: #1a1d24 !important;
            border-color: #2d323b !important;
        }
        body.dark-mode .infra-modal-header {
            background: #1a1d24 !important;
            border-bottom-color: #2d323b !important;
        }
        body.dark-mode .infra-modal-title {
            color: #fb923c !important;
        }
        body.dark-mode .infra-modal-report-id {
            color: #fb923c !important;
        }
        body.dark-mode .infra-modal-close {
            color: #9ca3af !important;
        }
        body.dark-mode .infra-modal-section {
            background: #22262e !important;
            border-color: #2d323b !important;
        }
        body.dark-mode .infra-modal-section-title {
            color: #fb923c !important;
            border-bottom-color: #2d323b !important;
        }
        body.dark-mode .infra-info-icon {
            background: rgba(249,115,22,0.15) !important;
        }
        body.dark-mode .infra-info-label {
            color: #9ca3af !important;
        }
        body.dark-mode .infra-info-value {
            color: #e4e6ea !important;
        }
        body.dark-mode .infra-description-text {
            color: #c0c8d8 !important;
        }
        body.dark-mode .infra-modal-footer {
            background: #1a1d24 !important;
            border-top-color: #2d323b !important;
        }
        body.dark-mode .infra-modal-btn-close {
            background: rgba(249,115,22,0.15) !important;
            color: #fb923c !important;
        }
        body.dark-mode .infra-modal-body::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.05) !important;
        }
        body.dark-mode .infra-modal-body::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.15) !important;
        }

        /* LGU Modal Dark Mode */
        body.dark-mode .lgu-modal-content {
            background: #1a1d24 !important;
            border-color: #2d323b !important;
        }
        body.dark-mode .lgu-modal-header {
            background: #1a1d24 !important;
            border-bottom-color: #2d323b !important;
        }
        body.dark-mode .lgu-modal-title {
            color: #60a5fa !important;
        }
        body.dark-mode .lgu-modal-report-id {
            color: #60a5fa !important;
        }
        body.dark-mode .lgu-modal-close {
            color: #9ca3af !important;
        }
        body.dark-mode .lgu-modal-section {
            background: #22262e !important;
            border-color: #2d323b !important;
        }
        body.dark-mode .lgu-modal-section-title {
            color: #60a5fa !important;
            border-bottom-color: #2d323b !important;
        }
        body.dark-mode .lgu-info-icon {
            background: rgba(55,98,200,0.15) !important;
        }
        body.dark-mode .lgu-info-label {
            color: #9ca3af !important;
        }
        body.dark-mode .lgu-info-value {
            color: #e4e6ea !important;
        }
        body.dark-mode .lgu-description-text {
            color: #c0c8d8 !important;
        }
        body.dark-mode .lgu-modal-footer {
            background: #1a1d24 !important;
            border-top-color: #2d323b !important;
        }
        body.dark-mode .lgu-modal-btn-close {
            background: rgba(55,98,200,0.15) !important;
            color: #60a5fa !important;
        }
        body.dark-mode .lgu-modal-body::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.05) !important;
        }
        body.dark-mode .lgu-modal-body::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.15) !important;
        }

        /* ── Verification dashboard refresh (theme-aware, UI only) ── */
        body { background: #f5f3ee; color: var(--text-primary); }
        body.dark-mode { background: var(--bg-page); }
        .vm-dash { padding: 24px 28px; max-width: 100%; overflow-x: hidden; }

        .vm-dash .section-panel,
        .vm-dash .lgu-reports-panel,
        .vm-dash .citizen-reports-panel,
        .vm-dash .dept-reports-panel,
        .vm-dash .infra-reports-panel {
            background: #f4f7fb;
            border: 1px solid #d5dce8;
            border-radius: 14px;
            box-shadow: var(--shadow-card);
            overflow: hidden;
            margin-bottom: 16px;
        }
        .vm-dash .lgu-reports-panel {
            background: #f4f7fb;
            border-color: #c8d0e0;
            border-left: 3px solid #1e3c72;
            box-shadow: 0 2px 10px rgba(30, 60, 114, 0.07);
        }
        .vm-dash .citizen-reports-panel {
            background: #f4faf6;
            border-color: #cce0d4;
            border-left: 3px solid #16a34a;
            box-shadow: 0 2px 10px rgba(22, 163, 74, 0.07);
        }
        .vm-dash .dept-reports-panel {
            background: #f5f3f8;
            border-color: #d4cfe0;
            border-left: 3px solid #4f4568;
            box-shadow: 0 2px 10px rgba(79, 69, 104, 0.08);
        }
        .vm-dash .infra-reports-panel {
            background: #fff9f4;
            border-color: #f0e0cc;
            border-left: 3px solid #f97316;
            box-shadow: 0 2px 10px rgba(249, 115, 22, 0.07);
        }
        .vm-dash .verification-header {
            background: transparent;
            padding: 20px 22px;
        }
        .vm-dash .header-content { margin-bottom: 0; gap: 16px; }
        .vm-dash .header-title h1 {
            color: var(--text-primary);
            font-size: 22px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 4px;
        }
        .vm-dash .header-icon {
            width: 40px; height: 40px; border-radius: 10px;
            display: inline-flex; align-items: center; justify-content: center;
            background: var(--color-primary-bg);
            color: var(--color-primary);
            font-size: 16px;
            flex-shrink: 0;
        }
        .vm-dash .header-title p { color: var(--text-secondary); font-size: 13px; margin: 0; }

        .vm-dash .workflow-stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            margin: 0 0 16px;
        }
        .vm-dash .workflow-stat {
            position: relative;
            overflow: hidden;
            min-width: 0;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 14px;
            border-radius: 14px;
            padding: 16px 18px;
            background: #f4f7fb;
            border: 1px solid #d5dce8;
            box-shadow: var(--shadow-card);
        }
        .vm-dash .workflow-stat::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: var(--border-default);
        }
        .vm-dash .workflow-stat-icon {
            width: 40px; height: 40px; border-radius: 10px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 15px; flex-shrink: 0;
            background: var(--bg-hover);
            color: var(--text-secondary);
        }
        .vm-dash .workflow-number {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
            letter-spacing: -0.03em;
            line-height: 1.15;
            margin-bottom: 2px;
        }
        .vm-dash .workflow-label {
            color: var(--text-secondary);
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }
        .vm-dash .workflow-stat.accent-amber::before { background: var(--color-warning); }
        .vm-dash .workflow-stat.accent-violet::before { background: var(--color-primary); }
        .vm-dash .workflow-stat.accent-rose::before { background: var(--color-success); }
        .vm-dash .workflow-stat.accent-amber .workflow-stat-icon { background: var(--color-warning-bg); color: var(--color-warning); }
        .vm-dash .workflow-stat.accent-violet .workflow-stat-icon { background: var(--color-primary-bg); color: var(--color-primary); }
        .vm-dash .workflow-stat.accent-rose .workflow-stat-icon { background: var(--color-success-bg); color: var(--color-success); }
        .vm-dash .workflow-stat.accent-amber,
        .vm-dash .workflow-stat.accent-violet,
        .vm-dash .workflow-stat.accent-rose { background: #f4f7fb; }

        .vm-dash .filters-section { background: transparent; padding: 16px 20px; }
        .vm-dash .form-label { color: var(--text-secondary); font-weight: 600; }
        .vm-dash .filter-select {
            background: var(--bg-input);
            color: var(--text-primary);
            border: 1px solid var(--border-input);
            border-radius: 8px;
        }
        .vm-dash .btn-secondary-custom {
            background: var(--bg-hover);
            color: var(--text-primary);
            border: 1px solid var(--border-default);
            border-radius: 8px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .vm-dash .btn-secondary-custom:hover {
            background: var(--color-primary-bg);
            color: var(--color-primary);
            border-color: var(--color-primary);
        }

        .vm-dash .lgu-reports-header,
        .vm-dash .citizen-reports-header,
        .vm-dash .dept-reports-header,
        .vm-dash .infra-reports-header {
            background: transparent;
            padding: 16px 20px;
        }
        .vm-dash .lgu-reports-header { border-bottom: 1px solid rgba(30, 60, 114, 0.12); }
        .vm-dash .citizen-reports-header { border-bottom: 1px solid rgba(22, 163, 74, 0.14); }
        .vm-dash .dept-reports-header { border-bottom: 1px solid rgba(79, 69, 104, 0.14); }
        .vm-dash .infra-reports-header { border-bottom: 1px solid rgba(249, 115, 22, 0.16); }
        .vm-dash .lgu-reports-title,
        .vm-dash .dept-reports-title {
            color: #1e3c72;
            font-size: 18px;
            font-weight: 700;
            letter-spacing: -0.01em;
        }
        .vm-dash .dept-reports-title { color: #3f3658; }
        .vm-dash .citizen-reports-title {
            color: #15803d;
            font-size: 18px;
            font-weight: 700;
            letter-spacing: -0.01em;
        }
        .vm-dash .infra-reports-title {
            color: #c2410c;
            font-size: 18px;
            font-weight: 700;
            letter-spacing: -0.01em;
        }
        .vm-dash .lgu-reports-subtitle { color: #4a5b82; }
        .vm-dash .citizen-reports-subtitle { color: #166534; }
        .vm-dash .dept-reports-subtitle { color: #6b6380; }
        .vm-dash .infra-reports-subtitle { color: #92400e; }
        .vm-dash .lgu-reports-icon,
        .vm-dash .citizen-reports-icon,
        .vm-dash .dept-reports-icon,
        .vm-dash .infra-reports-icon {
            width: 40px; height: 40px; border-radius: 10px;
            color: #fff !important;
        }
        .vm-dash .lgu-reports-icon { background: linear-gradient(135deg, #1e3c72, #0f274a) !important; }
        .vm-dash .citizen-reports-icon { background: linear-gradient(135deg, #16a34a, #15803d) !important; }
        .vm-dash .dept-reports-icon { background: linear-gradient(135deg, #5a4e78, #3f3658) !important; }
        .vm-dash .infra-reports-icon { background: linear-gradient(135deg, #f97316, #ea580c) !important; }

        .vm-dash .lgu-reports-badge {
            background: #3762c8 !important;
            color: #fff !important;
        }
        .vm-dash .citizen-reports-badge {
            background: #16a34a !important;
            color: #fff !important;
        }
        .vm-dash .dept-reports-badge,
        .vm-dash .dept-reports-badge.in-progress {
            background: #5a4e78 !important;
            color: #fff !important;
        }
        .vm-dash .infra-reports-badge,
        .vm-dash .infra-reports-badge.in-progress {
            background: #f97316 !important;
            color: #fff !important;
        }

        .vm-dash .lgu-reports-search,
        .vm-dash .citizen-reports-search,
        .vm-dash .dept-reports-search,
        .vm-dash .infra-reports-search {
            padding: 12px 20px;
            border-bottom: 1px solid var(--border-light);
            background: transparent;
        }
        .vm-dash .lgu-search-wrapper,
        .vm-dash .citizen-search-wrapper,
        .vm-dash .dept-search-wrapper,
        .vm-dash .infra-search-wrapper {
            background: var(--bg-input);
            border: 1px solid var(--border-input);
            border-radius: 8px;
        }
        .vm-dash .lgu-search-wrapper:focus-within {
            border-color: #1e3c72;
            box-shadow: 0 0 0 3px rgba(30, 60, 114, 0.12);
        }
        .vm-dash .citizen-search-wrapper:focus-within {
            border-color: #16a34a;
            box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.12);
        }
        .vm-dash .infra-search-wrapper:focus-within {
            border-color: #f97316;
            box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.12);
        }
        .vm-dash .lgu-search-input,
        .vm-dash .citizen-search-input,
        .vm-dash .infra-search-input {
            background: transparent;
            color: var(--text-primary);
            border: none;
        }
        .vm-dash .dept-search-input {
            background: var(--bg-input);
            color: var(--text-primary);
            border: 1px solid var(--border-input);
            border-radius: 8px;
        }
        .vm-dash .dept-search-input:focus {
            border-color: #5a4e78;
            box-shadow: 0 0 0 3px rgba(90, 78, 120, 0.14);
        }

        .vm-panel-pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 16px;
            border-top: 1px solid rgba(55, 98, 200, 0.12);
            flex-wrap: wrap;
        }
        .vm-panel-pagination-info {
            font-size: 13px;
            color: #4b5563;
        }
        body.dark-mode .vm-panel-pagination-info {
            color: #9ca3af;
        }
        .vm-panel-pagination-controls {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .vm-page-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            border: none;
            border-radius: 8px;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: #fff;
            cursor: pointer;
            padding: 0;
        }
        .vm-page-btn:hover:not(.disabled) {
            box-shadow: 0 4px 12px rgba(55, 98, 200, 0.3);
        }
        .vm-page-btn.disabled {
            opacity: 0.4;
            pointer-events: none;
            cursor: default;
        }
        .vm-panel-pagination-slot.is-loading {
            opacity: 0.55;
            pointer-events: none;
        }
        .vm-page-label {
            font-size: 13px;
            font-weight: 600;
            color: #1e3c72;
        }
        body.dark-mode .vm-page-label {
            color: #93c5fd;
        }
        .vm-dash .vm-panel-pagination { padding: 12px 16px; }
        .vm-dash .lgu-sort-btn,
        .vm-dash .citizen-sort-btn,
        .vm-dash .dept-sort-btn,
        .vm-dash .infra-sort-btn,
        .vm-dash .infra-sync-btn {
            border: none;
            border-radius: 8px;
            font-weight: 600;
            color: #fff !important;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .vm-dash .lgu-sort-btn { background: linear-gradient(135deg, #1e3c72, #0f274a) !important; }
        .vm-dash .citizen-sort-btn { background: linear-gradient(135deg, #16a34a, #15803d) !important; }
        .vm-dash .dept-sort-btn { background: linear-gradient(135deg, #5a4e78, #3f3658) !important; }
        .vm-dash .infra-sort-btn,
        .vm-dash .infra-sync-btn { background: linear-gradient(135deg, #f97316, #ea580c) !important; }
        .vm-dash .lgu-sort-btn:hover { background: linear-gradient(135deg, #0f274a, #0a1d35) !important; }
        .vm-dash .citizen-sort-btn:hover { background: linear-gradient(135deg, #15803d, #166534) !important; }
        .vm-dash .dept-sort-btn:hover { background: linear-gradient(135deg, #3f3658, #2e2742) !important; }
        .vm-dash .infra-sort-btn:hover,
        .vm-dash .infra-sync-btn:hover:not(:disabled) { background: linear-gradient(135deg, #ea580c, #c2410c) !important; }

        .vm-dash .lgu-table,
        .vm-dash .citizen-table,
        .vm-dash .dept-table,
        .vm-dash .infra-table { width: 100%; border-collapse: collapse; }
        .vm-dash .lgu-table thead th,
        .vm-dash .citizen-table thead th,
        .vm-dash .dept-table thead th,
        .vm-dash .infra-table thead th {
            font-size: 11px;
            letter-spacing: 0.4px;
            text-transform: uppercase;
            padding: 12px 16px;
            border-bottom: none;
        }
        .vm-dash .lgu-table thead th {
            background: linear-gradient(135deg, #1e3c72, #0f274a) !important;
            color: #fff !important;
        }
        .vm-dash .citizen-table thead th {
            background: linear-gradient(135deg, #16a34a, #15803d) !important;
            color: #fff !important;
        }
        .vm-dash .dept-table thead th {
            background: linear-gradient(135deg, #5a4e78, #3f3658) !important;
            color: #fff !important;
        }
        .vm-dash .infra-table thead th {
            background: linear-gradient(135deg, #f97316, #ea580c) !important;
            color: #fff !important;
        }
        .vm-dash .lgu-table td,
        .vm-dash .citizen-table td,
        .vm-dash .dept-table td,
        .vm-dash .infra-table td {
            color: var(--text-primary);
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-light);
            font-size: 13px;
            white-space: normal;
            vertical-align: middle;
        }
        .vm-dash .lgu-table td:first-child,
        .vm-dash .citizen-table td:first-child,
        .vm-dash .dept-table td:first-child,
        .vm-dash .infra-table td:first-child { white-space: nowrap; }
        .vm-dash .lgu-table td:nth-child(2),
        .vm-dash .citizen-table td:nth-child(2),
        .vm-dash .dept-table td:nth-child(2),
        .vm-dash .infra-table td:nth-child(2) {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 12px;
            color: var(--text-secondary);
        }
        .vm-dash .lgu-table tbody tr,
        .vm-dash .citizen-table tbody tr,
        .vm-dash .dept-table tbody tr,
        .vm-dash .infra-table tbody tr { transition: background 0.15s ease; }
        .vm-dash .lgu-table tbody tr:hover,
        .vm-dash .citizen-table tbody tr:hover,
        .vm-dash .dept-table tbody tr:hover,
        .vm-dash .infra-table tbody tr:hover { background: var(--bg-hover); }

        .vm-dash .lgu-status-badge,
        .vm-dash .citizen-status-badge,
        .vm-dash .dept-status-badge,
        .vm-dash .infra-status-badge,
        .vm-dash .cimm-status-badge,
        .vm-dash .t-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            border: none;
        }
        .vm-dash .lgu-status-badge.pending,
        .vm-dash .citizen-status-badge.pending,
        .vm-dash .dept-status-badge.pending,
        .vm-dash .infra-status-badge.pending,
        .vm-dash .cimm-status-badge.pending,
        .vm-dash .t-badge-pending,
        .vm-dash .cimm-st-awaiting,
        .vm-dash .lgu-status-badge.medium,
        .vm-dash .citizen-status-badge.medium,
        .vm-dash .dept-status-badge.medium {
            background: var(--badge-pending-bg) !important;
            color: var(--badge-pending-text) !important;
        }
        .vm-dash .lgu-status-badge.in-progress,
        .vm-dash .citizen-status-badge.in-progress,
        .vm-dash .dept-status-badge.in-progress,
        .vm-dash .infra-status-badge.in-progress,
        .vm-dash .cimm-status-badge.in-progress,
        .vm-dash .t-badge-info,
        .vm-dash .cimm-st-scheduled,
        .vm-dash .cimm-st-pending,
        .vm-dash .cimm-st-acceptance,
        .vm-dash .cimm-st-approval,
        .vm-dash .cimm-st-progress {
            background: var(--badge-in-progress-bg) !important;
            color: var(--badge-in-progress-text) !important;
        }
        .vm-dash .lgu-status-badge.approved,
        .vm-dash .lgu-status-badge.completed,
        .vm-dash .lgu-status-badge.resolved,
        .vm-dash .lgu-status-badge.verified,
        .vm-dash .citizen-status-badge.approved,
        .vm-dash .citizen-status-badge.completed,
        .vm-dash .citizen-status-badge.resolved,
        .vm-dash .dept-status-badge.approved,
        .vm-dash .dept-status-badge.completed,
        .vm-dash .dept-status-badge.resolved,
        .vm-dash .dept-status-badge.verified,
        .vm-dash .infra-status-badge.approved,
        .vm-dash .infra-status-badge.completed,
        .vm-dash .infra-status-badge.resolved,
        .vm-dash .cimm-st-validated,
        .vm-dash .cimm-st-completed,
        .vm-dash .cimm-st-archived {
            background: var(--badge-approved-bg) !important;
            color: var(--badge-approved-text) !important;
        }
        .vm-dash .lgu-status-badge.cancelled,
        .vm-dash .citizen-status-badge.cancelled,
        .vm-dash .dept-status-badge.cancelled,
        .vm-dash .dept-status-badge.dismissed,
        .vm-dash .infra-status-badge.cancelled,
        .vm-dash .cimm-st-cancelled,
        .vm-dash .lgu-status-badge.high,
        .vm-dash .lgu-status-badge.critical,
        .vm-dash .citizen-status-badge.high,
        .vm-dash .citizen-status-badge.critical,
        .vm-dash .dept-status-badge.high,
        .vm-dash .dept-status-badge.critical {
            background: var(--badge-cancelled-bg) !important;
            color: var(--badge-cancelled-text) !important;
        }
        .vm-dash .lgu-status-badge.low,
        .vm-dash .citizen-status-badge.low,
        .vm-dash .dept-status-badge.low {
            background: var(--bg-hover) !important;
            color: var(--text-secondary) !important;
        }

        .vm-dash .lgu-action-group,
        .vm-dash .citizen-action-group,
        .vm-dash .dept-action-group,
        .vm-dash .infra-action-group { gap: 6px; flex-wrap: wrap; }
        .vm-dash .lgu-action-btn,
        .vm-dash .citizen-action-btn,
        .vm-dash .dept-action-btn,
        .vm-dash .infra-action-btn {
            background: var(--color-primary-bg);
            color: var(--color-primary);
            border: 1px solid transparent;
            border-radius: 8px;
            font-weight: 600;
            font-size: 12px;
            padding: 6px 12px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background 0.15s ease, color 0.15s ease;
        }
        .vm-dash .lgu-action-btn:hover,
        .vm-dash .citizen-action-btn:hover,
        .vm-dash .dept-action-btn:hover,
        .vm-dash .infra-action-btn:hover {
            background: var(--color-primary);
            color: #fff;
        }
        .vm-dash .lgu-verify-btn,
        .vm-dash .citizen-verify-btn,
        .vm-dash .dept-verify-btn,
        .vm-dash .infra-verify-btn {
            background: var(--color-success-bg) !important;
            color: var(--color-success-text) !important;
            border: none;
            border-radius: 8px;
            padding: 6px 10px;
            display: inline-flex; align-items: center; gap: 5px;
            font-weight: 600;
            transition: background 0.15s ease, color 0.15s ease;
        }
        .vm-dash .lgu-verify-btn:hover,
        .vm-dash .citizen-verify-btn:hover,
        .vm-dash .dept-verify-btn:hover,
        .vm-dash .infra-verify-btn:hover {
            background: var(--color-success) !important;
            color: #fff !important;
        }
        .vm-dash .lgu-reject-btn,
        .vm-dash .citizen-reject-btn,
        .vm-dash .dept-reject-btn,
        .vm-dash .infra-reject-btn {
            background: var(--color-danger-bg) !important;
            color: var(--color-danger-text) !important;
            border: none;
            border-radius: 8px;
            padding: 6px 10px;
            display: inline-flex; align-items: center; gap: 5px;
            font-weight: 600;
            transition: background 0.15s ease, color 0.15s ease;
        }
        .vm-dash .lgu-reject-btn:hover,
        .vm-dash .citizen-reject-btn:hover,
        .vm-dash .dept-reject-btn:hover,
        .vm-dash .infra-reject-btn:hover {
            background: var(--color-danger) !important;
            color: #fff !important;
        }
        .vm-dash .lgu-action-btn i,
        .vm-dash .citizen-action-btn i,
        .vm-dash .dept-action-btn i,
        .vm-dash .infra-action-btn i,
        .vm-dash .lgu-verify-btn i,
        .vm-dash .citizen-verify-btn i,
        .vm-dash .dept-verify-btn i,
        .vm-dash .infra-verify-btn i,
        .vm-dash .lgu-reject-btn i,
        .vm-dash .citizen-reject-btn i,
        .vm-dash .dept-reject-btn i,
        .vm-dash .infra-reject-btn i { pointer-events: none; }

        .vm-dash .lgu-empty-state,
        .vm-dash .citizen-empty-state,
        .vm-dash .dept-empty-state,
        .vm-dash .infra-empty-state,
        .vm-dash .cimm-empty-state {
            padding: 40px 16px;
            color: var(--text-secondary);
        }
        .vm-dash .lgu-empty-state h4,
        .vm-dash .citizen-empty-state h4,
        .vm-dash .dept-empty-state h4,
        .vm-dash .infra-empty-state h4 { color: var(--text-primary); margin-bottom: 6px; font-size: 15px; }
        .vm-dash .lgu-empty-icon { background: rgba(30, 60, 114, 0.10) !important; }
        .vm-dash .lgu-empty-icon i { color: #1e3c72 !important; }
        .vm-dash .citizen-empty-icon { background: rgba(22, 163, 74, 0.10) !important; }
        .vm-dash .citizen-empty-icon i { color: #16a34a !important; }
        .vm-dash .dept-empty-icon { background: rgba(90, 78, 120, 0.10) !important; }
        .vm-dash .dept-empty-icon i { color: #5a4e78 !important; }
        .vm-dash .infra-empty-icon,
        .vm-dash .cimm-empty-state .refresh-icon { background: rgba(249, 115, 22, 0.10) !important; }
        .vm-dash .infra-empty-icon i { color: #f97316 !important; }

        .citizen-modal-content,
        .cimm-modal-content,
        .infra-modal-content,
        .lgu-modal-content {
            background: var(--bg-card) !important;
            color: var(--text-primary);
            border: 1px solid var(--border-default) !important;
            max-width: min(680px, 94vw);
            max-height: 86vh;
            box-shadow: var(--shadow-lg);
        }
        .citizen-modal-header,
        .cimm-modal-header,
        .infra-modal-header,
        .lgu-modal-header {
            background: var(--bg-card) !important;
            padding: 16px 20px 14px !important;
            border-bottom: 1px solid var(--border-light) !important;
        }
        .citizen-modal-body,
        .cimm-modal-body,
        .infra-modal-body,
        .lgu-modal-body { padding: 14px 20px !important; }
        .citizen-modal-footer,
        .cimm-modal-footer,
        .infra-modal-footer,
        .lgu-modal-footer {
            padding: 12px 20px !important;
            background: var(--bg-hover) !important;
            border-top: 1px solid var(--border-light) !important;
        }
        .citizen-modal-title,
        .cimm-modal-title,
        .infra-modal-title,
        .lgu-modal-title {
            color: var(--text-primary) !important;
            font-size: 18px !important;
            margin-bottom: 8px !important;
        }
        .citizen-modal-report-id,
        .cimm-modal-report-id,
        .infra-modal-report-id,
        .lgu-modal-report-id { color: var(--text-secondary) !important; }
        .citizen-modal-section,
        .cimm-modal-section,
        .infra-modal-section,
        .lgu-modal-section {
            background: var(--bg-hover) !important;
            border: 1px solid var(--border-light) !important;
            border-radius: 10px;
            padding: 14px 16px !important;
            margin-bottom: 12px;
            box-shadow: none;
        }
        .citizen-modal-section-title,
        .cimm-modal-section-title,
        .infra-modal-section-title,
        .lgu-modal-section-title {
            color: var(--text-secondary) !important;
            border-bottom-color: var(--border-light) !important;
            font-size: 12px !important;
        }
        .citizen-modal-section-title i,
        .cimm-modal-section-title i,
        .infra-modal-section-title i,
        .lgu-modal-section-title i { color: var(--color-primary) !important; }
        .citizen-info-label,
        .cimm-info-label,
        .infra-info-label,
        .lgu-info-label { color: var(--text-muted) !important; }
        .citizen-info-value,
        .cimm-info-value,
        .infra-info-value,
        .lgu-info-value,
        .citizen-description-text,
        .cimm-description-text,
        .infra-description-text,
        .lgu-description-text { color: var(--text-primary) !important; }
        .citizen-info-icon,
        .cimm-info-icon,
        .infra-info-icon,
        .lgu-info-icon {
            background: var(--color-primary-bg) !important;
            color: var(--color-primary) !important;
        }
        .citizen-modal-btn-close,
        .cimm-modal-btn-close,
        .infra-modal-btn-close,
        .lgu-modal-btn-close {
            background: var(--color-primary-bg) !important;
            color: var(--color-primary) !important;
            border-radius: 8px;
            font-weight: 600;
        }
        .citizen-modal-btn-close:hover,
        .cimm-modal-btn-close:hover,
        .infra-modal-btn-close:hover,
        .lgu-modal-btn-close:hover { background: var(--color-primary) !important; color: #fff !important; }
        .citizen-view-map-btn,
        .cimm-view-map-btn,
        .infra-view-map-btn,
        .lgu-view-map-btn {
            background: var(--color-primary-bg) !important;
            color: var(--color-primary) !important;
            border-color: transparent !important;
        }
        .citizen-view-map-btn:hover,
        .cimm-view-map-btn:hover,
        .infra-view-map-btn:hover,
        .lgu-view-map-btn:hover { background: var(--color-primary) !important; color: #fff !important; }
        .vm-map-link { color: var(--text-link); text-decoration: none; font-size: 12px; }
        .vm-map-link:hover { text-decoration: underline; }

        body.dark-mode .vm-dash .section-panel,
        body.dark-mode .vm-dash .lgu-reports-panel,
        body.dark-mode .vm-dash .citizen-reports-panel,
        body.dark-mode .vm-dash .dept-reports-panel,
        body.dark-mode .vm-dash .infra-reports-panel,
        body.dark-mode .vm-dash .workflow-stat {
            background: #1c2432 !important;
        }
        body.dark-mode .vm-dash .section-panel,
        body.dark-mode .vm-dash .workflow-stat {
            border-color: rgba(147, 179, 224, 0.22) !important;
        }
        body.dark-mode .vm-dash .lgu-reports-panel {
            border-color: rgba(147, 179, 224, 0.28) !important;
            border-left-color: #93b3e0 !important;
        }
        body.dark-mode .vm-dash .citizen-reports-panel {
            border-color: rgba(74, 222, 128, 0.28) !important;
            border-left-color: #4ade80 !important;
        }
        body.dark-mode .vm-dash .dept-reports-panel {
            border-color: rgba(167, 154, 196, 0.30) !important;
            border-left-color: #a79ac4 !important;
        }
        body.dark-mode .vm-dash .infra-reports-panel {
            border-color: rgba(251, 146, 60, 0.30) !important;
            border-left-color: #fb923c !important;
        }
        body.dark-mode .vm-dash .lgu-reports-header { border-bottom-color: rgba(147, 179, 224, 0.16) !important; }
        body.dark-mode .vm-dash .citizen-reports-header { border-bottom-color: rgba(74, 222, 128, 0.16) !important; }
        body.dark-mode .vm-dash .dept-reports-header { border-bottom-color: rgba(167, 154, 196, 0.16) !important; }
        body.dark-mode .vm-dash .infra-reports-header { border-bottom-color: rgba(251, 146, 60, 0.18) !important; }
        body.dark-mode .vm-dash .header-title h1,
        body.dark-mode .vm-dash .workflow-number { color: var(--text-primary) !important; }
        body.dark-mode .vm-dash .lgu-reports-title { color: #93b3e0 !important; }
        body.dark-mode .vm-dash .citizen-reports-title { color: #86efac !important; }
        body.dark-mode .vm-dash .dept-reports-title { color: #c5bdd8 !important; }
        body.dark-mode .vm-dash .infra-reports-title { color: #fdba74 !important; }
        body.dark-mode .vm-dash .header-title p,
        body.dark-mode .vm-dash .workflow-label { color: var(--text-secondary) !important; }
        body.dark-mode .vm-dash .lgu-reports-subtitle { color: #8aa3c8 !important; }
        body.dark-mode .vm-dash .citizen-reports-subtitle { color: #6ee7b7 !important; }
        body.dark-mode .vm-dash .dept-reports-subtitle { color: #a39bb8 !important; }
        body.dark-mode .vm-dash .infra-reports-subtitle { color: #fdba74 !important; }
        body.dark-mode .vm-dash .lgu-table thead th {
            background: linear-gradient(135deg, #1e3c72, #0f274a) !important;
            color: #fff !important;
        }
        body.dark-mode .vm-dash .citizen-table thead th {
            background: linear-gradient(135deg, #16a34a, #15803d) !important;
            color: #fff !important;
        }
        body.dark-mode .vm-dash .dept-table thead th {
            background: linear-gradient(135deg, #5a4e78, #3f3658) !important;
            color: #fff !important;
        }
        body.dark-mode .vm-dash .infra-table thead th {
            background: linear-gradient(135deg, #f97316, #ea580c) !important;
            color: #fff !important;
        }
        body.dark-mode .vm-dash .lgu-empty-icon { background: rgba(147, 179, 224, 0.14) !important; }
        body.dark-mode .vm-dash .lgu-empty-icon i { color: #93b3e0 !important; }
        body.dark-mode .vm-dash .citizen-empty-icon { background: rgba(74, 222, 128, 0.14) !important; }
        body.dark-mode .vm-dash .citizen-empty-icon i { color: #86efac !important; }
        body.dark-mode .vm-dash .dept-empty-icon { background: rgba(167, 154, 196, 0.14) !important; }
        body.dark-mode .vm-dash .dept-empty-icon i { color: #c5bdd8 !important; }
        body.dark-mode .vm-dash .infra-empty-icon,
        body.dark-mode .vm-dash .cimm-empty-state .refresh-icon { background: rgba(251, 146, 60, 0.14) !important; }
        body.dark-mode .vm-dash .infra-empty-icon i { color: #fdba74 !important; }
        body.dark-mode .vm-dash .lgu-table td,
        body.dark-mode .vm-dash .citizen-table td,
        body.dark-mode .vm-dash .dept-table td,
        body.dark-mode .vm-dash .infra-table td { color: var(--text-primary) !important; }
        body.dark-mode .vm-dash .lgu-action-btn,
        body.dark-mode .vm-dash .citizen-action-btn,
        body.dark-mode .vm-dash .dept-action-btn,
        body.dark-mode .vm-dash .infra-action-btn {
            background: var(--color-primary-bg) !important;
            color: var(--color-primary) !important;
        }
        body.dark-mode .vm-dash .lgu-action-btn:hover,
        body.dark-mode .vm-dash .citizen-action-btn:hover,
        body.dark-mode .vm-dash .dept-action-btn:hover,
        body.dark-mode .vm-dash .infra-action-btn:hover {
            background: var(--color-primary) !important;
            color: #fff !important;
        }
        body.dark-mode .vm-dash .lgu-verify-btn,
        body.dark-mode .vm-dash .citizen-verify-btn,
        body.dark-mode .vm-dash .dept-verify-btn,
        body.dark-mode .vm-dash .infra-verify-btn {
            background: var(--color-success-bg) !important;
            color: var(--color-success-text) !important;
        }
        body.dark-mode .vm-dash .lgu-reject-btn,
        body.dark-mode .vm-dash .citizen-reject-btn,
        body.dark-mode .vm-dash .dept-reject-btn,
        body.dark-mode .vm-dash .infra-reject-btn {
            background: var(--color-danger-bg) !important;
            color: var(--color-danger-text) !important;
        }
        body.dark-mode .citizen-modal-content,
        body.dark-mode .cimm-modal-content,
        body.dark-mode .infra-modal-content,
        body.dark-mode .lgu-modal-content,
        body.dark-mode .citizen-modal-header,
        body.dark-mode .cimm-modal-header,
        body.dark-mode .infra-modal-header,
        body.dark-mode .lgu-modal-header { background: var(--bg-card) !important; }
        body.dark-mode .citizen-modal-title,
        body.dark-mode .cimm-modal-title,
        body.dark-mode .infra-modal-title,
        body.dark-mode .lgu-modal-title { color: var(--text-primary) !important; }
        body.dark-mode .citizen-modal-report-id,
        body.dark-mode .cimm-modal-report-id,
        body.dark-mode .infra-modal-report-id,
        body.dark-mode .lgu-modal-report-id { color: var(--text-secondary) !important; }
        body.dark-mode .citizen-modal-section-title,
        body.dark-mode .cimm-modal-section-title,
        body.dark-mode .infra-modal-section-title,
        body.dark-mode .lgu-modal-section-title { color: var(--text-secondary) !important; }
        body.dark-mode .citizen-info-icon,
        body.dark-mode .cimm-info-icon,
        body.dark-mode .infra-info-icon,
        body.dark-mode .lgu-info-icon {
            background: var(--color-primary-bg) !important;
            color: var(--color-primary) !important;
        }
        body.dark-mode .citizen-modal-btn-close,
        body.dark-mode .cimm-modal-btn-close,
        body.dark-mode .infra-modal-btn-close,
        body.dark-mode .lgu-modal-btn-close {
            background: var(--color-primary-bg) !important;
            color: var(--color-primary) !important;
        }
        body.dark-mode .vm-map-link { color: var(--text-link); }

        @media (max-width: 768px) {
            .vm-dash { padding: 16px; }
            .vm-dash .header-content { flex-direction: column; align-items: flex-start; }
            .vm-dash .workflow-stats { width: 100%; grid-template-columns: 1fr; }
            .vm-dash .lgu-reports-search,
            .vm-dash .citizen-reports-search,
            .vm-dash .dept-reports-search,
            .vm-dash .infra-reports-search { flex-wrap: wrap; }
            .citizen-modal-content,
            .cimm-modal-content,
            .infra-modal-content,
            .lgu-modal-content { max-width: 96vw; max-height: 96vh; }
        }
        @media (max-width: 480px) {
            .vm-dash .header-icon { width: 36px; height: 36px; }
            .vm-dash .header-title h1 { font-size: 20px; }
        }

    </style>
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?>">
    <!-- SIDEBAR -->
    <?php include '../../includes/sidebar_nav.php'; ?>

    <div class="main-content vm-dash">
        <!-- Verification Header Panel -->
        <div class="section-panel">
            <div class="verification-header" style="margin-bottom:0; box-shadow:none; border:none; border-radius:0;">
                <div class="header-content">
                    <div class="header-title">
                        <h1><span class="header-icon"><i class="fas fa-clipboard-check"></i></span> Verification & Monitoring Reports</h1>
                        <p>Review and approve infrastructure Projects and monitoring data</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="workflow-stats">
            <div class="workflow-stat accent-amber">
                <div class="workflow-stat-icon"><i class="fas fa-hourglass-half"></i></div>
                <div>
                    <div class="workflow-number"><?php echo number_format($stats['pending']); ?></div>
                    <div class="workflow-label">Pending</div>
                </div>
            </div>
            <div class="workflow-stat accent-violet">
                <div class="workflow-stat-icon"><i class="fas fa-clipboard-list"></i></div>
                <div>
                    <div class="workflow-number"><?php echo number_format($stats['in_review']); ?></div>
                    <div class="workflow-label">In Review</div>
                </div>
            </div>
            <div class="workflow-stat accent-rose">
                <div class="workflow-stat-icon"><i class="fas fa-check-circle"></i></div>
                <div>
                    <div class="workflow-number"><?php echo number_format($stats['approved']); ?></div>
                    <div class="workflow-label">Approved</div>
                </div>
            </div>
        </div>

        <!-- Filters Panel -->
        <div class="section-panel">
            <div class="filters-section" style="margin-bottom:0; box-shadow:none; border:none; border-radius:0;">
                <div class="filter-group">
                    <div>
                        <label class="form-label" for="statusFilter">Status Filter</label>
                        <select class="filter-select" id="statusFilter" onchange="filterReports()">
                            <option value="pending" selected>Pending</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label" for="sourceFilter">Source System</label>
                        <select class="filter-select" id="sourceFilter" onchange="filterReports()">
                            <option value="all" <?php echo $source_filter === 'all' ? 'selected' : ''; ?>>All Sources</option>
                            <option value="lgu_reports" <?php echo $source_filter === 'lgu_reports' ? 'selected' : ''; ?>>LGU Monitoring Reports</option>
                            <?php if (!$is_road_supervisor): ?>
                            <option value="transport" <?php echo $source_filter === 'transport' ? 'selected' : ''; ?>>Citizen Reports</option>
                            <?php endif; ?>
                            <option value="cimm" <?php echo $source_filter === 'cimm' ? 'selected' : ''; ?>>CIMM Reports</option>
                            <option value="maintenance" <?php echo $source_filter === 'maintenance' ? 'selected' : ''; ?>>Infrastructure Projects</option>
                        </select>
                    </div>
                    <div>
                        <span class="form-label" aria-hidden="true">&nbsp;</span>
                        <div>
                            <button class="btn-secondary-custom" type="button" onclick="resetFilters()">
                                <i class="fas fa-arrow-clockwise"></i> Reset
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- LGU Monitoring Reports Panel -->
        <div class="lgu-reports-panel" id="lguMonitoringPanel">
            <div class="lgu-reports-header">
                <div class="lgu-reports-header-left">
                    <div class="lgu-reports-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div>
                        <div class="lgu-reports-title-group">
                            <h2 class="lgu-reports-title">LGU Monitoring Reports</h2>
                            <span class="lgu-reports-badge" id="lguReportsBadge"><?php echo $lgu_badge_count; ?> Reports</span>
                        </div>
                        <p class="lgu-reports-subtitle">Reports submitted by the LGU Road &amp; Transportation Department.</p>
                    </div>
                </div>
            </div>

            <div class="lgu-reports-search">
                <div class="lgu-search-wrapper">
                    <i class="fas fa-search"></i>
                    <input type="text" class="lgu-search-input" id="lguSearchInput" placeholder="Search by Report #..." value="<?php echo htmlspecialchars($lgu_search); ?>" oninput="onPanelServerSearch('lgu')">
                </div>
                <button class="lgu-sort-btn" onclick="toggleLguSort()">
                    <i class="fas fa-sort"></i> Sort
                </button>
            </div>

            <div class="lgu-table-wrapper">
                <table class="lgu-table" id="lguTable">
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>Report #</th>
                            <th>Title</th>
                            <th>Location</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // $lgu_reports_list is the paginated array from
                        // getLguReportsForVerification() (see near the top of
                        // this file). This loop used to iterate the old
                        // page-level $all_reports mysqli result instead, which
                        // stopped being assigned when the panel was switched to
                        // server-side pagination — referencing it here threw
                        // "Call to a member function data_seek() on null" on
                        // every load, a fatal error that cut the page off
                        // mid-render (so nothing after this table — Citizen,
                        // CIMM, Infrastructure — ever rendered either).
                        if ($lgu_has_reports):
                        ?>
                            <?php foreach ($lgu_reports_list as $report):
                                if ($is_transport_supervisor && ($report['source'] ?? '') === 'maintenance') continue;
                                $lgu_status_class = '';
                                if ($report['status'] === 'approved') $lgu_status_class = 'approved';
                                elseif ($report['status'] === 'cancelled') $lgu_status_class = 'cancelled';
                                elseif ($report['status'] === 'pending') $lgu_status_class = 'pending';
                                elseif ($report['status'] === 'in-progress') $lgu_status_class = 'in-progress';
                                elseif ($report['status'] === 'completed') $lgu_status_class = 'completed';

                                // Check if this report can be verified locally
                                $report_category = $report['report_category'] ?? null;
                                $report_source = $report['report_source'] ?? null;
                                $can_verify = canVerifyReport($report_category, $report_source);
                                // Reports not yet verified by CIMM show as awaiting external verification
                                // If CIMM verified and status is pending, show check button for final approval
                                $pending_ext_verify = ($report['cimm_sync_status'] ?? '') !== 'verified' && !$can_verify && $report['status'] === 'pending';
                                // Transportation reports can be approved directly; road reports require CIMM verification
                                $ready_for_approval = ($report_category === 'transportation') ? true : (($report['cimm_sync_status'] ?? '') === 'verified' && $report['status'] === 'pending');

                                $lgu_type_labels = [
                                    'traffic_jam' => 'Traffic Jam',
                                    'accident' => 'Vehicle Accident',
                                    'road_closure' => 'Road Closure',
                                    'traffic_light_outage' => 'Traffic Light',
                                    'congestion' => 'Congestion',
                                    'parking_violation' => 'Parking Violation',
                                    'public_transport_issue' => 'Public Transport',
                                    'potholes' => 'Potholes',
                                    'road_damage' => 'Road Damage',
                                    'cracks' => 'Road Cracks',
                                    'erosion' => 'Road Erosion',
                                    'flooding' => 'Street Flooding',
                                    'debris' => 'Road Debris',
                                    'shoulder_damage' => 'Shoulder Damage',
                                    'marking_fade' => 'Marking Fade',
                                ];

                                $lgu_source_labels = [
                                    'lgu' => 'LGU Staff',
                                    'external' => 'External (CIMM)',
                                    'transport' => 'Citizen',
                                    'cimm' => 'CIMM',
                                    'maintenance' => 'Infrastructure',
                                ];

                                $lgu_filter_status = 'pending';
                                if (in_array($report['status'], ['approved', 'completed'])) $lgu_filter_status = 'approved';
                                elseif (in_array($report['status'], ['cancelled'])) $lgu_filter_status = 'rejected';
                            ?>
                            <tr data-id="<?php echo (int)$report['id']; ?>" data-report-id="<?php echo (int)$report['id']; ?>" data-status="<?php echo $lgu_filter_status; ?>" data-source="<?php echo htmlspecialchars($report['source']); ?>">
                                <td>
                                    <div class="lgu-action-group">
                                        <button class="lgu-action-btn" onclick="viewLguReport(<?php echo $report['id']; ?>)">
                                            <i class="fas fa-eye" id="icon-<?php echo $report['id']; ?>"></i>
                                        </button>
                                        <?php if ($pending_ext_verify): ?>
                                            <span class="lgu-status-badge t-badge t-badge-pending" style="font-size:10px;padding:3px 8px;">Ext. Verify</span>
                                        <?php elseif ($ready_for_approval): ?>
                                            <form method="POST" class="lgu-action-form">
                                                <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                                <input type="hidden" name="source" value="<?php echo htmlspecialchars($report['source']); ?>">
                                                <button type="submit" name="action" value="cimm_approve" class="lgu-verify-btn" title="Approve CIMM verified report">
                                                    <i class="fas fa-check-circle"></i>
                                                </button>
                                            </form>
                                            <form method="POST" class="lgu-action-form" onsubmit="return confirm('Are you sure you want to reject this report?');">
                                                <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                                <input type="hidden" name="source" value="<?php echo htmlspecialchars($report['source']); ?>">
                                                <button type="submit" name="action" value="reject" class="lgu-reject-btn" title="Reject report">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                    <!-- Expandable Details Section -->
                                    <div class="expanded-details" id="details-<?php echo $report['id']; ?>" style="display:none;margin-top:12px;padding-top:12px;border-top:2px solid rgba(30,60,114,0.1);">
                                        <div class="detail-grid">
                                            <div class="detail-item">
                                                <strong>Report ID:</strong> <?php echo htmlspecialchars($report['report_id'] ?? 'N/A'); ?>
                                            </div>
                                            <div class="detail-item">
                                                <strong>Type:</strong> 
                                                <?php 
                                                $lgu_type = $report['report_type'] ?? '';
                                                echo htmlspecialchars($lgu_type_labels[$lgu_type] ?? ucfirst($lgu_type));
                                                ?>
                                            </div>
                                            <div class="detail-item">
                                                <strong>Priority:</strong> <span class="lgu-status-badge <?php echo htmlspecialchars($report['priority'] ?? 'medium'); ?>"><?php echo htmlspecialchars($report['priority'] ?? 'medium'); ?></span>
                                            </div>
                                            <div class="detail-item">
                                                <strong>Status:</strong> 
                                                <?php if ($pending_ext_verify): ?>
                                                <span class="lgu-status-badge t-badge t-badge-pending">Awaiting CIMM Verification</span>
                                                <?php elseif ($ready_for_approval): ?>
                                                <span class="lgu-status-badge t-badge t-badge-info">Ready for Final Approval</span>
                                                <?php else: ?>
                                                <span class="lgu-status-badge <?php echo $lgu_status_class; ?>"><?php echo htmlspecialchars($report['status'] ?? 'N/A'); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="detail-item full-width">
                                                <strong>Full Description:</strong>
                                                <div class="t-bg-primary" style="margin-top:8px;padding:12px;border-radius:8px;">
                                                    <?php echo nl2br(htmlspecialchars($report['description'] ?? 'No description provided')); ?>
                                                </div>
                                            </div>
                                            <div class="detail-item full-width">
                                                <strong>Location Address:</strong>
                                                <div style="margin-top:8px;">
                                                    <?php echo htmlspecialchars($report['location'] ?? 'N/A'); ?>
                                                </div>
                                            </div>
                                            <?php if (!empty($report['latitude']) && !empty($report['longitude'])): ?>
                                            <div class="detail-item full-width">
                                                <strong>Location Coordinates:</strong>
                                                <div style="margin-top:8px;">
                                                    Latitude: <?php echo htmlspecialchars($report['latitude']); ?>, 
                                                    Longitude: <?php echo htmlspecialchars($report['longitude']); ?>
                                                    <a href="https://www.google.com/maps?q=<?php echo htmlspecialchars($report['latitude']); ?>,<?php echo htmlspecialchars($report['longitude']); ?>" target="_blank" class="t-text-link" style="margin-left:10px;">
                                                        <i class="fas fa-map-marker-alt"></i> View on Map
                                                    </a>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            <?php if (!empty($report['attachments'])): 
                                                $attachments = json_decode($report['attachments'], true);
                                                if (is_array($attachments) && !empty($attachments)): ?>
                                            <div class="detail-item full-width">
                                                <strong>Attached Images:</strong>
                                                <div style="margin-top:12px;display:flex;gap:15px;flex-wrap:wrap;">
                                                    <?php foreach ($attachments as $attachment): 
                                                        if (isset($attachment['type']) && $attachment['type'] === 'image' && isset($attachment['file_path'])): ?>
                                                        <img src="../../<?php echo htmlspecialchars($attachment['file_path']); ?>" 
                                                             alt="Report Image" 
                                                             style="max-width:300px;max-height:300px;border-radius:8px;border:1px solid rgba(55,98,200,0.3);cursor:pointer;" 
                                                             onclick="window.open(this.src, '_blank')" 
                                                             title="Click to view full size" />
                                                    <?php endif; endforeach; ?>
                                                </div>
                                            </div>
                                            <?php endif; endif; ?>
                                            <div class="detail-item">
                                                <strong>Created:</strong> <?php echo htmlspecialchars($report['created_at'] ?? 'N/A'); ?>
                                            </div>
                                            <?php if (!empty($report['updated_at']) && $report['updated_at'] !== $report['created_at']): ?>
                                            <div class="detail-item">
                                                <strong>Last Updated:</strong> <?php echo htmlspecialchars($report['updated_at']); ?>
                                            </div>
                                            <?php endif; ?>
                                            <?php if (!empty($report['approved_at'])): ?>
                                            <div class="detail-item">
                                                <strong>Approved At:</strong> <?php echo htmlspecialchars($report['approved_at']); ?>
                                            </div>
                                            <?php endif; ?>
                                            <?php if (!empty($report['rejected_at'])): ?>
                                            <div class="detail-item">
                                                <strong>Rejected At:</strong> <?php echo htmlspecialchars($report['rejected_at']); ?>
                                            </div>
                                            <?php endif; ?>
                                            <?php if (!empty($report['engineer'])): ?>
                                            <div class="detail-item">
                                                <strong>CIMM Assigned Engineer:</strong> 
                                                <span class="lgu-status-badge t-badge t-badge-info"><?php echo htmlspecialchars($report['engineer']); ?></span>
                                            </div>
                                            <?php endif; ?>
                                            <?php if (!empty($report['budget_allocation']) && $report['budget_allocation'] !== '0.00'): ?>
                                            <div class="detail-item">
                                                <strong>CIMM Budget Allocation:</strong> 
                                                <span class="t-text-success">₱ <?php echo number_format((float)$report['budget_allocation'], 2); ?></span>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($report['report_id']); ?></td>
                                <td><?php echo htmlspecialchars(strlen($report['title'] ?? '') > 35 ? substr($report['title'], 0, 35) . '...' : ($report['title'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars($lgu_type_labels[$report['report_type']] ?? ucfirst($report['report_type'])); ?></td>
                                <td><?php echo htmlspecialchars($lgu_source_labels[$report['source']] ?? $report['department'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($report['cimm_district'] ?? '') !== '' ? htmlspecialchars($report['cimm_district']) : '—'; ?></td>
                                <td><span class="lgu-status-badge <?php echo htmlspecialchars($report['priority'] ?? 'medium'); ?>"><?php echo ucfirst(htmlspecialchars($report['priority'] ?? 'medium')); ?></span></td>
                                <td><?php echo htmlspecialchars($report['cimm_engineer_name'] ?? '') !== '' ? htmlspecialchars($report['cimm_engineer_name']) : '—'; ?></td>
                                <td><?php echo !empty($report['cimm_budget']) ? '₱' . number_format((float)$report['cimm_budget'], 2) : '—'; ?></td>
                                <td>
                                    <?php
                                    // Once CIMM has verified this report and turned it into a real
                                    // CIMM report, cimm_status carries CIMM's own resolution status
                                    // (the same value that decides whether a report shows on CIMM's
                                    // Pending / Current / Archive Reports pages) — show that instead
                                    // of this system's local workflow status, so the two systems
                                    // never disagree about where a report actually stands. Only
                                    // reports CIMM hasn't verified yet (no cimm_status) fall back to
                                    // the local "Awaiting Ext." / RGMAP-native status below.
                                    //
                                    // Label + colors copied 1:1 from CIMM's own status badges on
                                    // current_reports.php / pending_reports.php / archive_reports.php
                                    // (.scheduled-st, .pending-st, .pending-accept-st,
                                    // .pending-admin-st, .validated-st, .on-going, .completed,
                                    // .cancelled-st) — inline styles rather than shared CSS classes
                                    // since this is a different codebase and can't rely on those
                                    // class names existing here.
                                    $cimmStatusRaw = trim((string)($report['cimm_status'] ?? ''));
                                    if ($cimmStatusRaw !== ''):
                                        $cimmStatusLc = strtolower($cimmStatusRaw);
                                        // [label, background, color, border]
                                        $cimmStatusStyles = [
                                            'pending'                => ['Scheduled',         '#e3f2fd',              '#1565c0', '1.5px solid rgba(21,101,192,.3)'],
                                            'scheduled'              => ['Scheduled',         '#e3f2fd',              '#1565c0', '1.5px solid rgba(21,101,192,.3)'],
                                            'awaiting engineer'      => ['Awaiting Engineer',  '#ffe0b2',              '#e65100', 'none'],
                                            ''                       => ['Awaiting Engineer',  '#ffe0b2',              '#e65100', 'none'],
                                            'pending acceptance'     => ['Pending Acceptance', 'rgba(99,102,241,.12)', '#4338ca', '1px solid rgba(99,102,241,.28)'],
                                            'pending admin approval' => ['Pending Approval',   'rgba(139,92,246,.12)', '#4c1d95', '1px solid rgba(139,92,246,.28)'],
                                            'approved'               => ['Validated',          'rgba(46,125,50,.12)',  '#1b5e20', '1px solid rgba(46,125,50,.28)'],
                                            'in progress'            => ['In Progress',        '#fff59d',              '#f57f17', 'none'],
                                            'pending completion'     => ['Pending Completion', '#fff59d',              '#f57f17', 'none'],
                                            'completed'              => ['Completed',          'rgba(46,125,50,.12)',  '#1b5e20', '1px solid rgba(46,125,50,.28)'],
                                            'archived'               => ['Archived',           'rgba(46,125,50,.12)',  '#1b5e20', '1px solid rgba(46,125,50,.28)'],
                                            'cancelled'              => ['Cancelled',          '#ffcdd2',              '#b71c1c', 'none'],
                                            'rejected'               => ['Rejected',           '#ffcdd2',              '#b71c1c', 'none'],
                                        ];
                                        [$cimmDisplayLabel, $cimmBg, $cimmFg, $cimmBorder] = $cimmStatusStyles[$cimmStatusLc]
                                            ?? [$cimmStatusRaw, '#fff59d', '#f57f17', 'none'];
                                    ?>
                                    <span class="lgu-status-badge" title="CIMM report status" style="background:<?php echo $cimmBg; ?>;color:<?php echo $cimmFg; ?>;border:<?php echo $cimmBorder; ?>;"><?php echo htmlspecialchars($cimmDisplayLabel); ?></span>
                                    <?php elseif ($pending_ext_verify): ?>
                                    <span class="lgu-status-badge t-badge t-badge-pending">Awaiting Ext.</span>
                                    <?php else: ?>
                                    <span class="lgu-status-badge <?php echo $lgu_status_class; ?>"><?php echo ucfirst(htmlspecialchars(str_replace('-', ' ', $report['status']))); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $report['created_at'] ? date('M d, Y', strtotime($report['created_at'])) : '—'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="11">
                                    <div class="lgu-empty-state">
                                        <div class="lgu-empty-icon"><i class="fas fa-clipboard-list"></i></div>
                                        <p>No reports at this time.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div id="lguPagination" class="vm-panel-pagination-slot">
                <?php echo $lgu_pagination_html; ?>
            </div>
        </div>

        <!-- Citizen Reports Panel (Transportation-only — hidden for Road Operations Supervisors) -->
        <?php if (!$is_road_supervisor): ?>
        <div class="citizen-reports-panel" id="citizenPanel">
            <div class="citizen-reports-header">
                <div class="citizen-reports-header-left">
                    <div class="citizen-reports-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div>
                        <div class="citizen-reports-title-group">
                            <h2 class="citizen-reports-title">Citizen Reports</h2>
                            <span class="citizen-reports-badge" id="citizenReportsBadge"><?php echo $citizen_reports_total; ?> Reports</span>
                        </div>
                        <p class="citizen-reports-subtitle">Reports submitted by citizens via the public portal</p>
                    </div>
                </div>
            </div>

            <div class="citizen-reports-search">
                <div class="citizen-search-wrapper">
                    <i class="fas fa-search"></i>
                    <input type="text" class="citizen-search-input" id="citizenSearchInput" placeholder="Search by Report #..." value="<?php echo htmlspecialchars($citizen_search); ?>" oninput="onPanelServerSearch('citizen')">
                </div>
                <button class="citizen-sort-btn" onclick="toggleCitizenSort()">
                    <i class="fas fa-sort"></i> Sort
                </button>
            </div>

            <div class="citizen-table-wrapper">
                <table class="citizen-table" id="citizenTable">
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>Report #</th>
                            <th>Title</th>
                            <th>Location</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php echo vm_render_citizen_panel_tbody($citizen_reports_list); ?>
                    </tbody>
                </table>
            </div>
            <div id="citizenPagination" class="vm-panel-pagination-slot">
                <?php echo $citizen_pagination_html; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- CIMM Reports Panel -->
        <?php if (!$is_transport_supervisor): ?>
        <div class="dept-reports-panel" id="cimmReportsPanel">
            <div class="dept-reports-header">
                <div class="dept-reports-header-left">
                    <div class="dept-reports-icon">
                        <i class="fas fa-building"></i>
                    </div>
                    <div>
                        <div class="dept-reports-title-group">
                            <h2 class="dept-reports-title">CIMM Reports</h2>
                            <span class="dept-reports-badge in-progress" id="cimmReportsBadge"><?php echo $cimm_reports_total + ($sql_reports ? $sql_reports->num_rows : 0); ?> Reports</span>
                        </div>
                        <p class="dept-reports-subtitle">Department-submitted infrastructure Projects from CIMM</p>
                    </div>
                </div>
            </div>

            <div class="dept-reports-search">
                <div class="dept-search-wrapper">
                    <i class="fas fa-search"></i>
                    <input type="text" class="dept-search-input" id="deptSearchInput" placeholder="Search by Rep #..." value="<?php echo htmlspecialchars($cimm_search); ?>" oninput="onPanelServerSearch('cimm')">
                </div>
                <button class="dept-sort-btn" onclick="toggleDeptSort()">
                    <i class="fas fa-sort"></i> Sort
                </button>
            </div>

            <div class="dept-table-wrapper">
                <table class="dept-table" id="deptTable">
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>Rep #</th>
                            <th>Infrastructure</th>
                            <th>Location</th>
                            <th>Issue / Notes</th>
                            <th>Priority</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php echo vm_render_cimm_panel_tbody($cimm_reports, $sql_reports, $cimm_page === 1); ?>
                    </tbody>
                </table>
            </div>
            <div id="cimmPagination" class="vm-panel-pagination-slot">
                <?php echo $cimm_pagination_html; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Infrastructure Reports Panel -->
        <?php if (!$is_transport_supervisor): ?>
        <div class="infra-reports-panel" id="infraReportsPanel">
            <div class="infra-reports-header">
                <div class="infra-reports-header-left">
                    <div class="infra-reports-icon">
                        <i class="fas fa-hard-hat"></i>
                    </div>
                    <div>
                        <div class="infra-reports-title-group">
                            <h2 class="infra-reports-title">Infrastructure Projects</h2>
                            <span class="infra-reports-badge in-progress" id="infraReportsBadge"><?php echo is_array($infra_reports) ? count($infra_reports) : 0; ?> Reports</span>
                        </div>
                        <p class="infra-reports-subtitle">Infrastructure maintenance and infrastructure issue reports</p>
                    </div>
                </div>
                <button type="button" class="infra-sync-btn" id="infraSyncBtn" onclick="syncInfraProjects(this)" title="Pull latest projects from IPMS">
                    <i class="fas fa-sync-alt"></i> Sync
                </button>
            </div>

            <div class="infra-reports-search">
                <div class="infra-search-wrapper">
                    <i class="fas fa-search"></i>
                    <input type="text" class="infra-search-input" id="infraSearchInput" placeholder="Search by Rep #, Infrastructure, Location, Engineer...">
                </div>
                <button class="infra-sort-btn" onclick="toggleInfraSort()">
                    <i class="fas fa-sort"></i> Sort
                </button>
            </div>

            <div class="infra-table-wrapper">
                <table class="infra-table" id="infraTable">
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>Rep #</th>
                            <th>Infrastructure</th>
                            <th>Location</th>
                            <th>Issue / Notes</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $hasInfraReports = false;
                        if (!empty($infra_reports)):
                            foreach ($infra_reports as $irow):
                                $hasInfraReports = true;
                                $istatus_class = '';
                                if ($irow['status'] === 'approved') $istatus_class = 'approved';
                                elseif ($irow['status'] === 'cancelled') $istatus_class = 'cancelled';
                                elseif ($irow['status'] === 'pending') $istatus_class = 'pending';
                                elseif ($irow['status'] === 'in-progress') $istatus_class = 'in-progress';
                                elseif ($irow['status'] === 'completed') $istatus_class = 'completed';
                                // Map infra status to filter categories
                                $infra_filter_status = 'pending';
                                if (in_array($irow['status'], ['approved', 'completed'])) $infra_filter_status = 'approved';
                                elseif (in_array($irow['status'], ['cancelled'])) $infra_filter_status = 'rejected';
                        ?>
                        <tr data-id="<?php echo (int)$irow['id']; ?>" data-report-id="<?php echo (int)$irow['id']; ?>" data-status="<?php echo $infra_filter_status; ?>" data-source="maintenance">
                            <td>
                                <div class="infra-action-group">
                                    <button class="infra-action-btn" onclick="viewInfraReport(<?php echo $irow['id']; ?>, '<?php echo htmlspecialchars($irow['source'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <?php if (!in_array($irow['status'], ['approved', 'cancelled'], true)): ?>
                                    <form method="POST" class="infra-action-form" onsubmit="return confirm('Are you sure you want to approve this infrastructure project?');">
                                        <input type="hidden" name="report_id" value="<?php echo (int)$irow['id']; ?>">
                                        <input type="hidden" name="source" value="infra">
                                        <button type="submit" name="action" value="approve" class="infra-verify-btn" title="Approve infrastructure project">
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                    </form>
                                    <form method="POST" class="infra-action-form" onsubmit="return confirm('Are you sure you want to reject this infrastructure project?');">
                                        <input type="hidden" name="report_id" value="<?php echo (int)$irow['id']; ?>">
                                        <input type="hidden" name="source" value="infra">
                                        <button type="submit" name="action" value="reject" class="infra-reject-btn" title="Reject infrastructure project">
                                            <i class="fas fa-times"></i> Reject
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($irow['report_id']); ?></td>
                            <td><?php echo htmlspecialchars($irow['infrastructure'] ?: '—'); ?></td>
                            <td><?php if (($irow['start_address'] ?? '') !== ''): ?><span title="<?php echo htmlspecialchars($irow['start_address']); ?>"><?php echo htmlspecialchars(strlen($irow['start_address']) > 40 ? substr($irow['start_address'], 0, 40) . '...' : $irow['start_address']); ?></span><?php else: ?>—<?php endif; ?></td>
                            <td><?php echo htmlspecialchars(strlen($irow['issue_notes'] ?? '') > 40 ? substr($irow['issue_notes'], 0, 40) . '...' : ($irow['issue_notes'] ?? '—')); ?></td>
                            <td><span class="infra-status-badge <?php echo $istatus_class; ?>"><?php echo ucfirst(htmlspecialchars(str_replace(['-', '_'], ' ', $irow['status']))); ?></span></td>
                        </tr>
                        <?php
                            endforeach;
                        endif;
                        ?>

                        <?php if (!$hasInfraReports): ?>
                        <tr>
                            <td colspan="6">
                                <div class="infra-empty-state">
                                    <div class="infra-empty-icon">
                                        <i class="fas fa-hard-hat"></i>
                                    </div>
                                    <h4>No infrastructure projects yet</h4>
                                    <p>Synced IPMS projects will appear here for verification.</p>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        
        </div>

        </div>
    </div>



    <script>
        console.log('Script started executing');
        // Filter functionality
        function filterReports() {
            const status = document.getElementById('statusFilter').value;
            const source = document.getElementById('sourceFilter').value;
            const url = new URL(window.location);
            url.searchParams.set('status', status);
            url.searchParams.set('source', source);
            window.location.href = url.toString();
        }

        function resetFilters() {
            const url = new URL(window.location);
            url.searchParams.delete('status');
            url.searchParams.delete('source');
            url.searchParams.delete('lgu_page');
            url.searchParams.delete('citizen_page');
            url.searchParams.delete('cimm_page');
            url.searchParams.delete('lgu_q');
            url.searchParams.delete('citizen_q');
            url.searchParams.delete('cimm_q');
            window.location.href = url.toString();
        }

        // Apply source filter to show/hide panels on page load
        function applySourcePanels(source) {
            var allReportsPanel = document.getElementById('lguMonitoringPanel');
            var cimmPanel = document.getElementById('cimmReportsPanel');
            var infraPanel = document.getElementById('infraReportsPanel');
            var citizenPanel = document.getElementById('citizenPanel');

            if (source === 'cimm') {
                if (allReportsPanel) allReportsPanel.style.display = 'none';
                if (cimmPanel) cimmPanel.style.display = '';
                if (infraPanel) infraPanel.style.display = 'none';
                if (citizenPanel) citizenPanel.style.display = 'none';
            } else if (source === 'maintenance') {
                if (allReportsPanel) allReportsPanel.style.display = 'none';
                if (cimmPanel) cimmPanel.style.display = 'none';
                if (infraPanel) infraPanel.style.display = '';
                if (citizenPanel) citizenPanel.style.display = 'none';
            } else if (source === 'lgu_reports') {
                // LGU Monitoring Reports filter: show ONLY the LGU Monitoring
                // Reports panel. CIMM, Infrastructure and Citizen panels are
                // hidden so only LGU monitoring reports are shown.
                if (allReportsPanel) allReportsPanel.style.display = '';
                if (cimmPanel) cimmPanel.style.display = 'none';
                if (infraPanel) infraPanel.style.display = 'none';
                if (citizenPanel) citizenPanel.style.display = 'none';
            } else if (source === 'transport') {
                // Citizen Reports filter: show ONLY the Citizen Reports panel.
                // The LGU Monitoring panel (staff transport reports), CIMM
                // panel, and Infrastructure panel are all hidden so only
                // citizen-submitted reports are shown. CIMM / IPMS integration
                // code is untouched.
                if (allReportsPanel) allReportsPanel.style.display = 'none';
                if (cimmPanel) cimmPanel.style.display = 'none';
                if (infraPanel) infraPanel.style.display = 'none';
                if (citizenPanel) citizenPanel.style.display = '';
            } else {
                // 'all' or unset — show everything
                if (allReportsPanel) allReportsPanel.style.display = '';
                if (cimmPanel) cimmPanel.style.display = '';
                if (infraPanel) infraPanel.style.display = '';
                if (citizenPanel) citizenPanel.style.display = '';
            }
        }
        (function() {
            var urlParams = new URLSearchParams(window.location.search);
            applySourcePanels(urlParams.get('source') || 'all');
        })();

        // Apply status filter to hide/show rows in LGU, CIMM and Infra panels on page load
        (function() {
            var urlParams = new URLSearchParams(window.location.search);
            var statusFilter = urlParams.get('status') || 'all';
            // This page only lists pending reports now, so any stale
            // non-pending status value (e.g. from an old URL) must not hide
            // every row.
            if (statusFilter !== 'pending') statusFilter = 'all';
            if (statusFilter === 'all') return;

            var tableIds = ['lguTable', 'deptTable', 'infraTable'];
            tableIds.forEach(function(tableId) {
                var table = document.getElementById(tableId);
                if (table) {
                    table.querySelectorAll('tbody tr[data-status]').forEach(function(row) {
                        row.style.display = (row.getAttribute('data-status') === statusFilter) ? '' : 'none';
                    });
                }
            });
        })();

        // Deep-link focus: ?focus_report_id= / ?source= + ?id= from a
        // notification "View" button. The backend already verified the record
        // exists and classified it ($focus_target.found) — see the $focus_target
        // PHP block above — so this only needs to reveal the correct panel,
        // reveal the row, scroll to it and highlight it.
        var focusTarget = <?php echo json_encode($focus_target); ?>;
        if (focusTarget && focusTarget.id) {
            setTimeout(function() {
                var row = null;
                if (focusTarget.table) {
                    var table = document.getElementById(focusTarget.table);
                    if (table) {
                        var rows = table.querySelectorAll('tbody tr[data-report-id="' + focusTarget.id + '"]');
                        if (rows.length === 0) {
                            rows = table.querySelectorAll('tbody tr[data-id="' + focusTarget.id + '"]');
                        }
                        if (rows.length === 1) {
                            row = rows[0];
                        } else if (rows.length > 1) {
                            // The same id can exist in more than one table —
                            // disambiguate by data-source when possible.
                            rows.forEach(function(r) {
                                if (!row && r.getAttribute('data-source') === focusTarget.source) row = r;
                            });
                            if (!row) row = rows[0];
                        }
                    }
                }
                if (row && focusTarget.found) {
                    // Switch to the correct report panel.
                    if (focusTarget.filterValue) applySourcePanels(focusTarget.filterValue);
                    // Reveal the row in case a status filter had hidden it.
                    row.style.display = '';
                    // Sync the filter dropdowns with the focused panel.
                    var sf = document.getElementById('sourceFilter');
                    if (sf && focusTarget.filterValue) sf.value = focusTarget.filterValue;
                    var stf = document.getElementById('statusFilter');
                    if (stf) stf.value = 'all';
                    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    row.classList.add('vm-row-focus');
                    setTimeout(function() { row.classList.remove('vm-row-focus'); }, 5000);
                } else {
                    showNotification('The report referenced by this notification could not be found.', 'error');
                }
            }, 600);
        }



        // Toggle expanded details inline
        function toggleDetails(reportId) {
            const detailsDiv = document.getElementById('details-' + reportId);
            const icon = document.getElementById('icon-' + reportId);
            const text = document.getElementById('text-' + reportId);
            
            if (detailsDiv.style.display === 'none') {
                detailsDiv.style.display = 'block';
                if (icon) icon.className = 'fas fa-eye-slash';
                if (text) text.textContent = 'Hide Details';
            } else {
                detailsDiv.style.display = 'none';
                if (icon) icon.className = 'fas fa-eye';
                if (text) text.textContent = 'View Details';
            }
        }

        // Show notification
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = 'notification ' + type;
            notification.textContent = message;
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.remove();
            }, 3000);
        }

        // Handle form submissions with confirmation
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const action = this.querySelector('button[type="submit"]');
                if (action && action.value === 'reject') {
                    if (!confirm('Are you sure you want to reject this report?')) {
                        e.preventDefault();
                    }
                }
            });
        });
        
        // Show success message if available
        <?php if (isset($success_message)): ?>
        showNotification('<?php echo htmlspecialchars($success_message); ?>', 'success');
        <?php endif; ?>

        // AJAX panel pagination + server-side search (LGU, Citizen, CIMM).
        let vmPanelPageLoading = false;
        const vmPanelSearchTimers = {};
        const vmPanelDom = {
            lgu: { table: '#lguTable', pagination: 'lguPagination', badge: 'lguReportsBadge', search: 'lguSearchInput' },
            citizen: { table: '#citizenTable', pagination: 'citizenPagination', badge: 'citizenReportsBadge', search: 'citizenSearchInput' },
            cimm: { table: '#deptTable', pagination: 'cimmPagination', badge: 'cimmReportsBadge', search: 'deptSearchInput' }
        };

        function mergePanelRowsJson(targetMap, rowsJson) {
            if (!rowsJson || typeof rowsJson !== 'object') return;
            Object.keys(rowsJson).forEach(function(key) {
                targetMap[key] = rowsJson[key];
            });
        }

        function onPanelServerSearch(panel) {
            if (!vmPanelDom[panel]) return;
            clearTimeout(vmPanelSearchTimers[panel]);
            vmPanelSearchTimers[panel] = setTimeout(function trySearch() {
                if (vmPanelPageLoading) {
                    vmPanelSearchTimers[panel] = setTimeout(trySearch, 150);
                    return;
                }
                loadPanelPage(panel, 1);
            }, 300);
        }

        async function loadPanelPage(panel, page) {
            if (!panel || vmPanelPageLoading) return;
            const dom = vmPanelDom[panel];
            if (!dom) return;
            const pageNum = Math.max(1, parseInt(page, 10) || 1);
            const searchInput = document.getElementById(dom.search);
            const q = searchInput ? searchInput.value.trim() : '';
            const url = new URL(window.location.href);
            url.searchParams.set('ajax', 'panel_page');
            url.searchParams.set('panel', panel);
            url.searchParams.set('page', String(pageNum));
            url.searchParams.delete('lgu_q');
            url.searchParams.delete('citizen_q');
            url.searchParams.delete('cimm_q');
            url.searchParams.delete('q');
            if (q) url.searchParams.set('q', q);

            const tbody = document.querySelector(dom.table + ' tbody');
            const pagSlot = document.getElementById(dom.pagination);
            const badge = document.getElementById(dom.badge);
            if (!tbody || !pagSlot) return;

            vmPanelPageLoading = true;
            pagSlot.classList.add('is-loading');
            try {
                const res = await fetch(url.toString(), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    credentials: 'same-origin'
                });
                const data = await res.json();
                if (!data || !data.success) throw new Error((data && data.message) || 'Failed to load page');
                tbody.innerHTML = data.rows_html || '';
                pagSlot.innerHTML = data.pagination_html || '';
                if (badge && data.badge_text) badge.textContent = data.badge_text;
                if (panel === 'lgu' && data.rows_json) mergePanelRowsJson(lguDataMap, data.rows_json);
                if (panel === 'citizen' && data.rows_json) mergePanelRowsJson(citizenDataMap, data.rows_json);
                if (panel === 'cimm' && data.rows_json) mergePanelRowsJson(cimmDataMap, data.rows_json);

                const hist = new URL(window.location.href);
                hist.searchParams.set(panel + '_page', String(data.page || pageNum));
                if (q) hist.searchParams.set(panel + '_q', q);
                else hist.searchParams.delete(panel + '_q');
                hist.searchParams.delete('ajax');
                hist.searchParams.delete('q');
                window.history.replaceState({}, '', hist.toString());
            } catch (err) {
                console.error('Panel pagination failed:', err);
            } finally {
                pagSlot.classList.remove('is-loading');
                vmPanelPageLoading = false;
            }
        }

        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.vm-page-btn[data-panel]');
            if (!btn || btn.disabled || btn.classList.contains('disabled')) return;
            e.preventDefault();
            loadPanelPage(btn.getAttribute('data-panel'), btn.getAttribute('data-page'));
        });

        // CIMM Reports panel is now always visible (no tab filtering)

        // LGU sort functionality
        let lguSortAsc = true;
        function toggleLguSort() {
            const table = document.getElementById('lguTable');
            if (!table) return;
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            lguSortAsc = !lguSortAsc;
            rows.sort((a, b) => {
                const aText = a.cells[2]?.textContent.trim() || '';
                const bText = b.cells[2]?.textContent.trim() || '';
                return lguSortAsc ? aText.localeCompare(bText) : bText.localeCompare(aText);
            });
            rows.forEach(row => tbody.appendChild(row));
        }

        // CIMM sort functionality
        let cimmSortAsc = true;
        function toggleCimmSort() {
            const table = document.getElementById('cimmTable');
            if (!table) return;
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            cimmSortAsc = !cimmSortAsc;
            rows.sort((a, b) => {
                const aText = a.cells[1]?.textContent.trim() || '';
                const bText = b.cells[1]?.textContent.trim() || '';
                return cimmSortAsc ? aText.localeCompare(bText) : bText.localeCompare(aText);
            });
            rows.forEach(row => tbody.appendChild(row));
        }

        // CIMM & SQL report data maps (populated from PHP)
        var cimmDataMap = <?php echo json_encode(vm_build_cimm_rows_json($cimm_reports), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        var sqlDataMap = {};
        <?php
        if ($sql_reports && method_exists($sql_reports, 'data_seek')):
            $sql_reports->data_seek(0);
            if ($sql_reports->num_rows > 0):
                while ($sr = $sql_reports->fetch_assoc()):
        ?>
        (function() {
            try {
                sqlDataMap[<?php echo (int)$sr['rep_id']; ?>] = {
                    rep_id: <?php echo (int)$sr['rep_id']; ?>,
                    res_id: <?php echo (int)$sr['res_id']; ?>,
                    starting_date: <?php echo json_encode($sr['starting_date']); ?>,
                    estimated_end_date: <?php echo json_encode($sr['estimated_end_date']); ?>,
                    engineer_id: <?php echo json_encode($sr['engineer_id']); ?>,
                    report_by: <?php echo (int)$sr['report_by']; ?>,
                    priority_lvl: <?php echo json_encode($sr['priority_lvl']); ?>,
                    budget: <?php echo json_encode($sr['budget']); ?>,
                    created_at: <?php echo json_encode($sr['created_at']); ?>,
                    engineer_accepted: <?php echo (int)$sr['engineer_accepted']; ?>,
                    decline_reason: <?php echo json_encode($sr['decline_reason']); ?>,
                    decline_reviewed: <?php echo json_encode($sr['decline_reviewed']); ?>,
                    decline_review_note: <?php echo json_encode($sr['decline_review_note']); ?>,
                    reporter_name: <?php echo json_encode($sr['reporter_name'] ?? 'User #' . $sr['report_by']); ?>
                };
            } catch(e) {
                console.error('Error adding SQL report to map:', e);
            }
        })();
        <?php
                endwhile;
            endif;
        endif;
        ?>

        function setModalField(id, value) {
            var el = document.getElementById(id);
            if (el) el.textContent = value || '—';
        }

        function openCimmDetailModal() {
            var modal = document.getElementById('cimmDetailModal');
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeCimmDetailModal() {
            var modal = document.getElementById('cimmDetailModal');
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }

        // Photo lightbox
        function openLightbox(src) {
            var lb = document.getElementById('photoLightbox');
            var img = document.getElementById('lightboxImage');
            img.src = src;
            lb.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeLightbox(e) {
            if (e) e.stopPropagation();
            var lb = document.getElementById('photoLightbox');
            lb.classList.remove('active');
            document.body.style.overflow = '';
        }

        function statusBadgeHtml(status, label) {
            var colors = {
                'pending':        'background:rgba(249,115,22,0.1);color:#c2410c;',
                'in-progress':    'background:rgba(55,98,200,0.1);color:#3762c8;',
                'completed':      'background:rgba(5,150,105,0.1);color:#047857;',
                'resolved':       'background:rgba(5,150,105,0.1);color:#047857;',
                'approved':       'background:rgba(5,150,105,0.1);color:#047857;',
                'cancelled':      'background:rgba(220,38,38,0.08);color:#dc2626;'
            };
            var c = colors[status] || '';
            return '<span style="display:inline-block;padding:4px 12px;border-radius:6px;font-size:12px;font-weight:600;text-transform:capitalize;' + c + '">' + (label || status || '—') + '</span>';
        }

        function priorityBadgeHtml(priority) {
            var colors = {
                'high':   'background:rgba(220,38,38,0.1);color:#dc2626;',
                'medium': 'background:rgba(217,119,6,0.1);color:#d97706;',
                'low':    'background:rgba(107,114,128,0.12);color:#4b5563;'
            };
            var p = (priority || 'medium').toLowerCase();
            var c = colors[p] || '';
            return '<span style="display:inline-block;padding:4px 12px;border-radius:6px;font-size:12px;font-weight:600;text-transform:capitalize;' + c + '">' + (priority || '—') + '</span>';
        }

        function formatDate(dateStr) {
            if (!dateStr) return '—';
            var d = new Date(dateStr);
            if (isNaN(d.getTime())) return dateStr;
            var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            return months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
        }

        function formatCurrency(val) {
            if (!val || val == 0) return '—';
            return '₱' + parseFloat(val).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
        }

        // Helper: build a LGU info item with icon
        function lguInfoItem(icon, label, value) {
            var displayVal = (value && value !== '—' && value !== null) ? value : '—';
            return '<div class="lgu-info-item"><div class="lgu-info-icon"><i class="fas fa-' + icon + '"></i></div><div><div class="lgu-info-label">' + label + '</div><div class="lgu-info-value">' + displayVal + '</div></div></div>';
        }

        // Helper: LGU badge HTML
        function lguBadge(text, bg, color) {
            return '<span style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;background:' + bg + ';color:' + color + ';">' + text + '</span>';
        }

        // View LGU report details
        function viewLguReport(id) {
            var r = lguDataMap[id];
            if (!r) { alert('Report data not found.'); return; }

            var typeLabels = {
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

            var statusStyles = {
                'pending':    {bg:'rgba(249,115,22,0.1)', color:'#c2410c'},
                'approved':   {bg:'rgba(5,150,105,0.1)', color:'#047857'},
                'completed':  {bg:'rgba(5,150,105,0.1)', color:'#047857'},
                'cancelled':  {bg:'rgba(220,38,38,0.08)', color:'#dc2626'},
                'in-progress':{bg:'rgba(55,98,200,0.1)', color:'#3762c8'}
            };
            var pStyles = {
                'high':   {bg:'rgba(220,38,38,0.1)', color:'#dc2626'},
                'medium': {bg:'rgba(217,119,6,0.1)', color:'#d97706'},
                'low':    {bg:'rgba(107,114,128,0.12)', color:'#4b5563'}
            };

            // Header
            document.getElementById('lgu-report-id').textContent = 'Report #' + (r.report_id || '—');
            document.getElementById('lgu-title').textContent = r.title || '—';

            var st = (r.status || 'pending').toLowerCase();
            var ss = statusStyles[st] || {bg:'rgba(107,114,128,0.15)', color:'#6b7280'};
            var pp = (r.priority || 'medium').toLowerCase();
            var ps = pStyles[pp] || {bg:'rgba(107,114,128,0.15)', color:'#6b7280'};

            var badgesHtml = lguBadge(r.status || '—', ss.bg, ss.color);
            badgesHtml += lguBadge(r.priority || '—', ps.bg, ps.color);
            var sourceColors = {
                lgu: {bg:'rgba(55,98,200,0.1)', color:'#3762c8'},
                transport: {bg:'rgba(55,98,200,0.1)', color:'#3762c8'},
                cimm: {bg:'rgba(55,98,200,0.1)', color:'#3762c8'},
                external: {bg:'rgba(55,98,200,0.1)', color:'#3762c8'},
                maintenance: {bg:'rgba(55,98,200,0.1)', color:'#3762c8'}
            };
            var sc = sourceColors[(r.source || '').toLowerCase()] || {bg:'rgba(55,98,200,0.1)', color:'#3762c8'};
            var sourceLabel = r.source === 'lgu' ? 'LGU Monitoring' : r.source === 'external' ? 'External (CIMM)' : r.source === 'transport' ? 'Citizen' : r.source === 'maintenance' ? 'Infrastructure' : (r.source || '—');
            badgesHtml += lguBadge(sourceLabel, sc.bg, sc.color);
            var reportType = typeLabels[r.report_type] || r.report_type || '—';
            if (reportType !== '—') {
                badgesHtml += '<span style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;background:rgba(55,98,200,0.1);color:#3762c8;">' + reportType + '</span>';
            }
            document.getElementById('lgu-badges').innerHTML = badgesHtml;

            // Report Information
            var reportGrid = '';
            reportGrid += lguInfoItem('folder', 'Report Type', reportType);
            reportGrid += lguInfoItem('tag', 'Category', r.report_category);
            reportGrid += lguInfoItem('calendar-alt', 'Created Date', formatDate(r.created_at));
            reportGrid += lguInfoItem('sync-alt', 'Last Updated', formatDate(r.updated_at));
            document.getElementById('lgu-report-grid').innerHTML = reportGrid;

            // Source & Department
            var sourceGrid = '';
            sourceGrid += lguInfoItem('server', 'Source', lguBadge(sourceLabel, sc.bg, sc.color));
            sourceGrid += lguInfoItem('building', 'Department', r.department);
            if (r.created_by_name) {
                sourceGrid += lguInfoItem('user', 'Created By', r.created_by_name);
            }
            if (r.approved_at) {
                sourceGrid += lguInfoItem('thumbs-up', 'Approved At', formatDate(r.approved_at));
            }
            if (r.rejected_at) {
                sourceGrid += lguInfoItem('thumbs-down', 'Rejected At', formatDate(r.rejected_at));
            }
            if (r.report_category === 'road') {
                if (r.engineer) {
                    sourceGrid += lguInfoItem('hard-hat', 'Engineer', r.engineer);
                }
                if (r.budget_allocation) {
                    sourceGrid += lguInfoItem('money-bill-wave', 'Budget', '₱ ' + Number(r.budget_allocation).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
                }
                <?php if (!$is_road_supervisor): ?>
                if (r.detected_district) {
                    sourceGrid += lguInfoItem('map-pin', 'District', r.detected_district);
                }
                <?php endif; ?>
                sourceGrid += lguInfoItem('calendar-plus', 'CIMM Starting Date', formatDate(r.cimm_starting_date));
                sourceGrid += lguInfoItem('calendar-check', 'CIMM Estimated End Date', formatDate(r.cimm_estimated_end_date));
            }
            document.getElementById('lgu-source-grid').innerHTML = sourceGrid;

            // Report Creator Information — Road Supervisor portal only.
            var creatorSection = document.getElementById('lgu-creator-section');
            if (creatorSection) {
                if (r.creator_full_name) {
                    var creatorGrid = '';
                    creatorGrid += lguInfoItem('user', 'Full Name', r.creator_full_name);
                    creatorGrid += lguInfoItem('phone', 'Contact Number', r.creator_phone);
                    creatorGrid += lguInfoItem('envelope', 'Email', r.creator_email);
                    document.getElementById('lgu-creator-grid').innerHTML = creatorGrid;
                    creatorSection.style.display = '';
                } else {
                    creatorSection.style.display = 'none';
                }
            }

            // Location
            var locationGrid = '';
            var locVal = r.location || '—';
            if (r.latitude && r.longitude) {
                locVal += '<br><a href="https://www.google.com/maps?q=' + r.latitude + ',' + r.longitude + '" target="_blank" class="vm-map-link"><i class="fas fa-external-link-alt" style="font-size:10px;"></i> View on Map</a>';
            }
            locationGrid += '<div class="lgu-info-item lgu-info-value-full"><div class="lgu-info-icon"><i class="fas fa-map-marker-alt"></i></div><div><div class="lgu-info-label">Location</div><div class="lgu-info-value">' + locVal + '</div></div></div>';
            <?php if ($is_road_supervisor): ?>
            locationGrid += lguInfoItem('map-pin', 'District', r.detected_district);
            <?php endif; ?>
            document.getElementById('lgu-location-grid').innerHTML = locationGrid;

            // View Map button: only shown when the report has a saved
            // coordinate point (latitude / longitude).
            currentLguPoint = (r.latitude != null && r.longitude != null)
                ? [[parseFloat(r.latitude), parseFloat(r.longitude)]]
                : null;
            var lguMapBtn = document.getElementById('lgu-view-map-btn');
            if (lguMapBtn) lguMapBtn.style.display = currentLguPoint ? '' : 'none';
            var lguMapContainer = document.getElementById('lgu-map-container');
            if (lguMapContainer) lguMapContainer.classList.remove('road-map-visible');

            // Description
            document.getElementById('lgu-description').textContent = r.description || 'No description provided.';

            // Attachments
            var images = [];
            if (r.attachments && typeof r.attachments === 'string') {
                try {
                    var parsed = JSON.parse(r.attachments);
                    if (Array.isArray(parsed)) {
                        parsed.forEach(function(a) {
                            if (a.type === 'image' && a.file_path) {
                                images.push(a.file_path);
                            }
                        });
                    }
                } catch(e) {}
            }
            var attachHtml = '';
            if (images.length > 0) {
                attachHtml = '<div style="display:flex;gap:12px;flex-wrap:wrap;">';
                images.forEach(function(path) {
                    attachHtml += '<div style="border-radius:8px;overflow:hidden;max-width:200px;"><img src="../../' + path + '" alt="Report Photo" style="width:100%;height:auto;cursor:pointer;" onclick="openLightbox(this.src)" loading="lazy" onerror="this.closest(\'div\').style.display=\'none\'"></div>';
                });
                attachHtml += '</div>';
            } else {
                attachHtml = '<div style="padding:8px 0;color:#9ca3af;font-size:14px;">No attachments.</div>';
            }
            document.getElementById('lgu-attachments').innerHTML = attachHtml;

            // Timeline
            var timelineGrid = '';
            timelineGrid += lguInfoItem('calendar-check', 'Created', formatDate(r.created_at));
            if (r.approved_at) {
                timelineGrid += lguInfoItem('thumbs-up', 'Approved', formatDate(r.approved_at));
            }
            if (r.rejected_at) {
                timelineGrid += lguInfoItem('thumbs-down', 'Rejected', formatDate(r.rejected_at));
            }
            if (r.updated_at) {
                timelineGrid += lguInfoItem('edit', 'Last Updated', formatDate(r.updated_at));
            }
            document.getElementById('lgu-timeline-grid').innerHTML = timelineGrid;

            openLguModal();
        }

        function openLguModal() {
            var modal = document.getElementById('lguDetailModal');
            if (modal) {
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeLguModal() {
            var modal = document.getElementById('lguDetailModal');
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        // Helper: build a CIMM info item with icon
        function cimmInfoItem(icon, label, value) {
            var displayVal = (value && value !== '—' && value !== null) ? value : '—';
            return '<div class="cimm-info-item"><div class="cimm-info-icon"><i class="fas fa-' + icon + '"></i></div><div><div class="cimm-info-label">' + label + '</div><div class="cimm-info-value">' + displayVal + '</div></div></div>';
        }

        // Helper: CIMM badge HTML
        function cimmBadge(text, bg, color) {
            return '<span style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;background:' + bg + ';color:' + color + ';">' + text + '</span>';
        }

        // View CIMM report details
        function viewCimmReport(id) {
            var r = cimmDataMap[id];
            if (!r) { alert('Report data not found.'); return; }

            var statusStyles = {
                'pending':    {bg:'rgba(249,115,22,0.1)', color:'#c2410c'},
                'approved':   {bg:'rgba(5,150,105,0.1)', color:'#047857'},
                'completed':  {bg:'rgba(5,150,105,0.1)', color:'#047857'},
                'cancelled':  {bg:'rgba(220,38,38,0.08)', color:'#dc2626'},
                'in-progress':{bg:'rgba(55,98,200,0.1)', color:'#3762c8'},
                'resolved':   {bg:'rgba(5,150,105,0.1)', color:'#047857'}
            };
            var pStyles = {
                'high':   {bg:'rgba(220,38,38,0.1)', color:'#dc2626'},
                'medium': {bg:'rgba(217,119,6,0.1)', color:'#d97706'},
                'low':    {bg:'rgba(107,114,128,0.12)', color:'#4b5563'}
            };

            // Header
            document.getElementById('cimm-report-id').textContent = 'Report #' + (r.rep_number || '—');
            document.getElementById('cimm-title').textContent = r.infrastructure || '—';

            var st = (r.status || 'pending').toLowerCase();
            var ss = statusStyles[st] || {bg:'rgba(107,114,128,0.15)', color:'#6b7280'};
            var pp = (r.priority || 'medium').toLowerCase();
            var ps = pStyles[pp] || {bg:'rgba(107,114,128,0.15)', color:'#6b7280'};

            var badgesHtml = cimmBadge(r.status || '—', ss.bg, ss.color);
            if (r.verification_status && r.verification_status !== r.status) {
                badgesHtml += cimmBadge(r.verification_status, 'rgba(55,98,200,0.1)', '#3762c8');
            }
            if (r.approval_status) {
                badgesHtml += cimmBadge(r.approval_status, 'rgba(5,150,105,0.1)', '#047857');
            }
            if (r.cimm_req_id) {
                badgesHtml += '<span style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;background:rgba(107,114,128,0.12);color:#4b5563;">ID: ' + r.cimm_req_id + '</span>';
            }
            badgesHtml += cimmBadge(r.priority || '—', ps.bg, ps.color);
            document.getElementById('cimm-badges').innerHTML = badgesHtml;

            // Project Information
            var projectGrid = '';
            projectGrid += cimmInfoItem('building', 'Infrastructure', r.infrastructure);
            projectGrid += cimmInfoItem('folder', 'Report Type', r.report_type);
            projectGrid += cimmInfoItem('calendar-alt', 'Start Date', formatDate(r.start_date));
            projectGrid += cimmInfoItem('calendar-check', 'End Date', formatDate(r.end_date));
            projectGrid += cimmInfoItem('wallet', 'Budget', formatCurrency(r.budget));
            if (r.budget_allocation) {
                projectGrid += cimmInfoItem('wallet', 'Budget Allocation', formatCurrency(r.budget_allocation));
            }
            document.getElementById('cimm-project-grid').innerHTML = projectGrid;

            // Reporter & Engineer
            var peopleGrid = '';
            peopleGrid += cimmInfoItem('user', 'Reported By', r.reported_by);
            peopleGrid += cimmInfoItem('hard-hat', 'Engineer', r.engineer);
            document.getElementById('cimm-people-grid').innerHTML = peopleGrid;

            // Location
            var locationGrid = '';
            locationGrid += '<div class="cimm-info-item cimm-info-value-full"><div class="cimm-info-icon"><i class="fas fa-map-marker-alt"></i></div><div><div class="cimm-info-label">Location</div><div class="cimm-info-value">' + (r.location || '—') + '</div></div></div>';
            document.getElementById('cimm-location-grid').innerHTML = locationGrid;

            // View Map button: only shown when the report has a saved
            // coordinate point (coord_lat / coord_lng).
            currentCimmPoint = (r.latitude != null && r.longitude != null)
                ? [[parseFloat(r.latitude), parseFloat(r.longitude)]]
                : null;
            var cimmMapBtn = document.getElementById('cimm-view-map-btn');
            if (cimmMapBtn) cimmMapBtn.style.display = currentCimmPoint ? '' : 'none';
            var cimmMapContainer = document.getElementById('cimm-map-container');
            if (cimmMapContainer) cimmMapContainer.classList.remove('road-map-visible');

            // Issue / Notes
            document.getElementById('cimm-issue').textContent = r.issue_notes || 'No notes provided.';

            // Attachments
            document.getElementById('cimm-attachments').innerHTML = '<div style="padding:8px 0;color:#9ca3af;font-size:14px;">No attachments.</div>';
            
            // Attachments — evidence photos CIMM synced for this report
            // (r.evidence_urls, populated by rgmap_map_cimm_row_for_display()
            // from cimm_verification_reports.evidence_json).
            var evidenceUrls = Array.isArray(r.evidence_urls) ? r.evidence_urls : [];
            var attachHtml;
            if (evidenceUrls.length > 0) {
                attachHtml = '<div class="citizen-photo-gallery">';
                evidenceUrls.forEach(function(url) {
                    attachHtml += '<div class="citizen-photo-item"><img src="' + url + '" alt="Evidence photo" onclick="openLightbox(this.src)" loading="lazy" onerror="this.closest(\'.citizen-photo-item\').style.display=\'none\'"></div>';
                });
                attachHtml += '</div>';
            } else {
                attachHtml = '<div style="padding:8px 0;color:#9ca3af;font-size:14px;">No attachments.</div>';
            }
            document.getElementById('cimm-attachments').innerHTML = attachHtml;

            // Timeline & Updates
            var timelineGrid = '';
            timelineGrid += cimmInfoItem('calendar-alt', 'Start Date', formatDate(r.start_date));
            timelineGrid += cimmInfoItem('calendar-check', 'End Date', formatDate(r.end_date));
            if (r.verification_status) {
                timelineGrid += cimmInfoItem('clipboard-check', 'Verification', r.verification_status);
            }
            if (r.approval_status) {
                timelineGrid += cimmInfoItem('thumbs-up', 'Approval', r.approval_status);
            }
            document.getElementById('cimm-timeline-grid').innerHTML = timelineGrid;

            openCimmModal();
        }

        function openCimmModal() {
            var modal = document.getElementById('cimmReportModal');
            if (modal) {
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeCimmModal() {
            var modal = document.getElementById('cimmReportModal');
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        // View SQL reports table details
        function viewSqlReport(repId) {
            var r = sqlDataMap[repId];
            if (!r) { alert('Report data not found.'); return; }

            var status = 'pending';
            if (r.engineer_accepted == 1) status = 'completed';
            else if (r.decline_reason) status = 'cancelled';
            else if (r.decline_reviewed != null) status = r.decline_reviewed == 1 ? 'in-progress' : 'cancelled';

            document.getElementById('dm-budget')?.closest('.detail-row')?.style.removeProperty('display');
            document.getElementById('cimmModalTitle').textContent = 'Report — REP-' + r.rep_id;
            setModalField('dm-rep-number', 'REP-' + r.rep_id);
            setModalField('dm-infrastructure', 'Resource #' + r.res_id);
            setModalField('dm-location', '—');
            setModalField('dm-issue', r.decline_reason || '—');
            setModalField('dm-engineer', r.engineer_id ? 'Engineer #' + r.engineer_id : '—');
            setModalField('dm-reported-by', r.reporter_name);
            setModalField('dm-start-date', formatDate(r.starting_date));
            setModalField('dm-end-date', formatDate(r.estimated_end_date));
            document.getElementById('dm-priority').innerHTML = priorityBadgeHtml(r.priority_lvl);
            setModalField('dm-budget', formatCurrency(r.budget));
            document.getElementById('dm-status').innerHTML = statusBadgeHtml(status, status.charAt(0).toUpperCase() + status.slice(1));

            var extra = '';
            extra += '<div class="detail-row"><div class="detail-label">Created At</div><div class="detail-value">' + formatDate(r.created_at) + '</div></div>';
            extra += '<div class="detail-row"><div class="detail-label">Engineer Accepted</div><div class="detail-value">' + (r.engineer_accepted ? 'Yes' : 'No') + '</div></div>';
            if (r.decline_reviewed != null) {
                extra += '<div class="detail-row"><div class="detail-label">Decline Reviewed</div><div class="detail-value">' + (r.decline_reviewed == 1 ? 'Valid' : 'Invalid') + '</div></div>';
            }
            if (r.decline_review_note) {
                extra += '<div class="detail-row"><div class="detail-label">Decline Review Note</div><div class="detail-value">' + r.decline_review_note + '</div></div>';
            }
            document.getElementById('dm-extra-fields').innerHTML = extra;

            openCimmDetailModal();
        }

        // Dept Reports sort functionality
        let deptSortAsc = true;
        function toggleDeptSort() {
            const table = document.getElementById('deptTable');
            if (!table) return;
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            deptSortAsc = !deptSortAsc;
            rows.sort((a, b) => {
                const aText = a.cells[1]?.textContent.trim() || '';
                const bText = b.cells[1]?.textContent.trim() || '';
                return deptSortAsc ? aText.localeCompare(bText) : bText.localeCompare(aText);
            });
            rows.forEach(row => tbody.appendChild(row));
        }

        // Infra Reports data map (populated from PHP)
        var infraDataMap = {};
        <?php
        if (!empty($infra_reports)):
            foreach ($infra_reports as $ir):
        ?>
        (function() {
            try {
                infraDataMap[<?php echo (int)$ir['id']; ?> + '_' + <?php echo json_encode($ir['source']); ?>] = {
                    id: <?php echo (int)$ir['id']; ?>,
                    source: <?php echo json_encode($ir['source']); ?>,
                    report_id: <?php echo json_encode($ir['report_id']); ?>,
                    title: <?php echo json_encode($ir['title']); ?>,
                    report_type: <?php echo json_encode($ir['report_type']); ?>,
                    department: <?php echo json_encode($ir['department']); ?>,
                    status: <?php echo json_encode($ir['status']); ?>,
                    location: <?php echo json_encode($ir['location']); ?>,
                    start_address: <?php echo json_encode($ir['start_address'] ?? null); ?>,
                    end_address: <?php echo json_encode($ir['end_address'] ?? null); ?>,
                    description: <?php echo json_encode($ir['description']); ?>,
                    created_date: <?php echo json_encode($ir['created_date']); ?>,
                    created_at: <?php echo json_encode($ir['created_at']); ?>,
                    due_date: <?php echo json_encode($ir['due_date']); ?>,
                    estimated_cost: <?php echo json_encode($ir['estimated_cost'] ?? null); ?>,
                    actual_cost: <?php echo json_encode($ir['actual_cost'] ?? null); ?>,
                    engineer: <?php echo json_encode($ir['engineer'] ?? null); ?>,
                    start_date: <?php echo json_encode($ir['start_date'] ?? null); ?>,
                    end_date: <?php echo json_encode($ir['end_date'] ?? null); ?>,
                    budget: <?php echo json_encode($ir['budget'] ?? null); ?>,
maintenance_team: <?php echo json_encode($ir['maintenance_team'] ?? '—'); ?>,
                    attachments: <?php echo json_encode($ir['attachments'] ?? null); ?>,
                    polyline: <?php echo json_encode($ir['polyline'] ?? null); ?>
                };
            } catch(e) {
                console.error('Error adding infra report to map:', e);
            }
        })();
        <?php
            endforeach;
        endif;
        ?>

        // View Map — draws the report's saved location onto a Leaflet map with
        // TomTom tiles, instead of showing raw data. Infrastructure Projects
        // carry a polyline path (ipms_road_projects.polyline_json); CIMM reports
        // carry a single coordinate point (coord_lat / coord_lng).
        const TOMTOM_API_KEY = '<?php echo TOMTOM_API_KEY; ?>';
        let currentInfraPolyline = null;
        let currentCimmPoint = null;
        let currentCitizenPoint = null;
        let currentLguPoint = null;
        let roadMapInstances = {};

        // Shared map renderer for the Infrastructure and CIMM report modals.
        // When asLine is true the points are drawn as a polyline path; otherwise
        // the first point is drawn as a marker.
        function openRoadPathMap(containerId, points, asLine) {
            var container = document.getElementById(containerId);
            if (!container) return;

            if (!Array.isArray(points) || points.length < 1) {
                alert('No map data available for this report.');
                return;
            }
            if (typeof L === 'undefined') {
                alert('Map library failed to load.');
                return;
            }

            // Make the container visible first so Leaflet measures the correct
            // size when the map is created (display:none containers report 0x0).
            container.classList.add('road-map-visible');

            var map = roadMapInstances[containerId];
            if (!map) {
                map = L.map(containerId, { zoomControl: true })
                    .setView([14.6760, 121.0437], 12);
                L.tileLayer('https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?view=Unified&key=' + TOMTOM_API_KEY, {
                    attribution: '© TomTom',
                    maxZoom: 18
                }).addTo(map);
                roadMapInstances[containerId] = map;
            }

            // Remove any path/marker drawn for a previously-viewed report.
            map.eachLayer(function(layer) {
                if (layer instanceof L.Polyline || layer instanceof L.CircleMarker || layer instanceof L.Marker) {
                    map.removeLayer(layer);
                }
            });

            if (asLine && points.length >= 2) {
                L.polyline(points, { color: '#f97316', weight: 5, opacity: 0.9 }).addTo(map);
                map.fitBounds(L.latLngBounds(points).pad(0.25));
            } else {
                var pt = points[0];
                L.circleMarker(pt, { radius: 8, color: '#f97316', fillColor: '#f97316', fillOpacity: 0.85, weight: 2 }).addTo(map);
                map.setView(pt, 14);
            }

            // The modal animates open, which can leave the map with a stale
            // size; force a refresh once the transition has finished.
            setTimeout(function() {
                if (map) map.invalidateSize();
            }, 250);
        }

        function openInfraMap() {
            openRoadPathMap('infra-map-container', currentInfraPolyline, true);
        }

        function openCimmMap() {
            openRoadPathMap('cimm-map-container', currentCimmPoint, false);
        }

        function openCitizenMap() {
            openRoadPathMap('cm-map-container', currentCitizenPoint, false);
        }

        function openLguMap() {
            openRoadPathMap('lgu-map-container', currentLguPoint, false);
        }

        // Infra Reports search functionality
        document.getElementById('infraSearchInput')?.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const table = document.getElementById('infraTable');
            if (!table) return;
            const rows = table.querySelectorAll('tbody tr');
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });

        // Infra Reports sort functionality
        let infraSortAsc = true;
        function toggleInfraSort() {
            const table = document.getElementById('infraTable');
            if (!table) return;
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            infraSortAsc = !infraSortAsc;
            rows.sort((a, b) => {
                const aText = a.cells[1]?.textContent.trim() || '';
                const bText = b.cells[1]?.textContent.trim() || '';
                return infraSortAsc ? aText.localeCompare(bText) : bText.localeCompare(aText);
            });
            rows.forEach(row => tbody.appendChild(row));
        }

        function escapeInfraHtml(str) {
            return String(str == null ? '' : str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function truncateInfraText(str, maxLen) {
            var text = String(str == null ? '' : str);
            if (!text) return '—';
            return text.length > maxLen ? text.slice(0, maxLen) + '...' : text;
        }

        function infraStatusClass(status) {
            if (status === 'approved') return 'approved';
            if (status === 'cancelled') return 'cancelled';
            if (status === 'pending') return 'pending';
            if (status === 'in-progress') return 'in-progress';
            if (status === 'completed') return 'completed';
            return '';
        }

        function infraFilterStatus(status) {
            if (['approved', 'completed'].indexOf(status) !== -1) return 'approved';
            if (status === 'cancelled') return 'rejected';
            return 'pending';
        }

        function rebuildInfraTable(reports) {
            var table = document.getElementById('infraTable');
            if (!table) return;
            var tbody = table.querySelector('tbody');
            if (!tbody) return;

            infraDataMap = {};
            var badge = document.getElementById('infraReportsBadge');
            if (badge) badge.textContent = (reports.length || 0) + ' Reports';

            if (!reports.length) {
                tbody.innerHTML =
                    '<tr><td colspan="6"><div class="infra-empty-state">' +
                    '<div class="infra-empty-icon"><i class="fas fa-hard-hat"></i></div>' +
                    '<h4>No infrastructure projects yet</h4>' +
                    '<p>Synced IPMS projects will appear here for verification.</p>' +
                    '</div></td></tr>';
                return;
            }

            var html = '';
            reports.forEach(function(row) {
                var id = parseInt(row.id, 10) || 0;
                var source = row.source || 'maintenance';
                var status = String(row.status || '');
                var statusLabel = status.replace(/[-_]/g, ' ');
                statusLabel = statusLabel ? statusLabel.charAt(0).toUpperCase() + statusLabel.slice(1) : '—';
                var notes = truncateInfraText(row.issue_notes, 40);
                var canAct = ['approved', 'cancelled'].indexOf(status) === -1;
                var locRaw = row.start_address || '';
                var locCell = locRaw
                    ? '<span title="' + escapeInfraHtml(locRaw) + '">' + escapeInfraHtml(truncateInfraText(locRaw, 40)) + '</span>'
                    : '—';

                infraDataMap[id + '_' + source] = {
                    id: id,
                    source: source,
                    report_id: row.report_id,
                    title: row.title,
                    report_type: row.report_type,
                    department: row.department,
                    status: row.status,
                    location: row.location,
                    start_address: row.start_address || null,
                    end_address: row.end_address || null,
                    description: row.description,
                    created_date: row.created_date,
                    created_at: row.created_at,
                    due_date: row.due_date,
                    estimated_cost: row.estimated_cost || null,
                    actual_cost: row.actual_cost || null,
                    maintenance_team: row.maintenance_team || '—',
                    attachments: row.attachments || null,
                    polyline: row.polyline || null,
                    engineer: row.engineer || null,
                    start_date: row.start_date || null,
                    end_date: row.end_date || null,
                    budget: row.budget || null
                };

                html += '<tr data-id="' + id + '" data-report-id="' + id + '" data-status="' + escapeInfraHtml(infraFilterStatus(status)) + '" data-source="maintenance">';
                html += '<td><div class="infra-action-group">';
                html += '<button class="infra-action-btn" onclick="viewInfraReport(' + id + ', \'' + escapeInfraHtml(source) + '\')"><i class="fas fa-eye"></i> View</button>';
                if (canAct) {
                    html += '<form method="POST" class="infra-action-form" onsubmit="return confirm(\'Are you sure you want to approve this infrastructure project?\');">';
                    html += '<input type="hidden" name="report_id" value="' + id + '">';
                    html += '<input type="hidden" name="source" value="infra">';
                    html += '<button type="submit" name="action" value="approve" class="infra-verify-btn" title="Approve infrastructure project"><i class="fas fa-check"></i> Approve</button>';
                    html += '</form>';
                    html += '<form method="POST" class="infra-action-form" onsubmit="return confirm(\'Are you sure you want to reject this infrastructure project?\');">';
                    html += '<input type="hidden" name="report_id" value="' + id + '">';
                    html += '<input type="hidden" name="source" value="infra">';
                    html += '<button type="submit" name="action" value="reject" class="infra-reject-btn" title="Reject infrastructure project"><i class="fas fa-times"></i> Reject</button>';
                    html += '</form>';
                }
                html += '</div></td>';
                html += '<td>' + escapeInfraHtml(row.report_id || '—') + '</td>';
                html += '<td>' + escapeInfraHtml(row.infrastructure || '—') + '</td>';
                html += '<td>' + locCell + '</td>';
                html += '<td>' + escapeInfraHtml(notes) + '</td>';
                html += '<td><span class="infra-status-badge ' + escapeInfraHtml(infraStatusClass(status)) + '">' + escapeInfraHtml(statusLabel) + '</span></td>';
                html += '</tr>';
            });

            tbody.innerHTML = html;

            var searchInput = document.getElementById('infraSearchInput');
            if (searchInput && searchInput.value) {
                searchInput.dispatchEvent(new Event('input'));
            }
        }

        function syncInfraProjects(btn) {
            if (!btn || btn.disabled) return;
            var originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Syncing...';

            fetch('../api/ipms-road-projects-pull.php', { credentials: 'same-origin' })
                .then(function(r) {
                    return r.json().then(function(j) {
                        return { ok: r.ok, j: j };
                    });
                })
                .then(function(res) {
                    if (!res.ok || !res.j || !res.j.success) {
                        throw new Error((res.j && res.j.message) || 'Sync failed');
                    }
                    return fetch('../api/ipms-infra-panel-data.php', { credentials: 'same-origin' });
                })
                .then(function(r) {
                    return r.json().then(function(j) {
                        return { ok: r.ok, j: j };
                    });
                })
                .then(function(res) {
                    if (!res.ok || !res.j || !res.j.success) {
                        throw new Error((res.j && res.j.message) || 'Failed to refresh infrastructure projects');
                    }
                    rebuildInfraTable(Array.isArray(res.j.reports) ? res.j.reports : []);
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                })
                .catch(function(err) {
                    alert(err.message || 'Sync failed');
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                });
        }

        // Helper: build an infra info item with icon
        function infraInfoItem(icon, label, value) {
            var displayVal = (value && value !== '—' && value !== null) ? value : '—';
            return '<div class="infra-info-item"><div class="infra-info-icon"><i class="fas fa-' + icon + '"></i></div><div><div class="infra-info-label">' + label + '</div><div class="infra-info-value">' + displayVal + '</div></div></div>';
        }

        // Helper: infra badge HTML
        function infraBadge(text, bg, color) {
            return '<span style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;background:' + bg + ';color:' + color + ';">' + text + '</span>';
        }

        // View Infra report details
        function viewInfraReport(id, source) {
            var key = id + '_' + source;
            var r = infraDataMap[key];
            if (!r) { alert('Report data not found.'); return; }

            var typeLabels = {
                'infrastructure_issue': 'Infrastructure Issue',
                'routine': 'Routine Maintenance',
                'emergency': 'Emergency Repair',
                'preventive': 'Preventive Maintenance',
                'corrective': 'Corrective Maintenance',
                'scheduled': 'Scheduled Maintenance'
            };

            var statusStyles = {
                'pending':    {bg:'rgba(249,115,22,0.1)', color:'#c2410c'},
                'approved':   {bg:'rgba(5,150,105,0.1)', color:'#047857'},
                'completed':  {bg:'rgba(5,150,105,0.1)', color:'#047857'},
                'cancelled':  {bg:'rgba(220,38,38,0.08)', color:'#dc2626'},
                'in-progress':{bg:'rgba(55,98,200,0.1)', color:'#3762c8'}
            };

            // Header
            document.getElementById('infra-report-id').textContent = 'Report #' + (r.report_id || '—');
            document.getElementById('infra-title').textContent = r.title || '—';

            var st = (r.status || 'pending').toLowerCase();
            var ss = statusStyles[st] || {bg:'rgba(107,114,128,0.15)', color:'#6b7280'};

            var sourceLabel = source === 'transport' ? 'Road & Transportation' : 'Maintenance';
            var badgesHtml = infraBadge(r.status || '—', ss.bg, ss.color);
            badgesHtml += '<span style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;background:rgba(55,98,200,0.1);color:#3762c8;">' + sourceLabel + '</span>';
            document.getElementById('infra-badges').innerHTML = badgesHtml;

            // Project Information
            var projectGrid = '';
            projectGrid += infraInfoItem('building', 'Report Type', typeLabels[r.report_type] || r.report_type);
            projectGrid += infraInfoItem('folder', 'Department', r.department);
            projectGrid += infraInfoItem('calendar-alt', 'Created Date', formatDate(r.created_date));
            projectGrid += infraInfoItem('calendar-check', 'Due Date', formatDate(r.due_date));
            if (source === 'maintenance') {
                projectGrid += infraInfoItem('wallet', 'Est. Cost', r.estimated_cost ? formatCurrency(r.estimated_cost) + ' (est)' : '—');
                if (r.actual_cost) {
                    projectGrid += infraInfoItem('receipt', 'Actual Cost', formatCurrency(r.actual_cost));
                }
            } else {
                projectGrid += infraInfoItem('wallet', 'Est. Cost', r.estimated_cost ? formatCurrency(r.estimated_cost) : '—');
            }
            document.getElementById('infra-project-grid').innerHTML = projectGrid;

            // Assigned Engineer & Schedule
            var scheduleGrid = '';
            scheduleGrid += infraInfoItem('hard-hat', 'Engineer', r.engineer || '—');
            scheduleGrid += infraInfoItem('calendar-plus', 'Start Date', formatDate(r.start_date));
            scheduleGrid += infraInfoItem('calendar-minus', 'End Date', formatDate(r.end_date));
            scheduleGrid += infraInfoItem('money-bill-wave', 'Budget', r.budget ? formatCurrency(r.budget) : '—');
            document.getElementById('infra-schedule-grid').innerHTML = scheduleGrid;

            // Reporter & Department
            var peopleGrid = '';
            peopleGrid += infraInfoItem('hard-hat', 'Maintenance Team', r.maintenance_team);
            document.getElementById('infra-people-grid').innerHTML = peopleGrid;

            // Location — start/end addresses from TomTom reverse geocode
            var locationGrid = '';
            locationGrid += infraInfoItem('map-marker-alt', 'Start Address', r.start_address || '—');
            locationGrid += infraInfoItem('map-marker', 'End Address', r.end_address || '—');
            document.getElementById('infra-location-grid').innerHTML = locationGrid;

            // View Map button: only shown when the project has a saved road
            // path (polyline_json). Stores the drawn path for the map.
            currentInfraPolyline = (Array.isArray(r.polyline) && r.polyline.length >= 2)
                ? r.polyline.map(function(pt) { return [pt[0], pt[1]]; })
                : null;
            var mapBtn = document.getElementById('infra-view-map-btn');
            if (mapBtn) mapBtn.style.display = currentInfraPolyline ? '' : 'none';
            var mapContainer = document.getElementById('infra-map-container');
            if (mapContainer) mapContainer.classList.remove('road-map-visible');

            // Description
            document.getElementById('infra-description').textContent = r.description || 'No description provided.';

            // Attachments
            var images = [];
            if (r.attachments && typeof r.attachments === 'string') {
                try {
                    var parsed = JSON.parse(r.attachments);
                    if (Array.isArray(parsed)) {
                        parsed.forEach(function(a) {
                            if (a.type === 'image' && a.file_path) {
                                images.push(a.file_path);
                            }
                        });
                    }
                } catch(e) {}
            }
            var attachHtml = '';
            if (images.length > 0) {
                attachHtml = '<div class="citizen-photo-gallery">';
                images.forEach(function(path) {
                    attachHtml += '<div class="citizen-photo-item"><img src="../../' + path + '" alt="Report Photo" onclick="openLightbox(this.src)" loading="lazy" onerror="this.closest(\'.citizen-photo-item\').style.display=\'none\'"></div>';
                });
                attachHtml += '</div>';
            } else {
                attachHtml = '<div style="padding:8px 0;color:#9ca3af;font-size:14px;">No attachments.</div>';
            }
            document.getElementById('infra-attachments').innerHTML = attachHtml;

            // Timeline & Updates
            var timelineGrid = '';
            timelineGrid += infraInfoItem('calendar-plus', 'Created', formatDate(r.created_at));
            timelineGrid += infraInfoItem('calendar-alt', 'Created Date', formatDate(r.created_date));
            timelineGrid += infraInfoItem('calendar-check', 'Due Date', formatDate(r.due_date));
            if (r.updated_at) {
                timelineGrid += infraInfoItem('edit', 'Last Updated', formatDate(r.updated_at));
            }
            if (r.approved_at) {
                timelineGrid += infraInfoItem('thumbs-up', 'Approved', formatDate(r.approved_at));
            }
            if (r.rejected_at) {
                timelineGrid += infraInfoItem('thumbs-down', 'Rejected', formatDate(r.rejected_at));
            }
            document.getElementById('infra-timeline-grid').innerHTML = timelineGrid;

            openInfraModal();
        }

        function openInfraModal() {
            var modal = document.getElementById('infraReportModal');
            if (modal) {
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeInfraModal() {
            var modal = document.getElementById('infraReportModal');
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        // Citizen Reports data map (current page; AJAX merges additional pages)
        var citizenDataMap = <?php echo json_encode(vm_build_citizen_rows_json($citizen_reports_list), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

        // LGU Reports data map (current page; AJAX merges additional pages)
        var lguDataMap = <?php echo json_encode(vm_build_lgu_rows_json($lgu_reports_list, $lgu_creator_map, $is_transport_supervisor, $is_road_supervisor), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;


        // Citizen Reports sort functionality
        let citizenSortAsc = true;
        function toggleCitizenSort() {
            const table = document.getElementById('citizenTable');
            if (!table) return;
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            citizenSortAsc = !citizenSortAsc;
            rows.sort((a, b) => {
                const aText = a.cells[2]?.textContent.trim() || '';
                const bText = b.cells[2]?.textContent.trim() || '';
                return citizenSortAsc ? aText.localeCompare(bText) : bText.localeCompare(aText);
            });
            rows.forEach(row => tbody.appendChild(row));
        }

        // Helper: build a citizen info item with icon
        function cmInfoItem(icon, label, value) {
            var displayVal = (value && value !== '—') ? value : '—';
            return '<div class="citizen-info-item"><div class="citizen-info-icon"><i class="fas fa-' + icon + '"></i></div><div><div class="citizen-info-label">' + label + '</div><div class="citizen-info-value">' + displayVal + '</div></div></div>';
        }

        // Helper: badge HTML
        function cmBadge(text, bg, color) {
            return '<span style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;background:' + bg + ';color:' + color + ';">' + text + '</span>';
        }

        // View Citizen report details
        function viewCitizenReport(id) {
            var r = citizenDataMap[id];
            if (!r) { alert('Report data not found.'); return; }

            var typeLabels = {
                'traffic_jam': 'Traffic Jam',
                'accident': 'Accident',
                'road_closure': 'Road Closure',
                'traffic_light_outage': 'Traffic Light',
                'congestion': 'Congestion',
                'parking_violation': 'Parking Violation',
                'public_transport_issue': 'Public Transport',
                'pothole': 'Pothole',
                'flooding': 'Flooding',
                'road_damage': 'Road Damage',
                'accident_hotspot': 'Accident Hotspot',
                'street_light': 'Street Light',
                'illegal_dumping': 'Illegal Dumping',
                'other': 'Other'
            };

            var statusStyles = {
                'pending':    {bg:'rgba(249,115,22,0.1)', color:'#c2410c'},
                'approved':   {bg:'rgba(5,150,105,0.1)', color:'#047857'},
                'completed':  {bg:'rgba(5,150,105,0.1)', color:'#047857'},
                'cancelled':  {bg:'rgba(220,38,38,0.08)', color:'#dc2626'},
                'in-progress':{bg:'rgba(55,98,200,0.1)', color:'#3762c8'}
            };
            var pStyles = {
                'high':   {bg:'rgba(220,38,38,0.1)', color:'#dc2626'},
                'medium': {bg:'rgba(217,119,6,0.1)', color:'#d97706'},
                'low':    {bg:'rgba(107,114,128,0.12)', color:'#4b5563'}
            };

            // Header
            document.getElementById('cm-report-id').textContent = 'Report #' + (r.report_id || '—');
            document.getElementById('cm-title').textContent = r.title || '—';

            var st = (r.status || 'pending').toLowerCase();
            var ss = statusStyles[st] || {bg:'rgba(107,114,128,0.15)', color:'#6b7280'};
            var pp = (r.priority || 'medium').toLowerCase();
            var ps = pStyles[pp] || {bg:'rgba(107,114,128,0.15)', color:'#6b7280'};

            var badgesHtml = cmBadge(r.status || '—', ss.bg, ss.color);
            badgesHtml += cmBadge(r.priority || '—', ps.bg, ps.color);
            var reportType = typeLabels[r.report_type] || r.report_type || '—';
            if (reportType !== '—') {
                badgesHtml += '<span style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;background:rgba(55,98,200,0.1);color:#3762c8;">' + reportType + '</span>';
            }
            document.getElementById('cm-badges').innerHTML = badgesHtml;

            // Report Information
            var reportGrid = '';
            reportGrid += cmInfoItem('folder', 'Report Type', reportType);
            reportGrid += cmInfoItem('tag', 'Report Category', r.report_category);
            reportGrid += cmInfoItem('building', 'Department', r.department);
            reportGrid += cmInfoItem('calendar-alt', 'Created Date', formatDate(r.created_at));
            reportGrid += cmInfoItem('sync-alt', 'Last Updated', formatDate(r.updated_at));
            document.getElementById('cm-report-grid').innerHTML = reportGrid;

            // Reporter Information
            var reporterGrid = '';
            reporterGrid += cmInfoItem('user', 'Name', r.reporter_name);
            reporterGrid += cmInfoItem('envelope', 'Email', r.reporter_email);
            reporterGrid += cmInfoItem('phone', 'Phone', r.reporter_phone);
            document.getElementById('cm-reporter-grid').innerHTML = reporterGrid;

            // Location
            var locationGrid = '';
            var locVal = r.location || '—';
            if (r.latitude && r.longitude) {
                locVal += '<br><a href="https://www.google.com/maps?q=' + r.latitude + ',' + r.longitude + '" target="_blank" class="vm-map-link"><i class="fas fa-external-link-alt" style="font-size:10px;"></i> View on Map</a>';
            }
            locationGrid += '<div class="citizen-info-item citizen-info-value-full"><div class="citizen-info-icon"><i class="fas fa-map-marker-alt"></i></div><div><div class="citizen-info-label">Location</div><div class="citizen-info-value">' + locVal + '</div></div></div>';
            document.getElementById('cm-location-grid').innerHTML = locationGrid;

            // View Map button: only shown when the report has a saved
            // coordinate point (latitude / longitude).
            currentCitizenPoint = (r.latitude != null && r.longitude != null)
                ? [[parseFloat(r.latitude), parseFloat(r.longitude)]]
                : null;
            var cmMapBtn = document.getElementById('cm-view-map-btn');
            if (cmMapBtn) cmMapBtn.style.display = currentCitizenPoint ? '' : 'none';
            var cmMapContainer = document.getElementById('cm-map-container');
            if (cmMapContainer) cmMapContainer.classList.remove('road-map-visible');

            // Description
            document.getElementById('cm-description').textContent = r.description || 'No description provided.';

            // Attachments
            var images = [];
            if (r.attachments && typeof r.attachments === 'string') {
                try {
                    var parsed = JSON.parse(r.attachments);
                    if (Array.isArray(parsed)) {
                        parsed.forEach(function(a) {
                            if (a.type === 'image' && a.file_path) {
                                images.push(a.file_path);
                            }
                        });
                    }
                } catch(e) {}
            }
            if (images.length === 0 && r.image_path) {
                images.push(r.image_path);
            }
            var attachHtml = '';
            if (images.length > 0) {
                attachHtml = '<div class="citizen-photo-gallery">';
                images.forEach(function(path) {
                    attachHtml += '<div class="citizen-photo-item"><img src="../../' + path + '" alt="Report Photo" onclick="openLightbox(this.src)" loading="lazy" onerror="this.closest(\'.citizen-photo-item\').style.display=\'none\'"></div>';
                });
                attachHtml += '</div>';
            } else {
                attachHtml = '<div style="padding:8px 0;color:#9ca3af;font-size:14px;">No attachments.</div>';
            }
            document.getElementById('cm-attachments').innerHTML = attachHtml;

            // Timeline
            var timelineGrid = '';
            timelineGrid += cmInfoItem('calendar-check', 'Created', formatDate(r.created_at));
            if (r.approved_at) {
                timelineGrid += cmInfoItem('thumbs-up', 'Approved', formatDate(r.approved_at));
            }
            if (r.rejected_at) {
                timelineGrid += cmInfoItem('thumbs-down', 'Rejected', formatDate(r.rejected_at));
            }
            if (r.updated_at) {
                timelineGrid += cmInfoItem('edit', 'Last Updated', formatDate(r.updated_at));
            }
            document.getElementById('cm-timeline-grid').innerHTML = timelineGrid;

            openCitizenModal();
        }

        function openCitizenModal() {
            var modal = document.getElementById('citizenDetailModal');
            if (modal) {
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeCitizenModal() {
            var modal = document.getElementById('citizenDetailModal');
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        // Close modal after form submission in modal (if element exists)
        var modalFooterEl = document.getElementById('modalFooter');
        if (modalFooterEl) {
            modalFooterEl.addEventListener('submit', function(e) {
                const form = e.target.closest('form');
                if (form) {
                    setTimeout(function() { if (typeof closeModal === 'function') closeModal(); }, 100);
                }
            });
        }

        console.log('Script finished executing');
        console.log('toggleDetails is', typeof toggleDetails);
        console.log('viewCimmReport is', typeof viewCimmReport);
        console.log('viewInfraReport is', typeof viewInfraReport);

    </script>
    

    <!-- CIMM / SQL Report Detail Modal -->
    <div id="cimmDetailModal" class="modal-overlay" onclick="if(event.target===this)closeCimmDetailModal()">
        <div class="modal-content" style="max-width:700px;">
            <div class="modal-header">
                <h2 id="cimmModalTitle">Report Details</h2>
                <button class="modal-close" onclick="closeCimmDetailModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="cimmModalBody">
                    <div class="detail-row">
                        <div class="detail-label">Report #</div>
                        <div class="detail-value" id="dm-rep-number">—</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Infrastructure</div>
                        <div class="detail-value" id="dm-infrastructure">—</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Location</div>
                        <div class="detail-value" id="dm-location">—</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Issue / Notes</div>
                        <div class="detail-value" id="dm-issue">—</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Engineer</div>
                        <div class="detail-value" id="dm-engineer">—</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Reported By</div>
                        <div class="detail-value" id="dm-reported-by">—</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Start Date</div>
                        <div class="detail-value" id="dm-start-date">—</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">End Date</div>
                        <div class="detail-value" id="dm-end-date">—</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Priority</div>
                        <div class="detail-value" id="dm-priority">—</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Budget</div>
                        <div class="detail-value" id="dm-budget">—</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Status</div>
                        <div class="detail-value" id="dm-status">—</div>
                    </div>
                    <div id="dm-extra-fields"></div>
                </div>
            </div>
            <div class="modal-footer" id="cimmModalFooter">
                <button type="button" class="btn-review" onclick="closeCimmDetailModal()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <!-- Photo Lightbox -->
    <div id="photoLightbox" class="lightbox-overlay" onclick="closeLightbox(event)">
        <button class="lightbox-close" onclick="closeLightbox(event)">&times;</button>
        <img id="lightboxImage" src="" alt="Full size photo">
    </div>

    <!-- Citizen Report Detail Modal -->
    <div id="citizenDetailModal" class="citizen-modal-overlay" onclick="if(event.target===this)closeCitizenModal()">
        <div class="citizen-modal-content">
            <div class="citizen-modal-header">
                <div class="citizen-modal-header-top">
                    <div class="citizen-modal-title-area">
                        <div class="citizen-modal-report-id" id="cm-report-id">—</div>
                        <h3 class="citizen-modal-title" id="cm-title">—</h3>
                        <div class="citizen-modal-badges" id="cm-badges"></div>
                    </div>
                    <button class="citizen-modal-close" onclick="closeCitizenModal()">&times;</button>
                </div>
            </div>
            <div class="citizen-modal-body" id="cm-body">
                <!-- Report Information -->
                <div class="citizen-modal-section" id="cm-section-report">
                    <div class="citizen-modal-section-title"><i class="fas fa-info-circle"></i> Report Information</div>
                    <div class="citizen-info-grid" id="cm-report-grid"></div>
                </div>
                <!-- Reporter Information -->
                <div class="citizen-modal-section" id="cm-section-reporter">
                    <div class="citizen-modal-section-title"><i class="fas fa-user"></i> Reporter Information</div>
                    <div class="citizen-info-grid" id="cm-reporter-grid"></div>
                </div>
                <!-- Location -->
                <div class="citizen-modal-section" id="cm-section-location">
                    <div class="citizen-modal-section-title"><i class="fas fa-map-marker-alt"></i> Location
                        <button type="button" id="cm-view-map-btn" class="citizen-view-map-btn" style="display:none;" onclick="openCitizenMap()">
                            <i class="fas fa-map-marked-alt"></i> View Map
                        </button>
                    </div>
                    <div class="citizen-info-grid" id="cm-location-grid"></div>
                    <div class="road-map-container" id="cm-map-container"></div>
                </div>
                <!-- Description -->
                <div class="citizen-modal-section" id="cm-section-description">
                    <div class="citizen-modal-section-title"><i class="fas fa-align-left"></i> Report Description</div>
                    <div class="citizen-description-text" id="cm-description">—</div>
                </div>
                <!-- Attachments -->
                <div class="citizen-modal-section" id="cm-section-attachments">
                    <div class="citizen-modal-section-title"><i class="fas fa-paperclip"></i> Attachments</div>
                    <div id="cm-attachments"></div>
                </div>
                <!-- Timeline -->
                <div class="citizen-modal-section" id="cm-section-timeline">
                    <div class="citizen-modal-section-title"><i class="fas fa-clock"></i> Timeline</div>
                    <div class="citizen-info-grid" id="cm-timeline-grid"></div>
                </div>
            </div>
            <div class="citizen-modal-footer">
                <button type="button" class="citizen-modal-btn-close" onclick="closeCitizenModal()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <!-- CIMM Report Detail Modal -->
    <div id="cimmReportModal" class="cimm-modal-overlay" onclick="if(event.target===this)closeCimmModal()">
        <div class="cimm-modal-content">
            <div class="cimm-modal-header">
                <div class="cimm-modal-header-top">
                    <div class="cimm-modal-title-area">
                        <div class="cimm-modal-report-id" id="cimm-report-id">—</div>
                        <h3 class="cimm-modal-title" id="cimm-title">—</h3>
                        <div class="cimm-modal-badges" id="cimm-badges"></div>
                    </div>
                    <button class="cimm-modal-close" onclick="closeCimmModal()">&times;</button>
                </div>
            </div>
            <div class="cimm-modal-body">
                <!-- Project Information -->
                <div class="cimm-modal-section">
                    <div class="cimm-modal-section-title"><i class="fas fa-info-circle"></i> Project Information</div>
                    <div class="cimm-info-grid" id="cimm-project-grid"></div>
                </div>
                <!-- Reporter / Engineer Information -->
                <div class="cimm-modal-section">
                    <div class="cimm-modal-section-title"><i class="fas fa-user"></i> Reporter &amp; Engineer</div>
                    <div class="cimm-info-grid" id="cimm-people-grid"></div>
                </div>
                <!-- Location -->
                <div class="cimm-modal-section">
                    <div class="cimm-modal-section-title"><i class="fas fa-map-marker-alt"></i> Location
                        <button type="button" id="cimm-view-map-btn" class="cimm-view-map-btn" style="display:none;" onclick="openCimmMap()">
                            <i class="fas fa-map-marked-alt"></i> View Map
                        </button>
                    </div>
                    <div class="cimm-info-grid" id="cimm-location-grid"></div>
                    <div class="road-map-container" id="cimm-map-container"></div>
                </div>
                <!-- Issue / Notes -->
                <div class="cimm-modal-section">
                    <div class="cimm-modal-section-title"><i class="fas fa-align-left"></i> Issue / Notes</div>
                    <div class="cimm-description-text" id="cimm-issue">—</div>
                </div>
                <!-- Attachments -->
                <div class="cimm-modal-section">
                    <div class="cimm-modal-section-title"><i class="fas fa-paperclip"></i> Attachments</div>
                    <div id="cimm-attachments"></div>
                </div>
                <!-- Timeline / Updates -->
                <div class="cimm-modal-section">
                    <div class="cimm-modal-section-title"><i class="fas fa-clock"></i> Timeline &amp; Updates</div>
                    <div class="cimm-info-grid" id="cimm-timeline-grid"></div>
                </div>
            </div>
            <div class="cimm-modal-footer">
                <button type="button" class="cimm-modal-btn-close" onclick="closeCimmModal()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <!-- Infra Report Detail Modal -->
    <div id="infraReportModal" class="infra-modal-overlay" onclick="if(event.target===this)closeInfraModal()">
        <div class="infra-modal-content">
            <div class="infra-modal-header">
                <div class="infra-modal-header-top">
                    <div class="infra-modal-title-area">
                        <div class="infra-modal-report-id" id="infra-report-id">—</div>
                        <h3 class="infra-modal-title" id="infra-title">—</h3>
                        <div class="infra-modal-badges" id="infra-badges"></div>
                    </div>
                    <button class="infra-modal-close" onclick="closeInfraModal()">&times;</button>
                </div>
            </div>
            <div class="infra-modal-body">
                <!-- Project Information -->
                <div class="infra-modal-section">
                    <div class="infra-modal-section-title"><i class="fas fa-info-circle"></i> Project Information</div>
                    <div class="infra-info-grid" id="infra-project-grid"></div>
                </div>
                <!-- Engineer & Schedule -->
                <div class="infra-modal-section">
                    <div class="infra-modal-section-title"><i class="fas fa-calendar-alt"></i> Engineer &amp; Schedule</div>
                    <div class="infra-info-grid" id="infra-schedule-grid"></div>
                </div>
                <!-- Reporter / Department -->
                <div class="infra-modal-section">
                    <div class="infra-modal-section-title"><i class="fas fa-user"></i> Reporter &amp; Department</div>
                    <div class="infra-info-grid" id="infra-people-grid"></div>
                </div>
                <!-- Location -->
                <div class="infra-modal-section">
                    <div class="infra-modal-section-title"><i class="fas fa-map-marker-alt"></i> Location
                        <button type="button" id="infra-view-map-btn" class="infra-view-map-btn" style="display:none;" onclick="openInfraMap()">
                            <i class="fas fa-map-marked-alt"></i> View Map
                        </button>
                    </div>
                    <div class="infra-info-grid" id="infra-location-grid"></div>
                    <div class="road-map-container" id="infra-map-container"></div>
                </div>
                <!-- Description -->
                <div class="infra-modal-section">
                    <div class="infra-modal-section-title"><i class="fas fa-align-left"></i> Description</div>
                    <div class="infra-description-text" id="infra-description">—</div>
                </div>
                <!-- Attachments -->
                <div class="infra-modal-section">
                    <div class="infra-modal-section-title"><i class="fas fa-paperclip"></i> Attachments</div>
                    <div id="infra-attachments"></div>
                </div>
                <!-- Timeline / Updates -->
                <div class="infra-modal-section">
                    <div class="infra-modal-section-title"><i class="fas fa-clock"></i> Timeline &amp; Updates</div>
                    <div class="infra-info-grid" id="infra-timeline-grid"></div>
                </div>
            </div>
            <div class="infra-modal-footer">
                <button type="button" class="infra-modal-btn-close" onclick="closeInfraModal()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <!-- LGU Report Detail Modal -->
    <div id="lguDetailModal" class="lgu-modal-overlay" onclick="if(event.target===this)closeLguModal()">
        <div class="lgu-modal-content">
            <div class="lgu-modal-header">
                <div class="lgu-modal-header-top">
                    <div class="lgu-modal-title-area">
                        <div class="lgu-modal-report-id" id="lgu-report-id">—</div>
                        <h3 class="lgu-modal-title" id="lgu-title">—</h3>
                        <div class="lgu-modal-badges" id="lgu-badges"></div>
                    </div>
                    <button class="lgu-modal-close" onclick="closeLguModal()">&times;</button>
                </div>
            </div>
            <div class="lgu-modal-body">
                <!-- Report Information -->
                <div class="lgu-modal-section">
                    <div class="lgu-modal-section-title"><i class="fas fa-info-circle"></i> Report Information</div>
                    <div class="lgu-info-grid" id="lgu-report-grid"></div>
                </div>
                <!-- Source & Department -->
                <div class="lgu-modal-section">
                    <div class="lgu-modal-section-title"><i class="fas fa-building"></i> Source &amp; Department</div>
                    <div class="lgu-info-grid" id="lgu-source-grid"></div>
                </div>
                <!-- Report Creator (Road Supervisor portal only) -->
                <div class="lgu-modal-section" id="lgu-creator-section" style="display:none;">
                    <div class="lgu-modal-section-title"><i class="fas fa-user-circle"></i> Report Creator Information</div>
                    <div class="lgu-info-grid" id="lgu-creator-grid"></div>
                </div>
                <!-- Location -->
                <div class="lgu-modal-section">
                    <div class="lgu-modal-section-title"><i class="fas fa-map-marker-alt"></i> Location
                        <button type="button" id="lgu-view-map-btn" class="lgu-view-map-btn" style="display:none;" onclick="openLguMap()">
                            <i class="fas fa-map-marked-alt"></i> View Map
                        </button>
                    </div>
                    <div class="lgu-info-grid" id="lgu-location-grid"></div>
                    <div class="road-map-container" id="lgu-map-container"></div>
                </div>
                <!-- Description -->
                <div class="lgu-modal-section">
                    <div class="lgu-modal-section-title"><i class="fas fa-align-left"></i> Description</div>
                    <div class="lgu-description-text" id="lgu-description">—</div>
                </div>
                <!-- Attachments -->
                <div class="lgu-modal-section">
                    <div class="lgu-modal-section-title"><i class="fas fa-paperclip"></i> Attachments</div>
                    <div id="lgu-attachments"></div>
                </div>
                <!-- Timeline -->
                <div class="lgu-modal-section">
                    <div class="lgu-modal-section-title"><i class="fas fa-clock"></i> Timeline &amp; Updates</div>
                    <div class="lgu-info-grid" id="lgu-timeline-grid"></div>
                </div>
            </div>
            <div class="lgu-modal-footer">
                <button type="button" class="lgu-modal-btn-close" onclick="closeLguModal()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <!-- Session Timeout Modal -->
    <div id="sessionTimeoutOverlay" class="t-modal-overlay" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; z-index:10000;"></div>
    <div id="sessionTimeoutModal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); background:#fff; border-radius:12px; padding:32px; z-index:10001; width:400px; max-width:90vw; box-shadow:0 16px 48px rgba(0,0,0,0.3); text-align:center;">
        <div class="t-text-danger" style="font-size:48px; margin-bottom:16px;">
            <i class="fas fa-clock"></i>
        </div>
        <h3 style="margin:0 0 8px; font-size:20px; color:#1a1a2e;">Session Expiring</h3>
        <p class="t-text-secondary" style="margin:0 0 20px; font-size:14px;">
            Your session will expire in <strong><span id="sessionCountdown">60</span></strong> seconds due to inactivity.
        </p>
        <div style="display:flex; gap:12px; justify-content:center;">
            <button id="extendSessionBtn" class="t-gradient-primary" style="padding:10px 24px; color:#fff; border:none; border-radius:8px; font-size:14px; cursor:pointer; font-weight:600;">Extend Session</button>
            <button id="logoutSessionBtn" class="t-gradient-danger" style="padding:10px 24px; color:#fff; border:none; border-radius:8px; font-size:14px; cursor:pointer; font-weight:600;">Log Out</button>
        </div>
    </div>

    <!-- Session timeout data -->
    <script id="sessionTimeoutData" data-timeout="<?php echo $session_timeout; ?>"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="../../js/session-timeout.js"></script>
</body>
</html>

<?php
// Helper functions
function getReportIcon($reportType) {
    $icons = [
        'monthly' => 'calendar',
        'traffic' => 'traffic-light',
        'maintenance' => 'tools',
        'safety' => 'shield-alt',
        'budget' => 'dollar-sign',
        'road_damage' => 'road',
        'infrastructure_issue' => 'map-marker-alt',
        'traffic_violation' => 'car-crash',
        'maintenance_request' => 'wrench',
        'routine' => 'wrench',
        'emergency' => 'exclamation-triangle',
        'preventive' => 'shield-alt',
        'corrective' => 'tools',
        'scheduled' => 'calendar-alt'
    ];
    
    return $icons[$reportType] ?? 'file-alt';
}

function getTimeAgo($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M d, Y', $time);
    }
}

// Determine if a report can be verified locally
// Road reports created by this LGU (local source) must go to external Engineering Office
// Transportation reports and external reports can be verified here
// (canVerifyReport lives in verification_panel_pagination.php)

function getActivityTitle($activity) {
    $status = $activity['status'];
    $title = $activity['title'];
    $source = ucfirst($activity['source']);
    
    switch ($status) {
        case 'completed':
            return $source . ' Report: ' . $title . ' - Completed';
        case 'approved':
            return $source . ' Report: ' . $title . ' - Approved';
        case 'cancelled':
            return $source . ' Report: ' . $title . ' - Cancelled';
        case 'pending':
            return $source . ' Report: ' . $title . ' - Pending';
        case 'in-progress':
            return $source . ' Report: ' . $title . ' - In Progress';
        default:
            return $source . ' Report: ' . $title;
    }
}
?>