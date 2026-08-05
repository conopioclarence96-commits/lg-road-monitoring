<?php
// Shared hamburger menu markup for the public landing pages.
// Uses $basePath when it is defined (subfolder installs) and keeps
// same-page anchor links (#home / #road-projects) on index.php.
$__hm_base = isset($basePath) ? $basePath : '';
$__hm_is_index = (basename($_SERVER['PHP_SELF'] ?? '') === 'index.php');

// Secret-key gating for the Login link. On the production domain the Login
// button is only rendered when the page URL carries the secret key, so the
// public system doesn't advertise the staff login. On localhost this gating
// is disabled (the Login link always shows) to make development easy.
$__hm_secret = 'QC_RRD_LOGIN_2026_SECRET';
$__hm_host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
$__hm_host_only = strtolower(parse_url((string)$__hm_host, PHP_URL_HOST) ?: $__hm_host);
$__hm_is_local = in_array($__hm_host_only, ['localhost', '127.0.0.1', '::1'], true)
    || strpos($__hm_host_only, '.local') !== false;
$__hm_has_secret = isset($_GET['site_key']) && (string)$_GET['site_key'] === $__hm_secret;
$__hm_show_login = $__hm_is_local || $__hm_has_secret;
?>
<!-- Hamburger Menu Button -->
<button class="hamburger-btn" id="hamburgerBtn" aria-label="Open navigation menu" aria-expanded="false" aria-controls="sideMenu">
    <span class="bar"></span>
    <span class="bar"></span>
    <span class="bar"></span>
</button>

<!-- Side Menu Overlay -->
<div class="menu-overlay" id="menuOverlay"></div>

<!-- Side Menu -->
<aside class="side-menu" id="sideMenu" aria-label="Navigation menu">
    <div class="side-menu-header">
        <img src="<?php echo $__hm_base; ?>assets/img/logocityhall.png" alt="Quezon City Hall Logo">
        <h4>Road &amp; Transportation<br>Department</h4>
    </div>
    <ul class="side-menu-nav">
        <li><a href="<?php echo $__hm_is_index ? '#home' : $__hm_base . 'index.php'; ?>"><i class="fas fa-home"></i> Home</a></li>
        <li><a href="<?php echo $__hm_base; ?>road-updates.php"><i class="fas fa-newspaper"></i> Road Updates</a></li>
        <li><a href="<?php echo $__hm_is_index ? '#road-projects' : $__hm_base . 'index.php#road-projects'; ?>"><i class="fas fa-road"></i> Road Projects</a></li>
        <li><a href="<?php echo $__hm_base; ?>public_reports.php"><i class="fas fa-map-marked-alt"></i> Road Status</a></li>
        <li><a href="<?php echo $__hm_base; ?>about.php"><i class="fas fa-info-circle"></i> About</a></li>
        <li><a href="<?php echo $__hm_base; ?>contact.php"><i class="fas fa-envelope"></i> Contact</a></li>
        <li><a href="<?php echo $__hm_base; ?>public_transparency_view.php"><i class="fas fa-balance-scale"></i> Transparency</a></li>
    </ul>
    <div class="side-menu-footer">
        <?php if ($__hm_show_login): ?>
        <a href="<?php echo $__hm_base; ?>lgu_staff/login.php" class="btn btn-login">
            <i class="fas fa-sign-in-alt"></i> Login
        </a>
        <?php endif; ?>
    </div>
</aside>
