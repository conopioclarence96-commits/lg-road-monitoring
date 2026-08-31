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

/**
 * Calendar display dates for a scheduled item: start and end only (not every day between).
 *
 * @return array<int, string> Y-m-d dates, unique, sorted
 */
function sc_calendar_item_marker_dates(array $item): array {
    $start = sc_date_ymd($item['start_date'] ?? null);
    if ($start === null) {
        return [];
    }
    $dates = [$start];
    $end = sc_date_ymd($item['end_date'] ?? null);
    if ($end !== null && $end !== $start) {
        $dates[] = $end;
    }
    sort($dates);
    return $dates;
}

/** True when $day is the scheduled start or end date (calendar marker day). */
function sc_calendar_day_is_marker(?string $start, ?string $end, string $day): bool {
    $start = sc_date_ymd($start);
    if ($start === null) {
        return false;
    }
    if ($day === $start) {
        return true;
    }
    $end = sc_date_ymd($end);
    return $end !== null && $end !== $start && $day === $end;
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
            return sc_calendar_day_is_marker($start, $end, $dayYmd);
        }
        if ($monthStart !== null && $monthEnd !== null) {
            return sc_date_range_intersects($start, $end, $monthStart, $monthEnd);
        }
        return sc_date_ymd($start) !== null;
    };

    $scheduleSelect = "id, report_id, title, location, status, priority, report_type, report_category,
                district, cimm_starting_date, cimm_estimated_end_date";
    $scheduleDateWhere = "report_type != 'infrastructure_issue'
              AND status IN ('approved', 'in-progress')
              AND cimm_starting_date IS NOT NULL
              AND cimm_starting_date != '0000-00-00'";

    $appendRoadTransportRow = static function (array $r, string $source, string $sourceLabel) use (&$items, $include): void {
        $start = $r['cimm_starting_date'] ?? null;
        $end = $r['cimm_estimated_end_date'] ?? null;
        if (!$include($start, $end)) {
            return;
        }
        $items[] = [
            'source' => $source,
            'source_label' => $sourceLabel,
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
    };

    // --- LGU: staff-created local reports only (created_by != 0) ---
    $lguWhere = "report_source = 'local'
              AND created_by != 0
              AND {$scheduleDateWhere}";
    if ($is_road_supervisor) {
        $lguWhere .= " AND report_category = 'road'";
    } elseif ($is_transport_supervisor) {
        $lguWhere .= " AND report_category = 'transportation'";
    }

    $lguRows = fetch_all(
        "SELECT {$scheduleSelect}
         FROM road_transportation_reports
         WHERE {$lguWhere}
         ORDER BY cimm_starting_date ASC, id DESC"
    );
    if (is_array($lguRows)) {
        foreach ($lguRows as $r) {
            $appendRoadTransportRow($r, 'lgu', 'LGU');
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

/** Canonical display order for calendar source markers. */
function sc_calendar_source_order(): array {
    return ['lgu', 'cimm', 'ipms'];
}

/**
 * Build per-day counts for a visible calendar grid range.
 * Each report appears only on its start and end dates (not every day in between).
 *
 * @return array<string, array{count:int, sources:string[], source_counts:array<string,int>}>
 */
function sc_calendar_build_day_map(array $items, string $gridStart, string $gridEnd): array {
    $sourceOrder = sc_calendar_source_order();
    $days = [];
    foreach ($items as $item) {
        $source = (string)($item['source'] ?? '');
        foreach (sc_calendar_item_marker_dates($item) as $key) {
            if ($key < $gridStart || $key > $gridEnd) {
                continue;
            }
            if (!isset($days[$key])) {
                $days[$key] = ['count' => 0, 'sources' => [], 'source_counts' => []];
            }
            $days[$key]['count']++;
            if ($source !== '') {
                $days[$key]['source_counts'][$source] = ($days[$key]['source_counts'][$source] ?? 0) + 1;
                if (!in_array($source, $days[$key]['sources'], true)) {
                    $days[$key]['sources'][] = $source;
                }
            }
        }
    }

    foreach ($days as &$dayInfo) {
        usort($dayInfo['sources'], static function (string $a, string $b) use ($sourceOrder): int {
            $ia = array_search($a, $sourceOrder, true);
            $ib = array_search($b, $sourceOrder, true);
            $ia = ($ia === false) ? 999 : $ia;
            $ib = ($ib === false) ? 999 : $ib;
            return $ia <=> $ib;
        });
    }
    unset($dayInfo);

    return $days;
}

/** Trim user ID search input. */
function sc_calendar_normalize_id_query(string $query): string {
    return trim($query);
}

/** True when a calendar item matches an ID query (report_management-style partial match). */
function sc_calendar_item_matches_id(array $item, string $query): bool {
    $query = sc_calendar_normalize_id_query($query);
    if ($query === '') {
        return false;
    }
    $needle = strtolower($query);
    $reportId = strtolower(trim((string)($item['report_id'] ?? '')));
    if ($reportId !== '' && str_contains($reportId, $needle)) {
        return true;
    }
    if (ctype_digit($query)) {
        $id = (int)($item['id'] ?? 0);
        if ($id > 0 && (string)$id === $query) {
            return true;
        }
    }
    return false;
}

/** Start/end marker dates for ID search highlighting (not every day in between). */
function sc_calendar_item_scheduled_dates(array $item): array {
    return sc_calendar_item_marker_dates($item);
}

/**
 * Search the calendar data set by report/project ID (same sources as report management).
 *
 * @return array{found:bool,items:array<int,array>,highlight_dates:array<int,string>,focus_date:?string,message:string}
 */
function sc_calendar_search_by_id(
    bool $is_road_supervisor,
    bool $is_transport_supervisor,
    string $query
): array {
    $query = sc_calendar_normalize_id_query($query);
    if ($query === '') {
        return [
            'found' => false,
            'items' => [],
            'highlight_dates' => [],
            'focus_date' => null,
            'message' => 'Enter a report or project ID.',
        ];
    }

    $allItems = sc_calendar_collect_items($is_road_supervisor, $is_transport_supervisor, null, null, null);
    $matches = [];
    foreach ($allItems as $item) {
        if (sc_calendar_item_matches_id($item, $query)) {
            $matches[] = $item;
        }
    }

    if ($matches === []) {
        return [
            'found' => false,
            'items' => [],
            'highlight_dates' => [],
            'focus_date' => null,
            'message' => 'No schedule found for this ID.',
        ];
    }

    $highlightDates = [];
    $focusDate = null;
    foreach ($matches as &$item) {
        $item['scheduled_dates'] = sc_calendar_item_scheduled_dates($item);
        foreach ($item['scheduled_dates'] as $d) {
            $highlightDates[$d] = true;
            if ($focusDate === null || $d < $focusDate) {
                $focusDate = $d;
            }
        }
    }
    unset($item);

    $highlightList = array_keys($highlightDates);
    sort($highlightList);

    return [
        'found' => true,
        'items' => $matches,
        'highlight_dates' => $highlightList,
        'focus_date' => $focusDate,
        'message' => '',
    ];
}
