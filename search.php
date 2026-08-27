<?php
// Dedicated search results page — accurate to landing page content.
// Shows Pages (landing anchors + site sections), Road Reports, Projects, Announcements
// so after searching the user sees the exact content they need.

ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);
session_start();

$basePath = '';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
if (strpos($scriptName, '/lgu_staff/') !== false) {
    $basePath = '../';
} elseif (strpos($scriptName, '/public/') !== false) {
    $basePath = '../';
}

require_once __DIR__ . '/lgu_staff/includes/config.php';
require_once __DIR__ . '/lgu_staff/includes/functions.php';

$q = trim($_GET['q'] ?? '');
$q_safe = htmlspecialchars($q, ENT_QUOTES, 'UTF-8');
$has_query = ($q !== '' && mb_strlen($q) >= 2);

// Pages index — must stay in sync with includes/navbar_search.php
$all_pages = [
    ['title' => 'Home', 'url' => 'index.php#home', 'icon' => 'fa-home', 'kw' => 'home landing welcome hero quezon city road transportation department', 'desc' => 'Landing hero — overview and quick actions'],
    ['title' => 'Road Updates (Landing)', 'url' => 'index.php#updates', 'icon' => 'fa-newspaper', 'kw' => 'road updates latest news announcements landing home', 'desc' => 'Latest road condition cards on the landing page'],
    ['title' => 'Monitoring Statistics', 'url' => 'index.php#updates', 'icon' => 'fa-chart-bar', 'kw' => 'statistics stats monitoring total reports ongoing resolved pending numbers', 'desc' => 'Live counts of reports and repairs'],
    ['title' => 'Announcements', 'url' => 'index.php#announcements', 'icon' => 'fa-bullhorn', 'kw' => 'announcements notices lgu published official notices', 'desc' => 'Official LGU announcements on the landing page'],
    ['title' => 'About Section', 'url' => 'index.php#about', 'icon' => 'fa-info-circle', 'kw' => 'about mission vision values department', 'desc' => 'About the Road & Transportation Department'],
    ['title' => 'Contact', 'url' => 'index.php#contact', 'icon' => 'fa-envelope', 'kw' => 'contact phone email hotline office location address', 'desc' => 'Contact information and office location'],
    ['title' => 'Make a Report', 'url' => 'index.php#home', 'icon' => 'fa-pen-alt', 'kw' => 'report issue citizen make report pin map photo complaint file', 'desc' => 'Open the citizen report form'],
    ['title' => 'Road Updates', 'url' => 'road-updates.php', 'icon' => 'fa-newspaper', 'kw' => 'road updates listing all announcements', 'desc' => 'All road updates in one place'],
    ['title' => 'Road Status & Public Reports', 'url' => 'public_reports.php', 'icon' => 'fa-map-marked-alt', 'kw' => 'road status reports browse map filter pending in-progress completed pothole flood', 'desc' => 'Browse and filter every public road report'],
    ['title' => 'Infrastructure Projects', 'url' => 'infrastructure_projects.php', 'icon' => 'fa-hard-hat', 'kw' => 'infrastructure projects ipms road projects budget construction approved', 'desc' => 'Approved IPMS road projects'],
    ['title' => 'Transportation Updates', 'url' => 'transportation-updates.php', 'icon' => 'fa-bus', 'kw' => 'transportation updates transit bus terminal commute', 'desc' => 'Public transportation updates'],
    ['title' => 'Transportation Status', 'url' => 'transportation-status.php', 'icon' => 'fa-traffic-light', 'kw' => 'transportation status traffic condition status congestion', 'desc' => 'Current transportation conditions'],
    ['title' => 'Public Transparency', 'url' => 'public_transparency_view.php', 'icon' => 'fa-balance-scale', 'kw' => 'transparency public announcements published audit portal', 'desc' => 'Transparency portal and published announcements'],
    ['title' => 'About Page', 'url' => 'about.php', 'icon' => 'fa-info-circle', 'kw' => 'about page department mission', 'desc' => 'Detailed about page'],
    ['title' => 'Contact Page', 'url' => 'contact.php', 'icon' => 'fa-envelope', 'kw' => 'contact page email phone hotline', 'desc' => 'Contact form and details'],
    ['title' => 'Traffic Management', 'url' => 'service-traffic-management.php', 'icon' => 'fa-traffic-light', 'kw' => 'service traffic management enforcers monitoring officers congestion signal', 'desc' => 'Officer-led traffic management service'],
    ['title' => 'Emergency Road Response', 'url' => 'service-emergency-road-response.php', 'icon' => 'fa-truck-medical', 'kw' => 'service emergency rescue response accident rescue road closure flood', 'desc' => 'Emergency and road incident response'],
    ['title' => 'Infrastructure Maintenance', 'url' => 'service-infrastructure-maintenance.php', 'icon' => 'fa-tools', 'kw' => 'service infrastructure maintenance repair pothole crack construction works', 'desc' => 'Road maintenance and repair works'],
    ['title' => 'Road Condition Monitoring', 'url' => 'service-road-condition-monitoring.php', 'icon' => 'fa-chart-line', 'kw' => 'service monitoring road condition quality sensors inspection', 'desc' => 'Continuous road condition monitoring'],
];

