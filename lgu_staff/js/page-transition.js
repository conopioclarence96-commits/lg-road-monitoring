/**
 * Page Transition Script
 * Handles smooth overlay transitions when navigating between pages.
 * Requires <div class="page-transition-overlay" id="pageTransitionOverlay"> in each page.
 */
(function() {
    // On page load: ensure overlay is hidden (back-button / cache restoration)
    window.addEventListener('pageshow', function() {
        var overlay = document.getElementById('pageTransitionOverlay');
        if (overlay) overlay.classList.remove('active');
    });

    document.addEventListener('DOMContentLoaded', function() {
        var overlay = document.getElementById('pageTransitionOverlay');
        if (!overlay) return;

        // Ensure hidden on fresh load
        overlay.classList.remove('active');

        // Intercept all internal anchor clicks
        document.addEventListener('click', function(e) {
            var link = e.target.closest('a[href]');
            if (!link) return;

            var href = link.getAttribute('href');
            if (!href) return;

            // Skip external links, anchors, javascript:, mailto:, tel:, # anchors, print links
            if (href.startsWith('http') || href.startsWith('mailto:') || href.startsWith('tel:') ||
                href.startsWith('javascript:') || href === '#') return;

            // Skip anchor-only links (e.g. #section)
            if (href.charAt(0) === '#') return;

            // Skip download links
            if (link.hasAttribute('download')) return;

            // Skip if target=_blank
            if (link.target === '_blank') return;

            // Skip if modifier keys held (ctrl, shift, meta)
            if (e.ctrlKey || e.shiftKey || e.metaKey || e.altKey) return;

            // Don't transition if already on the same page
            var currentFile = window.location.pathname.split('/').pop().split('?')[0];
            var targetFile = href.split('/').pop().split('?')[0];
            if (currentFile === targetFile) return;

            e.preventDefault();
            overlay.classList.add('active');
            setTimeout(function() {
                window.location.href = href;
            }, 400);
        });
    });
})();
