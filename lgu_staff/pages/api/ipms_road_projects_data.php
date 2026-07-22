<?php
/**
 * IPMS Road Projects Data Access Layer
 *
 * Provides PDO connectivity and helper functions for the ipms_road_projects
 * table, which caches the "upcoming/ongoing road projects" feed pulled from
 * IPMS (see ipms-road-projects-pull.php in this same folder).
 *
 * This is a read-only mirror of IPMS project data — it is never written back
 * to IPMS, and it is intentionally kept separate from this app's own citizen
 * incident tables (road_transportation_reports / road_maintenance_reports).
 * The two are unrelated: this table is planned/ongoing construction projects
 * from IPMS; the incident tables are citizen-reported potholes/damage.
 *
 * IPMS's feed always returns its full current "upcoming" scope (not a
 * consume-once queue), so the poller upserts every project_id it sees and
 * then prunes any row that's no longer present in the latest pull — see
 * rgmap_prune_ipms_road_projects().
 */

/**
 * Create (or reuse) a PDO connection pointing at the same database the
 * mysqli $conn in config.php already uses.
 */
function rgmap_ipms_pdo(): PDO {
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
 * Ensure ipms_road_projects exists (idempotent — safe to call on every
 * request).
 */
function rgmap_ensure_ipms_road_projects_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ipms_road_projects (
        id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        project_id          INT UNSIGNED NOT NULL,
        project_name        VARCHAR(255) NOT NULL,
        project_status      VARCHAR(32)  NOT NULL,
        progress_percent    TINYINT UNSIGNED NOT NULL DEFAULT 0,
        start_date          DATE         NULL,
        end_date            DATE         NULL,
        road_name           VARCHAR(255) NOT NULL,
        road_type           VARCHAR(32)  NOT NULL,
        road_status         VARCHAR(64)  NOT NULL,
        polyline_json       LONGTEXT     NULL,
        road_length_meters  DECIMAL(12,2) NULL,
        start_lat           DECIMAL(10,7) NULL,
        start_lng           DECIMAL(10,7) NULL,
        end_lat             DECIMAL(10,7) NULL,
        end_lng             DECIMAL(10,7) NULL,
        barangays_json      TEXT         NULL,
        districts_json      TEXT         NULL,
        payload_json        LONGTEXT     NULL,
        synced_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_ipms_project (project_id),
        INDEX idx_project_status (project_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Upsert a single road project row from the IPMS feed shape (see
 * ipms-road-projects-pull.php for the exact fields IPMS sends).
 */
function rgmap_upsert_ipms_road_project(PDO $pdo, array $road): bool {
    $projectId = (int)($road['project_id'] ?? 0);
    if ($projectId <= 0) {
        return false;
    }

    $toDate = function ($v) {
        $v = trim((string)($v ?? ''));
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    };
    $toFloatOrNull = function ($v) {
        return ($v === null || $v === '') ? null : (float)$v;
    };

    $progress = (int)($road['progress_percent'] ?? 0);
    $progress = max(0, min(100, $progress));

    $polyline = $road['polyline_coordinates'] ?? [];
    $start = $road['start_coordinate'] ?? [];
    $end = $road['end_coordinate'] ?? [];

    $stmt = $pdo->prepare("
        INSERT INTO ipms_road_projects (
            project_id, project_name, project_status, progress_percent,
            start_date, end_date, road_name, road_type, road_status,
            polyline_json, road_length_meters, start_lat, start_lng, end_lat, end_lng,
            barangays_json, districts_json, payload_json
        ) VALUES (
            ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?
        )
        ON DUPLICATE KEY UPDATE
            project_name = VALUES(project_name),
            project_status = VALUES(project_status),
            progress_percent = VALUES(progress_percent),
            start_date = VALUES(start_date),
            end_date = VALUES(end_date),
            road_name = VALUES(road_name),
            road_type = VALUES(road_type),
            road_status = VALUES(road_status),
            polyline_json = VALUES(polyline_json),
            road_length_meters = VALUES(road_length_meters),
            start_lat = VALUES(start_lat),
            start_lng = VALUES(start_lng),
            end_lat = VALUES(end_lat),
            end_lng = VALUES(end_lng),
            barangays_json = VALUES(barangays_json),
            districts_json = VALUES(districts_json),
            payload_json = VALUES(payload_json),
            synced_at = CURRENT_TIMESTAMP
    ");

    return $stmt->execute([
        $projectId,
        (string)($road['project_name'] ?? $road['road_name'] ?? 'Untitled Road Project'),
        (string)($road['project_status'] ?? 'unknown'),
        $progress,
        $toDate($road['start_date'] ?? null),
        $toDate($road['end_date'] ?? null),
        (string)($road['road_name'] ?? $road['project_name'] ?? 'Unnamed Road'),
        (string)($road['road_type'] ?? ''),
        (string)($road['road_status'] ?? ''),
        json_encode($polyline, JSON_UNESCAPED_SLASHES),
        $toFloatOrNull($road['road_length_meters'] ?? null),
        $toFloatOrNull($start['lat'] ?? null),
        $toFloatOrNull($start['lng'] ?? null),
        $toFloatOrNull($end['lat'] ?? null),
        $toFloatOrNull($end['lng'] ?? null),
        json_encode($road['barangays_covered'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        json_encode($road['districts_covered'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        json_encode($road, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
}

/**
 * Remove any cached project row whose project_id was not present in the
 * latest successful IPMS pull. IPMS's feed is always the full current
 * "upcoming" scope, not an append-only log, so a project_id that disappears
 * (e.g. it moved to completed/cancelled) must disappear from our cache too.
 *
 * Only call this after a pull that fully succeeded — never on a partial or
 * failed response, or a transient IPMS error could wipe the whole cache.
 */
function rgmap_prune_ipms_road_projects(PDO $pdo, array $keepProjectIds): int {
    $keepProjectIds = array_values(array_unique(array_map('intval', $keepProjectIds)));

    if (empty($keepProjectIds)) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM ipms_road_projects");
        $count = (int)$stmt->fetchColumn();
        $pdo->exec("DELETE FROM ipms_road_projects");
        return $count;
    }

    $placeholders = implode(',', array_fill(0, count($keepProjectIds), '?'));
    $stmt = $pdo->prepare("DELETE FROM ipms_road_projects WHERE project_id NOT IN ($placeholders)");
    $stmt->execute($keepProjectIds);
    return $stmt->rowCount();
}

/**
 * "Upcoming" (not started yet) vs "ongoing" (in progress) bucketing for the
 * dashboard — IPMS already filters the feed to relevant statuses, this just
 * splits it for display.
 */
function rgmap_ipms_status_bucket(string $status): string {
    $ongoing = ['active', 'delayed', 'on_hold', 'completion_inspection'];
    return in_array($status, $ongoing, true) ? 'ongoing' : 'upcoming';
}

/**
 * Fetch cached IPMS road projects.
 *
 * @param PDO   $pdo  Database connection
 * @param array $opts Optional: ['limit' => int, 'status' => string]
 * @return array Rows with polyline_coordinates / start_coordinate /
 *               end_coordinate / barangays_covered / districts_covered
 *               decoded back into arrays, plus a 'scope_bucket' field.
 */
function rgmap_fetch_ipms_road_projects(PDO $pdo, array $opts = []): array {
    $limit = (int)($opts['limit'] ?? 200);
    if ($limit < 1) {
        $limit = 200;
    }

    rgmap_ensure_ipms_road_projects_table($pdo);

    $sql = "SELECT * FROM ipms_road_projects WHERE 1=1";
    $params = [];

    if (!empty($opts['status'])) {
        $sql .= " AND project_status = ?";
        $params[] = $opts['status'];
    }

    $sql .= " ORDER BY FIELD(project_status,'active','delayed','on_hold','completion_inspection','awarded','assigned','bidding','approved'), start_date ASC LIMIT " . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['polyline_coordinates'] = json_decode((string)($row['polyline_json'] ?? '[]'), true) ?: [];
        $row['barangays_covered'] = json_decode((string)($row['barangays_json'] ?? '[]'), true) ?: [];
        $row['districts_covered'] = json_decode((string)($row['districts_json'] ?? '[]'), true) ?: [];
        $row['start_coordinate'] = ($row['start_lat'] !== null && $row['start_lng'] !== null)
            ? ['lat' => (float)$row['start_lat'], 'lng' => (float)$row['start_lng']]
            : null;
        $row['end_coordinate'] = ($row['end_lat'] !== null && $row['end_lng'] !== null)
            ? ['lat' => (float)$row['end_lat'], 'lng' => (float)$row['end_lng']]
            : null;
        $row['scope_bucket'] = rgmap_ipms_status_bucket((string)$row['project_status']);
    }
    unset($row);

    return $rows;
}
