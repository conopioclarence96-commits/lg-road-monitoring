<?php
// Shared Services dropdown for the public landing page navbar.
// Renders a "Services" dropdown with quick links to the service detail pages.
// Uses $basePath when it is defined (subfolder installs).
$__sd_base = isset($basePath) ? $basePath : '';
?>
<style>
    .qc-services-dropdown {
        margin: 0;
        flex-shrink: 0;
    }
    .qc-services-dropdown .dropdown-toggle {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: transparent;
        border: 2px solid rgba(17, 82, 114, 0.25);
        border-radius: 8px;
        font-weight: 700;
        font-size: 0.88rem;
        padding: 8px 12px;
        transition: all 0.2s ease;
    }
    .qc-services-dropdown .dropdown-toggle:hover,
    .qc-services-dropdown .dropdown-toggle:focus {
        background: rgba(17, 82, 114, 0.08);
        transform: translateY(-1px);
    }
    .qc-navbar .qc-services-dropdown .dropdown-toggle { color: var(--qc-primary-800, #115272); }
    .navbar-dark .qc-services-dropdown .dropdown-toggle { color: #ffffff; border-color: rgba(255, 255, 255, 0.4); }
    .navbar-dark .qc-services-dropdown .dropdown-toggle:hover,
    .navbar-dark .qc-services-dropdown .dropdown-toggle:focus { background: rgba(255, 255, 255, 0.12); }
    .qc-services-dropdown .dropdown-menu {
        min-width: 280px;
        border: 1px solid #e0e8ee;
        border-radius: 12px;
        box-shadow: 0 12px 32px rgba(17, 82, 114, 0.14);
        padding: 8px;
    }
    .qc-services-dropdown .dropdown-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 12px;
        border-radius: 8px;
        font-size: 0.92rem;
        font-weight: 600;
        color: #38414a;
        transition: background-color 0.2s ease, color 0.2s ease;
    }
    .qc-services-dropdown .dropdown-item i {
        width: 20px;
        text-align: center;
        color: #1381b6;
        font-size: 1.05rem;
    }
    .qc-services-dropdown .dropdown-item:hover {
        background: #f1f9fe;
        color: #115272;
    }
</style>

<!-- Services Dropdown -->
<div class="dropdown qc-services-dropdown">
    <button class="btn dropdown-toggle" type="button" id="servicesDropdown" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="fas fa-cogs"></i> Services
    </button>
    <ul class="dropdown-menu" aria-labelledby="servicesDropdown">
        <li>
            <a class="dropdown-item" href="<?php echo $__sd_base; ?>service-traffic-management.php">
                <i class="fas fa-traffic-light"></i> Traffic Management
            </a>
        </li>
        <li>
            <a class="dropdown-item" href="<?php echo $__sd_base; ?>service-emergency-road-response.php">
                <i class="fas fa-truck-medical"></i> Emergency Road Response
            </a>
        </li>
        <li>
            <a class="dropdown-item" href="<?php echo $__sd_base; ?>service-infrastructure-maintenance.php">
                <i class="fas fa-tools"></i> Infrastructure Projects
            </a>
        </li>
        <li>
            <a class="dropdown-item" href="<?php echo $__sd_base; ?>service-road-condition-monitoring.php">
                <i class="fas fa-chart-line"></i> Road Condition Monitoring
            </a>
        </li>
    </ul>
</div>
