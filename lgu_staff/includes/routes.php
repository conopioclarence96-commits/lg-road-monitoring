<?php
/**
 * Clean public URL routes for RGMap (Apache rewrite targets stay internal).
 * Browser-visible paths hide lgu_staff/pages/.../*.php filenames.
 */

/** @return array<string,string> route key => public path (leading slash, no .php) */
function rgmap_route_map(): array {
    return [
        'home'                 => '/',
        'login'                => '/login',
        'logout'               => '/logout',
        'change-password'      => '/change-password',
        'forgot-password'      => '/forgot-password',
        'admin-dashboard'      => '/admin-dashboard',
        'staff-dashboard'      => '/staff-dashboard',
        'manage-accounts'      => '/manage-accounts',
        'account-approvals'    => '/account-approvals',
        'create-staff'         => '/create-staff',
        'send-registration'    => '/send-registration',
        'monitoring'           => '/monitoring',
        'verification'         => '/verification',
        'report-management'    => '/reports',
        'completed-projects'   => '/completed-projects',
        'public-transparency'  => '/public-transparency',
        'announcements'        => '/announcements',
        'analytics'            => '/analytics',
        'audit-trail'          => '/audit-trail',
        'notifications'        => '/notifications',
        'schedule-calendar'    => '/schedule-calendar',
        'archive'              => '/archive',
        'officer-archive'      => '/officer-archive',
        'settings'             => '/settings',
        'change-info'          => '/change-info',
        'sla-dashboard'        => '/sla-dashboard',
        'stats'                => '/stats',
        'print-report'         => '/print-report',
    ];
}

/** @return array<string,string> route key => internal file path relative to project root */
function rgmap_route_internal_files(): array {
    return [
        'login'                => 'lgu_staff/login.php',
        'logout'               => 'lgu_staff/logout.php',
        'change-password'      => 'lgu_staff/change_password.php',
        'forgot-password'      => 'lgu_staff/forgot_password.php',
        'admin-dashboard'      => 'lgu_staff/pages/admin/admin_dashboard.php',
        'staff-dashboard'      => 'lgu_staff/pages/lgu/lgu_staff_dashboard.php',
        'manage-accounts'      => 'lgu_staff/pages/admin/manage_accounts.php',
        'account-approvals'    => 'lgu_staff/pages/admin/account_approvals.php',
        'create-staff'         => 'lgu_staff/pages/admin/create_staff_account.php',
        'send-registration'    => 'lgu_staff/pages/admin/send_registration_link.php',
        'monitoring'           => 'lgu_staff/pages/shared/road_transportation_monitoring.php',
        'verification'         => 'lgu_staff/pages/admin/verification_monitoring.php',
        'report-management'    => 'lgu_staff/pages/admin/report_management.php',
        'completed-projects'   => 'lgu_staff/pages/shared/completed_projects.php',
        'public-transparency'  => 'lgu_staff/pages/shared/public_transparency.php',
        'announcements'        => 'lgu_staff/pages/admin/announcements.php',
        'analytics'            => 'lgu_staff/pages/shared/analytics.php',
        'audit-trail'          => 'lgu_staff/pages/admin/audit_trail.php',
        'notifications'        => 'lgu_staff/pages/shared/notifications.php',
        'schedule-calendar'    => 'lgu_staff/pages/admin/schedule_calendar.php',
        'archive'              => 'lgu_staff/pages/admin/archive.php',
        'officer-archive'      => 'lgu_staff/pages/lgu/officer_archive.php',
        'settings'             => 'lgu_staff/pages/shared/settings.php',
        'change-info'          => 'lgu_staff/pages/lgu/change_info.php',
        'sla-dashboard'        => 'lgu_staff/pages/shared/sla_dashboard.php',
        'stats'                => 'lgu_staff/stats.php',
        'print-report'         => 'lgu_staff/pages/shared/print_report.php',
    ];
}

