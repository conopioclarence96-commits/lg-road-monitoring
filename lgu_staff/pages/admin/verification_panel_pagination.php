<?php
/**
 * Panel pagination + server-side search for verification_monitoring.php.
 * Each panel (LGU, Citizen, CIMM) uses its own LIMIT/OFFSET (10 per page).
 */

function canVerifyReport($category, $source) {
    return !($category === 'road' && $source === 'local');
}

/** LGU road report: CIMM has set resolution_status to Scheduled. */
function vm_lgu_road_cimm_status_is_scheduled(?string $cimm_status): bool {
    return strtolower(trim((string)($cimm_status ?? ''))) === 'scheduled';
}

function vm_panel_page(string $panel): int {
    return max(1, (int)($_GET[$panel . '_page'] ?? 1));
}

function vm_panel_offset(string $panel, int $perPage): int {
    return (vm_panel_page($panel) - 1) * max(1, $perPage);
}

function vm_build_panel_pagination(string $panel, int $page, int $perPage, int $total): array {
    $perPage = max(1, $perPage);
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = min(max(1, $page), $totalPages);
    $from = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
    $to = min($total, $page * $perPage);

    $html = '<div class="vm-panel-pagination" data-panel="' . htmlspecialchars($panel) . '" data-page="' . $page . '" data-total="' . $total . '" data-per-page="' . $perPage . '">';
    $html .= '<div class="vm-panel-pagination-info">Showing ' . $from . '–' . $to . ' of ' . $total . '</div>';
    $html .= '<div class="vm-panel-pagination-controls">';
    $prevDisabled = $page <= 1;
    $nextDisabled = $page >= $totalPages;
    $html .= '<button type="button" class="vm-page-btn' . ($prevDisabled ? ' disabled' : '') . '" data-panel="' . htmlspecialchars($panel) . '" data-page="' . ($page - 1) . '"' . ($prevDisabled ? ' disabled aria-disabled="true"' : '') . '><i class="fas fa-chevron-left"></i></button>';
    $html .= '<span class="vm-page-label">Page ' . $page . ' / ' . $totalPages . '</span>';
    $html .= '<button type="button" class="vm-page-btn' . ($nextDisabled ? ' disabled' : '') . '" data-panel="' . htmlspecialchars($panel) . '" data-page="' . ($page + 1) . '"' . ($nextDisabled ? ' disabled aria-disabled="true"' : '') . '><i class="fas fa-chevron-right"></i></button>';
    $html .= '</div></div>';

    return [
        'html' => $html,
        'total_pages' => $totalPages,
        'page' => $page,
        'from' => $from,
        'to' => $to,
    ];
}

/**
 * @return array{rows:array<int,array>,total:int}
 */
function getLguReportsForVerification(
    mysqli $conn,
    bool $transport_only = false,
    bool $road_only = false,
    int $limit = 10,
    int $offset = 0,
    string $search = ''
): array {
    $limit = max(1, $limit);
    $offset = max(0, $offset);

    $transport_where = "status IN ('pending','rejected')";
    $maintenance_where = "status IN ('pending','rejected')";
    $infra_exclude = "report_type != 'infrastructure_issue'";
    $citizen_exclude = "(report_source IS NULL OR report_source != 'local' OR report_category IS NULL OR report_category != 'transportation' OR created_by IS NULL OR created_by != 0)";
    $transport_category_filter = $transport_only ? " AND report_category = 'transportation'" : '';
    $road_category_filter = $road_only ? " AND report_category = 'road'" : '';

    $search_clause = '';
    $search = trim($search);
    if ($search !== '') {
        $like = $conn->real_escape_string($search);
        $like = str_replace(['%', '_'], ['\\%', '\\_'], $like);
        $search_clause = " AND report_id LIKE '%{$like}%'";
    }

    $source_case = "CASE WHEN report_source = 'external' THEN 'external' ELSE 'lgu' END as source";
    $transport_cols = "id, report_id, title, report_type, report_category, report_source, department, priority, status, cimm_sync_status, created_date, due_date, description, location, attachments, latitude, longitude, detected_district, created_at, updated_at, approved_at, rejected_at, cimm_engineer_name, cimm_budget, cimm_starting_date, cimm_estimated_end_date, cimm_status, cimm_district, created_by";
    $maintenance_cols = "id, report_id, title, report_type, NULL as report_category, NULL as report_source, department, priority, status, NULL as cimm_sync_status, created_date, due_date, description, location, NULL as attachments, NULL as latitude, NULL as longitude, NULL as detected_district, created_at, updated_at, approved_at, rejected_at, NULL as cimm_engineer_name, NULL as cimm_budget, NULL as cimm_starting_date, NULL as cimm_estimated_end_date, NULL as cimm_status, NULL as cimm_district, NULL as created_by";

    $parts = ["(SELECT {$source_case}, {$transport_cols} FROM road_transportation_reports WHERE {$transport_where} AND {$infra_exclude} AND {$citizen_exclude}{$transport_category_filter}{$road_category_filter}{$search_clause})"];

    if (!$transport_only) {
        $parts[] = "(SELECT 'maintenance' as source, {$maintenance_cols} FROM road_maintenance_reports WHERE {$maintenance_where}{$search_clause})";
    }

    $union = implode(' UNION ALL ', $parts);
    $countRow = fetch_one("SELECT COUNT(*) AS c FROM ({$union}) AS combined");
    $total = (int)($countRow['c'] ?? 0);

    $sql = "SELECT * FROM ({$union}) AS combined ORDER BY created_at DESC LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset;
    $rows = fetch_all($sql);

    return [
        'rows' => is_array($rows) ? $rows : [],
        'total' => $total,
    ];
}

