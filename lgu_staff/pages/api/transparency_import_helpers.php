<?php
/**
 * Build transparency project data from a single road report and its progress updates.
 * Used when an admin approves a transparency upload request.
 */

function transparency_ensure_request_tables($conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS transparency_upload_requests (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        report_id INT UNSIGNED NOT NULL,
        report_source VARCHAR(50) NOT NULL DEFAULT 'lgu',
        report_type VARCHAR(100) DEFAULT NULL,
        report_mgmt_source VARCHAR(50) DEFAULT NULL,
        report_title VARCHAR(255) DEFAULT NULL,
        report_location VARCHAR(255) DEFAULT NULL,
        status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        requested_by INT UNSIGNED NOT NULL,
        reviewed_by INT UNSIGNED DEFAULT NULL,
        reviewed_at DATETIME DEFAULT NULL,
        rejection_reason TEXT DEFAULT NULL,
        published_project_id INT UNSIGNED DEFAULT NULL,
        import_payload LONGTEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_status (status),
        KEY idx_report (report_id, report_source)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Import payload built once when the approved request is first opened in
    // public_transparency.php, so re-opening it never re-copies the photos.
    $chk_payload = $conn->query("SHOW COLUMNS FROM transparency_upload_requests LIKE 'import_payload'");
    if ($chk_payload && $chk_payload->num_rows === 0) {
        $conn->query("ALTER TABLE transparency_upload_requests ADD COLUMN import_payload LONGTEXT DEFAULT NULL AFTER published_project_id");
    }

    $chk = $conn->query("SHOW COLUMNS FROM published_completed_projects LIKE 'progress_conducted_by'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE published_completed_projects ADD COLUMN progress_conducted_by VARCHAR(255) DEFAULT NULL AFTER completed_by");
    }
    $chk2 = $conn->query("SHOW COLUMNS FROM published_completed_projects LIKE 'source_report_id'");
    if ($chk2 && $chk2->num_rows === 0) {
        $conn->query("ALTER TABLE published_completed_projects ADD COLUMN source_report_id INT UNSIGNED DEFAULT NULL AFTER is_published");
        $conn->query("ALTER TABLE published_completed_projects ADD COLUMN source_report_source VARCHAR(50) DEFAULT NULL AFTER source_report_id");
    }

    // Some deployments carry a published_completed_projects table whose `id`
    // lost its AUTO_INCREMENT, which makes every insert fail with
    // "Field 'id' doesn't have a default value".
    try {
        $col = $conn->query(
            "SELECT EXTRA FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'published_completed_projects'
                AND COLUMN_NAME = 'id'"
        );
        $extra = ($col && ($r = $col->fetch_assoc())) ? (string)($r['EXTRA'] ?? '') : 'auto_increment';
        if (stripos($extra, 'auto_increment') === false) {
            $pk = $conn->query("SHOW KEYS FROM published_completed_projects WHERE Key_name = 'PRIMARY'");
            if ($pk && $pk->num_rows === 0) {
                $conn->query("ALTER TABLE published_completed_projects ADD PRIMARY KEY (id)");
            }
            $conn->query("ALTER TABLE published_completed_projects MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT");
        }
    } catch (Exception $e) {
        error_log('published_completed_projects id migration warning: ' . $e->getMessage());
    }
}

function transparency_mgmt_source_for_report(array $report): string {
    $src = strtolower(trim((string)($report['report_source_key'] ?? '')));
    if ($src === 'lgu') {
        return 'lgu';
    }
    if ($src === 'citizen') {
        return 'transport';
    }
    if ($src === 'cimm') {
        return 'cimm';
    }
    return !empty($report['created_by']) ? 'lgu' : 'transport';
}