/**
 * Web root prefix when the app lives in a subdirectory (e.g. /lg-road-monitoring).
 */
function rgmap_web_base(): string {
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (preg_match('#^(/.*?)/lgu_staff/#', $script, $m)) {
        $base = rtrim($m[1], '/');
        return $base;
    }

    $uri = str_replace('\\', '/', (string)($_SERVER['REQUEST_URI'] ?? ''));
    $uri = strtok($uri, '?') ?: '';
    if (preg_match('#^(/lg-road-monitoring)(?:/|$)#', $uri, $m)) {
        $base = $m[1];
        return $base;
    }

    $base = '';
    return $base;
}

/** Absolute URL path prefix for lgu_staff static assets (css, js, uploads, …). */
function rgmap_lgu_base(): string {
    return rgmap_web_base() . '/lgu_staff';
}

/** Public clean URL for a named route. */
function rgmap_url(string $route_key, array $query = []): string {
    $routes = rgmap_route_map();
    $path = $routes[$route_key] ?? $route_key;
    if ($path !== '/' && $path[0] !== '/') {
        $path = '/' . $path;
    }
    $url = rgmap_web_base() . $path;
    if (!empty($query)) {
        $url .= (strpos($url, '?') !== false ? '&' : '?') . http_build_query($query);
    }
    return $url;
}

/** Absolute path to an asset under lgu_staff/ (css, assets, js, uploads, …). */
function rgmap_asset(string $relative): string {
    return rgmap_lgu_base() . '/' . ltrim(str_replace('\\', '/', $relative), '/');
}

/** Absolute path to a JSON/API script exposed at /api/{script}. */
function rgmap_api_url(string $script): string {
    $script = ltrim(str_replace('\\', '/', $script), '/');
    return rgmap_web_base() . '/api/' . $script;
}

/** Route key for the currently executing internal PHP script, if mapped. */
function rgmap_current_route_key(): ?string {
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    foreach (rgmap_route_internal_files() as $key => $internal) {
        if ($script === '/' . $internal || substr($script, -strlen('/' . $internal)) === '/' . $internal) {
            return $key;
        }
    }
    return null;
}

/** Default post-login route key — mirrors login.php role switch (clean URLs). */
function rgmap_role_home_route(string $role): string {
    if ($role === 'system_admin') {
        return 'admin-dashboard';
    }
    return 'staff-dashboard';
}

/** Redirect helper using clean public URLs. */
function rgmap_redirect(string $route_key, array $query = [], int $status = 302): void {
    header('Location: ' . rgmap_url($route_key, $query), true, $status);
    exit;
}

/** Public homepage (index.php at project root). */
function rgmap_home_url(): string {
    $base = rgmap_web_base();
    return $base === '' ? '/' : $base . '/';
}

/** Absolute base URL for resolving relative asset/API paths (with trailing slash). */
function rgmap_base_href(): string {
    $base = rgmap_web_base();
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host . ($base === '' ? '/' : $base . '/');
}

/** Emit <base href="..."> so css/js/api paths work from clean flat routes. */
function rgmap_head_base_tag(): void {
    echo '<base href="' . htmlspecialchars(rgmap_base_href(), ENT_QUOTES, 'UTF-8') . '">' . "\n";
}

/**
 * If the browser requested an internal *.php path for a mapped route, 301 to the clean URL.
 * POST/AJAX/unmapped scripts are left unchanged.
 */
function rgmap_enforce_clean_url(): void {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'HEAD') {
        return;
    }

    $route_key = rgmap_current_route_key();
    if ($route_key === null) {
        return;
    }

    $request_path = strtok(str_replace('\\', '/', (string)($_SERVER['REQUEST_URI'] ?? '')), '?') ?: '';
    if (!preg_match('#\.php(?:/|$)#i', $request_path)) {
        return;
    }

    $query = $_GET;
    header('Location: ' . rgmap_url($route_key, $query), true, 301);
    exit;
}