/**
 * @return array{rows:array<int,array>,total:int}
 */
function getCitizenReportsForVerification(
    mysqli $conn,
    int $limit = 10,
    int $offset = 0,
    string $search = ''
): array {
    $limit = max(1, $limit);
    $offset = max(0, $offset);

    $where = "report_source = 'local' AND report_category = 'transportation' AND created_by = 0 AND status IN ('pending','rejected')";
    $search = trim($search);
    if ($search !== '') {
        $like = $conn->real_escape_string($search);
        $like = str_replace(['%', '_'], ['\\%', '\\_'], $like);
        $where .= " AND report_id LIKE '%{$like}%'";
    }

    $countRow = fetch_one("SELECT COUNT(*) AS c FROM road_transportation_reports WHERE {$where}");
    $total = (int)($countRow['c'] ?? 0);

    $sql = "SELECT id, report_id, title, report_type, report_category, report_source,
                   department, priority, status, created_date, due_date, description, location,
                   attachments, latitude, longitude, created_at, updated_at, approved_at, rejected_at,
                   reporter_name, reporter_email, reporter_phone, image_path, created_by
            FROM road_transportation_reports
            WHERE {$where}
            ORDER BY created_at DESC
            LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset;

    $rows = fetch_all($sql);
    return [
        'rows' => is_array($rows) ? $rows : [],
        'total' => $total,
    ];
}

/**
 * @return array{rows:array<int,array>,total:int}
 */
function getCimmReportsPaginated(
    string $filter = 'all',
    int $limit = 10,
    int $offset = 0,
    string $search = ''
): array {
    $limit = max(1, $limit);
    $offset = max(0, $offset);

    $pdo = rgmap_verification_pdo();
    $rows = rgmap_fetch_cimm_verification_reports($pdo, [
        'limit' => 5000,
        'infrastructure' => 'Roads',
        'verification_status' => 'Pending Review',
        'approval_status' => 'Approved',
    ]);

    $mapped = array_map('rgmap_map_cimm_row_for_display', $rows);

    if ($filter === 'staff' || $filter === 'dept') {
        $mapped = array_values(array_filter($mapped, static function ($r) use ($filter) {
            return ($r['report_type'] ?? '') === $filter;
        }));
    }

    $mapped = array_values(array_filter($mapped, static function ($r) {
        return ($r['status'] ?? '') === 'pending';
    }));

    $search = trim($search);
    if ($search !== '') {
        $mapped = array_values(array_filter($mapped, static function ($r) use ($search) {
            return stripos((string)($r['rep_number'] ?? ''), $search) !== false;
        }));
    }

    $total = count($mapped);
    $pageRows = array_slice($mapped, $offset, $limit);

    return [
        'rows' => $pageRows,
        'total' => $total,
    ];
}