function transparency_fetch_road_report($conn, int $report_id, string $source): ?array {
    $source = strtolower(trim($source));
    if (!in_array($source, ['lgu', 'citizen'], true)) {
        return null;
    }

    $row = fetch_one(
        "SELECT id, report_id, report_type, title, description, location, status, report_category,
                report_source, created_by, completed_at, cimm_engineer_name, cimm_budget,
                estimation, assigned_to
         FROM road_transportation_reports
         WHERE id = ?",
        [$report_id],
        'i'
    );
    if (!$row) {
        return null;
    }
    // Road projects belong to the Road Operations Supervisor and transportation
    // projects to the Transportation Operations Supervisor; both go through this
    // workflow. transparency_role_may_request() keeps them apart per role.
    $category = strtolower((string)($row['report_category'] ?? ''));
    if (!in_array($category, ['road', 'transportation'], true)) {
        return null;
    }
    if (strtolower((string)($row['status'] ?? '')) !== 'completed') {
        return null;
    }

    $expected = !empty($row['created_by']) ? 'lgu' : 'citizen';
    if ($source !== $expected) {
        return null;
    }

    $engineer = trim((string)($row['cimm_engineer_name'] ?? ''));
    if ($engineer === '') {
        $engineer = trim((string)($row['assigned_to'] ?? ''));
    }

    $cost = 0.0;
    if (($row['cimm_budget'] ?? '') !== '' && $row['cimm_budget'] !== null) {
        $cost = (float)$row['cimm_budget'];
    } elseif (($row['estimation'] ?? '') !== '' && $row['estimation'] !== null) {
        $cost = (float)$row['estimation'];
    }

    $row['report_source_key'] = $source;
    $row['engineer_name'] = $engineer;
    $row['cost'] = $cost;
    $label = ($category === 'transportation') ? 'Transportation Project #' : 'Road Project #';
    $row['fallback_title'] = $label . ($row['report_id'] ?? $report_id);
    return $row;
}

/**
 * Which completed projects a supervisor may send for transparency review. Each
 * role is limited to the reports its own portal lists, so a request can never
 * carry another portal's report:
 *  - Road Operations Supervisor: road projects, including CIMM road reports.
 *  - Transportation Operations Supervisor: transportation projects, both
 *    citizen reports and LGU monitoring reports.
 */
function transparency_role_may_request(string $role, array $report): bool {
    $category = strtolower(trim((string)($report['report_category'] ?? '')));
    $source = strtolower(trim((string)($report['report_source_key'] ?? '')));

    if ($role === 'road_ops_supervisor') {
        return $category === 'road';
    }
    if ($role === 'trans_ops_supervisor') {
        return $category === 'transportation' && in_array($source, ['lgu', 'citizen'], true);
    }
    return false;
}

/**
 * Tells the supervisor who raised a Transparency Upload Request that the
 * administrator has decided on it, through the existing report_notifications
 * feed that already carries the completion/cancellation notices. Addressed to
 * the requester's own account by email, so only they see it.
 *
 * report_id is 0 and the request id goes in update_id (both existing columns),
 * matching how this table's other non-report rows are stored: every other feed
 * query gates on the report existing in a live report table, so these notices
 * stay out of the admin's and other roles' lists, and the request id keeps each
 * notice tied to exactly one request and one completed project.
 *
 * Never throws: a notification problem must not undo a decision the admin has
 * already committed.
 */
function transparency_notify_requester($conn, array $request, string $decision): bool {
    $requested_by = (int)($request['requested_by'] ?? 0);
    $request_id = (int)($request['id'] ?? 0);
    if ($requested_by <= 0 || $request_id <= 0) {
        return false;
    }

    try {
        $stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
        $stmt->bind_param('i', $requested_by);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $email = trim((string)($row['email'] ?? ''));
        if ($email === '') {
            return false;
        }

        $approved = ($decision === 'approve');
        $type = $approved ? 'transparency_approved' : 'transparency_rejected';
        $message = $approved
            ? 'Transparency Upload Request Approved.'
            : 'Transparency Upload Request Rejected.';

        $ins = $conn->prepare(
            "INSERT INTO report_notifications (report_id, update_id, type, message, recipient_email, is_read)
             VALUES (0, ?, ?, ?, ?, 0)"
        );
        $ins->bind_param('isss', $request_id, $type, $message, $email);
        $ok = $ins->execute();
        $ins->close();
        return (bool)$ok;
    } catch (Exception $e) {
        error_log('transparency_notify_requester error: ' . $e->getMessage());
        return false;
    }
}

