<?php
/**
 * RGMAO → CIMM report push — the reverse direction of the existing
 * CIMM → RGMAO integration (see cimm-reports-webhook.php / cimm-reports-pull.php
 * in this same folder, and cimm_rgmap_sync.php in the CIMM repo).
 *
 * When a staff member submits a report on road_transportation_monitoring.php,
 * this pushes it to CIMM so it shows up in CIMM's "Road Monitoring Reports"
 * panel on pending_reports.php. When CIMM staff verify it there, CIMM calls
 * back into rgmap-verify-webhook.php (this same folder) to mark it verified
 * here too.
 *
 * Reuses the same shared-secret env vars as the CIMM → RGMAO direction
 * (CIMM_RGMAP_WEBHOOK_KEY / CIMM_RGMAP_API_KEY) — it's the same two systems
 * talking, no need for a second key pair.
 */
declare(strict_types=1);

/**
 * @return array{webhook_url:string,webhook_key:string,api_key:string,enabled:bool}
 */
function rgmap_cimm_config(): array {
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $cfg = [
        'webhook_url' => getenv('RGMAP_CIMM_REPORT_WEBHOOK_URL') ?: rgmap_cimm_detect_webhook_url(),
        'webhook_key' => getenv('CIMM_RGMAP_WEBHOOK_KEY') ?: 'CIMM_RGMAP_SHARED_KEY_2026',
        'api_key'     => getenv('CIMM_RGMAP_API_KEY') ?: 'CIMM_RGMAP_SHARED_KEY_2026',
        'enabled'     => (getenv('RGMAP_CIMM_SYNC_ENABLED') ?: '1') !== '0',
    ];
    return $cfg;
}

/**
 * Default CIMM inbound endpoint: assume local XAMPP dev (CIMM repo folder
 * "LGU") unless this server is clearly the live rgmap.infragovservices.com
 * host, matching the detection convention already used by
 * cimm-reports-pull.php in this same folder.
 */
function rgmap_cimm_detect_webhook_url(): string {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $hostOnly = explode(':', $host)[0];
    $isLocal = in_array($hostOnly, ['localhost', '127.0.0.1', '::1'], true);
    return $isLocal
        ? (($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . $host . '/LGU/lgu-portal/public/api/rgmap-report-webhook.php')
        : 'https://cimm.infragovservices.com/lgu-portal/public/api/rgmap-report-webhook.php';
}

/**
 * Absolute base URL for this RGMAO install, used to build absolute image
 * URLs CIMM can actually load (attachments are stored as relative paths).
 */
function rgmap_cimm_detect_own_base_url(): string {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $hostOnly = explode(':', $host)[0];
    $isLocal = in_array($hostOnly, ['localhost', '127.0.0.1', '::1'], true);
    $scheme = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || !$isLocal) ? 'https' : 'http';
    return $isLocal
        ? $scheme . '://' . $host . '/lg-road-monitoring'
        : 'https://rgmap.infragovservices.com';
}