$pages_hit = [];
if ($has_query) {
    $tokens = preg_split('/\s+/', mb_strtolower($q));
    foreach ($all_pages as $p) {
        $hay = mb_strtolower($p['title'] . ' ' . $p['kw'] . ' ' . $p['desc']);
        $ok = true;
        foreach ($tokens as $tok) {
            if ($tok === '') continue;
            if (mb_strpos($hay, $tok) === false) { $ok = false; break; }
        }
        if ($ok) $pages_hit[] = $p;
    }
}

// DB results — same sources as public_search_api.php but slightly higher limits for the dedicated page
$reports = [];
$projects = [];
$cimm_items = [];
$announcements = [];
if ($has_query && $conn) {
    $like = '%' . $q . '%';
    // Transportation
    try {
        $stmt = $conn->prepare("SELECT id, report_id, title, description, location, status, priority, 'report' AS type, created_at FROM road_transportation_reports WHERE title LIKE ? OR report_id LIKE ? OR location LIKE ? OR description LIKE ? ORDER BY created_at DESC LIMIT 10");
        if ($stmt) { $stmt->bind_param('ssss', $like,$like,$like,$like); $stmt->execute(); $r=$stmt->get_result(); while($row=$r->fetch_assoc()) $reports[]=$row; $stmt->close(); }
    } catch (Throwable $e) { error_log("search transport ".$e->getMessage()); }
    // Maintenance
    try {
        $stmt = $conn->prepare("SELECT id, report_id, title, description, location, status, priority, 'report' AS type, created_at FROM road_maintenance_reports WHERE title LIKE ? OR report_id LIKE ? OR location LIKE ? OR description LIKE ? ORDER BY created_at DESC LIMIT 10");
        if ($stmt) { $stmt->bind_param('ssss', $like,$like,$like,$like); $stmt->execute(); $r=$stmt->get_result(); while($row=$r->fetch_assoc()) $reports[]=$row; $stmt->close(); }
    } catch (Throwable $e) { error_log("search maint ".$e->getMessage()); }
    // IPMS
    try {
        $chk=$conn->query("SHOW TABLES LIKE 'ipms_road_projects'");
        if($chk && $chk->num_rows>0){
            $stmt=$conn->prepare("SELECT project_id AS id, CAST(project_id AS CHAR) AS report_id, project_name AS title, COALESCE(NULLIF(road_status,''),'') AS description, COALESCE(NULLIF(road_name,''),project_name) AS location, 'pending' AS status, 'project' AS type, created_at FROM ipms_road_projects WHERE status='approved' AND (project_name LIKE ? OR road_name LIKE ? OR road_status LIKE ? OR CAST(project_id AS CHAR) LIKE ?) ORDER BY created_at DESC LIMIT 10");
            if($stmt){ $stmt->bind_param('ssss',$like,$like,$like,$like); $stmt->execute(); $r=$stmt->get_result(); while($row=$r->fetch_assoc()) $projects[]=$row; $stmt->close(); }
        }
        if($chk) $chk->close();
    } catch(Throwable $e){ error_log("search ipms ".$e->getMessage()); }
    // CIMM
    try {
        $chk=$conn->query("SHOW TABLES LIKE 'cimm_verification_reports'");
        if($chk && $chk->num_rows>0){
            $stmt=$conn->prepare("SELECT id, reference_code AS report_id, infrastructure AS title, issue AS description, location, COALESCE(resolution_status,'pending') AS status, 'cimm' AS type, COALESCE(submitted_at, verified_at, synced_at, NOW()) AS created_at FROM cimm_verification_reports WHERE infrastructure='Roads' AND (reference_code LIKE ? OR infrastructure LIKE ? OR issue LIKE ? OR location LIKE ?) ORDER BY created_at DESC LIMIT 10");
            if($stmt){ $stmt->bind_param('ssss',$like,$like,$like,$like); $stmt->execute(); $r=$stmt->get_result(); while($row=$r->fetch_assoc()) $cimm_items[]=$row; $stmt->close(); }
        }
        if($chk) $chk->close();
    } catch(Throwable $e){ error_log("search cimm ".$e->getMessage()); }
    // Announcements
    try {
        $chk=$conn->query("SHOW TABLES LIKE 'public_transparency_announcements'");
        if($chk && $chk->num_rows>0){
            $stmt=$conn->prepare("SELECT id, title, content AS description, posted_at AS created_at, 'announcement' AS type FROM public_transparency_announcements WHERE is_published=1 AND (title LIKE ? OR content LIKE ?) ORDER BY posted_at DESC LIMIT 10");
            if($stmt){ $stmt->bind_param('ss',$like,$like); $stmt->execute(); $r=$stmt->get_result(); while($row=$r->fetch_assoc()) $announcements[]=$row; $stmt->close(); }
        }
        if($chk) $chk->close();
    } catch(Throwable $e){ error_log("search ann ".$e->getMessage()); }
}

