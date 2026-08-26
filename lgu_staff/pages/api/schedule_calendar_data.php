<?php
/**
 * Schedule calendar data helpers — role-scoped approved/in-progress reports
 * spanning start/end dates across LGU, CIMM, and IPMS sources.
 */
require_once __DIR__ . '/cimm_verification_data.php';
require_once __DIR__ . '/ipms_road_projects_data.php';

/** Normalize a date value to Y-m-d or null. */
function sc_date_ymd($value): ?string {
    if ($value === null || $value === '') {
        return null;
    }
    $raw = trim((string)$value);
    if ($raw === '' || str_starts_with($raw, '0000-00-00')) {
        return null;
    }
    $t = strtotime($raw);
    if ($t === false) {
        return null;
    }
    return date('Y-m-d', $t);
}

/** True when [start, end] covers $day (open-ended if end is null). */
function sc_date_spans_day(?string $start, ?string $end, string $day): bool {
    $start = sc_date_ymd($start);
    $end = sc_date_ymd($end);
    if ($start === null) {
        return false;
    }
    if ($start > $day) {
        return false;
    }
    if ($end === null) {
        return true;
    }
    return $end >= $day;
}

/** True when [start, end] intersects inclusive [$rangeStart, $rangeEnd]. */
function sc_date_range_intersects(?string $start, ?string $end, string $rangeStart, string $rangeEnd): bool {
    $start = sc_date_ymd($start);
    $end = sc_date_ymd($end);
    if ($start === null) {
        return false;
    }
    $effEnd = $end ?? '9999-12-31';
    return $start <= $rangeEnd && $effEnd >= $rangeStart;
}

/** Map CIMM verification row to calendar display status (mirrors report management). */
function sc_map_cimm_status(array $row): string {
    $verification = $row['verification_status'] ?? 'Pending Review';
    $localOverrideMap = [
        'Pending'     => 'pending',
        'Approved'    => 'approved',
        'In Progress' => 'in-progress',
        'Completed'   => 'completed',
        'Cancelled'   => 'cancelled',
    ];
    if ($verification === 'Dismissed') {
        return 'cancelled';
    }
    if (isset($localOverrideMap[$verification])) {
        return $localOverrideMap[$verification];
    }
    return cimm_resolution_status_to_display(
        $row['resolution_status'] ?? null,
        $row['approval_status'] ?? null
    );
}

/**
 * Collect role-scoped approved/in-progress reports for the schedule calendar.
 * Pass $dayYmd for a single day, or $monthStart+$monthEnd for a range filter.
 */
