<?php
/**
 * CIMM Verification Data Access Layer
 *
 * Provides PDO connectivity and helper functions for the
 * cimm_verification_reports table used by the admin verification
 * monitoring page.
 *
 * Reports are populated live from the CIMM system (EXEQUIELKENT/LGU) via:
 *   - cimm-reports-webhook.php (push): CIMM POSTs here on create/validate/reject
 *   - cimm-reports-pull.php    (pull): optional cron catch-up, fetches from
 *                                       CIMM's export API and replays into the
 *                                       webhook above
 *
 * Schema note: this table originally only stored a small subset of fields.
 * It has since been expanded to match the full payload CIMM's sync layer
 * sends (see cimm_rgmap_sync.php -> cimm_rgmap_fetch_report() in the CIMM
 * repo). rgmap_ensure_cimm_verification_table() below both creates the table
 * fresh and migrates any pre-existing (older, narrower) copy of it, so this
 * is safe to deploy on top of an install that's already been running.
 */

/**
 * Create (or reuse) a PDO connection pointing at the same database
 * the mysqli $conn in config.php already uses.
 */
function rgmap_verification_pdo(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET time_zone = '+08:00'");
    return $pdo;
}

/**
 * Ensure cimm_verification_reports exists with the full schema CIMM's sync
 * payload expects, migrating an older/narrower copy of the table in place
 * if one already exists (idempotent — safe to call on every request).
 */
