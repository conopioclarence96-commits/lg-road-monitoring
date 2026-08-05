<?php
// Shared hamburger menu markup for the public landing pages.
// Uses $basePath when it is defined (subfolder installs) and keeps
// same-page anchor links (#home / #road-projects) on index.php.
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
</aside>