function sc_calendar_collect_items(
    bool $is_road_supervisor,
    bool $is_transport_supervisor,
    ?string $dayYmd = null,
    ?string $monthStart = null,
    ?string $monthEnd = null
): array {
    $items = [];

    $include = static function (?string $start, ?string $end) use ($dayYmd, $monthStart, $monthEnd): bool {
        if ($dayYmd !== null) {
            return sc_date_spans_day($start, $end, $dayYmd);
        }
        if ($monthStart !== null && $monthEnd !== null) {
            return sc_date_range_intersects($start, $end, $monthStart, $monthEnd);
        }
        return sc_date_ymd($start) !== null;
    };

    // --- LGU (road_transportation_reports) ---
    $where = "report_source = 'local'
              AND created_by != 0
              AND report_type != 'infrastructure_issue'
              AND status IN ('approved', 'in-progress')
              AND cimm_starting_date IS NOT NULL
              AND cimm_starting_date != '0000-00-00'";
    if ($is_road_supervisor) {
        $where .= " AND report_category = 'road'";
    } elseif ($is_transport_supervisor) {
        $where .= " AND report_category = 'transportation'";
    }

    $lguRows = fetch_all(
        "SELECT id, report_id, title, location, status, priority, report_type, report_category,
                district, cimm_starting_date, cimm_estimated_end_date
         FROM road_transportation_reports
         WHERE {$where}
         ORDER BY cimm_starting_date ASC, id DESC"
    );
    if (is_array($lguRows)) {
        foreach ($lguRows as $r) {
            $start = $r['cimm_starting_date'] ?? null;
            $end = $r['cimm_estimated_end_date'] ?? null;
            if (!$include($start, $end)) {
                continue;
            }
            $items[] = [
                'source' => 'lgu',
                'source_label' => 'LGU',
                'id' => (int)($r['id'] ?? 0),
                'report_id' => (string)($r['report_id'] ?? ''),
                'title' => (string)($r['title'] ?? ''),
                'status' => (string)($r['status'] ?? ''),
                'priority' => (string)($r['priority'] ?? ''),
                'location' => (string)($r['location'] ?? ''),
                'district' => (string)($r['district'] ?? ''),
                'report_type' => (string)($r['report_type'] ?? ''),
                'report_category' => (string)($r['report_category'] ?? ''),
                'start_date' => sc_date_ymd($start),
                'end_date' => sc_date_ymd($end),
            ];
        }
    }

    // --- CIMM + IPMS: admin and road roles only ---
    if (!$is_transport_supervisor) {
        try {
            $pdo = rgmap_verification_pdo();
            $cimmRaw = rgmap_fetch_cimm_verification_reports($pdo, [
                'verification_status' => ['Approved', 'In Progress', 'Completed', 'Cancelled'],
                'infrastructure' => 'Roads',
            ]);
        } catch (Exception $e) {
            error_log('Schedule calendar CIMM fetch failed: ' . $e->getMessage());
            $cimmRaw = [];
        }

        foreach ($cimmRaw as $row) {
            $status = sc_map_cimm_status($row);
            if (!in_array($status, ['approved', 'in-progress'], true)) {
                continue;
            }
            $start = $row['starting_date'] ?? null;
            $end = $row['estimated_end_date'] ?? null;
            if (!$include($start, $end)) {
                continue;
            }
            $items[] = [
                'source' => 'cimm',
                'source_label' => 'CIMM',
                'id' => (int)($row['id'] ?? 0),
                'report_id' => (string)($row['reference_code'] ?? ('REQ-' . ($row['cimm_req_id'] ?? ''))),
                'title' => (string)($row['infrastructure'] ?? 'CIMM Report'),
                'status' => $status,
                'priority' => strtolower((string)($row['priority'] ?? 'medium')),
                'location' => (string)($row['location'] ?? ''),
                'district' => (string)($row['district'] ?? ''),
                'report_type' => 'infrastructure_issue',
                'start_date' => sc_date_ymd($start),
                'end_date' => sc_date_ymd($end),
                'engineer' => (string)($row['engineer'] ?? ''),
            ];
        }

        try {
            $infraRows = rgmap_infra_panel_rows(null, ['approved', 'in-progress']);
        } catch (Exception $e) {
            error_log('Schedule calendar IPMS fetch failed: ' . $e->getMessage());
            $infraRows = [];
        }
        foreach ($infraRows as $r) {
            $start = $r['start_date'] ?? null;
            $end = $r['end_date'] ?? null;
            if (!$include($start, $end)) {
                continue;
            }
            $items[] = [
                'source' => 'ipms',
                'source_label' => 'IPMS',
                'id' => (int)($r['id'] ?? 0),
                'report_id' => (string)($r['report_id'] ?? ''),
                'title' => (string)($r['title'] ?? ''),
                'status' => (string)($r['status'] ?? ''),
                'priority' => (string)($r['priority'] ?? ''),
                'location' => (string)($r['location'] ?? ''),
                'district' => (string)($r['district'] ?? ''),
                'report_type' => (string)($r['report_type'] ?? 'infrastructure_issue'),
                'start_date' => sc_date_ymd($start),
                'end_date' => sc_date_ymd($end),
                'start_address' => (string)($r['start_address'] ?? ''),
                'end_address' => (string)($r['end_address'] ?? ''),
                'engineer' => (string)($r['engineer'] ?? ''),
                'budget' => $r['budget'] ?? null,
                'department' => (string)($r['department'] ?? 'Engineering'),
            ];
        }
    }

    return $items;
}

/**
 * Build per-day counts for a visible calendar grid range.
 *
 * @return array<string, array{count:int, sources:string[]}>
 */
function sc_calendar_build_day_map(array $items, string $gridStart, string $gridEnd): array {
    $days = [];
    foreach ($items as $item) {
        $start = sc_date_ymd($item['start_date'] ?? null);
        if ($start === null) {
            continue;
        }
        $end = sc_date_ymd($item['end_date'] ?? null) ?? $gridEnd;
        $cur = $start < $gridStart ? $gridStart : $start;
        $last = $end > $gridEnd ? $gridEnd : $end;
        if ($cur > $last) {
            continue;
        }
        $source = (string)($item['source'] ?? '');
        $cursor = strtotime($cur);
        $lastTs = strtotime($last);
        while ($cursor !== false && $lastTs !== false && $cursor <= $lastTs) {
            $key = date('Y-m-d', $cursor);
            if (!isset($days[$key])) {
                $days[$key] = ['count' => 0, 'sources' => []];
            }
            $days[$key]['count']++;
            if ($source !== '' && !in_array($source, $days[$key]['sources'], true)) {
                $days[$key]['sources'][] = $source;
            }
            $cursor = strtotime('+1 day', $cursor);
        }
    }
    return $days;
}
