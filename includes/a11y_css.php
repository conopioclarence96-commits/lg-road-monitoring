<?php if (empty($GLOBALS['a11y_css_loaded'])): $GLOBALS['a11y_css_loaded'] = true; ?>
<style>
    .a11y-fab { position: fixed; bottom: 30px; right: 30px; z-index: 1000; }
    .a11y-fab-btn { width: 60px; height: 60px; border-radius: 50%; background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); color: white; border: none; box-shadow: 0 4px 20px rgba(30, 60, 114, 0.4); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1.6rem; transition: all 0.3s ease; }
    .a11y-fab-btn:hover { transform: scale(1.1); box-shadow: 0 6px 28px rgba(30, 60, 114, 0.55); }
    .a11y-panel { position: absolute; bottom: 75px; right: 0; background: white; border-radius: 16px; box-shadow: 0 8px 40px rgba(0,0,0,0.18); padding: 20px; width: 280px; display: none; animation: a11ySlideUp 0.3s ease; }
    .a11y-panel.active { display: block; }
    @keyframes a11ySlideUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
    .a11y-panel h5 { font-size: 1rem; font-weight: 700; color: var(--primary-color); margin-bottom: 15px; display: flex; align-items: center; gap: 8px; }
    .a11y-option { display: flex; align-items: center; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee; }
    .a11y-option:last-child { border-bottom: none; }
    .a11y-option > label:not(.a11y-option-row) { font-size: 0.9rem; font-weight: 500; color: #333; margin: 0; }
    .a11y-option-row { display: flex; align-items: center; justify-content: space-between; width: 100%; margin: 0; cursor: pointer; }
    .a11y-option-text { font-size: 0.9rem; font-weight: 500; color: #333; }
    .a11y-btn-group { display: flex; gap: 6px; }
    .a11y-btn-group button { width: 34px; height: 34px; border-radius: 8px; border: 1px solid #ddd; background: #f8f9fa; color: var(--primary-color); font-size: 1rem; font-weight: 700; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; }
    .a11y-btn-group button:hover { background: var(--primary-color); color: white; border-color: var(--primary-color); }
    .a11y-switch { position: relative; display: inline-block; width: 48px; height: 26px; flex-shrink: 0; }
    .a11y-switch input { opacity: 0; width: 0; height: 0; position: absolute; margin: 0; }
    .a11y-switch-slider { position: absolute; inset: 0; background: #ccc; border-radius: 26px; transition: background 0.25s ease; }
    .a11y-switch-slider::before { content: ''; position: absolute; height: 20px; width: 20px; left: 3px; bottom: 3px; background: #fff; border-radius: 50%; transition: transform 0.25s ease; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
    .a11y-switch input:checked + .a11y-switch-slider { background: var(--primary-color); }
    .a11y-switch input:checked + .a11y-switch-slider::before { transform: translateX(22px); }
    .a11y-switch input:focus-visible + .a11y-switch-slider { outline: 2px solid var(--primary-color); outline-offset: 2px; }
    .a11y-reset { width: 100%; margin-top: 10px; padding: 8px; border-radius: 8px; border: none; background: #eee; color: #555; font-size: 0.85rem; font-weight: 600; cursor: pointer; transition: background 0.2s ease; }
    .a11y-reset:hover { background: #ddd; }
    body.high-contrast { background: #000 !important; color: #fff !important; }
    body.high-contrast .navbar { background: #000 !important; }
    body.high-contrast .hero, body.high-contrast .hero-bar, body.high-contrast .service-hero { background: #000 !important; }
    body.high-contrast .section, body.high-contrast .contact-section, body.high-contrast .content-section { background: #111 !important; }
    body.high-contrast .stat-card, body.high-contrast .service-card, body.high-contrast .update-card, body.high-contrast .mission-card, body.high-contrast .feature-card, body.high-contrast .emergency-card, body.high-contrast .report-card, body.high-contrast .info-card, body.high-contrast .publication-feed-card { background: #1a1a1a !important; color: #fff !important; }
    body.high-contrast footer { background: #000 !important; }
    body.high-contrast h1, body.high-contrast h2, body.high-contrast h3, body.high-contrast h4, body.high-contrast h5, body.high-contrast h6, body.high-contrast .section-title, body.high-contrast .stat-number, body.high-contrast .service-title { color: #fff !important; }
    body.high-contrast p, body.high-contrast .card-text, body.high-contrast .stat-label, body.high-contrast .text-muted, body.high-contrast .report-desc, body.high-contrast .publication-feed-card__desc { color: #ccc !important; }
    body.high-contrast .a11y-panel { background: #1a1a1a; color: #fff; }
    body.high-contrast .a11y-panel h5 { color: #fff; }
    body.high-contrast .a11y-option > label:not(.a11y-option-row),
    body.high-contrast .a11y-option-text { color: #fff; }
    body.high-contrast .a11y-btn-group button { background: #333; color: #fff; border-color: #555; }
    body.high-contrast .a11y-btn-group button:hover { background: #fff; color: #000; }
    body.high-contrast .a11y-switch-slider { background: #555; }
    body.high-contrast .a11y-switch input:checked + .a11y-switch-slider { background: #fff; }
    body.high-contrast .a11y-switch input:checked + .a11y-switch-slider::before { background: #000; }
    body.high-contrast .a11y-reset { background: #333; color: #fff; }
    body.high-contrast .a11y-reset:hover { background: #555; }
    body.high-contrast .a11y-option { border-color: #333; }
    body.high-contrast .filters-bar { background: #1a1a1a; border-color: #333; }
    body.high-contrast .stats-ribbon { background: #1a1a1a; border-color: #333; }
    body.large-text p, body.large-text .card-text, body.large-text .stat-label, body.large-text .lead, body.large-text .report-desc, body.large-text .publication-feed-card__desc { font-size: 1.15em; }
    body.large-text .section-title { font-size: 2.8rem; }
    body.readable-font * { font-family: 'Verdana', 'Arial', sans-serif !important; }

    /* Dark Mode Transition */
    body.theme-transition,
    body.theme-transition *,
    body.theme-transition *::before,
    body.theme-transition *::after {
        transition: background-color 0.35s ease, color 0.35s ease, border-color 0.35s ease, box-shadow 0.35s ease, fill 0.35s ease, stroke 0.35s ease !important;
    }
    /* Dark Mode */
    html.dark-mode { background: #121212 !important; color: #e0e0e0 !important; }
    html.dark-mode .navbar { background: linear-gradient(135deg, #0d1b2a 0%, #1b2838 100%) !important; }
    html.dark-mode .navbar-brand { color: #fff !important; }
    html.dark-mode .navbar-nav .nav-link { color: #ccc !important; }
    html.dark-mode .navbar-nav .nav-link:hover { color: #4CAF50 !important; }
    html.dark-mode .hero { background: linear-gradient(rgba(13, 27, 42, 0.9), rgba(27, 40, 56, 0.9)) !important; }
    html.dark-mode .section { background: #121212 !important; }
    html.dark-mode .section-title { color: #90caf9 !important; }
    html.dark-mode .section-subtitle { color: #aaa !important; }
    html.dark-mode .stat-card, html.dark-mode .service-card, html.dark-mode .update-card,
    html.dark-mode .mission-card, html.dark-mode .feature-card, html.dark-mode .emergency-card,
    html.dark-mode .report-card, html.dark-mode .info-card, html.dark-mode .publication-feed-card,
    html.dark-mode .contact-card { background: #1e1e1e !important; color: #e0e0e0 !important; border-color: #333 !important; }
    html.dark-mode .stat-card h3, html.dark-mode .stat-card .stat-number { color: #fff !important; }
    html.dark-mode .stat-card p, html.dark-mode .stat-card .stat-label { color: #bbb !important; }
    html.dark-mode .service-card .service-title { color: #fff !important; }
    html.dark-mode .service-card p, html.dark-mode .service-card .service-desc { color: #bbb !important; }
    html.dark-mode .update-card h5, html.dark-mode .update-card h4 { color: #fff !important; }
    html.dark-mode .update-card p, html.dark-mode .update-card .card-text { color: #bbb !important; }
    html.dark-mode .mission-card h3 { color: #fff !important; }
    html.dark-mode .mission-card p { color: #bbb !important; }
    html.dark-mode .feature-card h3, html.dark-mode .feature-card h4 { color: #fff !important; }
    html.dark-mode .feature-card p { color: #bbb !important; }
    html.dark-mode h1, html.dark-mode h2, html.dark-mode h3, html.dark-mode h4, html.dark-mode h5, html.dark-mode h6 { color: #e0e0e0 !important; }
    html.dark-mode p, html.dark-mode .card-text, html.dark-mode .stat-label,
    html.dark-mode .text-muted, html.dark-mode .report-desc, html.dark-mode .publication-feed-card__desc { color: #bbb !important; }
    html.dark-mode a { color: #90caf9 !important; }
    html.dark-mode a:hover { color: #64b5f6 !important; }
    html.dark-mode footer { background: #0d1117 !important; color: #ccc !important; }
    html.dark-mode footer a { color: #90caf9 !important; }
    html.dark-mode .contact-section { background: #1a1a2e !important; }
    html.dark-mode .content-section { background: #16213e !important; }
    html.dark-mode form, html.dark-mode .form-control { background: #1e1e1e !important; color: #e0e0e0 !important; border-color: #444 !important; }
    html.dark-mode .form-control:focus { background: #2a2a2a !important; color: #fff !important; border-color: #90caf9 !important; box-shadow: 0 0 0 0.2rem rgba(144, 202, 249, 0.25) !important; }
    html.dark-mode .form-control::placeholder { color: #888 !important; }
    html.dark-mode .btn-primary { background: #1e3c72 !important; border-color: #1e3c72 !important; }
    html.dark-mode .btn-primary:hover { background: #2a5298 !important; border-color: #2a5298 !important; }
    html.dark-mode .btn-hero.btn-primary-hero { background: #4CAF50 !important; }
    html.dark-mode .btn-hero.btn-secondary-hero { border-color: #ccc !important; color: #ccc !important; }
    html.dark-mode .btn-hero.btn-secondary-hero:hover { background: #ccc !important; color: #121212 !important; }
    html.dark-mode table { background: #1e1e1e !important; color: #e0e0e0 !important; }
    html.dark-mode table thead { background: #2a2a2a !important; }
    html.dark-mode table th { color: #ccc !important; border-color: #444 !important; }
    html.dark-mode table td { color: #ddd !important; border-color: #333 !important; }
    html.dark-mode .table { --bs-table-bg: #1e1e1e; --bs-table-color: #e0e0e0; --bs-table-border-color: #333; }
    html.dark-mode .table > :not(caption) > * > * { background-color: var(--bs-table-bg); color: var(--bs-table-color); border-color: var(--bs-table-border-color); }
    html.dark-mode .table-danger { --bs-table-bg: #2a1416; --bs-table-color: #fda4af; --bs-table-border-color: #5c2228; }
    html.dark-mode .table-primary { --bs-table-bg: #122a44; --bs-table-color: #93c5fd; --bs-table-border-color: #1e3a5f; }
    html.dark-mode .table-success { --bs-table-bg: #13251a; --bs-table-color: #6ee7b7; --bs-table-border-color: #1f4d33; }
    html.dark-mode .table-warning { --bs-table-bg: #3a3418; --bs-table-color: #fde68a; --bs-table-border-color: #4a411f; }
    html.dark-mode .table-info { --bs-table-bg: #0e2430; --bs-table-color: #7dd3fc; --bs-table-border-color: #1f4a5e; }
    html.dark-mode .table-secondary { --bs-table-bg: #262a30; --bs-table-color: #c8cdd4; --bs-table-border-color: #3a3f47; }
    html.dark-mode .table-dark { --bs-table-bg: #2a2d33; --bs-table-color: #e4e6ea; --bs-table-border-color: #3a3f47; }
    html.dark-mode .table-light { --bs-table-bg: #1e2229; --bs-table-color: #e4e6ea; --bs-table-border-color: #2d323b; }
    html.dark-mode .table-striped > tbody > tr:nth-of-type(odd) > * { --bs-table-bg: #1a1a1a !important; }
    html.dark-mode .table-striped > tbody > tr:nth-of-type(even) > * { --bs-table-bg: #222 !important; }
    html.dark-mode .card { background: #1e1e1e !important; color: #e0e0e0 !important; border-color: #333 !important; }
    html.dark-mode .card-header { background: #2a2a2a !important; color: #e0e0e0 !important; border-color: #444 !important; }
    html.dark-mode .modal-content { background: #1e1e1e !important; color: #e0e0e0 !important; }
    html.dark-mode .modal-header { border-bottom-color: #444 !important; }
    html.dark-mode .modal-footer { border-top-color: #444 !important; }
    html.dark-mode .badge { color: #fff !important; }
    html.dark-mode .text-dark { color: #e0e0e0 !important; }
    html.dark-mode .text-primary { color: #90caf9 !important; }
    html.dark-mode .bg-light { background: #1e1e1e !important; }
    html.dark-mode .bg-white { background: #1e1e1e !important; }
    html.dark-mode .border { border-color: #444 !important; }
    html.dark-mode .alert-success { background: #13251a !important; color: #6ee7b7 !important; border-color: #1f4d33 !important; }
    html.dark-mode .alert-danger { background: #2a1416 !important; color: #fda4af !important; border-color: #5c2228 !important; }
    html.dark-mode .alert-info { background: #0e2430 !important; color: #7dd3fc !important; border-color: #1f4a5e !important; }
    html.dark-mode .alert-primary { background: #122a44 !important; color: #93c5fd !important; border-color: #1e3a5f !important; }
    html.dark-mode .alert-secondary { background: #262a30 !important; color: #c8cdd4 !important; border-color: #3a3f47 !important; }
    html.dark-mode .alert-dark { background: #2a2d33 !important; color: #e4e6ea !important; border-color: #3a3f47 !important; }
    html.dark-mode .alert-light { background: #1e2229 !important; color: #e4e6ea !important; border-color: #2d323b !important; }
    html.dark-mode .alert-warning { background: #3a3418 !important; color: #fde68a !important; border-color: #4a411f !important; }
    html.dark-mode .alert-warning h5 { color: #fde68a !important; }
    html.dark-mode .alert-link { color: inherit !important; font-weight: 700 !important; }
    html.dark-mode .list-group-item { background: #1e1e1e !important; color: #e0e0e0 !important; border-color: #333 !important; }
    html.dark-mode .dropdown-menu { background: #1e1e1e !important; border-color: #444 !important; }
    html.dark-mode .dropdown-item { color: #ccc !important; }
    html.dark-mode .dropdown-item:hover { background: #333 !important; color: #fff !important; }
    html.dark-mode .a11y-panel { background: #1e1e1e !important; color: #e0e0e0 !important; }
    html.dark-mode .a11y-panel h5 { color: #90caf9 !important; }
    html.dark-mode .a11y-option > label:not(.a11y-option-row),
    html.dark-mode .a11y-option-text { color: #e0e0e0 !important; }
    html.dark-mode .a11y-option { border-color: #333 !important; }
    html.dark-mode .a11y-btn-group button { background: #333 !important; color: #fff !important; border-color: #555 !important; }
    html.dark-mode .a11y-btn-group button:hover { background: #90caf9 !important; color: #000 !important; }
    html.dark-mode .a11y-switch-slider { background: #555 !important; }
    html.dark-mode .a11y-switch input:checked + .a11y-switch-slider { background: #4CAF50 !important; }
    html.dark-mode .a11y-reset { background: #333 !important; color: #ccc !important; }
    html.dark-mode .a11y-reset:hover { background: #555 !important; }
    html.dark-mode .filters-bar { background: #1e1e1e !important; border-color: #333 !important; }
    html.dark-mode .stats-ribbon { background: #1a1a2e !important; border-color: #333 !important; }
    html.dark-mode .progress { background: #333 !important; }
    html.dark-mode .progress-bar { background: #4CAF50 !important; }
    html.dark-mode input, html.dark-mode select, html.dark-mode textarea { background: #1e1e1e !important; color: #e0e0e0 !important; border-color: #444 !important; }
    html.dark-mode ::-webkit-scrollbar { width: 8px; }
    html.dark-mode ::-webkit-scrollbar-track { background: #1e1e1e; }
    html.dark-mode ::-webkit-scrollbar-thumb { background: #555; border-radius: 4px; }
    html.dark-mode ::-webkit-scrollbar-thumb:hover { background: #777; }

    @media (max-width: 768px) { .a11y-fab { bottom: 20px; right: 20px; } .a11y-fab-btn { width: 50px; height: 50px; font-size: 1.3rem; } .a11y-panel { width: 260px; bottom: 65px; } }
</style>
<?php endif; ?>
