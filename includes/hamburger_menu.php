<?php
// Shared hamburger menu markup for the public landing pages.
// Uses $basePath when it is defined (subfolder installs) and keeps
// same-page anchor links (#home) on index.php.
$__hm_base = isset($basePath) ? $basePath : '';
$__hm_is_index = (basename($_SERVER['PHP_SELF'] ?? '') === 'index.php');
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
        <img src="<?php echo $__hm_base; ?>assets/img/infra-gov-logo.png" alt="Quezon City Hall Logo">
        <h4>Road &amp; Transportation<br>Department</h4>
    </div>
    <ul class="side-menu-nav">
        <li><a href="<?php echo $__hm_is_index ? '#home' : $__hm_base . 'index.php'; ?>"><i class="fas fa-home"></i> Home</a></li>
    </ul>
    <div class="side-menu-group">
        <h5 class="menu-label"><i class="fas fa-cogs"></i> Services</h5>
        <ul class="side-menu-nav">
            <li><a href="<?php echo $__hm_base; ?>service-traffic-management.php"><i class="fas fa-traffic-light"></i> Traffic Management</a></li>
            <li><a href="<?php echo $__hm_base; ?>service-emergency-road-response.php"><i class="fas fa-truck-medical"></i> Emergency Road Response</a></li>
            <li><a href="<?php echo $__hm_base; ?>service-infrastructure-maintenance.php"><i class="fas fa-tools"></i> Infrastructure Maintenance</a></li>
            <li><a href="<?php echo $__hm_base; ?>service-road-condition-monitoring.php"><i class="fas fa-chart-line"></i> Road Condition Monitoring</a></li>
        </ul>
    </div>
    <div class="side-menu-group">
        <h5 class="menu-label"><i class="fas fa-tasks"></i> Programs</h5>
        <ul class="side-menu-nav">
            <li><a href="<?php echo $__hm_base; ?>road-updates.php"><i class="fas fa-newspaper"></i> Road Updates</a></li>
            <li><a href="<?php echo $__hm_base; ?>public_reports.php"><i class="fas fa-map-marked-alt"></i> Road Status</a></li>
            <li><a href="<?php echo $__hm_base; ?>transportation-updates.php"><i class="fas fa-bus"></i> Transportation Updates</a></li>
            <li><a href="<?php echo $__hm_base; ?>transportation-status.php"><i class="fas fa-traffic-light"></i> Transportation Status</a></li>
            <li><a href="<?php echo $__hm_base; ?>public_transparency_view.php"><i class="fas fa-balance-scale"></i> Transparency</a></li>
        </ul>
    </div>
    <div class="side-menu-footer" style="display: none;">
        <a href="<?php echo $__hm_base; ?>lgu_staff/login.php" class="btn btn-login">
            <i class="fas fa-sign-in-alt"></i> Login
        </a>
    </div>
</aside>
