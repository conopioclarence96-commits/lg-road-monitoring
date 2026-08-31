<?php
require_once '../../includes/session_config.php';
require_once '../../includes/config.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/../api/schedule_calendar_data.php';

$session_timeout = 30 * 60;
lgu_enforce_idle_timeout($session_timeout, '../../login.php?timeout=1');

$allowed_roles = ['system_admin', 'road_ops_supervisor', 'trans_ops_supervisor'];
if (!is_logged_in() || !in_array($_SESSION['role'] ?? '', $allowed_roles, true)) {
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    header('Location: ../../login.php');
    exit();
}

$user_role = $_SESSION['role'] ?? '';
$is_road_supervisor = ($user_role === 'road_ops_supervisor');
$is_transport_supervisor = ($user_role === 'trans_ops_supervisor');
$is_system_admin = ($user_role === 'system_admin');

// --- AJAX: month day map ---
if (($_GET['ajax'] ?? '') === 'calendar_month') {
    header('Content-Type: application/json; charset=utf-8');
    $year = (int)($_GET['year'] ?? date('Y'));
    $month = (int)($_GET['month'] ?? date('n'));
    if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid year/month']);
        exit;
    }

    $monthStart = sprintf('%04d-%02d-01', $year, $month);
    $monthEnd = date('Y-m-t', strtotime($monthStart));
    $firstDow = (int)date('w', strtotime($monthStart));
    $gridStart = date('Y-m-d', strtotime($monthStart . ' -' . $firstDow . ' days'));
    $lastDow = (int)date('w', strtotime($monthEnd));
    $gridEnd = date('Y-m-d', strtotime($monthEnd . ' +' . (6 - $lastDow) . ' days'));

    $items = sc_calendar_collect_items(
        $is_road_supervisor,
        $is_transport_supervisor,
        null,
        $gridStart,
        $gridEnd
    );
    $days = sc_calendar_build_day_map($items, $gridStart, $gridEnd);

    echo json_encode([
        'success' => true,
        'year' => $year,
        'month' => $month,
        'month_start' => $monthStart,
        'month_end' => $monthEnd,
        'grid_start' => $gridStart,
        'grid_end' => $gridEnd,
        'days' => $days,
        'total_items' => count($items),
    ]);
    exit;
}

// --- AJAX: day detail list ---
if (($_GET['ajax'] ?? '') === 'calendar_day') {
    header('Content-Type: application/json; charset=utf-8');
    $date = trim((string)($_GET['date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid date']);
        exit;
    }
    $date = date('Y-m-d', strtotime($date));

    $items = sc_calendar_collect_items(
        $is_road_supervisor,
        $is_transport_supervisor,
        $date,
        null,
        null
    );

    echo json_encode([
        'success' => true,
        'date' => $date,
        'items' => array_values($items),
        'count' => count($items),
    ]);
    exit;
}