/**
 * CIMM verification reports live in their own synced table and use different
 * column names, so normalise them onto the same keys the road path exposes.
 * Only COMPLETED road ('Roads') reports are eligible, mirroring what the
 * Completed Projects view lists for the Road Operations Supervisor.
 */
function transparency_fetch_cimm_report($conn, int $report_id): ?array {
    $row = fetch_one("SELECT * FROM cimm_verification_reports WHERE id = ?", [$report_id], 'i');
    if (!$row) {
        return null;
    }
    if (strtolower(trim((string)($row['infrastructure'] ?? ''))) !== 'roads') {
        return null;
    }
    if (strtolower(trim((string)($row['verification_status'] ?? ''))) !== 'completed') {
        return null;
    }

    $reference = trim((string)($row['reference_code'] ?? '')) ?: (string)$report_id;
    $issue = trim((string)($row['issue'] ?? ''));

    // budget_allocation is the LGU-side override; budget is what CIMM synced.
    $cost = 0.0;
    if (($row['budget_allocation'] ?? '') !== '' && $row['budget_allocation'] !== null) {
        $cost = (float)$row['budget_allocation'];
    } elseif (($row['budget'] ?? '') !== '' && $row['budget'] !== null) {
        $cost = (float)$row['budget'];
    }

    return [
        'id' => (int)$row['id'],
        'report_id' => $reference,
        'report_type' => 'infrastructure_issue',
        'title' => $issue,
        'description' => $issue,
        'location' => trim((string)($row['location'] ?? '')),
        'status' => 'completed',
        'report_category' => 'road',
        'completed_at' => $row['resolved_at'] ?: ($row['verified_at'] ?? null),
        'engineer_name' => trim((string)($row['engineer'] ?? '')),
        'cost' => $cost,
        'report_source_key' => 'cimm',
        'fallback_title' => 'CIMM Road Project ' . $reference,
    ];
}

/**
 * Source-aware lookup for the transparency request workflow. Returns null when
 * the report is not eligible, which callers surface as a validation error.
 */
function transparency_fetch_request_report($conn, int $report_id, string $source): ?array {
    $source = strtolower(trim($source));
    if ($source === 'cimm') {
        return transparency_fetch_cimm_report($conn, $report_id);
    }
    return transparency_fetch_road_report($conn, $report_id, $source);
}

function transparency_fetch_updates_for_report($conn, int $report_id): array {
    $updates = [];
    $stmt = $conn->prepare(
        "SELECT u.id, u.title, u.description, u.created_at, COALESCE(us.full_name, 'LGU Staff') AS admin_name
         FROM report_updates u
         LEFT JOIN users us ON u.user_id = us.id
         WHERE u.report_id = ?
         ORDER BY u.created_at ASC, u.id ASC"
    );
    $stmt->bind_param('i', $report_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $media = [];
        $m_stmt = $conn->prepare(
            "SELECT id, file_path, file_type FROM report_update_media WHERE update_id = ? ORDER BY id ASC"
        );
        $m_stmt->bind_param('i', $row['id']);
        $m_stmt->execute();
        $m_res = $m_stmt->get_result();
        while ($m = $m_res->fetch_assoc()) {
            $media[] = $m;
        }
        $m_stmt->close();
        $row['media'] = $media;
        $updates[] = $row;
    }
    $stmt->close();
    return $updates;
}

function transparency_resolve_upload_path(string $file_path): ?string {
    $file_path = ltrim(str_replace('\\', '/', $file_path), '/');
    $roots = [
        dirname(__DIR__, 2),
        dirname(__DIR__, 3),
    ];
    foreach ($roots as $root) {
        $full = $root . '/' . $file_path;
        if (is_file($full)) {
            return $full;
        }
    }
    return null;
}

function transparency_copy_photo_to_completed(string $file_path): ?string {
    $src = transparency_resolve_upload_path($file_path);
    if (!$src) {
        return null;
    }
    $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        return null;
    }

    $upload_dir = dirname(__DIR__, 3) . '/uploads/completed_projects';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    $filename = uniqid('trans_', true) . '.' . $ext;
    $dest = $upload_dir . '/' . $filename;
    if (!@copy($src, $dest)) {
        return null;
    }
    return 'uploads/completed_projects/' . $filename;
}