function rgmap_cimm_ensure_schema(mysqli $conn): void {
    $conn->query("ALTER TABLE road_transportation_reports ADD COLUMN IF NOT EXISTS cimm_sync_status VARCHAR(20) DEFAULT NULL");
    $conn->query("ALTER TABLE road_transportation_reports ADD COLUMN IF NOT EXISTS cimm_pushed_at TIMESTAMP NULL DEFAULT NULL");
    $conn->query("ALTER TABLE road_transportation_reports ADD COLUMN IF NOT EXISTS cimm_verified_at TIMESTAMP NULL DEFAULT NULL");
    $conn->query("ALTER TABLE road_transportation_reports ADD COLUMN IF NOT EXISTS cimm_verified_by VARCHAR(150) DEFAULT NULL");

    $conn->query("
        CREATE TABLE IF NOT EXISTS rgmap_cimm_push_log (
            report_id INT UNSIGNED NOT NULL PRIMARY KEY,
            last_event VARCHAR(32) NOT NULL DEFAULT 'created',
            last_pushed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            last_http_code SMALLINT UNSIGNED NULL,
            last_error VARCHAR(255) NULL,
            INDEX idx_pushed_at (last_pushed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/**
 * @return array<string,mixed>|null
 */
function rgmap_cimm_fetch_report(mysqli $conn, int $id): ?array {
    if ($id <= 0) {
        return null;
    }
    $stmt = $conn->prepare(
        "SELECT r.*, u.full_name AS reporter_full_name, u.email AS reporter_account_email
         FROM road_transportation_reports r
         LEFT JOIN users u ON u.id = r.created_by
         WHERE r.id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }

    $baseUrl = rgmap_cimm_detect_own_base_url();
    $attachments = [];
    $decoded = json_decode((string)($row['attachments'] ?? ''), true);
    if (is_array($decoded)) {
        foreach ($decoded as $att) {
            if (!empty($att['file_path'])) {
                $attachments[] = $baseUrl . '/' . ltrim((string)$att['file_path'], '/');
            }
        }
    } elseif (!empty($row['image_path'])) {
        $attachments[] = $baseUrl . '/' . ltrim((string)$row['image_path'], '/');
    }

    return [
        'source_system'    => 'rgmap',
        'event'            => 'upsert',
        'rgmap_report_pk'  => (int)$row['id'],
        'rgmap_report_id'  => (string)$row['report_id'],
        'title'            => (string)$row['title'],
        'report_type'      => (string)$row['report_type'],
        'report_category'  => $row['report_category'] ?? null,
        'department'       => (string)$row['department'],
        'priority'         => $row['priority'] ?? null,
        'status'           => (string)$row['status'],
        'severity'         => $row['severity'] ?? null,
        'description'      => (string)($row['description'] ?? ''),
        'location'         => (string)($row['location'] ?? ''),
        'coord_lat'        => $row['latitude'] ?? null,
        'coord_lng'        => $row['longitude'] ?? null,
        'reporter_name'    => $row['reporter_name'] ?: ($row['reporter_full_name'] ?? null),
        'reporter_email'   => $row['reporter_email'] ?: ($row['reporter_account_email'] ?? null),
        'reporter_phone'   => $row['reporter_phone'] ?? null,
        'attachments'      => $attachments,
        'created_date'     => $row['created_date'] ?? null,
        'submitted_at'     => $row['created_at'] ?? null,
        'portal_url'       => $baseUrl . '/lgu_staff/pages/shared/road_transportation_monitoring.php',
    ];
}

/**
 * @return array{ok:bool,http_code:int,error:?string}
 */
function rgmap_cimm_push_payload(array $payload): array {
    $cfg = rgmap_cimm_config();
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $hostOnly = explode(':', $host)[0];
    $isLocal = in_array($hostOnly, ['localhost', '127.0.0.1', '::1'], true);

    $ch = curl_init($cfg['webhook_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $cfg['webhook_key'],
            'X-API-Key: ' . $cfg['webhook_key'],
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => !$isLocal,
        CURLOPT_SSL_VERIFYHOST => $isLocal ? 0 : 2,
    ]);
    $resp = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch) ?: null;
    curl_close($ch);

    $ok = $err === null && $httpCode >= 200 && $httpCode < 300;
    if (!$ok && $err === null) {
        $err = 'HTTP ' . $httpCode . ': ' . substr((string)$resp, 0, 200);
    }

    return ['ok' => $ok, 'http_code' => $httpCode, 'error' => $err];
}

function rgmap_cimm_sync_report(mysqli $conn, int $id, string $event = 'created'): array {
    rgmap_cimm_ensure_schema($conn);

    $cfg = rgmap_cimm_config();
    if (!$cfg['enabled']) {
        return ['ok' => false, 'error' => 'sync disabled'];
    }

    $payload = rgmap_cimm_fetch_report($conn, $id);
    if ($payload === null) {
        return ['ok' => false, 'error' => 'report not found'];
    }
    $payload['event'] = $event;

    $result = rgmap_cimm_push_payload($payload);

    $stmt = $conn->prepare(
        "INSERT INTO rgmap_cimm_push_log (report_id, last_event, last_http_code, last_error)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            last_event = VALUES(last_event),
            last_http_code = VALUES(last_http_code),
            last_error = VALUES(last_error),
            last_pushed_at = CURRENT_TIMESTAMP"
    );
    if ($stmt) {
        $stmt->bind_param('isis', $id, $event, $result['http_code'], $result['error']);
        $stmt->execute();
        $stmt->close();
    }

    if ($result['ok']) {
        $upd = $conn->prepare("UPDATE road_transportation_reports SET cimm_sync_status = 'pushed', cimm_pushed_at = NOW() WHERE id = ?");
        if ($upd) {
            $upd->bind_param('i', $id);
            $upd->execute();
            $upd->close();
        }
    }

    return $result;
}

/**
 * Fire-and-forget — safe to call right after INSERT in the report-submit
 * handler without slowing the user-facing response down.
 */
function rgmap_cimm_sync_report_async(mysqli $conn, int $id, string $event = 'created'): void {
    try {
        rgmap_cimm_sync_report($conn, $id, $event);
    } catch (\Throwable $e) {
        error_log('RGMAO→CIMM async push failed for report ' . $id . ': ' . $e->getMessage());
    }
}
