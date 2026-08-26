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
        status_bucket       VARCHAR(16)  NULL,
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
        budget              DECIMAL(15,2) NULL,
        assigned_engineers_json TEXT     NULL,
        payload_json        LONGTEXT     NULL,
        synced_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        status              VARCHAR(50)  NULL DEFAULT NULL,
        priority            VARCHAR(20)  NULL DEFAULT NULL,
        start_address       VARCHAR(100) NULL DEFAULT NULL,
        end_address         VARCHAR(100) NULL DEFAULT NULL,
        UNIQUE KEY uq_ipms_project (project_id),
        INDEX idx_project_status (project_status),
        INDEX idx_status_bucket (status_bucket)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Idempotent add-ons for installs created before these columns existed —
    // same convention IPMS itself uses for its own schema evolution.
    $existing = $pdo->query("SHOW COLUMNS FROM ipms_road_projects LIKE 'status_bucket'")->fetchAll();
    if (empty($existing)) {
        $pdo->exec("ALTER TABLE ipms_road_projects ADD COLUMN status_bucket VARCHAR(16) NULL AFTER project_status, ADD INDEX idx_status_bucket (status_bucket)");
    }
    $existingBudget = $pdo->query("SHOW COLUMNS FROM ipms_road_projects LIKE 'budget'")->fetchAll();
    if (empty($existingBudget)) {
        $pdo->exec("ALTER TABLE ipms_road_projects ADD COLUMN budget DECIMAL(15,2) NULL AFTER districts_json");
    }
    $existingEngineers = $pdo->query("SHOW COLUMNS FROM ipms_road_projects LIKE 'assigned_engineers_json'")->fetchAll();
    if (empty($existingEngineers)) {
        $pdo->exec("ALTER TABLE ipms_road_projects ADD COLUMN assigned_engineers_json TEXT NULL AFTER budget");
    }
    $existingStatus = $pdo->query("SHOW COLUMNS FROM ipms_road_projects LIKE 'status'")->fetchAll();
    if (empty($existingStatus)) {
        $pdo->exec("ALTER TABLE ipms_road_projects ADD COLUMN status VARCHAR(50) NULL DEFAULT NULL AFTER created_at");
    }
    $existingStartAddr = $pdo->query("SHOW COLUMNS FROM ipms_road_projects LIKE 'start_address'")->fetchAll();
    if (empty($existingStartAddr)) {
        $pdo->exec("ALTER TABLE ipms_road_projects ADD COLUMN start_address VARCHAR(100) NULL DEFAULT NULL AFTER status");
    }
    $existingEndAddr = $pdo->query("SHOW COLUMNS FROM ipms_road_projects LIKE 'end_address'")->fetchAll();
    if (empty($existingEndAddr)) {
        $pdo->exec("ALTER TABLE ipms_road_projects ADD COLUMN end_address VARCHAR(100) NULL DEFAULT NULL AFTER start_address");
    }
    $existingPriority = $pdo->query("SHOW COLUMNS FROM ipms_road_projects LIKE 'priority'")->fetchAll();
    if (empty($existingPriority)) {
        $pdo->exec("ALTER TABLE ipms_road_projects ADD COLUMN priority VARCHAR(20) NULL DEFAULT NULL AFTER status");
    }
    $existingRfa = $pdo->query("SHOW COLUMNS FROM ipms_road_projects LIKE 'restored_from_archive'")->fetchAll();
    if (empty($existingRfa)) {
        $pdo->exec("ALTER TABLE ipms_road_projects ADD COLUMN restored_from_archive TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
    }
}