function transparency_first_image_from_update(array $update): ?string {
    foreach ($update['media'] ?? [] as $media) {
        $type = strtolower((string)($media['file_type'] ?? ''));
        $path = (string)($media['file_path'] ?? '');
        if ($path === '') {
            continue;
        }
        if ($type === 'image' || $type === '' || preg_match('/\.(jpe?g|png|gif|webp)$/i', $path)) {
            return $path;
        }
    }
    return null;
}

function transparency_date_only(?string $datetime): ?string {
    if (!$datetime) {
        return null;
    }
    $ts = strtotime($datetime);
    if ($ts === false) {
        return null;
    }
    return date('Y-m-d', $ts);
}

/**
 * Build import payload scoped to one report. Throws on validation failure.
 *
 * @return array<string,mixed>
 */
function transparency_build_import_data($conn, int $report_id, string $source): array {
    $report = transparency_fetch_request_report($conn, $report_id, $source);
    if (!$report) {
        throw new InvalidArgumentException('Report not found or not eligible for transparency import.');
    }

    $updates = transparency_fetch_updates_for_report($conn, $report_id);
    if (empty($updates)) {
        throw new InvalidArgumentException('This report has no progress updates to import.');
    }

    $conducted = [];
    foreach ($updates as $upd) {
        $name = trim((string)($upd['admin_name'] ?? ''));
        if ($name !== '' && !in_array($name, $conducted, true)) {
            $conducted[] = $name;
        }
    }

    $first_update = $updates[0];
    $last_update = $updates[count($updates) - 1];
    $start_date = transparency_date_only($first_update['created_at'] ?? null);
    $end_date = transparency_date_only($last_update['created_at'] ?? null);

    $completion_date = null;
    foreach ($updates as $upd) {
        if (strtolower(trim((string)($upd['title'] ?? ''))) === 'completed') {
            $completion_date = transparency_date_only($upd['created_at'] ?? null);
            break;
        }
    }
    if (!$completion_date) {
        $completion_date = transparency_date_only($report['completed_at'] ?? null) ?: $end_date;
    }

    $before_src = transparency_first_image_from_update($first_update);
    $after_src = transparency_first_image_from_update($last_update);

    return [
        'title' => trim((string)($report['title'] ?? '')) ?: (string)($report['fallback_title'] ?? ('Road Project #' . $report_id)),
        'description' => trim((string)($report['description'] ?? '')),
        'location' => trim((string)($report['location'] ?? '')),
        'completed_date' => $completion_date,
        'first_update_date' => $start_date,
        'last_update_date' => $end_date,
        'cost' => (float)($report['cost'] ?? 0),
        'completed_by' => (string)($report['engineer_name'] ?? ''),
        'progress_conducted_by' => implode(', ', $conducted),
        'before_photo' => $before_src ? transparency_copy_photo_to_completed($before_src) : null,
        'photo' => $after_src ? transparency_copy_photo_to_completed($after_src) : null,
        'report_type' => (string)($report['report_type'] ?? ''),
        'report_mgmt_source' => transparency_mgmt_source_for_report($report),
        'source_report_id' => $report_id,
        'source_report_source' => $source,
    ];
}

function transparency_create_draft_from_report($conn, int $report_id, string $source): int {
    $data = transparency_build_import_data($conn, $report_id, $source);

    $stmt = $conn->prepare(
        "INSERT INTO published_completed_projects
            (title, description, location, completed_date, cost, completed_by,
             progress_conducted_by, photo, before_photo, is_published,
             source_report_id, source_report_source)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)"
    );
    $stmt->bind_param(
        'ssssdssssis',
        $data['title'],
        $data['description'],
        $data['location'],
        $data['completed_date'],
        $data['cost'],
        $data['completed_by'],
        $data['progress_conducted_by'],
        $data['photo'],
        $data['before_photo'],
        $data['source_report_id'],
        $data['source_report_source']
    );
    if (!$stmt->execute()) {
        throw new RuntimeException('Failed to create transparency draft: ' . $conn->error);
    }
    $project_id = (int)$stmt->insert_id;
    $stmt->close();
    return $project_id;
}
