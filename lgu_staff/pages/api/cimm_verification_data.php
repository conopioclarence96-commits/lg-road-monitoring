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
