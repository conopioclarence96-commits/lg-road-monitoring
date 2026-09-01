<?php
/**
 * Shared <base href> for staff pages served via clean URLs (/reports, /monitoring, …).
 * Include immediately after <head> on every layout page.
 */
if (!function_exists('rgmap_head_base_tag')) {
    require_once __DIR__ . '/routes.php';
}
rgmap_head_base_tag();
