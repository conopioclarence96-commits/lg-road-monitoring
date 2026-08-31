<?php
/**
 * Completed Projects — finalized (status = completed) reports table.
 *
 * The Public column (and its transparency statuses) is owned by this page.
 * Shared chrome/table shell still comes from road_transportation_monitoring.php.
 */
define('MONITORING_COMPLETED_VIEW', true);
define('COMPLETED_PROJECTS_SHOW_PUBLIC_COLUMN', true);

/**
 * Public Transparency statuses for the Completed Projects → Public column.
 *
 * Awaiting — no public transparency request has been made yet.
 * Pending  — a request exists and the admin has not acted on it yet.
 * Approved — admin approved the request, but it has not been published yet.
 * Posted    — the report is actually published in public_transparency.php.
 * Rejected — admin rejected the request.
 *
 * @return array<string, array{label:string, class:string, title:string}>
 */
function completed_projects_public_status_map() {
    return [
        'awaiting' => [
            'label' => 'Awaiting',
            'class' => 'pt-status-awaiting',
            'title' => 'No public transparency request has been made yet.',
        ],
        'pending' => [
            'label' => 'Pending',
            'class' => 'pt-status-pending',
            'title' => 'A request exists and the admin has not acted on it yet.',
        ],
        'approved' => [
            'label' => 'Approved',
            'class' => 'pt-status-approved',
            'title' => 'Admin approved the request, but it has not been published yet.',
        ],
        'posted' => [
            'label' => 'Posted',
            'class' => 'pt-status-posted',
            'title' => 'The report is actually published in public_transparency.php.',
        ],
        'rejected' => [
            'label' => 'Rejected',
            'class' => 'pt-status-rejected',
            'title' => 'Admin rejected the request.',
        ],
    ];
}

/**
 * @param string $status awaiting|pending|approved|posted|rejected
 * @return array{label:string, class:string, title:string}
 */
function completed_projects_public_status_meta($status) {
    $map = completed_projects_public_status_map();
    $key = strtolower(trim((string)$status));
    return $map[$key] ?? $map['awaiting'];
}

/** Table header cell for the Public column. */
function completed_projects_public_column_header_html() {
    return '<th class="pt-col" title="Public transparency publication state">Public</th>';
}

/**
 * Table body cell for the Public column (display-only).
 *
 * @param array $report Row that already has public_transparency_status annotated.
 */
