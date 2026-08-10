<?php
// Shared navbar search bar for the public landing page navbar.
// Live-searches site pages (client-side index) and public reports (API).
// Uses $basePath when it is defined (subfolder installs).
$__ns_base = isset($basePath) ? $basePath : '';
?>
<style>
    .qc-nav-search {
        position: relative;
        flex-shrink: 0;
    }
    .qc-search-input {
        width: 210px;
        padding: 8px 36px 8px 12px;
        border: 2px solid rgba(17, 82, 114, 0.25);
        border-radius: 8px;
        background: transparent;
        font-size: 0.85rem;
        font-weight: 500;
        color: #38414a;
        outline: none;
        transition: all 0.2s ease;
    }
    .qc-search-input:focus {
        border-color: #1381b6;
        background: #ffffff;
        box-shadow: 0 0 0 3px rgba(19, 129, 182, 0.12);
    }
    .qc-search-input::placeholder { color: #8a97a3; }
    .qc-navbar .qc-search-input { color: var(--qc-primary-800, #115272); }
    .navbar-dark .qc-search-input { color: #ffffff; border-color: rgba(255, 255, 255, 0.4); }
    .navbar-dark .qc-search-input::placeholder { color: rgba(255, 255, 255, 0.7); }
    .qc-search-btn {
        position: absolute;
        right: 6px;
        top: 50%;
        transform: translateY(-50%);
        border: none;
        background: transparent;
        color: #1381b6;
        cursor: pointer;
        padding: 6px;
        font-size: 0.9rem;
    }
    .qc-search-results {
        position: absolute;
        top: calc(100% + 8px);
        right: 0;
        min-width: 320px;
        max-width: 420px;
        background: #ffffff;
        border: 1px solid #e0e8ee;
        border-radius: 12px;
        box-shadow: 0 12px 32px rgba(17, 82, 114, 0.14);
        padding: 8px;
        max-height: 420px;
        overflow-y: auto;
        display: none;
        z-index: 1050;
    }
    .qc-search-results.show { display: block; }
    .qc-search-group-title {
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        color: #8a97a3;
        padding: 8px 10px 4px;
    }
    .qc-search-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 9px 12px;
        border-radius: 8px;
        font-size: 0.9rem;
        font-weight: 600;
        color: #38414a;
        text-decoration: none;
        cursor: pointer;
        transition: background-color 0.2s ease, color 0.2s ease;
    }
    .qc-search-item i {
        width: 20px;
        text-align: center;
        color: #1381b6;
        font-size: 1rem;
    }
    .qc-search-item small {
        display: block;
        color: #8a97a3;
        font-weight: 500;
        font-size: 0.75rem;
    }
    .qc-search-item:hover { background: #f1f9fe; color: #115272; }
    .qc-search-empty { padding: 14px; text-align: center; color: #8a97a3; font-size: 0.85rem; }
    .qc-search-loading { padding: 14px; text-align: center; color: #8a97a3; font-size: 0.85rem; }

    @media (max-width: 1200px) {
        .qc-search-input { width: 160px; }
    }
</style>

<!-- Navbar Search -->
<div class="qc-nav-search" id="qcNavSearch">
    <input type="text" class="qc-search-input" id="qcSearchInput" placeholder="Search site..." autocomplete="off" aria-label="Search site">
    <button class="qc-search-btn" type="button" aria-label="Search"><i class="fas fa-search"></i></button>
    <div class="qc-search-results" id="qcSearchResults"></div>
</div>

<script>
(function () {
    var BASE = '<?php echo $__ns_base; ?>';
    var input = document.getElementById('qcSearchInput');
    var resultsBox = document.getElementById('qcSearchResults');
    if (!input || !resultsBox) return;

    var pages = [
        { title: 'Home', url: 'index.php', icon: 'fa-home', kw: 'home landing page start welcome' },
        { title: 'Road Updates', url: 'road-updates.php', icon: 'fa-newspaper', kw: 'news updates announcements latest' },
        { title: 'Road Projects', url: 'index.php#road-projects', icon: 'fa-road', kw: 'projects ongoing upcoming infrastructure ipms' },
        { title: 'Road Status', url: 'public_reports.php', icon: 'fa-map-marked-alt', kw: 'reports status browse reports issues problems' },
        { title: 'Transparency', url: 'public_transparency_view.php', icon: 'fa-balance-scale', kw: 'transparency portal documents reports' },
        { title: 'About', url: 'about.php', icon: 'fa-info-circle', kw: 'about us mission department' },
        { title: 'Contact', url: 'contact.php', icon: 'fa-envelope', kw: 'contact reach email phone' },
        { title: 'Traffic Management', url: 'service-traffic-management.php', icon: 'fa-traffic-light', kw: 'service traffic management congestion signal' },
        { title: 'Emergency Road Response', url: 'service-emergency-road-response.php', icon: 'fa-truck-medical', kw: 'service emergency response rescue road accidents' },
        { title: 'Infrastructure Maintenance', url: 'service-infrastructure-maintenance.php', icon: 'fa-tools', kw: 'service infrastructure maintenance repair road works' },
        { title: 'Road Condition Monitoring', url: 'service-road-condition-monitoring.php', icon: 'fa-chart-line', kw: 'service monitoring road condition quality sensors' }
    ];

    var searchTimer = null;

    function escapeHtml(t) {
        if (!t) return '';
        var d = document.createElement('div');
        d.textContent = t;
        return d.innerHTML;
    }

    function pageMatches(p, q) {
        return (p.title + ' ' + p.kw).toLowerCase().indexOf(q) !== -1;
    }

    function showLoading() {
        resultsBox.innerHTML = '<div class="qc-search-loading"><i class="fas fa-spinner fa-spin"></i> Searching&hellip;</div>';
        resultsBox.classList.add('show');
    }

    function renderReport(report, first) {
        var el = document.createElement('a');
        el.className = 'qc-search-item' + (first ? ' qc-search-item-active' : '');
        var status = report.status ? ' &middot; ' + report.status.replace(/-/g, ' ') : '';
        var loc = report.location || '';
        el.innerHTML =
            '<i class="fas fa-map-marker-alt"></i>' +
            '<span>' + escapeHtml(report.title) +
            '<small>' + escapeHtml(loc) + escapeHtml(status) + '</small></span>';
        el.href = BASE + 'public_reports.php?report_id=' + encodeURIComponent(report.id);
        return el;
    }

    function renderPage(p, first) {
        var el = document.createElement('a');
        el.className = 'qc-search-item' + (first ? ' qc-search-item-active' : '');
        el.innerHTML = '<i class="fas ' + p.icon + '"></i><span>' + escapeHtml(p.title) + '</span>';
        el.href = BASE + p.url;
        return el;
    }

    function buildGroups(pagesHit, reports) {
        var groups = [];
        if (pagesHit.length) groups.push({ title: 'Pages', items: pagesHit });
        if (reports && reports.length) groups.push({ title: 'Reports', items: reports });
        return groups;
    }

    function doSearch() {
        var q = input.value.trim().toLowerCase();
        if (q.length < 2) {
            resultsBox.classList.remove('show');
            resultsBox.innerHTML = '';
            return;
        }

        var pagesHit = pages.filter(function (p) { return pageMatches(p, q); });

        showLoading();

        var url = BASE + 'includes/public_search_api.php?q=' + encodeURIComponent(q);
        fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var reports = Array.isArray(data) ? data : [];
                var groups = buildGroups(pagesHit, reports);

                if (!groups.length) {
                    resultsBox.innerHTML = '<div class="qc-search-empty"><i class="fas fa-search"></i> No results for &ldquo;' + escapeHtml(input.value.trim()) + '&rdquo;</div>';
                    resultsBox.classList.add('show');
                    return;
                }

                resultsBox.innerHTML = '';
                var firstItem = null;
                groups.forEach(function (g) {
                    var title = document.createElement('div');
                    title.className = 'qc-search-group-title';
                    title.textContent = g.title;
                    resultsBox.appendChild(title);
                    g.items.forEach(function (it) {
                        var el = it.type === 'report' ? renderReport(it, !firstItem) : renderPage(it, !firstItem);
                        if (!firstItem) firstItem = el;
                        resultsBox.appendChild(el);
                    });
                });
                resultsBox.classList.add('show');
            })
            .catch(function () {
                if (pagesHit.length) {
                    var groups = buildGroups(pagesHit, []);
                    resultsBox.innerHTML = '';
                    groups.forEach(function (g) {
                        var title = document.createElement('div');
                        title.className = 'qc-search-group-title';
                        title.textContent = g.title;
                        resultsBox.appendChild(title);
                        g.items.forEach(function (it) {
                            resultsBox.appendChild(renderPage(it, false));
                        });
                    });
                    resultsBox.classList.add('show');
                } else {
                    resultsBox.innerHTML = '<div class="qc-search-empty"><i class="fas fa-search"></i> No results found</div>';
                    resultsBox.classList.add('show');
                }
            });
    }

    input.addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(doSearch, 200);
    });

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            var first = resultsBox.querySelector('.qc-search-item');
            if (first) { e.preventDefault(); window.location.href = first.href; }
        }
    });

    document.addEventListener('click', function (e) {
        var box = document.getElementById('qcNavSearch');
        if (box && !box.contains(e.target)) {
            resultsBox.classList.remove('show');
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            resultsBox.classList.remove('show');
            input.blur();
        }
    });
})();
</script>