function vm_lookup_creator_map(mysqli $conn, array $reports): array {
    $creator_ids = [];
    foreach ($reports as $row) {
        $cb = (int)($row['created_by'] ?? 0);
        if ($cb > 0) {
            $creator_ids[$cb] = true;
        }
    }
    if (!$creator_ids) {
        return [];
    }
    $creator_map = [];
    try {
        $in = implode(',', array_map('intval', array_keys($creator_ids)));
        $res = $conn->query("SELECT id, full_name FROM users WHERE id IN ({$in})");
        if ($res) {
            while ($u = $res->fetch_assoc()) {
                $creator_map[(int)$u['id']] = $u;
            }
        }
    } catch (Exception $e) {
        error_log('vm_lookup_creator_map: ' . $e->getMessage());
    }
    return $creator_map;
}

function vm_build_lgu_rows_json(array $reports, array $creator_map, bool $is_transport_supervisor, bool $is_road_supervisor): array {
    $out = [];
    foreach ($reports as $lr) {
        if ($is_transport_supervisor && ($lr['source'] ?? '') === 'maintenance') {
            continue;
        }
        $entry = [
            'id' => (int)($lr['id'] ?? 0),
            'report_id' => $lr['report_id'] ?? null,
            'title' => $lr['title'] ?? null,
            'report_type' => $lr['report_type'] ?? null,
            'report_category' => $lr['report_category'] ?? null,
            'source' => $lr['source'] ?? null,
            'department' => $lr['department'] ?? null,
            'priority' => $lr['priority'] ?? null,
            'status' => $lr['status'] ?? null,
            'location' => $lr['location'] ?? null,
            'latitude' => $lr['latitude'] ?? null,
            'longitude' => $lr['longitude'] ?? null,
            'detected_district' => $lr['detected_district'] ?? null,
            'description' => $lr['description'] ?? null,
            'attachments' => $lr['attachments'] ?? null,
            'created_at' => $lr['created_at'] ?? null,
            'updated_at' => $lr['updated_at'] ?? null,
            'approved_at' => $lr['approved_at'] ?? null,
            'rejected_at' => $lr['rejected_at'] ?? null,
            'created_by' => (int)($lr['created_by'] ?? 0),
            'created_by_name' => ($creator_map[(int)($lr['created_by'] ?? 0)] ?? [])['full_name'] ?? null,
            'engineer' => $lr['engineer'] ?? $lr['cimm_engineer_name'] ?? null,
            'budget_allocation' => $lr['budget_allocation'] ?? $lr['cimm_budget'] ?? null,
            'cimm_starting_date' => $lr['cimm_starting_date'] ?? null,
            'cimm_estimated_end_date' => $lr['cimm_estimated_end_date'] ?? null,
        ];
        if ($is_road_supervisor) {
            $entry['creator_full_name'] = $lr['creator_full_name'] ?? null;
            $entry['creator_phone'] = $lr['creator_phone'] ?? null;
            $entry['creator_email'] = $lr['creator_email'] ?? null;
        }
        $out[(string)(int)$lr['id']] = $entry;
    }
    return $out;
}

function vm_build_citizen_rows_json(array $reports): array {
    $out = [];
    foreach ($reports as $cr) {
        $out[(string)(int)$cr['id']] = [
            'id' => (int)$cr['id'],
            'report_id' => $cr['report_id'] ?? null,
            'title' => $cr['title'] ?? null,
            'report_type' => $cr['report_type'] ?? null,
            'report_category' => $cr['report_category'] ?? null,
            'department' => $cr['department'] ?? null,
            'priority' => $cr['priority'] ?? null,
            'status' => $cr['status'] ?? null,
            'location' => $cr['location'] ?? null,
            'latitude' => $cr['latitude'] ?? null,
            'longitude' => $cr['longitude'] ?? null,
            'description' => $cr['description'] ?? null,
            'created_at' => $cr['created_at'] ?? null,
            'updated_at' => $cr['updated_at'] ?? null,
            'approved_at' => $cr['approved_at'] ?? null,
            'rejected_at' => $cr['rejected_at'] ?? null,
            'reporter_name' => $cr['reporter_name'] ?? '—',
            'reporter_email' => $cr['reporter_email'] ?? '—',
            'reporter_phone' => $cr['reporter_phone'] ?? '—',
            'image_path' => $cr['image_path'] ?? null,
            'attachments' => $cr['attachments'] ?? null,
        ];
    }
    return $out;
}

