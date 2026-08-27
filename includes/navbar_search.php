<?php
// Shared navbar search bar for the public site.
// Live-searches site pages (client-side index anchored to the landing page content)
// and all public data (reports, infrastructure projects, announcements) via API.
// Uses $basePath when defined (subfolder installs); falls back to JS-derived root.
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
        min-width: 340px;
        max-width: 440px;
        background: #ffffff;
        border: 1px solid #e0e8ee;
        border-radius: 12px;
        box-shadow: 0 12px 32px rgba(17, 82, 114, 0.14);
        padding: 8px;
        max-height: 480px;
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
        flex-shrink: 0;
    }
    .qc-search-item small {
        display: block;
        color: #8a97a3;
        font-weight: 500;
        font-size: 0.75rem;
    }
    .qc-search-item:hover { background: #f1f9fe; color: #115272; }
    .qc-search-item.qc-search-item-active { background: #e3f1fb; }
    .qc-search-empty { padding: 14px; text-align: center; color: #8a97a3; font-size: 0.85rem; }
    .qc-search-loading { padding: 14px; text-align: center; color: #8a97a3; font-size: 0.85rem; }
    .qc-search-view-all {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        margin-top: 6px;
        padding: 10px 12px;
        border-radius: 8px;
        background: var(--qc-primary-800, #115272);
        color: #fff !important;
        font-weight: 700;
        font-size: 0.85rem;
        text-decoration: none;
        transition: background 0.2s;
    }
    .qc-search-view-all:hover { background: var(--qc-primary-700, #1d698b); color: #fff; }
    .qc-search-highlight { background: #fff3cd; padding: 0 2px; border-radius: 3px; }

    @media (max-width: 1200px) {
        .qc-search-input { width: 160px; }
    }
</style>

<!-- Navbar Search -->
<div class="qc-nav-search" id="qcNavSearch">
    <input type="text" class="qc-search-input" id="qcSearchInput" placeholder="Search site..." autocomplete="off" aria-label="Search site">
    <button class="qc-search-btn" type="button" id="qcSearchBtn" aria-label="Search"><i class="fas fa-search"></i></button>
    <div class="qc-search-results" id="qcSearchResults" role="listbox" aria-label="Search results"></div>
</div>

<script>
(function () {
    var BASE_PHP = '<?php echo addslashes($__ns_base); ?>';
    // Derive base from current location as fallback — handles any install path
    var path = window.location.pathname || '/';
    var derivedBase = '';
    var marker = '/lg-road-monitoring/';
    var idx = path.toLowerCase().indexOf(marker);
    if (idx !== -1) { derivedBase = path.substring(0, idx + marker.length); }
    else {
        // fallback: directory of current file (keeps relative URLs working at root)
        // if we are at /search.php or /index.php, '' means same-dir relative which is correct
        derivedBase = BASE_PHP;
    }
    var BASE = derivedBase || BASE_PHP;
    // Ensure BASE ends with '' or '/' correctly for concatenation with 'includes/...'
    function joinBase(base, rel) {
        if (!base) return rel;
        if (base.slice(-1) === '/' || base.slice(-1) === '\\') return base + rel;
        return base + '/' + rel;
    }
    function apiUrl(q) { return joinBase(BASE, 'includes/public_search_api.php?q=' + encodeURIComponent(q)); }
    function pageUrl(rel) {
        if (/^https?:\/\//i.test(rel) || rel.charAt(0) === '#') return rel;
        return joinBase(BASE, rel);
    }

    var input = document.getElementById('qcSearchInput');
    var btn = document.getElementById('qcSearchBtn');
    var resultsBox = document.getElementById('qcSearchResults');
    if (!input || !resultsBox) return;

    // Exhaustive index that mirrors what the landing page actually shows
    var pages = [
        { title: 'Home', url: 'index.php#home', icon: 'fa-home', kw: 'home landing welcome hero quezon city road transportation department' },
        { title: 'Road Updates (Landing)', url: 'index.php#updates', icon: 'fa-newspaper', kw: 'road updates latest news announcements landing home' },
        { title: 'Monitoring Statistics', url: 'index.php#updates', icon: 'fa-chart-bar', kw: 'statistics stats monitoring total reports ongoing resolved pending numbers' },
        { title: 'Announcements', url: 'index.php#announcements', icon: 'fa-bullhorn', kw: 'announcements notices lgu published official notices public' },
        { title: 'About', url: 'index.php#about', icon: 'fa-info-circle', kw: 'about mission vision values department' },
        { title: 'Contact', url: 'index.php#contact', icon: 'fa-envelope', kw: 'contact phone email hotline office location address' },
        { title: 'Make a Report', url: 'index.php#home', icon: 'fa-pen-alt', kw: 'report issue citizen make report pin map photo complaint file' },
        { title: 'Road Updates', url: 'road-updates.php', icon: 'fa-newspaper', kw: 'road updates listing all announcements' },
        { title: 'Road Status & Public Reports', url: 'public_reports.php', icon: 'fa-map-marked-alt', kw: 'road status reports browse map filter pending in-progress completed pothole flood' },
        { title: 'Infrastructure Projects', url: 'infrastructure_projects.php', icon: 'fa-hard-hat', kw: 'infrastructure projects ipms road projects budget construction approved' },
        { title: 'Transportation Updates', url: 'transportation-updates.php', icon: 'fa-bus', kw: 'transportation updates transit bus terminal commute' },
        { title: 'Transportation Status', url: 'transportation-status.php', icon: 'fa-traffic-light', kw: 'transportation status traffic condition status congestion' },
        { title: 'Public Transparency', url: 'public_transparency_view.php', icon: 'fa-balance-scale', kw: 'transparency public announcements published audit transparency portal' },
        { title: 'About Page', url: 'about.php', icon: 'fa-info-circle', kw: 'about page department mission' },
        { title: 'Contact Page', url: 'contact.php', icon: 'fa-envelope', kw: 'contact page email phone hotline' },
        { title: 'Traffic Management', url: 'service-traffic-management.php', icon: 'fa-traffic-light', kw: 'service traffic management enforcers monitoring officers congestion signal' },
        { title: 'Emergency Road Response', url: 'service-emergency-road-response.php', icon: 'fa-truck-medical', kw: 'service emergency rescue response accident rescue road closure flood' },
        { title: 'Infrastructure Maintenance', url: 'service-infrastructure-maintenance.php', icon: 'fa-tools', kw: 'service infrastructure maintenance repair pothole crack construction works' },
        { title: 'Road Condition Monitoring', url: 'service-road-condition-monitoring.php', icon: 'fa-chart-line', kw: 'service monitoring road condition quality sensors inspection qc' }
    ];

    var searchTimer = null;
    var lastQuery = '';
    var focusedIndex = -1;

    function escapeHtml(t) {
        if (!t) return '';
        var d = document.createElement('div');
        d.textContent = t;
        return d.innerHTML;
    }

    function pageMatches(p, q) {
        var hay = (p.title + ' ' + p.kw).toLowerCase();
        // support multi-word queries: all tokens must be present
        var tokens = q.toLowerCase().trim().split(/\s+/);
        for (var i = 0; i < tokens.length; i++) {
            if (hay.indexOf(tokens[i]) === -1) return false;
        }
        return true;
    }

    function showLoading() {
        resultsBox.innerHTML = '<div class="qc-search-loading"><i class="fas fa-spinner fa-spin"></i> Searching&hellip;</div>';
        resultsBox.classList.add('show');
    }

    function renderReport(report) {
        var el = document.createElement('a');
        el.className = 'qc-search-item';
        el.setAttribute('role', 'option');
        var status = report.status ? ' \u00b7 ' + report.status.replace(/-/g, ' ') : '';
        var loc = report.location || '';
        var typeLabel = report.type || 'report';
        var icon = 'fa-map-marker-alt';
        if (typeLabel === 'project' || report.source === 'infrastructure') icon = 'fa-hard-hat';
        else if (typeLabel === 'announcement') icon = 'fa-bullhorn';
        else if (typeLabel === 'cimm') icon = 'fa-clipboard-check';
        el.innerHTML =
            '<i class="fas ' + icon + '"></i>' +
            '<span>' + escapeHtml(report.title || 'Untitled') +
            '<small>' + escapeHtml(loc) + escapeHtml(status) + ' \u00b7 ' + escapeHtml(typeLabel) + '</small></span>';
        if (typeLabel === 'announcement') {
            el.href = pageUrl('public_transparency_view.php');
        } else if (typeLabel === 'project' || report.source === 'infrastructure') {
            el.href = pageUrl('infrastructure_projects.php');
        } else if (report.source === 'cimm') {
            el.href = pageUrl('public_reports.php?type=cimm');
        } else {
            // transportation / maintenance reports open detail on public_reports
            el.href = pageUrl('public_reports.php?report_id=' + encodeURIComponent(report.id));
        }
        return el;
    }

    function renderPage(p) {
        var el = document.createElement('a');
        el.className = 'qc-search-item';
        el.setAttribute('role', 'option');
        el.innerHTML = '<i class="fas ' + p.icon + '"></i><span>' + escapeHtml(p.title) + '<small>' + escapeHtml(p.url) + '</small></span>';
        el.href = pageUrl(p.url);
        return el;
    }

    function renderViewAll(q) {
        var a = document.createElement('a');
        a.className = 'qc-search-view-all';
        a.href = pageUrl('search.php?q=' + encodeURIComponent(q));
        a.innerHTML = '<i class="fas fa-search"></i> View all results for &ldquo;' + escapeHtml(q) + '&rdquo;';
        return a;
    }

    function doSearch() {
        var raw = input.value.trim();
        var q = raw.toLowerCase();
        if (q.length < 2) {
            resultsBox.classList.remove('show');
            resultsBox.innerHTML = '';
            focusedIndex = -1;
            return;
        }
        lastQuery = raw;
        var pagesHit = pages.filter(function (p) { return pageMatches(p, q); });
        // Cap pages to avoid flooding
        pagesHit = pagesHit.slice(0, 8);

        showLoading();

        fetch(apiUrl(raw))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var reports = Array.isArray(data) ? data : [];
                // Group reports by type for nicer headings
                var reportGroups = {};
                reports.forEach(function (rep) {
                    var key = (rep.type === 'announcement') ? 'Announcements' : (rep.type === 'project' || rep.source === 'infrastructure') ? 'Projects' : (rep.source === 'cimm' || rep.type === 'cimm') ? 'CIMM Reports' : 'Road Reports';
                    if (!reportGroups[key]) reportGroups[key] = [];
                    reportGroups[key].push(rep);
                });

                if (!pagesHit.length && !reports.length) {
                    resultsBox.innerHTML = '<div class="qc-search-empty"><i class="fas fa-search"></i> No results for &ldquo;' + escapeHtml(raw) + '&rdquo;<br><small>Try different keywords or view all results</small></div>';
                    resultsBox.appendChild(renderViewAll(raw));
                    resultsBox.classList.add('show');
                    return;
                }

                resultsBox.innerHTML = '';
                var firstItem = null;
                if (pagesHit.length) {
                    var t = document.createElement('div'); t.className = 'qc-search-group-title'; t.textContent = 'Pages'; resultsBox.appendChild(t);
                    pagesHit.forEach(function (p) {
                        var el = renderPage(p);
                        if (!firstItem) firstItem = el;
                        resultsBox.appendChild(el);
                    });
                }
                Object.keys(reportGroups).forEach(function (gName) {
                    var title = document.createElement('div'); title.className = 'qc-search-group-title'; title.textContent = gName; resultsBox.appendChild(title);
                    reportGroups[gName].forEach(function (rep) {
                        var el = renderReport(rep);
                        if (!firstItem) firstItem = el;
                        resultsBox.appendChild(el);
                    });
                });
                // Always offer full search page so user "sees the content they need"
                resultsBox.appendChild(renderViewAll(raw));
                if (firstItem) firstItem.classList.add('qc-search-item-active');
                focusedIndex = 0;
                resultsBox.classList.add('show');
            })
            .catch(function () {
                // Fallback: at least show pages
                if (pagesHit.length) {
                    resultsBox.innerHTML = '';
                    var title = document.createElement('div'); title.className = 'qc-search-group-title'; title.textContent = 'Pages'; resultsBox.appendChild(title);
                    var first = null;
                    pagesHit.forEach(function (p) {
                        var el = renderPage(p);
                        if (!first) { first = el; el.classList.add('qc-search-item-active'); }
                        resultsBox.appendChild(el);
                    });
                    resultsBox.appendChild(renderViewAll(raw));
                    focusedIndex = 0;
                    resultsBox.classList.add('show');
                } else {
                    resultsBox.innerHTML = '<div class="qc-search-empty"><i class="fas fa-search"></i> No results found</div>';
                    resultsBox.appendChild(renderViewAll(raw));
                    resultsBox.classList.add('show');
                }
            });
    }

    function getVisibleItems() {
        return Array.prototype.slice.call(resultsBox.querySelectorAll('.qc-search-item'));
    }
    function updateFocus(items) {
        items.forEach(function (el, i) {
            if (i === focusedIndex) el.classList.add('qc-search-item-active');
            else el.classList.remove('qc-search-item-active');
        });
        if (items[focusedIndex]) items[focusedIndex].scrollIntoView({ block: 'nearest' });
    }

    input.addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(doSearch, 200);
        focusedIndex = -1;
    });

    // Pre-fill from ?q= on search.php or any page, and auto-open
    (function prefill() {
        try {
            var params = new URLSearchParams(window.location.search);
            var q = params.get('q');
            if (q && !input.value) {
                input.value = q;
                // don't auto dropdown on the search page itself (it has its own results)
                if (!window.location.pathname.toLowerCase().endsWith('search.php')) {
                    doSearch();
                }
            }
        } catch (e) {}
    })();

    function goToFirstOrSearch() {
        var raw = input.value.trim();
        if (!raw) return;
        var first = resultsBox.querySelector('.qc-search-item');
        if (first && resultsBox.classList.contains('show')) {
            window.location.href = first.href;
        } else {
            window.location.href = pageUrl('search.php?q=' + encodeURIComponent(raw));
        }
    }

    input.addEventListener('keydown', function (e) {
        var items = getVisibleItems();
        if (e.key === 'ArrowDown') {
            if (!resultsBox.classList.contains('show')) { doSearch(); return; }
            e.preventDefault();
            focusedIndex = Math.min(focusedIndex + 1, items.length - 1);
            if (focusedIndex < 0) focusedIndex = 0;
            updateFocus(items);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            focusedIndex = Math.max(focusedIndex - 1, 0);
            updateFocus(items);
        } else if (e.key === 'Enter') {
            if (focusedIndex >= 0 && items[focusedIndex]) {
                e.preventDefault();
                window.location.href = items[focusedIndex].href;
            } else {
                // If dropdown visible, first item wins; else go to full search page
                if (resultsBox.classList.contains('show')) {
                    var first = resultsBox.querySelector('.qc-search-item');
                    if (first) { e.preventDefault(); window.location.href = first.href; return; }
                }
                e.preventDefault();
                goToFirstOrSearch();
            }
        } else if (e.key === 'Escape') {
            resultsBox.classList.remove('show');
            input.blur();
        }
    });

    if (btn) {
        btn.addEventListener('click', function () {
            var q = input.value.trim();
            if (!q) { input.focus(); return; }
            if (resultsBox.classList.contains('show')) {
                var items = getVisibleItems();
                if (focusedIndex >= 0 && items[focusedIndex]) { window.location.href = items[focusedIndex].href; return; }
                var first = resultsBox.querySelector('.qc-search-item');
                if (first) { window.location.href = first.href; return; }
            }
            window.location.href = pageUrl('search.php?q=' + encodeURIComponent(q));
        });
    }

    document.addEventListener('click', function (e) {
        var box = document.getElementById('qcNavSearch');
        if (box && !box.contains(e.target)) {
            resultsBox.classList.remove('show');
        } else if (box && box.contains(e.target) && input.value.trim().length >= 2 && !resultsBox.classList.contains('show')) {
            doSearch();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            resultsBox.classList.remove('show');
            input.blur();
        }
    });

    // On index landing page, if query matches an in-page anchor, smooth highlight after navigation
    // handled by search.php redirect logic + hash
})();
</script>