function rgmap_ensure_cimm_verification_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS cimm_verification_reports (
        id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        cimm_req_id         INT UNSIGNED NOT NULL,
        cimm_rep_id         INT UNSIGNED NULL,
        reference_code      VARCHAR(32)  NOT NULL,
        report_reference    VARCHAR(32)  NULL,
        infrastructure      VARCHAR(120) NOT NULL,
        location            VARCHAR(255) NOT NULL,
        issue               TEXT         NOT NULL,
        reporter_name       VARCHAR(120) NULL,
        contact_number      VARCHAR(30)  NULL,
        email               VARCHAR(180) NULL,
        district            VARCHAR(80)  NULL,
        coord_lat           DECIMAL(10,7) NULL,
        coord_lng           DECIMAL(10,7) NULL,
        cprf_facility_id    INT UNSIGNED NULL,
        cprf_facility_name  VARCHAR(150) NULL,
        approval_status     VARCHAR(32)  NOT NULL DEFAULT 'Pending',
        rejection_reason    TEXT         NULL,
        resolution_status   VARCHAR(64)  NULL,
        resolution_note     TEXT         NULL,
        resolved_at         DATETIME     NULL,
        priority            VARCHAR(32)  DEFAULT 'medium',
        engineer            VARCHAR(150) NULL,
        budget              DECIMAL(15,2) NULL,
        starting_date       DATE         NULL,
        estimated_end_date  DATE         NULL,
        submitted_at        DATETIME     NULL,
        evidence_json       LONGTEXT     NULL,
        ai_json             LONGTEXT     NULL,
        portal_url          VARCHAR(500) NULL,
        verification_status VARCHAR(30)  NOT NULL DEFAULT 'Pending Review',
        verification_note   TEXT         NULL,
        verified_by         INT UNSIGNED NULL,
        verified_at         DATETIME     NULL,
        payload_json        LONGTEXT     NULL,
        last_event          VARCHAR(32)  NOT NULL DEFAULT 'upsert',
        synced_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cimm_req (cimm_req_id),
        INDEX idx_verification_status (verification_status),
        INDEX idx_approval_status (approval_status),
        INDEX idx_submitted (submitted_at),
        INDEX idx_infrastructure (infrastructure)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Migrate an older/narrower copy of this table (the original mock-data
    // schema only had a handful of these columns) up to the full schema.
    // MariaDB supports "ADD COLUMN IF NOT EXISTS", so this is a no-op on a
    // table that's already up to date.
    $columns = [
        "cimm_rep_id INT UNSIGNED NULL AFTER cimm_req_id",
        "report_reference VARCHAR(32) NULL AFTER reference_code",
        "contact_number VARCHAR(30) NULL AFTER reporter_name",
        "email VARCHAR(180) NULL AFTER contact_number",
        "district VARCHAR(80) NULL AFTER email",
        "coord_lat DECIMAL(10,7) NULL AFTER district",
        "coord_lng DECIMAL(10,7) NULL AFTER coord_lat",
        "engineer VARCHAR(150) NULL AFTER priority",
        "cprf_facility_id INT UNSIGNED NULL AFTER cprf_facility_name",
        "rejection_reason TEXT NULL AFTER approval_status",
        "resolution_status VARCHAR(64) NULL AFTER rejection_reason",
        "resolution_note TEXT NULL AFTER resolution_status",
        "resolved_at DATETIME NULL AFTER resolution_note",
        "submitted_at DATETIME NULL AFTER estimated_end_date",
        "evidence_json LONGTEXT NULL AFTER submitted_at",
        "ai_json LONGTEXT NULL AFTER evidence_json",
        "portal_url VARCHAR(500) NULL AFTER ai_json",
        "verification_note TEXT NULL AFTER verification_status",
        "verified_by INT UNSIGNED NULL AFTER verification_note",
        "verified_at DATETIME NULL AFTER verified_by",
        "payload_json LONGTEXT NULL AFTER verified_at",
        "last_event VARCHAR(32) NOT NULL DEFAULT 'upsert' AFTER payload_json",
        "synced_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER last_event",
    ];
    foreach ($columns as $def) {
        try {
            $pdo->exec("ALTER TABLE cimm_verification_reports ADD COLUMN IF NOT EXISTS $def");
        } catch (\Throwable $e) {
            error_log('cimm_verification_reports column migration warning: ' . $e->getMessage());
        }
    }

    // The original schema only had a plain (non-unique) index on cimm_req_id,
    // which isn't enough for the webhook's ON DUPLICATE KEY UPDATE upsert to
    // work correctly. Add the unique key it needs, alongside the old index.
    try {
        $pdo->exec("ALTER TABLE cimm_verification_reports ADD UNIQUE KEY IF NOT EXISTS uq_cimm_req (cimm_req_id)");
    } catch (\Throwable $e) {
        error_log('cimm_verification_reports unique-key migration warning: ' . $e->getMessage());
    }
}

/**
 * Fetch CIMM verification reports.
 *
 * @param PDO   $pdo   Database connection
 * @param array $opts  Optional: ['limit' => int, 'status' => string, 'approval' => string]
 * @return array
 */
function rgmap_fetch_cimm_verification_reports(PDO $pdo, array $opts = []): array {
    $limit = (int) ($opts['limit'] ?? 500);
    if ($limit < 1) {
        $limit = 500;
    }

    // Ensure the table exists (and is fully migrated) so the page doesn't
    // blow up even if no sync has run yet.
    rgmap_ensure_cimm_verification_table($pdo);

    $sql = "SELECT * FROM cimm_verification_reports WHERE 1=1";
    $params = [];

    // Apply specific filters based on opts
    if (!empty($opts['infrastructure'])) {
        $sql .= " AND infrastructure = ?";
        $params[] = $opts['infrastructure'];
    }

    if (!empty($opts['verification_status'])) {
        if (is_array($opts['verification_status'])) {
            $placeholders = implode(',', array_fill(0, count($opts['verification_status']), '?'));
            $sql .= " AND verification_status IN ($placeholders)";
            foreach ($opts['verification_status'] as $value) {
                $params[] = $value;
            }
        } else {
            $sql .= " AND verification_status = ?";
            $params[] = $opts['verification_status'];
        }
    }

    if (!empty($opts['approval_status'])) {
        $sql .= " AND approval_status = ?";
        $params[] = $opts['approval_status'];
    }

    // Legacy support for old 'status' and 'approval' parameters
    if (!empty($opts['status'])) {
        $sql .= " AND verification_status = ?";
        $params[] = $opts['status'];
    }
    if (!empty($opts['approval'])) {
        $sql .= " AND approval_status = ?";
        $params[] = $opts['approval'];
    }

    $sql .= " ORDER BY submitted_at DESC, id DESC LIMIT " . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Decode the JSON blobs CIMM sends (evidence photo URLs + AI screening
    // results) into arrays so the display layer can use them directly.
    foreach ($rows as &$row) {
        $row['evidence_urls'] = json_decode((string)($row['evidence_json'] ?? '[]'), true) ?: [];
        $row['ai'] = json_decode((string)($row['ai_json'] ?? '{}'), true) ?: [];
    }
    unset($row);

    return $rows;
}

/**
 * SQL CASE expression mapping CIMM's request_resolutions.status values
 * (Approved, Rejected, Scheduled, In Progress, Pending Completion,
 * Completed, Cancelled — see request_resolutions in the CIMM repo) onto
 * this system's road_transportation_reports status vocabulary (pending,
 * in-progress, completed, cancelled, approved, rejected).
 *
 * Bug fix: the "Recent Submissions" widgets (getRecentSubmissions() in
 * road_transportation_monitoring.php, getRecentSubmissionsPaginated() in
 * get_recent_submissions_paginated.php, and resolve_recent_focus_row())
 * used to hardcode `'completed' AS status` for every CIMM-sourced row, so
 * a report showed as Completed the moment CIMM verified/synced it —
 * regardless of what CIMM's resolution_status actually said (Pending,
 * Scheduled, In Progress, Pending Completion, ...). This CASE expression
 * replaces that literal so the real, current status is shown and updates
 * as CIMM pushes changes.
 *
 * Expects resolution_status and approval_status to be selected from
 * cimm_verification_reports in the same query. Wrap the query that uses
 * this in a derived table (SELECT * FROM (...) AS alias) if the caller
 * also filters on `status`, since a SELECT alias can't be referenced in
 * that same query's WHERE clause.
 */
function cimm_status_case_sql(): string {
    return "CASE
        WHEN resolution_status = 'Completed' THEN 'completed'
        WHEN resolution_status IN ('In Progress', 'Pending Completion') THEN 'in-progress'
        WHEN resolution_status = 'Cancelled' THEN 'cancelled'
        WHEN resolution_status = 'Rejected' THEN 'cancelled'
        WHEN resolution_status IN ('Scheduled', 'Approved') THEN 'pending'
        WHEN approval_status = 'Rejected' THEN 'cancelled'
        ELSE 'pending'
    END";
}

/**
 * PHP-side twin of cimm_status_case_sql() for pages that fetch a
 * cimm_verification_reports row and map it to a display status in PHP
 * (e.g. verification_monitoring.php) rather than in the SQL itself.
 * Keep this in sync with cimm_status_case_sql() above — same mapping,
 * same fallback order, just expressed as PHP instead of SQL.
 */
function cimm_resolution_status_to_display(?string $resolutionStatus, ?string $approvalStatus = null): string {
    switch ($resolutionStatus) {
        case 'Completed':
            return 'completed';
        case 'In Progress':
        case 'Pending Completion':
            return 'in-progress';
        case 'Cancelled':
        case 'Rejected':
            return 'cancelled';
        case 'Scheduled':
        case 'Approved':
            return 'pending';
    }
    if ($approvalStatus === 'Rejected') {
        return 'cancelled';
    }
    return 'pending';
}

/**
 * Apply one CIMM report payload (the same shape cimm_rgmap_fetch_report()
 * in the CIMM repo produces) into cimm_verification_reports, and — if the
 * payload carries rgmap_report_pk (meaning this CIMM report started life as
 * one of THIS system's own road_transportation_reports rows) — also keep
 * that row's cimm_engineer_name/cimm_budget/cimm_status/cimm_district etc.
 * current.
 *
 * This is the single write path shared by:
 *   - cimm-reports-webhook.php   (live push from CIMM, one report at a time)
 *   - cimm-reports-pull.php      (cron catch-up, replays into the webhook)
 *   - rgmap_backfill_stale_cimm_reports() (verification_monitoring.php's
 *     capped auto-heal for rows that predate this function, or that never
 *     wrote correctly because of the parameter-count bug this replaces —
 *     see git history on cimm-reports-webhook.php)
 *
 * @return array{id:int,cimm_req_id:int,reference:string}
 */
function rgmap_apply_cimm_report_payload(PDO $pdo, ?mysqli $conn, array $data): array {
    $cimmReqId = (int)($data['cimm_req_id'] ?? 0);
    if ($cimmReqId <= 0) {
        throw new \InvalidArgumentException('cimm_req_id is required');
    }

    rgmap_ensure_cimm_verification_table($pdo);

    $reference = (string)($data['reference'] ?? ('REQ-' . str_pad((string)$cimmReqId, 3, '0', STR_PAD_LEFT)));
    $cimmRepId = isset($data['cimm_rep_id']) ? (int)$data['cimm_rep_id'] : null;
    if ($cimmRepId !== null && $cimmRepId <= 0) {
        $cimmRepId = null;
    }

    $evidenceJson = json_encode($data['evidence_urls'] ?? [], JSON_UNESCAPED_SLASHES);
    $aiJson = json_encode($data['ai'] ?? [], JSON_UNESCAPED_SLASHES);
    $payloadJson = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $event = (string)($data['event'] ?? $_SERVER['HTTP_X_CIMM_EVENT'] ?? 'upsert');

    $stmt = $pdo->prepare("
        INSERT INTO cimm_verification_reports (
            cimm_req_id, cimm_rep_id, reference_code, report_reference,
            infrastructure, location, issue, reporter_name, contact_number, email,
            district, coord_lat, coord_lng, cprf_facility_id, cprf_facility_name,
            approval_status, rejection_reason, resolution_status, resolution_note, resolved_at,
            priority, engineer, budget, starting_date, estimated_end_date, submitted_at,
            evidence_json, ai_json, portal_url, payload_json, last_event
        ) VALUES (
            ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?
        )
        ON DUPLICATE KEY UPDATE
            cimm_rep_id = VALUES(cimm_rep_id),
            reference_code = VALUES(reference_code),
            report_reference = VALUES(report_reference),
            infrastructure = VALUES(infrastructure),
            location = VALUES(location),
            issue = VALUES(issue),
            reporter_name = VALUES(reporter_name),
            contact_number = VALUES(contact_number),
            email = VALUES(email),
            district = VALUES(district),
            coord_lat = VALUES(coord_lat),
            coord_lng = VALUES(coord_lng),
            cprf_facility_id = VALUES(cprf_facility_id),
            cprf_facility_name = VALUES(cprf_facility_name),
            approval_status = VALUES(approval_status),
            rejection_reason = VALUES(rejection_reason),
            resolution_status = VALUES(resolution_status),
            resolution_note = VALUES(resolution_note),
            resolved_at = VALUES(resolved_at),
            priority = VALUES(priority),
            engineer = VALUES(engineer),
            budget = VALUES(budget),
            starting_date = VALUES(starting_date),
            estimated_end_date = VALUES(estimated_end_date),
            submitted_at = VALUES(submitted_at),
            evidence_json = VALUES(evidence_json),
            ai_json = VALUES(ai_json),
            portal_url = VALUES(portal_url),
            payload_json = VALUES(payload_json),
            last_event = VALUES(last_event),
            synced_at = CURRENT_TIMESTAMP
    ");

    // 31 placeholders above, 31 values below — keep these in lockstep. A past
    // mismatch here (two stray trailing values with no matching placeholder)
    // made every call throw "Invalid parameter number" under
    // ATTR_EMULATE_PREPARES=false, silently failing every push AND every
    // pull-replay — see git history on the old inline version of this code
    // in cimm-reports-webhook.php for the fix.
    $stmt->execute([
        $cimmReqId,
        $cimmRepId,
        $reference,
        $data['report_reference'] ?? null,
        (string)($data['infrastructure'] ?? 'Unknown'),
        (string)($data['location'] ?? ''),
        (string)($data['issue'] ?? ''),
        $data['reporter_name'] ?? null,
        (string)($data['contact_number'] ?? ''),
        $data['email'] ?? null,
        $data['district'] ?? null,
        $data['coord_lat'] ?? null,
        $data['coord_lng'] ?? null,
        $data['cprf_facility_id'] ?? null,
        $data['cprf_facility_name'] ?? null,
        (string)($data['approval_status'] ?? 'Pending'),
        $data['rejection_reason'] ?? null,
        $data['resolution_status'] ?? null,
        $data['resolution_note'] ?? null,
        $data['resolved_at'] ?? null,
        $data['priority'] ?? null,
        $data['engineer'] ?? null,
        $data['budget'] ?? null,
        $data['starting_date'] ?? null,
        $data['estimated_end_date'] ?? null,
        (string)($data['submitted_at'] ?? date('Y-m-d H:i:s')),
        $evidenceJson,
        $aiJson,
        $data['portal_url'] ?? null,
        $payloadJson,
        $event,
    ]);

    $localIdStmt = $pdo->prepare('SELECT id FROM cimm_verification_reports WHERE cimm_req_id = ? LIMIT 1');
    $localIdStmt->execute([$cimmReqId]);
    $localId = (int)($localIdStmt->fetchColumn() ?: 0);

    // ── If this CIMM report originated from one of THIS system's own
    //    road_transportation_reports rows, also keep that specific row
    //    current — not just the cimm_verification_reports mirror above —
    //    so staff looking at the report they originally submitted see the
    //    latest engineer/budget/dates/status/district without having to go
    //    find it in a different panel. ──────────────────────────────────
    $rgmapReportPk = isset($data['rgmap_report_pk']) ? (int)$data['rgmap_report_pk'] : 0;
    if ($rgmapReportPk > 0 && $conn instanceof mysqli) {
        require_once __DIR__ . '/rgmap_cimm_sync.php';
        rgmap_cimm_ensure_schema($conn);

        $cimmEngineerName = $data['engineer'] ?? null;
        $cimmBudget = isset($data['budget']) ? (float)$data['budget'] : null;
        $cimmStartingDate = $data['starting_date'] ?? null;
        $cimmEstimatedEndDate = $data['estimated_end_date'] ?? null;
        $cimmStatus = $data['resolution_status'] ?? null;
        $cimmReportUrl = $data['portal_url'] ?? null;
        $cimmDistrict = $data['district'] ?? null;

        $updStmt = $conn->prepare(
            "UPDATE road_transportation_reports
             SET cimm_engineer_name = ?, cimm_budget = ?, cimm_starting_date = ?,
                 cimm_estimated_end_date = ?, cimm_status = ?, cimm_report_url = ?,
                 cimm_district = ?
             WHERE id = ?"
        );
        if ($updStmt) {
            $updStmt->bind_param(
                'sdsssssi',
                $cimmEngineerName, $cimmBudget, $cimmStartingDate,
                $cimmEstimatedEndDate, $cimmStatus, $cimmReportUrl, $cimmDistrict, $rgmapReportPk
            );
            $updStmt->execute();
            $updStmt->close();
        }
    }

    return ['id' => $localId, 'cimm_req_id' => $cimmReqId, 'reference' => $reference];
}

/**
 * Capped, idempotent, best-effort auto-heal for rows in
 * cimm_verification_reports that are missing a district (the visible
 * symptom of the webhook parameter-count bug fixed in
 * rgmap_apply_cimm_report_payload() above — rows written while that bug was
 * live either never landed at all, or in some cases landed via an older
 * code path without today's full column set). Also covers rows whose
 * linked road_transportation_reports.cimm_status was never populated for
 * the same reason, which is what made the "LGU Monitoring Reports" panel
 * keep showing the stale "Pushed" badge instead of CIMM's real status.
 *
 * Re-fetches each affected report fresh from CIMM's export API (the same
 * endpoint cimm-reports-pull.php uses) and replays it through
 * rgmap_apply_cimm_report_payload() — the now-fixed write path — so old
 * data self-corrects the next time this page loads, without requiring
 * anyone to manually run the pull cron. Capped per page load (matching the
 * codebase's existing backfill convention — see the district backfill in
 * the CIMM repo's road_monitoring.php) so a large backlog can't turn one
 * page view into a slow batch job; any remainder is picked up on the next
 * load. Safe to call on every request — a report with nothing stale is a
 * cheap no-op query and this does nothing further.
 *
 * @return array{attempted:int,fixed:int,failed:int}
 */
function rgmap_backfill_stale_cimm_reports(PDO $pdo, ?mysqli $conn, int $limit = 5): array {
    rgmap_ensure_cimm_verification_table($pdo);

    $stmt = $pdo->prepare(
        "SELECT cimm_req_id FROM cimm_verification_reports
         WHERE district IS NULL OR district = ''
         ORDER BY id ASC LIMIT " . max(1, $limit)
    );
    $stmt->execute();
    $staleReqIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $result = ['attempted' => 0, 'fixed' => 0, 'failed' => 0];
    if (empty($staleReqIds)) {
        return $result;
    }

    $cfg = cimm_verification_export_config();
    foreach ($staleReqIds as $reqId) {
        $reqId = (int)$reqId;
        if ($reqId <= 0) {
            continue;
        }
        $result['attempted']++;

        $url = $cfg['export_url'] . '?key=' . rawurlencode($cfg['api_key']) . '&req_id=' . $reqId;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => !$cfg['is_local'],
            CURLOPT_SSL_VERIFYHOST => $cfg['is_local'] ? 0 : 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $resp = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300 || !is_string($resp)) {
            $result['failed']++;
            continue;
        }
        $decoded = json_decode($resp, true);
        $reports = is_array($decoded) ? ($decoded['reports'] ?? []) : [];
        $payload = is_array($reports) && !empty($reports) ? $reports[0] : null;
        if (!is_array($payload)) {
            $result['failed']++;
            continue;
        }

        try {
            rgmap_apply_cimm_report_payload($pdo, $conn, $payload);
            $result['fixed']++;
        } catch (\Throwable $e) {
            error_log('rgmap_backfill_stale_cimm_reports failed for req ' . $reqId . ': ' . $e->getMessage());
            $result['failed']++;
        }
    }

    return $result;
}

/**
 * Same local/live URL-guessing convention used throughout this codebase
 * (see rgmap_road_reports_config() in the CIMM repo, and the inline
 * version of this in cimm-reports-pull.php) for reaching CIMM's export API.
 *
 * @return array{export_url:string,api_key:string,is_local:bool}
 */
function cimm_verification_export_config(): array {
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $hostOnly = explode(':', $host)[0];
    $isLocal = in_array($hostOnly, ['localhost', '127.0.0.1', '::1'], true);
    $defaultExportUrl = $isLocal
        ? (($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . $host . '/LGU/lgu-portal/public/api/cimm-reports-export.php')
        : 'https://cimm.infragovservices.com/lgu-portal/public/api/cimm-reports-export.php';

    $cfg = [
        'export_url' => getenv('CIMM_REPORTS_EXPORT_URL') ?: $defaultExportUrl,
        'api_key'    => getenv('CIMM_RGMAP_API_KEY') ?: 'CIMM_RGMAP_SHARED_KEY_2026',
        'is_local'   => $isLocal,
    ];
    return $cfg;
}

/**
 * Update the RGMAO-side verification decision on a synced CIMM report.
 * Not yet wired to a UI action, but available for a future
 * Verify / Flag / Dismiss control on the CIMM reports panel.
 */
function rgmap_update_verification_status(
    PDO $pdo,
    int $cimmReqId,
    string $verificationStatus,
    ?string $note = null,
    ?int $verifiedBy = null
): bool {
    $allowed = ['Pending Review', 'Verified', 'Flagged', 'Dismissed', 'Pending', 'Approved', 'In Progress', 'Completed', 'Cancelled'];
    if (!in_array($verificationStatus, $allowed, true)) {
        return false;
    }

    $stmt = $pdo->prepare("
        UPDATE cimm_verification_reports
        SET verification_status = ?,
            verification_note = ?,
            verified_by = ?,
            verified_at = NOW()
        WHERE cimm_req_id = ?
    ");
    return $stmt->execute([$verificationStatus, $note, $verifiedBy, $cimmReqId]);
}