// --- AJAX: search by report/project ID ---
if (($_GET['ajax'] ?? '') === 'calendar_search') {
    header('Content-Type: application/json; charset=utf-8');
    $query = trim((string)($_GET['q'] ?? $_GET['id'] ?? ''));
    $result = sc_calendar_search_by_id($is_road_supervisor, $is_transport_supervisor, $query);

    $payload = [
        'success' => true,
        'found' => $result['found'],
        'query' => sc_calendar_normalize_id_query($query),
        'items' => $result['items'],
        'highlight_dates' => $result['highlight_dates'],
        'focus_date' => $result['focus_date'],
        'message' => $result['message'],
    ];

    if ($result['found'] && !empty($result['focus_date'])) {
        $ts = strtotime($result['focus_date']);
        $payload['focus_year'] = (int)date('Y', $ts);
        $payload['focus_month'] = (int)date('n', $ts);
    }

    echo json_encode($payload);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule Calendar | LGU Staff</title>
    <link rel="icon" type="image/png" href="../../assets/img/infra-gov-logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/theme-tokens.css">
    <link rel="stylesheet" href="../../css/theme-utilities.css">
    <link rel="stylesheet" href="../../css/sidebar.css?v=6">
    <link rel="stylesheet" href="../../../styles/transition.css">
    <?php if (!empty($_SESSION['darkmode'])): ?><link rel="stylesheet" href="../../css/dark-mode.css"><?php endif; ?>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { background: #f5f3ee; min-height: 100vh; color: var(--text-primary); }
        body.dark-mode { background: var(--bg-page); }

        .main-content.sc-dash {
            margin-left: 250px;
            padding: 28px 32px;
            max-width: 100%;
            overflow-x: hidden;
        }
        .sc-dash .dashboard-header {
            background: #f4f7fb;
            border-radius: 14px;
            padding: 20px 26px;
            margin-bottom: 22px;
            border: 1px solid #d5dce8;
            box-shadow: var(--shadow-card);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        body.dark-mode .sc-dash .dashboard-header {
            background: var(--bg-card);
            border-color: rgba(255,255,255,0.08);
        }
        .sc-dash .welcome-text h1 {
            font-size: 22px;
            font-weight: 700;
            color: #1e3c72;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        body.dark-mode .sc-dash .welcome-text h1 { color: #e5e7eb; }
        .sc-dash .welcome-text p { margin-top: 4px; font-size: 13px; color: #6b7280; }
        .sc-dash .header-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: #fff;
            font-size: 15px;
        }
        .dt-chip {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 12px;
            background: #fff;
            border: 1px solid #e5e7eb;
            font-size: 12px;
            color: #4b5563;
        }
        body.dark-mode .dt-chip {
            background: #1f2937;
            border-color: rgba(255,255,255,0.08);
            color: #9ca3af;
        }
        .dt-chip i { color: #3762c8; }

        .sc-panel {
            background: #fff;
            border-radius: 14px;
            border: 1px solid rgba(55, 98, 200, 0.14);
            box-shadow: var(--shadow-card);
            overflow: hidden;
        }
        body.dark-mode .sc-panel {
            background: var(--bg-card);
            border-color: rgba(255,255,255,0.08);
        }
        .sc-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 16px 20px;
            border-bottom: 1px solid rgba(55, 98, 200, 0.12);
            flex-wrap: wrap;
        }
        .sc-panel-header-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .sc-panel-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: #fff;
        }
        .sc-panel-title {
            font-size: 17px;
            font-weight: 700;
            color: #1e3c72;
        }
        body.dark-mode .sc-panel-title { color: #e5e7eb; }
        .sc-panel-subtitle {
            font-size: 12px;
            color: #6b7280;
            margin-top: 2px;
        }
        .sc-panel-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 999px;
            background: #3762c8;
            color: #fff;
            font-size: 12px;
            font-weight: 600;
            margin-left: 8px;
        }

        .sc-cal-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 16px;
            flex-wrap: wrap;
        }
        .sc-cal-nav {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .sc-cal-nav-btn {
            width: 34px;
            height: 34px;
            border: none;
            border-radius: 8px;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: #fff;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .sc-cal-nav-btn:hover {
            box-shadow: 0 4px 12px rgba(55, 98, 200, 0.3);
        }
        .sc-cal-month-label {
            font-size: 16px;
            font-weight: 700;
            color: #1e3c72;
            min-width: 160px;
            text-align: center;
        }
        body.dark-mode .sc-cal-month-label { color: #e5e7eb; }
        .sc-cal-legend {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            font-size: 12px;
            color: #6b7280;
        }
        .sc-cal-legend span {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .sc-cal-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }
        .sc-cal-dot.lgu { background: #3762c8; }
        .sc-cal-dot.cimm { background: #7c3aed; }
        .sc-cal-dot.ipms { background: #f97316; }

        .sc-cal-grid {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 1px;
            background: rgba(55, 98, 200, 0.12);
            border-top: 1px solid rgba(55, 98, 200, 0.12);
        }
        .sc-cal-dow {
            background: #f8fafc;
            text-align: center;
            padding: 8px 4px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.04em;
            color: #64748b;
            text-transform: uppercase;
        }
        body.dark-mode .sc-cal-dow {
            background: #1f2937;
            color: #9ca3af;
        }
        .sc-cal-day {
            background: #fff;
            min-height: 88px;
            padding: 8px;
            cursor: default;
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 6px;
            transition: background 0.15s ease;
        }
        body.dark-mode .sc-cal-day { background: #111827; }
        .sc-cal-day.other-month { opacity: 0.45; }
        .sc-cal-day.has-items { cursor: pointer; }
        .sc-cal-day.has-items:hover { background: rgba(55, 98, 200, 0.08); }
        body.dark-mode .sc-cal-day.has-items:hover { background: rgba(55, 98, 200, 0.18); }
        .sc-cal-day.today {
            outline: 2px solid #3762c8;
            outline-offset: -2px;
            z-index: 1;
        }
        .sc-cal-day-num {
            font-size: 13px;
            font-weight: 600;
            color: #1f2937;
        }
        body.dark-mode .sc-cal-day-num { color: #e5e7eb; }
        .sc-cal-day-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 4px;
            margin-top: auto;
        }
        .sc-cal-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 22px;
            height: 22px;
            padding: 0 6px;
            border-radius: 999px;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: #fff;
            font-size: 11px;
            font-weight: 700;
        }
        .sc-cal-sources {
            display: flex;
            gap: 3px;
            align-items: center;
        }
        .sc-cal-empty-hint {
            padding: 10px 16px 14px;
            font-size: 13px;
            color: #6b7280;
        }

        .sc-cal-search-row {
            padding: 12px 16px 0;
            border-top: 1px solid rgba(55, 98, 200, 0.08);
        }
        .sc-cal-search-label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
        }
        body.dark-mode .sc-cal-search-label { color: #d1d5db; }
        .sc-cal-search-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .sc-cal-search-input {
            flex: 1 1 220px;
            min-width: 180px;
            padding: 8px 12px;
            border: 1px solid rgba(55, 98, 200, 0.22);
            border-radius: 8px;
            font-size: 13px;
            background: #fff;
            color: #1f2937;
        }
        body.dark-mode .sc-cal-search-input {
            background: #111827;
            border-color: rgba(255,255,255,0.12);
            color: #e5e7eb;
        }
        .sc-cal-search-input:focus {
            outline: none;
            border-color: #3762c8;
            box-shadow: 0 0 0 3px rgba(55, 98, 200, 0.12);
        }
        .sc-cal-search-btn {
            border: none;
            border-radius: 8px;
            padding: 8px 14px;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: #fff;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
        }
        .sc-cal-search-btn:hover {
            box-shadow: 0 4px 12px rgba(55, 98, 200, 0.28);
        }
        .sc-cal-search-clear {
            border: none;
            border-radius: 8px;
            padding: 8px 12px;
            background: #e5e7eb;
            color: #374151;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
        }
        body.dark-mode .sc-cal-search-clear {
            background: #374151;
            color: #e5e7eb;
        }
        .sc-cal-search-msg {
            margin-top: 8px;
            font-size: 12px;
            min-height: 18px;
        }
        .sc-cal-search-msg.is-error { color: #dc2626; }
        .sc-cal-search-msg.is-success { color: #15803d; }
        body.dark-mode .sc-cal-search-msg.is-success { color: #4ade80; }

        .sc-cal-day.search-hit {
            outline: 2px solid #f59e0b;
            outline-offset: -2px;
            z-index: 2;
            background: rgba(245, 158, 11, 0.1);
        }
        body.dark-mode .sc-cal-day.search-hit {
            background: rgba(245, 158, 11, 0.18);
        }
        .sc-cal-day.search-hit-start .sc-cal-day-num {
            background: #f59e0b;
            color: #fff;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .sc-cal-day.search-hit-end .sc-cal-day-num {
            box-shadow: inset 0 0 0 2px #f59e0b;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .sc-day-item.search-hit {
            border-color: #f59e0b;
            box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.35);
        }

        .sc-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .sc-modal-overlay.active { display: flex; }
        .sc-modal-content {
            background: #fff;
            border-radius: 14px;
            width: 100%;
            max-width: 720px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 20px 50px rgba(0,0,0,0.2);
        }
        body.dark-mode .sc-modal-content { background: #1f2937; }
        .sc-modal-header {
            padding: 16px 20px;
            border-bottom: 1px solid rgba(55, 98, 200, 0.12);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
        }
        .sc-modal-report-id { font-size: 12px; color: #6b7280; margin-bottom: 4px; }
        .sc-modal-title { font-size: 18px; font-weight: 700; color: #1e3c72; }
        body.dark-mode .sc-modal-title { color: #e5e7eb; }
        .sc-modal-close {
            border: none;
            background: transparent;
            font-size: 24px;
            line-height: 1;
            cursor: pointer;
            color: #6b7280;
        }
        .sc-modal-body {
            padding: 16px 20px;
            overflow-y: auto;
            flex: 1;
        }
        .sc-modal-footer {
            padding: 12px 20px;
            border-top: 1px solid rgba(55, 98, 200, 0.12);
            display: flex;
            justify-content: flex-end;
        }
        .sc-modal-btn-close {
            border: none;
            border-radius: 8px;
            padding: 8px 14px;
            background: #e5e7eb;
            color: #374151;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
        }
        body.dark-mode .sc-modal-btn-close { background: #374151; color: #e5e7eb; }

        .sc-day-list { display: flex; flex-direction: column; gap: 10px; }
        .sc-day-item {
            border: 1px solid rgba(55, 98, 200, 0.14);
            border-radius: 10px;
            padding: 12px 14px;
            background: #fff;
        }
        body.dark-mode .sc-day-item {
            background: #111827;
            border-color: rgba(255,255,255,0.08);
        }
        .sc-day-item-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 8px;
        }
        .sc-day-item-title {
            font-weight: 700;
            color: #1e3c72;
            font-size: 14px;
        }
        body.dark-mode .sc-day-item-title { color: #e5e7eb; }
        .sc-day-item-meta {
            font-size: 12px;
            color: #6b7280;
            display: grid;
            gap: 4px;
        }
        .sc-source-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
        }
        .sc-source-badge.lgu { background: rgba(55,98,200,0.12); color: #3762c8; }
        .sc-source-badge.cimm { background: rgba(124,58,237,0.12); color: #7c3aed; }
        .sc-source-badge.ipms { background: rgba(249,115,22,0.12); color: #f97316; }
        .sc-day-item-actions { margin-top: 10px; }
        .sc-action-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: none;
            border-radius: 8px;
            padding: 7px 12px;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: #fff;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }

        @media (max-width: 768px) {
            .main-content.sc-dash { margin-left: 0; padding: 20px 14px 40px; }
            .sc-cal-day { min-height: 64px; padding: 6px; }
            .sc-cal-dow { font-size: 10px; padding: 6px 2px; }
            .sc-cal-month-label { min-width: 120px; font-size: 14px; }
        }
    </style>
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?><?php echo $is_transport_supervisor ? ' trans-supervisor-view' : ''; ?><?php echo $is_system_admin ? ' system-admin-view' : ''; ?><?php echo $is_road_supervisor ? ' road-supervisor-view' : ''; ?>">
    <?php include '../../includes/sidebar_nav.php'; ?>

    <div class="main-content sc-dash">
        <div class="dashboard-header">
            <div class="welcome-text">
                <h1>
                    <span class="header-icon"><i class="fas fa-calendar-alt"></i></span>
                    Schedule Calendar
                </h1>
                <p>Approved and in-progress work active on each day<?php
                    if ($is_road_supervisor) echo ' (road reports)';
                    elseif ($is_transport_supervisor) echo ' (transportation reports)';
                ?>.</p>
            </div>
            <div class="dt-chip">
                <i class="fas fa-calendar-day"></i>
                <div>
                    <div id="currentDate"></div>
                    <div id="currentTime"></div>
                </div>
            </div>
        </div>

        <div class="sc-panel" id="rmScheduleCalendar">
            <div class="sc-panel-header">
                <div class="sc-panel-header-left">
                    <div class="sc-panel-icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div>
                        <div style="display:flex;align-items:center;flex-wrap:wrap;">
                            <h2 class="sc-panel-title">Monthly Schedule</h2>
                            <span class="sc-panel-badge" id="rmCalBadge">—</span>
                        </div>
                        <p class="sc-panel-subtitle">Click a day to see every active report</p>
                    </div>
                </div>
            </div>
            <div class="sc-cal-search-row">
                <label class="sc-cal-search-label" for="rmCalIdSearch">Search by ID</label>
                <div class="sc-cal-search-wrap">
                    <input type="text" id="rmCalIdSearch" class="sc-cal-search-input"
                        placeholder="Enter report or project ID…" autocomplete="off" spellcheck="false">
                    <button type="button" class="sc-cal-search-btn" id="rmCalIdSearchBtn">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <button type="button" class="sc-cal-search-clear" id="rmCalIdSearchClear" style="display:none;">
                        Clear
                    </button>
                </div>
                <div class="sc-cal-search-msg" id="rmCalSearchMsg" aria-live="polite"></div>
            </div>
            <div class="sc-cal-toolbar">
                <div class="sc-cal-nav">
                    <button type="button" class="sc-cal-nav-btn" id="rmCalPrev" title="Previous month" aria-label="Previous month">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <div class="sc-cal-month-label" id="rmCalMonthLabel">—</div>
                    <button type="button" class="sc-cal-nav-btn" id="rmCalNext" title="Next month" aria-label="Next month">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                    <button type="button" class="sc-cal-nav-btn" id="rmCalToday" title="Go to today" aria-label="Go to today" style="width:auto;padding:0 12px;font-size:12px;font-weight:600;">
                        Today
                    </button>
                </div>
                <div class="sc-cal-legend">
                    <span><i class="sc-cal-dot lgu"></i> LGU</span>
                    <?php if (!$is_transport_supervisor): ?>
                    <span><i class="sc-cal-dot cimm"></i> CIMM</span>
                    <span><i class="sc-cal-dot ipms"></i> IPMS</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="sc-cal-grid" id="rmCalGrid" aria-live="polite"></div>
            <div class="sc-cal-empty-hint" id="rmCalHint">Loading schedule…</div>
        </div>
    </div>

    <div id="rmCalendarDayModal" class="sc-modal-overlay" onclick="if(event.target===this)closeCalendarDayModal()">
        <div class="sc-modal-content">
            <div class="sc-modal-header">
                <div>
                    <div class="sc-modal-report-id" id="rmCalDaySub">Active reports</div>
                    <h3 class="sc-modal-title" id="rmCalDayTitle">—</h3>
                </div>
                <button type="button" class="sc-modal-close" onclick="closeCalendarDayModal()">&times;</button>
            </div>
            <div class="sc-modal-body">
                <div class="sc-day-list" id="rmCalDayList"></div>
            </div>
            <div class="sc-modal-footer">
                <button type="button" class="sc-modal-btn-close" onclick="closeCalendarDayModal()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <script>
        (function () {
            function pad(n) { return String(n).padStart(2, '0'); }
            function tickClock() {
                const now = new Date();
                const dateEl = document.getElementById('currentDate');
                const timeEl = document.getElementById('currentTime');
                if (dateEl) {
                    dateEl.textContent = now.toLocaleDateString('en-US', {
                        weekday: 'short', year: 'numeric', month: 'short', day: 'numeric'
                    });
                }
                if (timeEl) {
                    timeEl.textContent = now.toLocaleTimeString('en-US', {
                        hour: '2-digit', minute: '2-digit', second: '2-digit'
                    });
                }
            }
            tickClock();
            setInterval(tickClock, 1000);

            const rmCalState = {
                year: new Date().getFullYear(),
                month: new Date().getMonth() + 1,
                days: {},
                loading: false,
                searchQuery: '',
                highlightDates: [],
                searchStartDates: [],
                searchEndDates: []
            };
            let rmCalendarDayItems = [];
            let rmCalLastGridData = null;

            function rmCalItemMatchesQuery(item, query) {
                if (!query) return false;
                const needle = String(query).trim().toLowerCase();
                if (!needle) return false;
                const reportId = String(item.report_id || '').toLowerCase();
                if (reportId && reportId.indexOf(needle) !== -1) return true;
                if (/^\d+$/.test(needle)) {
                    const id = parseInt(item.id, 10);
                    if (!isNaN(id) && String(id) === needle) return true;
                }
                return false;
            }

            function setSearchMessage(text, type) {
                const el = document.getElementById('rmCalSearchMsg');
                if (!el) return;
                el.textContent = text || '';
                el.classList.remove('is-error', 'is-success');
                if (type) el.classList.add(type === 'error' ? 'is-error' : 'is-success');
            }

            function clearCalendarSearch() {
                rmCalState.searchQuery = '';
                rmCalState.highlightDates = [];
                rmCalState.searchStartDates = [];
                rmCalState.searchEndDates = [];
                setSearchMessage('');
                const input = document.getElementById('rmCalIdSearch');
                const clearBtn = document.getElementById('rmCalIdSearchClear');
                if (input) input.value = '';
                if (clearBtn) clearBtn.style.display = 'none';
                if (rmCalLastGridData) renderCalendarGrid(rmCalLastGridData);
            }

            function applySearchHighlights() {
                const grid = document.getElementById('rmCalGrid');
                if (!grid || !rmCalState.highlightDates.length) return;
                const hitSet = {};
                rmCalState.highlightDates.forEach(d => { hitSet[d] = true; });
                const startSet = {};
                rmCalState.searchStartDates.forEach(d => { startSet[d] = true; });
                const endSet = {};
                rmCalState.searchEndDates.forEach(d => { endSet[d] = true; });
                grid.querySelectorAll('.sc-cal-day[data-date]').forEach(el => {
                    const key = el.getAttribute('data-date');
                    if (hitSet[key]) {
                        el.classList.add('search-hit');
                        if (startSet[key]) el.classList.add('search-hit-start');
                        if (endSet[key]) el.classList.add('search-hit-end');
                    }
                });
                const firstInMonth = rmCalState.highlightDates.find(d => {
                    const parts = d.split('-');
                    return parseInt(parts[0], 10) === rmCalState.year
                        && parseInt(parts[1], 10) === rmCalState.month;
                });
                if (firstInMonth) {
                    const target = grid.querySelector('.sc-cal-day[data-date="' + firstInMonth + '"]');
                    if (target && typeof target.scrollIntoView === 'function') {
                        target.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                    }
                }
            }

            function buildSearchSuccessMessage(items) {
                if (!items.length) return '';
                if (items.length === 1) {
                    const it = items[0];
                    const id = it.report_id || it.id;
                    return 'Found ' + (it.source_label || it.source || 'report') + ' '
                        + id + ': start ' + (it.start_date || '—')
                        + ', end ' + (it.end_date || 'Open')
                        + '. Both dates are highlighted on the calendar.';
                }
                return 'Found ' + items.length + ' scheduled reports matching this ID. Highlighted dates are shown on the calendar.';
            }

            function runCalendarIdSearch() {
                const input = document.getElementById('rmCalIdSearch');
                const clearBtn = document.getElementById('rmCalIdSearchClear');
                const query = input ? String(input.value || '').trim() : '';
                if (!query) {
                    clearCalendarSearch();
                    setSearchMessage('Enter a report or project ID.', 'error');
                    return;
                }

                setSearchMessage('Searching…');
                fetch('schedule_calendar.php?ajax=calendar_search&q=' + encodeURIComponent(query), { credentials: 'same-origin' })
                    .then(r => r.json())
                    .then(data => {
                        if (!data || !data.success) {
                            setSearchMessage((data && data.message) || 'Search failed.', 'error');
                            return;
                        }
                        if (!data.found) {
                            rmCalState.searchQuery = '';
                            rmCalState.highlightDates = [];
                            rmCalState.searchStartDates = [];
                            rmCalState.searchEndDates = [];
                            if (clearBtn) clearBtn.style.display = 'none';
                            if (rmCalLastGridData) renderCalendarGrid(rmCalLastGridData);
                            setSearchMessage(data.message || 'No schedule found for this ID.', 'error');
                            return;
                        }

                        rmCalState.searchQuery = data.query || query;
                        rmCalState.highlightDates = Array.isArray(data.highlight_dates) ? data.highlight_dates : [];
                        rmCalState.searchStartDates = (data.items || [])
                            .map(it => it.start_date)
                            .filter(Boolean);
                        rmCalState.searchEndDates = (data.items || [])
                            .map(it => it.end_date)
                            .filter(function (d, i, arr) {
                                const start = (data.items[i] || {}).start_date;
                                return d && d !== start;
                            });
                        if (clearBtn) clearBtn.style.display = '';

                        const focusYear = parseInt(data.focus_year, 10) || rmCalState.year;
                        const focusMonth = parseInt(data.focus_month, 10) || rmCalState.month;
                        const sameMonth = focusYear === rmCalState.year && focusMonth === rmCalState.month;

                        const afterRender = () => {
                            applySearchHighlights();
                            setSearchMessage(buildSearchSuccessMessage(data.items || []), 'success');
                        };

                        if (sameMonth) {
                            if (rmCalLastGridData) renderCalendarGrid(rmCalLastGridData);
                            afterRender();
                        } else {
                            loadCalendarMonth(focusYear, focusMonth, afterRender);
                        }
                    })
                    .catch(() => setSearchMessage('Search failed.', 'error'));
            }

            function rmCalEscape(s) {
                return String(s == null ? '' : s)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;');
            }

            function rmCalMonthName(year, month) {
                return new Date(year, month - 1, 1).toLocaleString('en-US', { month: 'long', year: 'numeric' });
            }

            const rmCalSourceLabels = {
                lgu: 'LGU',
                cimm: 'CIMM',
                ipms: 'IPMS'
            };

            function rmSourceToRmLink(item) {
                const src = item.source;
                let sourceParam = 'lgu_reports';
                if (src === 'cimm') sourceParam = 'cimm';
                else if (src === 'ipms') sourceParam = 'maintenance';
                return 'report_management.php?source=' + encodeURIComponent(sourceParam)
                    + '&id=' + encodeURIComponent(item.id)
                    + '&open=1';
            }

            function loadCalendarMonth(year, month, onRendered) {
                if (rmCalState.loading) return;
                rmCalState.loading = true;
                const hint = document.getElementById('rmCalHint');
                const badge = document.getElementById('rmCalBadge');
                if (hint) hint.textContent = 'Loading schedule…';

                const url = 'schedule_calendar.php?ajax=calendar_month&year=' + encodeURIComponent(year)
                    + '&month=' + encodeURIComponent(month);
                fetch(url, { credentials: 'same-origin' })
                    .then(r => r.json())
                    .then(data => {
                        if (!data || !data.success) {
                            if (hint) hint.textContent = (data && data.message) || 'Failed to load calendar';
                            return;
                        }
                        rmCalState.year = data.year;
                        rmCalState.month = data.month;
                        rmCalState.days = data.days || {};
                        rmCalLastGridData = data;
                        const label = document.getElementById('rmCalMonthLabel');
                        if (label) label.textContent = rmCalMonthName(data.year, data.month);
                        if (badge) {
                            const n = parseInt(data.total_items, 10) || 0;
                            badge.textContent = n + (n === 1 ? ' Active' : ' Active');
                        }
                        renderCalendarGrid(data);
                        if (rmCalState.highlightDates.length) {
                            applySearchHighlights();
                        }
                        if (hint) {
                            hint.textContent = (parseInt(data.total_items, 10) || 0) === 0
                                ? 'No approved or in-progress reports with schedule dates this month.'
                                : 'Click a day with reports to view everything active that day.';
                        }
                        if (typeof onRendered === 'function') onRendered();
                    })
                    .catch(() => {
                        if (hint) hint.textContent = 'Failed to load calendar';
                    })
                    .finally(() => { rmCalState.loading = false; });
            }

            function renderCalendarGrid(data) {
                const grid = document.getElementById('rmCalGrid');
                if (!grid) return;
                const daysMap = data.days || {};
                const monthStart = data.month_start;
                const monthEnd = data.month_end;
                const gridStart = data.grid_start;
                const gridEnd = data.grid_end;
                const today = new Date();
                const todayKey = today.getFullYear() + '-' + pad(today.getMonth() + 1) + '-' + pad(today.getDate());
                const hitSet = {};
                rmCalState.highlightDates.forEach(d => { hitSet[d] = true; });
                const startSet = {};
                rmCalState.searchStartDates.forEach(d => { startSet[d] = true; });
                const endSet = {};
                rmCalState.searchEndDates.forEach(d => { endSet[d] = true; });

                const dows = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                let html = '';
                dows.forEach(d => { html += '<div class="sc-cal-dow">' + d + '</div>'; });

                let cursor = new Date(gridStart + 'T00:00:00');
                const end = new Date(gridEnd + 'T00:00:00');
                while (cursor <= end) {
                    const y = cursor.getFullYear();
                    const m = pad(cursor.getMonth() + 1);
                    const d = pad(cursor.getDate());
                    const key = y + '-' + m + '-' + d;
                    const inMonth = key >= monthStart && key <= monthEnd;
                    const info = daysMap[key] || null;
                    const count = info ? (parseInt(info.count, 10) || 0) : 0;
                    const sources = (info && Array.isArray(info.sources)) ? info.sources : [];
                    const sourceCounts = (info && info.source_counts && typeof info.source_counts === 'object')
                        ? info.source_counts
                        : {};
                    const classes = ['sc-cal-day'];
                    if (!inMonth) classes.push('other-month');
                    if (key === todayKey) classes.push('today');
                    if (count > 0 || hitSet[key]) classes.push('has-items');
                    if (hitSet[key]) classes.push('search-hit');
                    if (startSet[key]) classes.push('search-hit-start');
                    if (endSet[key]) classes.push('search-hit-end');

                    html += '<div class="' + classes.join(' ') + '"'
                        + ((count > 0 || hitSet[key]) ? ' role="button" tabindex="0" data-date="' + key + '"' : '')
                        + '>';
                    html += '<div class="sc-cal-day-num">' + cursor.getDate() + '</div>';
                    if (count > 0) {
                        html += '<div class="sc-cal-day-meta">';
                        html += '<span class="sc-cal-count">' + count + '</span>';
                        html += '<span class="sc-cal-sources">';
                        sources.forEach(s => {
                            const srcCount = parseInt(sourceCounts[s], 10) || 0;
                            const label = (rmCalSourceLabels[s] || s.toUpperCase())
                                + (srcCount > 1 ? ' (' + srcCount + ')' : '');
                            html += '<i class="sc-cal-dot ' + rmCalEscape(s) + '" title="' + rmCalEscape(label) + '"></i>';
                        });
                        html += '</span></div>';
                    }
                    html += '</div>';
                    cursor.setDate(cursor.getDate() + 1);
                }
                grid.innerHTML = html;

                grid.querySelectorAll('.sc-cal-day.has-items').forEach(el => {
                    const open = () => openCalendarDay(el.getAttribute('data-date'));
                    el.addEventListener('click', open);
                    el.addEventListener('keydown', (e) => {
                        if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            open();
                        }
                    });
                });
            }

            function openCalendarDay(date) {
                if (!date) return;
                const list = document.getElementById('rmCalDayList');
                const title = document.getElementById('rmCalDayTitle');
                const sub = document.getElementById('rmCalDaySub');
                const modal = document.getElementById('rmCalendarDayModal');
                if (list) list.innerHTML = '<p style="color:#6b7280;">Loading…</p>';
                if (title) {
                    const d = new Date(date + 'T00:00:00');
                    title.textContent = d.toLocaleDateString('en-US', {
                        weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
                    });
                }
                if (sub) sub.textContent = 'Active reports';
                if (modal) modal.classList.add('active');

                fetch('schedule_calendar.php?ajax=calendar_day&date=' + encodeURIComponent(date), { credentials: 'same-origin' })
                    .then(r => r.json())
                    .then(data => {
                        if (!data || !data.success) {
                            if (list) list.innerHTML = '<p style="color:#ef4444;">Failed to load day details.</p>';
                            return;
                        }
                        rmCalendarDayItems = data.items || [];
                        if (sub) sub.textContent = (data.count || 0) + ' report' + ((data.count === 1) ? '' : 's');
                        renderCalendarDayList(rmCalendarDayItems);
                    })
                    .catch(() => {
                        if (list) list.innerHTML = '<p style="color:#ef4444;">Failed to load day details.</p>';
                    });
            }

            function renderCalendarDayList(items) {
                const list = document.getElementById('rmCalDayList');
                if (!list) return;
                if (!items.length) {
                    list.innerHTML = '<p style="color:#6b7280;">No reports active on this day.</p>';
                    return;
                }
                list.innerHTML = items.map((item) => {
                    const src = item.source || 'lgu';
                    const status = (item.status || '—').replace(/-/g, ' ');
                    const range = (item.start_date || '—') + ' → ' + (item.end_date || 'Open');
                    const href = rmSourceToRmLink(item);
                    const isHit = rmCalItemMatchesQuery(item, rmCalState.searchQuery);
                    return '<div class="sc-day-item' + (isHit ? ' search-hit' : '') + '">'
                        + '<div class="sc-day-item-top">'
                        + '<div class="sc-day-item-title">' + rmCalEscape(item.title || 'Untitled') + '</div>'
                        + '<span class="sc-source-badge ' + rmCalEscape(src) + '">' + rmCalEscape(item.source_label || src.toUpperCase()) + '</span>'
                        + '</div>'
                        + '<div class="sc-day-item-meta">'
                        + '<div><strong>ID:</strong> ' + rmCalEscape(item.report_id || item.id) + '</div>'
                        + '<div><strong>Status:</strong> ' + rmCalEscape(status) + '</div>'
                        + '<div><strong>Schedule:</strong> ' + rmCalEscape(range) + '</div>'
                        + (item.location ? '<div><strong>Location:</strong> ' + rmCalEscape(item.location) + '</div>' : '')
                        + (item.engineer ? '<div><strong>Engineer:</strong> ' + rmCalEscape(item.engineer) + '</div>' : '')
                        + '</div>'
                        + '<div class="sc-day-item-actions">'
                        + '<a class="sc-action-btn" href="' + rmCalEscape(href) + '">'
                        + '<i class="fas fa-external-link-alt"></i> Open in Report Management</a>'
                        + '</div></div>';
                }).join('');
            }

            window.closeCalendarDayModal = function () {
                const modal = document.getElementById('rmCalendarDayModal');
                if (modal) modal.classList.remove('active');
            };

            const prev = document.getElementById('rmCalPrev');
            const next = document.getElementById('rmCalNext');
            const todayBtn = document.getElementById('rmCalToday');
            if (prev) {
                prev.addEventListener('click', () => {
                    let y = rmCalState.year, m = rmCalState.month - 1;
                    if (m < 1) { m = 12; y -= 1; }
                    loadCalendarMonth(y, m);
                });
            }
            if (next) {
                next.addEventListener('click', () => {
                    let y = rmCalState.year, m = rmCalState.month + 1;
                    if (m > 12) { m = 1; y += 1; }
                    loadCalendarMonth(y, m);
                });
            }
            if (todayBtn) {
                todayBtn.addEventListener('click', () => {
                    const now = new Date();
                    loadCalendarMonth(now.getFullYear(), now.getMonth() + 1);
                });
            }

            const searchInput = document.getElementById('rmCalIdSearch');
            const searchBtn = document.getElementById('rmCalIdSearchBtn');
            const searchClear = document.getElementById('rmCalIdSearchClear');
            if (searchBtn) searchBtn.addEventListener('click', runCalendarIdSearch);
            if (searchInput) {
                searchInput.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        runCalendarIdSearch();
                    }
                });
            }
            if (searchClear) searchClear.addEventListener('click', clearCalendarSearch);

            loadCalendarMonth(rmCalState.year, rmCalState.month);
        })();
    </script>
</body>
</html>