function completed_projects_public_column_cell_html(array $report) {
    $meta = completed_projects_public_status_meta($report['public_transparency_status'] ?? 'awaiting');
    return '<td class="pt-col">'
        . '<span class="pt-status-badge ' . htmlspecialchars($meta['class'], ENT_QUOTES, 'UTF-8') . '"'
        . ' title="' . htmlspecialchars($meta['title'], ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8')
        . '</span></td>';
}

/**
 * CSS so Public fits inside the existing Completed Projects panel.
 * No horizontal scrolling — tighten sizing so all columns stay visible.
 */
function completed_projects_public_column_css() {
    return <<<'CSS'
        /* Public column — owned by completed_projects.php */
        .pt-status-badge {
            display: inline-block; padding: 3px 8px; border-radius: 999px;
            font-size: 10px; font-weight: 600; white-space: nowrap; border: none;
        }
        .pt-status-awaiting { background: rgba(107,114,128,0.14); color: #4b5563; }
        .pt-status-pending { background: rgba(245,158,11,0.16); color: #b45309; }
        .pt-status-approved { background: rgba(59,130,246,0.14); color: #1d4ed8; }
        .pt-status-posted { background: rgba(16,185,129,0.16); color: #047857; }
        .pt-status-rejected { background: rgba(239,68,68,0.14); color: #b91c1c; }
        body.dark-mode .pt-status-awaiting { background: rgba(156,163,175,0.2); color: #d1d5db; }
        body.dark-mode .pt-status-pending { background: rgba(245,158,11,0.22); color: #fbbf24; }
        body.dark-mode .pt-status-approved { background: rgba(96,165,250,0.22); color: #93c5fd; }
        body.dark-mode .pt-status-posted { background: rgba(52,211,153,0.2); color: #6ee7b7; }
        body.dark-mode .pt-status-rejected { background: rgba(248,113,113,0.2); color: #fca5a5; }

        /* Table layout — clean columns, horizontal scroll when needed */
        body.completed-projects-view .mon-dash .reports-table-section {
            max-width: 100%;
            box-sizing: border-box;
            overflow: visible !important;
        }
        body.completed-projects-view .mon-dash .reports-table-wrap,
        body.completed-projects-view .mon-dash .completed-reports-scroll {
            overflow-x: auto !important;
            overflow-y: visible !important;
            -webkit-overflow-scrolling: touch;
            padding: 0 4px 6px !important;
            box-sizing: border-box;
        }
        body.completed-projects-view .mon-dash #recentReportsTable {
            table-layout: auto !important;
            min-width: 950px !important;
            width: auto !important;
            border-collapse: collapse;
        }
        body.completed-projects-view .mon-dash #recentReportsTable th,
        body.completed-projects-view .mon-dash #recentReportsTable td {
            padding: 10px 8px !important;
            font-size: 12px !important;
            vertical-align: middle;
            white-space: nowrap !important;
            overflow: visible !important;
            text-overflow: unset !important;
        }
        body.completed-projects-view .mon-dash #recentReportsTable th {
            font-size: 10px !important;
            letter-spacing: 0.2px;
            white-space: nowrap !important;
            border-bottom: 2px solid var(--border-light, #e5e7eb);
        }
        /* Title column wraps naturally */
        body.completed-projects-view .mon-dash #recentReportsTable td:nth-child(2) {
            white-space: normal !important;
            max-width: 240px;
            word-break: break-word;
        }
        body.completed-projects-view .mon-dash #recentReportsTable .pt-col {
            display: table-cell !important;
            visibility: visible !important;
            opacity: 1 !important;
            min-width: 0 !important;
            white-space: nowrap !important;
            overflow: visible !important;
            text-overflow: clip !important;
        }
        body.completed-projects-view .mon-dash #recentReportsTable .action-cell {
            white-space: normal !important;
            overflow: visible !important;
        }
        body.completed-projects-view .mon-dash #recentReportsTable .action-cell .table-action-btn {
            padding: 4px 8px;
            font-size: 11px;
            margin: 2px 2px 2px 0;
        }
        body.completed-projects-view .mon-dash .badge,
        body.completed-projects-view .mon-dash .db-badge,
        body.completed-projects-view .mon-dash .category-badge,
        body.completed-projects-view .mon-dash .cimm-verify-badge,
        body.completed-projects-view .mon-dash .assignment-badge {
            font-size: 10px;
            padding: 3px 7px;
            white-space: nowrap;
        }
CSS;
}

/** JSON map for Load More / JS row builder. */
function completed_projects_public_column_js_map() {
    return completed_projects_public_status_map();
}

/**
 * Strip the default citizen-report title suffix for display on Completed Projects.
 */
function completed_projects_format_citizen_title(string $title): string {
    $clean = preg_replace('/\s+at pinned location/i', '', $title);
    $clean = trim((string)$clean);
    return $clean !== '' ? $clean : 'Untitled';
}

/**
 * Format a report title for the Completed Projects table/modals.
 */
function completed_projects_format_report_title(string $title, string $source = ''): string {
    if (strtolower(trim($source)) === 'citizen') {
        return completed_projects_format_citizen_title($title);
    }
    $clean = trim($title);
    return $clean !== '' ? $clean : 'Untitled';
}

/** Normalize citizen report titles in a fetched report list. */
function completed_projects_apply_title_format(array &$reports): void {
    foreach ($reports as &$report) {
        if (strtolower((string)($report['source'] ?? '')) === 'citizen') {
            $report['title'] = completed_projects_format_citizen_title((string)($report['title'] ?? ''));
        }
    }
    unset($report);
}

/** Client-side title cleanup for Load More / detail modal (Completed Projects only). */
function completed_projects_title_js(): string {
    return <<<'JS'
        function formatCompletedProjectCitizenTitle(title, source) {
            if (String(source || '').toLowerCase() !== 'citizen') {
                return title || 'Untitled';
            }
            var clean = String(title || '').replace(/\s+at pinned location/gi, '').trim();
            return clean || 'Untitled';
        }
JS;
}

require __DIR__ . '/road_transportation_monitoring.php';
