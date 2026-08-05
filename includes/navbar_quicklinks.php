<?php
// Shared centered quick-links group for the public landing page navbar.
// Holds the Services and Programs dropdowns side by side.
// Hidden on small screens; equivalent links live in the hamburger menu.
?>
<style>
    .qc-nav-center {
        position: absolute;
        right: 76px;
        top: 50%;
        transform: translateY(-50%);
        display: flex;
        align-items: center;
        gap: 10px;
        z-index: 1;
    }
    @media (max-width: 991.98px) {
        .qc-nav-center { display: none; }
    }
</style>
<div class="qc-nav-center">
    <?php include __DIR__ . '/services_dropdown.php'; ?>
    <?php include __DIR__ . '/programs_dropdown.php'; ?>
    <?php include __DIR__ . '/navbar_search.php'; ?>
</div>
