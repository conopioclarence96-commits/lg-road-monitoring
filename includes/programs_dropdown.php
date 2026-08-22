<?php
// Shared Programs dropdown for the public landing page navbar.
// Renders a "Programs" dropdown with quick links to program pages.
// Uses $basePath when it is defined (subfolder installs).
$__pd_base = isset($basePath) ? $basePath : '';
?>
<style>
    .qc-programs-dropdown {
        margin: 0;
        flex-shrink: 0;
    }
    .qc-programs-dropdown .dropdown-toggle {
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
    .qc-programs-dropdown .dropdown-toggle:hover,
    .qc-programs-dropdown .dropdown-toggle:focus {
        background: rgba(17, 82, 114, 0.08);
        transform: translateY(-1px);
    }
    .qc-navbar .qc-programs-dropdown .dropdown-toggle { color: var(--qc-primary-800, #115272); }
    .navbar-dark .qc-programs-dropdown .dropdown-toggle { color: #ffffff; border-color: rgba(255, 255, 255, 0.4); }
    .navbar-dark .qc-programs-dropdown .dropdown-toggle:hover,
    .navbar-dark .qc-programs-dropdown .dropdown-toggle:focus { background: rgba(255, 255, 255, 0.12); }
    .qc-programs-dropdown .dropdown-menu {
        min-width: 280px;
        border: 1px solid #e0e8ee;
        border-radius: 12px;
        box-shadow: 0 12px 32px rgba(17, 82, 114, 0.14);
        padding: 8px;
    }
    .qc-programs-dropdown .dropdown-item {
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
    .qc-programs-dropdown .dropdown-item i {
        width: 20px;
        text-align: center;
        color: #1381b6;
        font-size: 1.05rem;
    }
    .qc-programs-dropdown .dropdown-item:hover {
        background: #f1f9fe;
        color: #115272;
    }
</style>

<!-- Programs Dropdown -->
<div class="dropdown qc-programs-dropdown">
    <button class="btn dropdown-toggle" type="button" id="programsDropdown" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="fas fa-tasks"></i> Programs
    </button>
    <ul class="dropdown-menu" aria-labelledby="programsDropdown">
        <li>
            <a class="dropdown-item" href="<?php echo $__pd_base; ?>road-updates.php">
                <i class="fas fa-newspaper"></i> Road Updates
            </a>
        </li>
        <li>
            <a class="dropdown-item" href="<?php echo $__pd_base; ?>public_reports.php">
                <i class="fas fa-map-marked-alt"></i> Road Status
            </a>
        </li>
        <li>
            <hr class="dropdown-divider">
        </li>
        <li>
            <a class="dropdown-item" href="<?php echo $__pd_base; ?>transportation-updates.php">
                <i class="fas fa-bus"></i> Transportation Updates
            </a>
        </li>
        <li>
            <a class="dropdown-item" href="<?php echo $__pd_base; ?>transportation-status.php">
                <i class="fas fa-traffic-light"></i> Transportation Status
            </a>
        </li>
        <li>
            <hr class="dropdown-divider">
        </li>
        <li>
            <a class="dropdown-item" href="<?php echo $__pd_base; ?>public_transparency_view.php">
                <i class="fas fa-balance-scale"></i> Transparency
            </a>
        </li>
    </ul>
</div>