function vm_build_cimm_rows_json(array $reports): array {
    $out = [];
    foreach ($reports as $row) {
        $out[(string)(int)$row['id']] = $row;
    }
    return $out;
}

function vm_render_lgu_panel_tbody(array $reports, bool $is_transport_supervisor): string {
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

    ob_start();
    $hasRows = false;
    foreach ($reports as $report) {
        if ($is_transport_supervisor && ($report['source'] ?? '') === 'maintenance') {
            continue;
        }
        $hasRows = true;

        $lgu_status_class = '';
        if ($report['status'] === 'approved') $lgu_status_class = 'approved';
        elseif ($report['status'] === 'cancelled') $lgu_status_class = 'cancelled';
        elseif ($report['status'] === 'pending') $lgu_status_class = 'pending';
        elseif ($report['status'] === 'in-progress') $lgu_status_class = 'in-progress';
        elseif ($report['status'] === 'completed') $lgu_status_class = 'completed';

        $report_category = $report['report_category'] ?? null;
        $report_source = $report['report_source'] ?? null;
        $can_verify = canVerifyReport($report_category, $report_source);
        $pending_ext_verify = strtolower(trim((string)($report['cimm_status'] ?? ''))) !== 'scheduled'
            && ($report['cimm_sync_status'] ?? '') !== 'verified'
            && !$can_verify
            && ($report['status'] ?? '') === 'pending';
        $ready_for_approval = ($report_category === 'transportation')
            ? (($report['status'] ?? '') === 'pending')
            : (($report_category === 'road')
                && vm_lgu_road_cimm_status_is_scheduled($report['cimm_status'] ?? null)
                && ($report['status'] ?? '') === 'pending');

        $lgu_filter_status = 'pending';
        if (in_array($report['status'], ['approved', 'completed'], true)) $lgu_filter_status = 'approved';
        elseif (in_array($report['status'], ['cancelled'], true)) $lgu_filter_status = 'rejected';
        ?>
        <tr data-id="<?php echo (int)$report['id']; ?>" data-report-id="<?php echo (int)$report['id']; ?>" data-status="<?php echo $lgu_filter_status; ?>" data-source="<?php echo htmlspecialchars((string)($report['source'] ?? ''), ENT_QUOTES); ?>">
            <td>
                <div class="lgu-action-group">
                    <button class="lgu-action-btn" onclick="viewLguReport(<?php echo (int)$report['id']; ?>)">
                        <i class="fas fa-eye" id="icon-<?php echo (int)$report['id']; ?>"></i> View
                    </button>
                    <?php if ($pending_ext_verify): ?>
                        <span class="lgu-status-badge t-badge t-badge-pending" style="font-size:10px;padding:3px 8px;">Ext. Verify</span>
                    <?php elseif ($ready_for_approval): ?>
                        <form method="POST" class="lgu-action-form">
                            <input type="hidden" name="report_id" value="<?php echo (int)$report['id']; ?>">
                            <input type="hidden" name="source" value="<?php echo htmlspecialchars((string)($report['source'] ?? ''), ENT_QUOTES); ?>">
                            <button type="submit" name="action" value="cimm_approve" class="lgu-verify-btn" title="Approve CIMM verified report">
                                <i class="fas fa-check-circle"></i> Approve
                            </button>
                        </form>
                        <form method="POST" class="lgu-action-form" onsubmit="return confirm('Are you sure you want to reject this report?');">
                            <input type="hidden" name="report_id" value="<?php echo (int)$report['id']; ?>">
                            <input type="hidden" name="source" value="<?php echo htmlspecialchars((string)($report['source'] ?? ''), ENT_QUOTES); ?>">
                            <button type="submit" name="action" value="reject" class="lgu-reject-btn" title="Reject report">
                                <i class="fas fa-times"></i> Reject
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="expanded-details" id="details-<?php echo (int)$report['id']; ?>" style="display:none;margin-top:12px;padding-top:12px;border-top:2px solid rgba(30,60,114,0.1);">
                    <div class="detail-grid">
                        <div class="detail-item"><strong>Report ID:</strong> <?php echo htmlspecialchars($report['report_id'] ?? 'N/A'); ?></div>
                        <div class="detail-item"><strong>Type:</strong> <?php $lgu_type = $report['report_type'] ?? ''; echo htmlspecialchars($lgu_type_labels[$lgu_type] ?? ucfirst($lgu_type)); ?></div>
                        <div class="detail-item"><strong>Priority:</strong> <span class="lgu-status-badge <?php echo htmlspecialchars((string)($report['priority'] ?? 'medium')); ?>"><?php echo htmlspecialchars((string)($report['priority'] ?? 'medium')); ?></span></div>
                        <div class="detail-item"><strong>Status:</strong>
                            <?php if ($pending_ext_verify): ?>
                            <span class="lgu-status-badge t-badge t-badge-pending">Awaiting CIMM Verification</span>
                            <?php elseif ($ready_for_approval): ?>
                            <span class="lgu-status-badge t-badge t-badge-info">Ready for Final Approval</span>
                            <?php else: ?>
                            <span class="lgu-status-badge <?php echo $lgu_status_class; ?>"><?php echo htmlspecialchars((string)($report['status'] ?? 'N/A')); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="detail-item full-width"><strong>Full Description:</strong><div class="t-bg-primary" style="margin-top:8px;padding:12px;border-radius:8px;"><?php echo nl2br(htmlspecialchars($report['description'] ?? 'No description provided')); ?></div></div>
                        <div class="detail-item full-width"><strong>Location Address:</strong><div style="margin-top:8px;"><?php echo htmlspecialchars($report['location'] ?? 'N/A'); ?></div></div>
                        <?php if (!empty($report['latitude']) && !empty($report['longitude'])): ?>
                        <div class="detail-item full-width"><strong>Location Coordinates:</strong><div style="margin-top:8px;">Latitude: <?php echo htmlspecialchars((string)$report['latitude']); ?>, Longitude: <?php echo htmlspecialchars((string)$report['longitude']); ?> <a href="https://www.google.com/maps?q=<?php echo htmlspecialchars((string)$report['latitude']); ?>,<?php echo htmlspecialchars((string)$report['longitude']); ?>" target="_blank" class="t-text-link" style="margin-left:10px;"><i class="fas fa-map-marker-alt"></i> View on Map</a></div></div>
                        <?php endif; ?>
                        <?php if (!empty($report['attachments'])):
                            $attachments = json_decode((string)$report['attachments'], true);
                            if (is_array($attachments) && !empty($attachments)): ?>
                        <div class="detail-item full-width"><strong>Attached Images:</strong><div style="margin-top:12px;display:flex;gap:15px;flex-wrap:wrap;">
                            <?php foreach ($attachments as $attachment):
                                if (isset($attachment['type']) && $attachment['type'] === 'image' && isset($attachment['file_path'])): ?>
                            <img src="../../<?php echo htmlspecialchars((string)$attachment['file_path']); ?>" alt="Report Image" style="max-width:300px;max-height:300px;border-radius:8px;border:1px solid rgba(55,98,200,0.3);cursor:pointer;" onclick="window.open(this.src, '_blank')" onerror="this.style.display='none'" title="Click to view full size" />
                            <?php endif; endforeach; ?>
                        </div></div>
                        <?php endif; endif; ?>
                        <div class="detail-item"><strong>Created:</strong> <?php echo htmlspecialchars((string)($report['created_at'] ?? 'N/A')); ?></div>
                        <?php if (!empty($report['updated_at']) && $report['updated_at'] !== $report['created_at']): ?><div class="detail-item"><strong>Last Updated:</strong> <?php echo htmlspecialchars((string)$report['updated_at']); ?></div><?php endif; ?>
                        <?php if (!empty($report['approved_at'])): ?><div class="detail-item"><strong>Approved At:</strong> <?php echo htmlspecialchars((string)$report['approved_at']); ?></div><?php endif; ?>
                        <?php if (!empty($report['rejected_at'])): ?><div class="detail-item"><strong>Rejected At:</strong> <?php echo htmlspecialchars((string)$report['rejected_at']); ?></div><?php endif; ?>
                    </div>
                </div>
            </td>
            <td><?php echo htmlspecialchars((string)($report['report_id'] ?? '')); ?></td>
            <td><?php echo htmlspecialchars(strlen($report['title'] ?? '') > 35 ? substr($report['title'], 0, 35) . '...' : ($report['title'] ?? '')); ?></td>
            <td><?php if (($report['location'] ?? '') !== ''): ?><span title="<?php echo htmlspecialchars((string)$report['location']); ?>"><?php echo htmlspecialchars(strlen((string)$report['location']) > 40 ? substr((string)$report['location'], 0, 40) . '...' : (string)$report['location']); ?></span><?php else: ?>—<?php endif; ?></td>
            <td><span class="lgu-status-badge <?php echo htmlspecialchars((string)($report['priority'] ?? 'medium')); ?>"><?php echo ucfirst(htmlspecialchars((string)($report['priority'] ?? 'medium'))); ?></span></td>
            <td>
                <?php
                $cimmStatusRaw = trim((string)($report['cimm_status'] ?? ''));
                if ($cimmStatusRaw !== ''):
                    $cimmStatusLc = strtolower($cimmStatusRaw);
                    $cimmStatusMeta = [
                        'pending' => ['Scheduled', 'cimm-st-scheduled'],
                        'scheduled' => ['Scheduled', 'cimm-st-scheduled'],
                        'awaiting engineer' => ['Awaiting Engineer', 'cimm-st-awaiting'],
                        '' => ['Awaiting Engineer', 'cimm-st-awaiting'],
                        'pending acceptance' => ['Pending Acceptance', 'cimm-st-acceptance'],
                        'pending admin approval' => ['Pending Approval', 'cimm-st-approval'],
                        'approved' => ['Validated', 'cimm-st-validated'],
                        'in progress' => ['In Progress', 'cimm-st-progress'],
                        'pending completion' => ['Pending Completion', 'cimm-st-pending'],
                        'completed' => ['Completed', 'cimm-st-completed'],
                        'archived' => ['Archived', 'cimm-st-archived'],
                        'cancelled' => ['Cancelled', 'cimm-st-cancelled'],
                        'rejected' => ['Rejected', 'cimm-st-cancelled'],
                    ];
                    [$cimmDisplayLabel, $cimmStatusClass] = $cimmStatusMeta[$cimmStatusLc] ?? [$cimmStatusRaw, 'cimm-st-pending'];
                ?>
                <span class="lgu-status-badge <?php echo $cimmStatusClass; ?>" title="CIMM report status"><?php echo htmlspecialchars($cimmDisplayLabel); ?></span>
                <?php elseif ($pending_ext_verify): ?>
                <span class="lgu-status-badge t-badge t-badge-pending">Awaiting Ext.</span>
                <?php else: ?>
                <span class="lgu-status-badge <?php echo $lgu_status_class; ?>"><?php echo ucfirst(htmlspecialchars(str_replace('-', ' ', (string)($report['status'] ?? '')))); ?></span>
                <?php endif; ?>
            </td>
            <td><?php echo !empty($report['created_at']) ? date('M d, Y', strtotime((string)$report['created_at'])) : '—'; ?></td>
        </tr>
        <?php
    }

    if (!$hasRows):
        ?>
        <tr>
            <td colspan="7">
                <div class="lgu-empty-state">
                    <div class="lgu-empty-icon"><i class="fas fa-clipboard-list"></i></div>
                    <h4>No LGU reports yet</h4>
                    <p>New monitoring reports will appear here when they are submitted.</p>
                </div>
            </td>
        </tr>
        <?php
    endif;

    return (string)ob_get_clean();
}

