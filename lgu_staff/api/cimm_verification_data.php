<?php
/**
 * DEPRECATED LOCATION.
 *
 * This file used to be the CIMM verification data-access layer, before the
 * app's API endpoints were reorganized under lgu_staff/pages/api/. It was
 * left behind as a stale, narrower duplicate (older schema, no unique key
 * on cimm_req_id) of the real file, which is what caused every hardcoded
 * "/lgu_staff/api/cimm-reports-webhook.php"-style URL in this codebase (and
 * in CIMM's cimm_rgmap_sync.php) to point at a path that doesn't actually
 * serve the webhook.
 *
 * Nothing in the app currently includes this file directly — the admin page
 * requires the canonical copy at lgu_staff/pages/api/cimm_verification_data.php.
 * This shim exists only so that if anything *does* still reference this old
 * path, it gets the current, correct implementation instead of silently
 * running against the outdated schema again.
 */
require_once __DIR__ . '/../pages/api/cimm_verification_data.php';