$total = count($pages_hit) + count($reports) + count($projects) + count($cimm_items) + count($announcements);

function highlight($text, $q) {
    if ($q === '' || $text === '') return htmlspecialchars($text);
    $escaped = htmlspecialchars($text);
    $tokens = preg_split('/\s+/', preg_quote($q, '/'));
    // use case-insensitive highlight preserving original case via callback on escaped already — simpler: wrap via regex on escaped
    foreach (array_filter($tokens) as $tok) {
        if (mb_strlen($tok) < 2) continue;
        $escaped = preg_replace('/(' . $tok . ')/i', '<mark class="search-hl">$1</mark>', $escaped);
    }
    return $escaped;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/includes/a11y_head.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search<?php echo $has_query ? ' — ' . $q_safe : ''; ?> - Road & Transportation Department</title>
    <link rel="icon" type="image/png" href="assets/img/infra-gov-logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles/transition.css">
    <style>
        :root {
            --primary-color: #115272; --secondary-color: #1d698b; --accent-color: #d93939;
            --qc-primary-50:#f1f9fe; --qc-primary-100:#e1f1fc; --qc-primary-500:#21a1d6; --qc-primary-600:#1381b6; --qc-primary-800:#115272; --qc-primary-900:#143c5e;
            --qc-shades-100:#eef1f3; --qc-shades-300:#c3ccd3; --qc-shades-500:#5f6c75; --qc-card-border:#e0e8ee; --qc-icon-bg:#d6e9f8;
        }
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Montserrat',sans-serif;color:#3e454c;background:#fff}
        .qc-navbar{background:#fff!important;border-bottom:1px solid var(--qc-shades-100);box-shadow:0 1px 3px rgba(17,82,114,.06);padding:.55rem 0}
        .qc-brand{display:flex;align-items:center;gap:12px;text-decoration:none;padding:0}
        .qc-brand img{height:46px;width:auto;border-radius:6px}
        .qc-brand-text{line-height:1.15;text-align:left}
        .qc-brand-text strong{display:block;font-size:1.02rem;font-weight:800;color:var(--qc-primary-800)}
        .qc-brand-text small{display:block;font-size:.7rem;font-weight:600;letter-spacing:.5px;text-transform:uppercase;color:var(--qc-primary-600)}
        .qc-navbar .container-fluid{padding-right:76px}
        .hamburger-btn{top:11px!important;right:18px!important;width:42px!important;height:42px!important;border:2px solid rgba(17,82,114,.25)!important;background:rgba(17,82,114,.06)!important;box-shadow:0 4px 12px rgba(17,82,114,.12)!important}
        .hamburger-btn:hover{background:rgba(17,82,114,.14)!important}
        .hamburger-btn .bar{background:var(--qc-primary-800)!important}
        .hero-bar{background:linear-gradient(115deg,rgba(11,42,62,.96) 0%,rgba(17,82,114,.9) 55%,rgba(19,129,182,.78) 100%),url('assets/img/cityhall.jpeg') center/cover;padding:112px 0 64px;color:#fff;text-align:center}
        .hero-bar h1{font-size:2rem;font-weight:800;margin-bottom:8px;text-shadow:0 2px 10px rgba(0,0,0,.25)}
        .hero-bar p{font-size:1.02rem;opacity:.92;margin:0}
        .search-hl{background:#fff3cd;padding:0 2px;border-radius:3px;color:#664d03}
        .section{padding:32px 0 48px}
        .result-card{background:#fff;border:1px solid var(--qc-card-border);border-radius:12px;padding:16px 18px;transition:transform .15s,box-shadow .15s}
        .result-card:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(17,82,114,.08)}
        .result-card .r-title{font-weight:700;color:var(--qc-primary-900);margin:0 0 4px;line-height:1.3}
        .result-card .r-title a{color:inherit;text-decoration:none}
        .result-card .r-title a:hover{color:var(--qc-primary-600);text-decoration:underline}
        .result-card .r-meta{font-size:.82rem;color:var(--qc-shades-500);display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:6px}
        .result-card .r-desc{font-size:.88rem;color:#4a5c6a;line-height:1.55;margin:0;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
        .r-badge{display:inline-flex;align-items:center;gap:6px;padding:3px 8px;border-radius:20px;font-size:.7rem;font-weight:700;letter-spacing:.3px;text-transform:uppercase}
        .r-badge.page{background:#e3f1fb;color:#115272}
        .r-badge.report{background:#fff3cd;color:#8a6d1a}
        .r-badge.project{background:#d6e9f8;color:#0f4762}
        .r-badge.cimm{background:#e8eaf6;color:#3949ab}
        .r-badge.announcement{background:#def7ec;color:#03543f}
        .group-title{font-size:.95rem;font-weight:800;color:var(--qc-primary-800);margin:26px 0 12px;display:flex;align-items:center;gap:8px}
        .group-title i{color:var(--qc-primary-600)}
        .group-title .count{margin-left:auto;background:var(--qc-primary-50);color:var(--qc-primary-800);padding:2px 10px;border-radius:20px;font-size:.78rem}
        .empty-state{text-align:center;padding:48px 20px;color:var(--qc-shades-500)}
        .empty-state i{font-size:2.6rem;opacity:.35;margin-bottom:12px;color:var(--qc-primary-800)}
        .inline-search{max-width:560px;margin:18px auto 0;display:flex;gap:8px}
        .inline-search input{flex:1;padding:11px 14px;border:1px solid #cbd3d6;border-radius:8px;font-family:inherit}
        .inline-search input:focus{outline:none;border-color:var(--qc-primary-500);box-shadow:0 0 0 3px rgba(33,161,214,.15)}
        .inline-search button{padding:11px 18px;border:none;border-radius:8px;background:var(--qc-primary-800);color:#fff;font-weight:700}
        .inline-search button:hover{background:var(--qc-primary-600)}
        footer.qc-footer{background:linear-gradient(135deg,var(--qc-primary-800) 0%,#1d698b 100%);color:#fff;padding:34px 0 20px}
        footer.qc-footer a{color:#fff;text-decoration:none}
        .footer-top-row{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:20px}
        .footer-contact-row{display:flex;align-items:center;gap:14px;font-size:14px}
        .footer-contact-item{display:inline-flex;align-items:center;gap:8px;color:#fff}
        .contact-separator{width:1px;height:18px;background:rgba(255,255,255,.4)}
        .footer-links-row{display:flex;flex-wrap:wrap;gap:22px}
        .footer-links-row a{font-size:12px;font-weight:600}
        .footer-links-row a:hover{text-decoration:underline}
        .footer-divider{height:1px;background:rgba(255,255,255,.34);margin:22px 0 14px}
        .footer-copyright{font-size:13px;color:rgba(244,248,251,.85);text-align:center;margin:0}
        @media(max-width:768px){.hero-bar{padding:96px 0 48px}.hero-bar h1{font-size:1.5rem}.footer-top-row{flex-direction:column;text-align:center}.footer-contact-row{justify-content:center;flex-wrap:wrap}.footer-links-row{justify-content:center;gap:16px}}
    </style>
    <?php include __DIR__ . '/includes/a11y_css.php'; ?>
    <?php include __DIR__ . '/includes/hamburger_menu_css.php'; ?>
</head>
<body>
    <nav class="navbar navbar-light fixed-top qc-navbar">
        <div class="container-fluid">
            <a class="navbar-brand qc-brand" href="index.php">
                <img src="assets/img/infra-gov-logo.png" alt="Quezon City Hall Logo">
                <span class="qc-brand-text">
                    <strong>Road &amp; Transportation Department</strong>
                    <small>Quezon City Government</small>
                </span>
            </a>
            <?php include __DIR__ . '/includes/navbar_quicklinks.php'; ?>
        </div>
    </nav>
    <?php include __DIR__ . '/includes/mobile_navbar_css.php'; ?>
    <?php include __DIR__ . '/includes/hamburger_menu.php'; ?>

    <div class="hero-bar">
        <div class="container">
            <h1><i class="fas fa-search"></i> Search</h1>
            <?php if($has_query): ?>
                <p>Showing <strong><?php echo $total; ?></strong> result(s) for &ldquo;<strong><?php echo $q_safe; ?></strong>&rdquo; — accurate to landing page content</p>
            <?php else: ?>
                <p>Search pages, road reports, infrastructure projects &amp; announcements — accurate to what the landing page shows</p>
            <?php endif; ?>
            <form class="inline-search" method="GET" action="search.php" role="search">
                <label for="searchQ" class="visually-hidden">Search site</label>
                <input type="text" id="searchQ" name="q" value="<?php echo $q_safe; ?>" placeholder="Try: traffic, pothole, announcement, road closure..." autocomplete="off">
                <button type="submit"><i class="fas fa-search"></i> Search</button>
            </form>
        </div>
    </div>

    <section class="section">
        <div class="container">
            <?php if(!$has_query): ?>
                <div class="empty-state">
                    <i class="fas fa-search"></i>
                    <h5>Type at least 2 characters to search</h5>
                    <p class="mb-0">The search covers everything on the landing page — hero, updates, statistics, announcements, plus all reports and projects.</p>
                </div>
            <?php elseif($total === 0): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h5>No results for &ldquo;<?php echo $q_safe; ?>&rdquo;</h5>
                    <p class="mb-0">Try different keywords: <em>traffic, road, report, pothole, construction, transparency, about, contact</em>.</p>
                    <div class="mt-3"><a href="public_reports.php" class="btn btn-primary"><i class="fas fa-map-marked-alt"></i> Browse All Road Reports</a></div>
                </div>
            <?php else: ?>

                <?php if(!empty($pages_hit)): ?>
                    <div class="group-title"><i class="fas fa-sitemap"></i> Pages &amp; Sections <span class="count"><?php echo count($pages_hit); ?></span></div>
                    <div class="row g-3">
                        <?php foreach($pages_hit as $p): ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="result-card">
                                    <div class="r-meta"><span class="r-badge page"><i class="fas <?php echo htmlspecialchars($p['icon']); ?>"></i> Page</span></div>
                                    <p class="r-title"><a href="<?php echo htmlspecialchars($basePath . $p['url']); ?>"><?php echo highlight($p['title'], $q); ?></a></p>
                                    <p class="r-desc"><?php echo highlight($p['desc'], $q); ?><br><small class="text-muted"><?php echo htmlspecialchars($p['url']); ?></small></p>
                                    <a href="<?php echo htmlspecialchars($basePath . $p['url']); ?>" class="btn btn-sm btn-outline-primary mt-2"><i class="fas fa-arrow-right"></i> Open</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if(!empty($reports)): ?>
                    <div class="group-title"><i class="fas fa-map-marker-alt"></i> Road Reports <span class="count"><?php echo count($reports); ?></span></div>
                    <div class="row g-3">
                        <?php foreach($reports as $r): ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="result-card">
                                    <div class="r-meta">
                                        <span class="r-badge report"><i class="fas fa-road"></i> <?php echo htmlspecialchars($r['status'] ?? 'report'); ?></span>
                                        <?php if(!empty($r['priority'])): ?><span class="r-badge" style="background:#fde68a;color:#92400e;"><?php echo htmlspecialchars($r['priority']); ?></span><?php endif; ?>
                                    </div>
                                    <p class="r-title"><a href="public_reports.php?report_id=<?php echo (int)$r['id']; ?>"><?php echo highlight($r['title'] ?? 'Untitled', $q); ?></a></p>
                                    <p class="r-desc"><?php echo highlight(mb_substr($r['description'] ?? '',0,180), $q); ?></p>
                                    <p class="r-meta"><i class="fas fa-map-marker-alt" style="color:#dc3545"></i> <?php echo highlight($r['location'] ?? '—', $q); ?></p>
                                    <a href="public_reports.php?report_id=<?php echo (int)$r['id']; ?>" class="btn btn-sm mt-2" style="background:var(--qc-primary-800);color:#fff;"><i class="fas fa-eye"></i> View Report</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if(!empty($projects)): ?>
                    <div class="group-title"><i class="fas fa-hard-hat"></i> Infrastructure Projects <span class="count"><?php echo count($projects); ?></span></div>
                    <div class="row g-3">
                        <?php foreach($projects as $p): ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="result-card">
                                    <div class="r-meta"><span class="r-badge project"><i class="fas fa-hard-hat"></i> Project</span></div>
                                    <p class="r-title"><a href="infrastructure_projects.php"><?php echo highlight($p['title'] ?? 'Project', $q); ?></a></p>
                                    <p class="r-desc"><?php echo highlight($p['description'] ?: $p['location'] ?? '', $q); ?></p>
                                    <p class="r-meta"><i class="fas fa-map-marker-alt"></i> <?php echo highlight($p['location'] ?? '', $q); ?></p>
                                    <a href="infrastructure_projects.php" class="btn btn-sm btn-outline-primary mt-2"><i class="fas fa-external-link-alt"></i> View Projects</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if(!empty($cimm_items)): ?>
                    <div class="group-title"><i class="fas fa-clipboard-check"></i> CIMM Reports <span class="count"><?php echo count($cimm_items); ?></span></div>
                    <div class="row g-3">
                        <?php foreach($cimm_items as $c): ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="result-card">
                                    <div class="r-meta"><span class="r-badge cimm"><i class="fas fa-clipboard-check"></i> CIMM</span></div>
                                    <p class="r-title"><a href="public_reports.php?type=cimm"><?php echo highlight($c['title'] ?? 'Roads', $q); ?></a> <small>· <?php echo htmlspecialchars($c['report_id']); ?></small></p>
                                    <p class="r-desc"><?php echo highlight(mb_substr($c['description'] ?? '',0,180), $q); ?></p>
                                    <p class="r-meta"><i class="fas fa-map-marker-alt"></i> <?php echo highlight($c['location'] ?? '', $q); ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if(!empty($announcements)): ?>
                    <div class="group-title"><i class="fas fa-bullhorn"></i> Announcements <span class="count"><?php echo count($announcements); ?></span></div>
                    <div class="row g-3">
                        <?php foreach($announcements as $a): ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="result-card">
                                    <div class="r-meta"><span class="r-badge announcement"><i class="fas fa-bullhorn"></i> Announcement</span></div>
                                    <p class="r-title"><a href="public_transparency_view.php"><?php echo highlight($a['title'] ?? 'Announcement', $q); ?></a></p>
                                    <p class="r-desc"><?php echo highlight(mb_substr($a['description'] ?? '',0,200), $q); ?></p>
                                    <a href="public_transparency_view.php" class="btn btn-sm btn-outline-primary mt-2"><i class="fas fa-balance-scale"></i> Open Transparency</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="text-center mt-4">
                    <a href="index.php" class="btn btn-outline-secondary"><i class="fas fa-home"></i> Back to Home</a>
                    <a href="public_reports.php" class="btn btn-primary ms-2" style="background:var(--qc-primary-800);border:none"><i class="fas fa-list"></i> Browse All Reports</a>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <footer class="qc-footer">
        <div class="container">
            <div class="footer-top-row">
                <div class="footer-contact-row">
                    <a href="tel:+63289881234" class="footer-contact-item"><i class="fas fa-phone-alt"></i> (02) 8988-1234</a>
                    <span class="contact-separator"></span>
                    <a href="mailto:roads@lgu.gov.ph" class="footer-contact-item"><i class="fas fa-envelope"></i> roads@lgu.gov.ph</a>
                    <span class="contact-separator"></span>
                    <a href="contact.php" class="footer-contact-item"><i class="fas fa-map-marker-alt"></i> Quezon City Hall</a>
                </div>
            </div>
            <div class="footer-links-row" style="margin-top:14px">
                <a href="index.php">Home</a><a href="road-updates.php">Road Updates</a><a href="public_reports.php">Road Status</a><a href="about.php">About</a><a href="contact.php">Contact</a><a href="public_transparency_view.php">Transparency</a>
            </div>
            <div class="footer-divider"></div>
            <p class="footer-copyright"><i class="fas fa-copyright"></i> 2026 Road and Transportation Department. All rights reserved.</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php include __DIR__ . '/includes/a11y_html.php'; ?>
    <script src="lgu_staff/js/page-transition.js"></script>
    <?php include __DIR__ . '/includes/a11y_js.php'; ?>
    <?php include __DIR__ . '/includes/hamburger_menu_js.php'; ?>
</body>
</html>
