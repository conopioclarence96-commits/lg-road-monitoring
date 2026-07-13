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
    .a11y-option label { font-size: 0.9rem; font-weight: 500; color: #333; margin: 0; }
    .a11y-btn-group { display: flex; gap: 6px; }
    .a11y-btn-group button { width: 34px; height: 34px; border-radius: 8px; border: 1px solid #ddd; background: #f8f9fa; color: var(--primary-color); font-size: 1rem; font-weight: 700; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; }
    .a11y-btn-group button:hover { background: var(--primary-color); color: white; border-color: var(--primary-color); }
    .a11y-toggle-btn { padding: 6px 14px; border-radius: 8px; border: 1px solid #ddd; background: #f8f9fa; color: var(--primary-color); font-size: 0.8rem; font-weight: 600; cursor: pointer; transition: all 0.2s ease; }
    .a11y-toggle-btn.active { background: var(--primary-color); color: white; border-color: var(--primary-color); }
    .a11y-toggle-btn:hover { background: var(--secondary-color); color: white; border-color: var(--secondary-color); }
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
    body.high-contrast .a11y-option label { color: #fff; }
    body.high-contrast .a11y-btn-group button { background: #333; color: #fff; border-color: #555; }
    body.high-contrast .a11y-btn-group button:hover { background: #fff; color: #000; }
    body.high-contrast .a11y-toggle-btn { background: #333; color: #fff; border-color: #555; }
    body.high-contrast .a11y-reset { background: #333; color: #fff; }
    body.high-contrast .a11y-reset:hover { background: #555; }
    body.high-contrast .a11y-option { border-color: #333; }
    body.high-contrast .filters-bar { background: #1a1a1a; border-color: #333; }
    body.high-contrast .stats-ribbon { background: #1a1a1a; border-color: #333; }
    body.large-text p, body.large-text .card-text, body.large-text .stat-label, body.large-text .lead, body.large-text .report-desc, body.large-text .publication-feed-card__desc { font-size: 1.15em; }
    body.large-text .section-title { font-size: 2.8rem; }
    body.readable-font * { font-family: 'Verdana', 'Arial', sans-serif !important; }
    @media (max-width: 768px) { .a11y-fab { bottom: 20px; right: 20px; } .a11y-fab-btn { width: 50px; height: 50px; font-size: 1.3rem; } .a11y-panel { width: 260px; bottom: 65px; } }
</style>
<?php endif; ?>