function rgmap_ensure_ipms_skip_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ipms_road_projects_archive LIKE ipms_road_projects");
    foreach ([
        "previous_status VARCHAR(50) NULL DEFAULT NULL",
        "archived_at DATETIME NULL DEFAULT NULL",
        "archive_status VARCHAR(50) NULL DEFAULT NULL",
    ] as $def) {
        try {
            $pdo->exec("ALTER TABLE ipms_road_projects_archive ADD COLUMN IF NOT EXISTS $def");
        } catch (Throwable $e) {
            error_log('rgmap_ensure_ipms_skip_tables archive col: ' . $e->getMessage());
        }
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS ipms_sync_exclusions (
        project_id INT UNSIGNED NOT NULL,
        excluded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        excluded_by VARCHAR(180) NULL DEFAULT NULL,
        reason VARCHAR(100) NULL DEFAULT NULL,
        PRIMARY KEY (project_id)
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

    rgmap_ensure_ipms_skip_tables($pdo);
    $skip = $pdo->prepare("SELECT 1 FROM ipms_sync_exclusions WHERE project_id = ? LIMIT 1");
    $skip->execute([$projectId]);
    if ($skip->fetchColumn()) {
        return true;
    }
    $skip = $pdo->prepare("SELECT 1 FROM ipms_road_projects_archive WHERE project_id = ? LIMIT 1");
    $skip->execute([$projectId]);
    if ($skip->fetchColumn()) {
        return true;
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

    // Prefer the bucket IPMS sends; fall back to deriving it locally (e.g. an
    // older IPMS deployment that hasn't picked up the status_bucket field yet).
    $statusBucket = (string)($road['status_bucket'] ?? '') ?: rgmap_ipms_status_bucket((string)($road['project_status'] ?? ''));

    $budget = $toFloatOrNull($road['budget'] ?? null);

    // assigned_engineers is a list (0, 1, or many names) — never assume a
    // single engineer, and tolerate it being absent/malformed on older feed
    // responses by falling back to an empty list.
    $assignedEngineers = [];
    $ae = $road['assigned_engineers'] ?? null;
    if (is_array($ae)) {
        foreach ($ae as $name) {
            $name = trim((string)$name);
            if ($name !== '') {
                $assignedEngineers[] = $name;
            }
        }
    } elseif (is_string($ae) && trim($ae) !== '') {
        // Tolerate a comma-separated string from older partners
        foreach (array_map('trim', explode(',', $ae)) as $name) {
            if ($name !== '') $assignedEngineers[] = $name;
        }
    }

    // Local workflow status: new rows are 'pending', unless IPMS marks the
    // project completed (status_bucket). On update leave status alone except
    // when status_bucket becomes completed — then force status = completed.
    $localStatus = ($statusBucket === 'completed') ? 'completed' : 'pending';

    $clipAddr = static function ($v): ?string {
        $v = trim((string)($v ?? ''));
        if ($v === '') {
            return null;
        }
        return mb_substr($v, 0, 100);
    };
    $startAddress = $clipAddr($road['start_address'] ?? null);
    $endAddress = $clipAddr($road['end_address'] ?? null);

    $stmt = $pdo->prepare("
        INSERT INTO ipms_road_projects (
            project_id, project_name, project_status, status_bucket, progress_percent,
            start_date, end_date, road_name, road_type, road_status,
            polyline_json, road_length_meters, start_lat, start_lng, end_lat, end_lng,
            barangays_json, districts_json, budget, assigned_engineers_json, payload_json,
            status, start_address, end_address
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?
        )
        ON DUPLICATE KEY UPDATE
            project_name = VALUES(project_name),
            project_status = VALUES(project_status),
            status_bucket = VALUES(status_bucket),
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
            budget = VALUES(budget),
            assigned_engineers_json = VALUES(assigned_engineers_json),
            payload_json = VALUES(payload_json),
            status = IF(VALUES(status_bucket) = 'completed', 'completed', status),
            start_address = COALESCE(NULLIF(start_address, ''), VALUES(start_address)),
            end_address = COALESCE(NULLIF(end_address, ''), VALUES(end_address)),
            synced_at = CURRENT_TIMESTAMP
    ");

    return $stmt->execute([
        $projectId,
        (string)($road['project_name'] ?? $road['road_name'] ?? 'Untitled Road Project'),
        (string)($road['project_status'] ?? 'unknown'),
        $statusBucket,
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
        $budget,
        json_encode($assignedEngineers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        json_encode($road, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        $localStatus,
        $startAddress,
        $endAddress,
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
 * Bucketing for the dashboard: new (approved, not yet under construction),
 * ongoing (in progress), completed, or cancelled. Fallback used only when a
 * cached row (or an older IPMS deployment) doesn't already carry a
 * status_bucket value from the feed itself.
 */
function rgmap_ipms_status_bucket(string $status): string {
    $ongoing = ['active', 'delayed', 'on_hold', 'completion_inspection'];
    $completed = ['completed', 'turnover'];
    if (in_array($status, $ongoing, true)) {
        return 'ongoing';
    }
    if (in_array($status, $completed, true)) {
        return 'completed';
    }
    if ($status === 'cancelled') {
        return 'cancelled';
    }
    return 'new';
}

/**
 * Fetch cached IPMS road projects.
 *
 * @param PDO   $pdo  Database connection
 * @param array $opts Optional: ['limit' => int, 'status' => string, 'bucket' => string]
 * @return array Rows with polyline_coordinates / start_coordinate /
 *               end_coordinate / barangays_covered / districts_covered /
 *               assigned_engineers decoded back into arrays, budget cast to
 *               float (or null), plus a 'scope_bucket' field
 *               (new/ongoing/completed/cancelled).
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

    if (!empty($opts['bucket'])) {
        $sql .= " AND status_bucket = ?";
        $params[] = $opts['bucket'];
    }

    // Local workflow status filter (approved / cancelled / in-progress / …).
    // When cancelled is included, only restored-from-archive cancelled rows
    // match — same gate as CIMM/LGU report_management panels.
    if (!empty($opts['workflow_statuses']) && is_array($opts['workflow_statuses'])) {
        $statuses = array_values(array_filter(array_map(static function ($s) {
            return strtolower(trim((string)$s));
        }, $opts['workflow_statuses']), static fn($s) => $s !== ''));
        if (!empty($statuses)) {
            $hasCancelled = in_array('cancelled', $statuses, true);
            $nonCancelled = array_values(array_filter($statuses, static fn($s) => $s !== 'cancelled'));
            if ($hasCancelled && !empty($nonCancelled)) {
                $ph = implode(',', array_fill(0, count($nonCancelled), '?'));
                $sql .= " AND (LOWER(COALESCE(status,'')) IN ($ph)"
                    . " OR (LOWER(COALESCE(status,'')) = 'cancelled' AND COALESCE(restored_from_archive, 0) = 1))";
                foreach ($nonCancelled as $s) {
                    $params[] = $s;
                }
            } elseif ($hasCancelled) {
                $sql .= " AND LOWER(COALESCE(status,'')) = 'cancelled' AND COALESCE(restored_from_archive, 0) = 1";
            } else {
                $ph = implode(',', array_fill(0, count($statuses), '?'));
                $sql .= " AND LOWER(COALESCE(status,'')) IN ($ph)";
                foreach ($statuses as $s) {
                    $params[] = $s;
                }
            }
            // Workflow panels must not bury restored cancelled rows behind the
            // default LIMIT 200 (cancelled sorts last by status_bucket).
            $limit = max($limit, 5000);
        }
    }

    // Ongoing/new work surfaces first (most relevant to citizens right now);
    // completed/cancelled sort to the bottom since they're historical.
    $sql .= " ORDER BY FIELD(status_bucket,'ongoing','new','completed','cancelled'),
              FIELD(project_status,'active','delayed','on_hold','completion_inspection','awarded','assigned','bidding','approved'),
              start_date ASC LIMIT " . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['polyline_coordinates'] = json_decode((string)($row['polyline_json'] ?? '[]'), true) ?: [];
        $row['barangays_covered'] = json_decode((string)($row['barangays_json'] ?? '[]'), true) ?: [];
        $row['districts_covered'] = json_decode((string)($row['districts_json'] ?? '[]'), true) ?: [];
        $row['assigned_engineers'] = json_decode((string)($row['assigned_engineers_json'] ?? '[]'), true) ?: [];
        $row['budget'] = $row['budget'] !== null ? (float)$row['budget'] : null;
        $row['start_coordinate'] = ($row['start_lat'] !== null && $row['start_lng'] !== null)
            ? ['lat' => (float)$row['start_lat'], 'lng' => (float)$row['start_lng']]
            : null;
        $row['end_coordinate'] = ($row['end_lat'] !== null && $row['end_lng'] !== null)
            ? ['lat' => (float)$row['end_lat'], 'lng' => (float)$row['end_lng']]
            : null;
        $row['scope_bucket'] = !empty($row['status_bucket']) ? $row['status_bucket'] : rgmap_ipms_status_bucket((string)$row['project_status']);
    }
    unset($row);

    return $rows;
}

/**
 * Map cached IPMS projects into the shape used by verification / report
 * management Infrastructure panels. Filters by local workflow `status`
 * (e.g. pending on verification, approved on report management).
 *
 * @return array<int, array<string, mixed>>
 */
function rgmap_infra_panel_rows(?PDO $pdo = null, string|array $workflowStatus = 'pending'): array {
    $pdo = $pdo ?? rgmap_ipms_pdo();
    $rows = [];
    $allowed = is_array($workflowStatus) ? $workflowStatus : [$workflowStatus];
    $allowed = array_values(array_filter(array_map(static function ($s) {
        return strtolower(trim((string)$s));
    }, $allowed), static fn($s) => $s !== ''));
    if (empty($allowed)) {
        $allowed = ['pending'];
    }

    // Filter in SQL so restored cancelled / completed rows are not dropped by
    // the default LIMIT 200 fetch (cancelled sorts last by status_bucket).
    $raw = rgmap_fetch_ipms_road_projects($pdo, [
        'workflow_statuses' => $allowed,
        'limit' => 5000,
    ]);

    foreach ($raw as $proj) {
        $st = strtolower(trim((string)($proj['status'] ?? '')));
        // Mirror CIMM / LGU: only restored-cancelled projects are visible here.
        if ($st === 'cancelled' && (int)($proj['restored_from_archive'] ?? 0) !== 1) {
            continue;
        }
        if (!in_array($st, $allowed, true)) {
            continue;
        }

        $infraEngineers = $proj['assigned_engineers'] ?? [];
        $infraEngineer = is_array($infraEngineers)
            ? implode(', ', array_filter(array_map('trim', array_map('strval', $infraEngineers)), fn($n) => $n !== ''))
            : trim((string)$infraEngineers);

        $rows[] = [
            'id'               => (int)$proj['project_id'],
            'source'           => 'maintenance',
            'source_system'    => 'maintenance',
            'report_id'        => (string)$proj['project_id'],
            'title'            => trim((string)($proj['project_name'] ?? '')),
            'infrastructure'   => trim((string)($proj['road_type'] ?? '')),
            'report_type'      => trim((string)($proj['road_type'] ?? '')) ?: 'infrastructure_issue',
            'department'       => 'Engineering',
            'priority'         => trim((string)($proj['priority'] ?? '')) ?: '—',
            'status'           => (string)($proj['status'] ?? ($allowed[0] ?? '')),
            'restored_from_archive' => (int)($proj['restored_from_archive'] ?? 0),
            'location'         => (is_array($proj['barangays_covered'] ?? null) && count($proj['barangays_covered']))
                ? implode(', ', array_filter(array_map('trim', array_map('strval', $proj['barangays_covered'])), fn($b) => $b !== ''))
                : trim((string)($proj['road_name'] ?? '')),
            'start_address'    => trim((string)($proj['start_address'] ?? '')) ?: null,
            'end_address'      => trim((string)($proj['end_address'] ?? '')) ?: null,
            'district'         => (is_array($proj['districts_covered'] ?? null) && count($proj['districts_covered']))
                ? implode(', ', array_filter(array_map('trim', array_map('strval', $proj['districts_covered'])), static fn($d) => $d !== ''))
                : null,
            'description'      => trim((string)($proj['road_status'] ?? '')),
            'created_date'     => null,
            'created_at'       => $proj['created_at'] ?? null,
            'updated_at'       => $proj['synced_at'] ?? ($proj['created_at'] ?? null),
            'due_date'         => $proj['end_date'] ?? null,
            'reporter_name'    => null,
            'estimated_cost'   => null,
            'actual_cost'      => null,
            'maintenance_team' => null,
            'attachments'      => null,
            'issue_notes'      => trim((string)($proj['road_status'] ?? '')),
            'engineer'         => $infraEngineer,
            'assigned_to'      => $infraEngineer,
            'start_date'       => $proj['start_date'] ?? null,
            'end_date'         => $proj['end_date'] ?? null,
            'budget'           => $proj['budget'] ?? null,
            'polyline'         => $proj['polyline_coordinates'] ?? null,
            'from_ipms'        => true,
        ];
    }

    return $rows;
}