function vm_render_citizen_panel_tbody(array $reports): string {
    ob_start();
    if (!empty($reports)):
        foreach ($reports as $crow):
            $c_status_class = '';
            if ($crow['status'] === 'approved') $c_status_class = 'approved';
            elseif ($crow['status'] === 'cancelled') $c_status_class = 'cancelled';
            elseif ($crow['status'] === 'pending') $c_status_class = 'pending';
            elseif ($crow['status'] === 'in-progress') $c_status_class = 'in-progress';
            elseif ($crow['status'] === 'completed') $c_status_class = 'completed';
            $citizen_filter_status = 'pending';
            if (in_array($crow['status'], ['approved', 'completed'], true)) $citizen_filter_status = 'approved';
            elseif (in_array($crow['status'], ['cancelled'], true)) $citizen_filter_status = 'rejected';
            ?>
        <tr data-id="<?php echo (int)$crow['id']; ?>" data-report-id="<?php echo (int)$crow['id']; ?>" data-status="<?php echo $citizen_filter_status; ?>" data-source="citizen">
            <td>
                <div class="citizen-action-group">
                    <button class="citizen-action-btn" onclick="viewCitizenReport(<?php echo (int)$crow['id']; ?>)"><i class="fas fa-eye"></i> View</button>
                    <?php if (($crow['status'] ?? '') === 'pending'): ?>
                    <form method="POST" class="citizen-action-form" onsubmit="return confirm('Are you sure you want to approve this citizen report?');">
                        <input type="hidden" name="report_id" value="<?php echo (int)$crow['id']; ?>">
                        <input type="hidden" name="source" value="transport">
                        <button type="submit" name="action" value="approve" class="citizen-verify-btn" title="Approve report"><i class="fas fa-check"></i> Approve</button>
                    </form>
                    <form method="POST" class="citizen-action-form" onsubmit="return confirm('Are you sure you want to reject this citizen report?');">
                        <input type="hidden" name="report_id" value="<?php echo (int)$crow['id']; ?>">
                        <input type="hidden" name="source" value="transport">
                        <button type="submit" name="action" value="reject" class="citizen-reject-btn" title="Reject report"><i class="fas fa-times"></i> Reject</button>
                    </form>
                    <?php endif; ?>
                </div>
            </td>
            <td><?php echo htmlspecialchars((string)($crow['report_id'] ?? '')); ?></td>
            <td><?php echo htmlspecialchars(strlen($crow['title'] ?? '') > 35 ? substr($crow['title'], 0, 35) . '...' : ($crow['title'] ?? '')); ?></td>
            <td><?php echo htmlspecialchars((string)($crow['location'] ?? '—')); ?></td>
            <td><span class="citizen-status-badge <?php echo htmlspecialchars((string)($crow['priority'] ?? '')); ?>"><?php echo ucfirst(htmlspecialchars((string)($crow['priority'] ?? ''))); ?></span></td>
            <td><span class="citizen-status-badge <?php echo $c_status_class; ?>"><?php echo ucfirst(htmlspecialchars(str_replace('-', ' ', (string)($crow['status'] ?? '')))); ?></span></td>
            <td><?php echo !empty($crow['created_at']) ? date('M d, Y', strtotime((string)$crow['created_at'])) : '—'; ?></td>
        </tr>
            <?php
        endforeach;
    else:
        ?>
        <tr>
            <td colspan="7">
                <div class="citizen-empty-state">
                    <div class="citizen-empty-icon"><i class="fas fa-users"></i></div>
                    <h4>No citizen reports yet</h4>
                    <p>Public portal submissions will show up here for review.</p>
                </div>
            </td>
        </tr>
        <?php
    endif;
    return (string)ob_get_clean();
}

function vm_render_cimm_panel_tbody(array $cimm_reports, $sql_reports = null, bool $include_sql = false): string {
    ob_start();
    $hasAnyReports = false;

    if (!empty($cimm_reports)):
        foreach ($cimm_reports as $row):
            $hasAnyReports = true;
            $cimm_filter_status = 'pending';
            if (in_array($row['status'], ['completed', 'approved', 'verified'], true)) $cimm_filter_status = 'approved';
            elseif (in_array($row['status'], ['resolved', 'dismissed'], true)) $cimm_filter_status = 'rejected';
            ?>
        <tr data-id="<?php echo (int)$row['id']; ?>" data-report-id="<?php echo (int)$row['id']; ?>" data-status="<?php echo $cimm_filter_status; ?>" data-source="cimm">
            <td>
                <div class="dept-action-group">
                    <button class="dept-action-btn" onclick="viewCimmReport(<?php echo (int)$row['id']; ?>)"><i class="fas fa-eye"></i> View</button>
                    <form method="POST" class="dept-action-form" onsubmit="return confirm('Are you sure you want to approve this CIMM report?');">
                        <input type="hidden" name="cimm_req_id" value="<?php echo (int)$row['cimm_req_id']; ?>">
                        <button type="submit" name="action" value="approve_cimm" class="dept-verify-btn" title="Approve report"><i class="fas fa-check"></i> Approve</button>
                    </form>
                    <form method="POST" class="dept-action-form" onsubmit="return confirm('Are you sure you want to reject this CIMM report?');">
                        <input type="hidden" name="cimm_req_id" value="<?php echo (int)$row['cimm_req_id']; ?>">
                        <input type="hidden" name="rejection_reason" value="Rejected by admin">
                        <button type="submit" name="action" value="reject_cimm" class="dept-reject-btn" title="Reject report"><i class="fas fa-times"></i> Reject</button>
                    </form>
                </div>
            </td>
            <td><?php echo htmlspecialchars((string)($row['rep_number'] ?? '')); ?></td>
            <td><?php echo htmlspecialchars((string)($row['infrastructure'] ?? '')); ?></td>
            <td><?php echo htmlspecialchars((string)($row['location'] ?? '')); ?></td>
            <td><?php echo htmlspecialchars(strlen($row['issue_notes'] ?? '') > 40 ? substr($row['issue_notes'], 0, 40) . '...' : ($row['issue_notes'] ?? '')); ?></td>
            <td><span class="dept-status-badge <?php echo htmlspecialchars((string)($row['priority'] ?? '')); ?>"><?php echo ucfirst(htmlspecialchars((string)($row['priority'] ?? ''))); ?></span></td>
            <td><span class="dept-status-badge <?php echo htmlspecialchars((string)($row['status'] ?? '')); ?>"><?php echo ucfirst(htmlspecialchars(str_replace('-', ' ', (string)($row['status'] ?? '')))); ?></span></td>
        </tr>
            <?php
        endforeach;
    endif;

    if ($include_sql && $sql_reports && method_exists($sql_reports, 'num_rows') && $sql_reports->num_rows > 0):
        $sql_reports->data_seek(0);
        while ($row = $sql_reports->fetch_assoc()):
            $hasAnyReports = true;
            $status = 'pending';
            if ($row['engineer_accepted'] == 1) {
                $status = 'completed';
            } elseif (!empty($row['decline_reason'])) {
                $status = 'cancelled';
            } elseif (!empty($row['decline_reviewed'])) {
                $status = $row['decline_reviewed'] == 1 ? 'in-progress' : 'cancelled';
            }
            $sql_filter_status = 'pending';
            if (in_array($status, ['completed'], true)) $sql_filter_status = 'approved';
            elseif (in_array($status, ['cancelled'], true)) $sql_filter_status = 'rejected';
            ?>
        <tr data-id="<?php echo (int)$row['rep_id']; ?>" data-report-id="<?php echo (int)$row['rep_id']; ?>" data-status="<?php echo $sql_filter_status; ?>" data-source="cimm_sql">
            <td><button class="dept-action-btn" onclick="viewSqlReport(<?php echo (int)$row['rep_id']; ?>)"><i class="fas fa-eye"></i> View</button></td>
            <td>REP-<?php echo (int)$row['rep_id']; ?></td>
            <td><?php echo htmlspecialchars((string)$row['res_id']); ?></td>
            <td>—</td>
            <td><?php echo htmlspecialchars(strlen($row['decline_reason'] ?? '') > 40 ? substr($row['decline_reason'], 0, 40) . '...' : ($row['decline_reason'] ?? '—')); ?></td>
            <td><span class="dept-status-badge <?php echo strtolower(htmlspecialchars((string)$row['priority_lvl'])); ?>"><?php echo ucfirst(htmlspecialchars((string)$row['priority_lvl'])); ?></span></td>
            <td><span class="dept-status-badge <?php echo $status; ?>"><?php echo ucfirst(htmlspecialchars($status)); ?></span></td>
        </tr>
            <?php
        endwhile;
    endif;

    if (!$hasAnyReports):
        ?>
        <tr>
            <td colspan="7">
                <div class="dept-empty-state">
                    <div class="dept-empty-icon"><i class="fas fa-building"></i></div>
                    <h4>No CIMM reports yet</h4>
                    <p>Department infrastructure reports will appear here when received.</p>
                </div>
            </td>
        </tr>
        <?php
    endif;

    return (string)ob_get_clean();
}
