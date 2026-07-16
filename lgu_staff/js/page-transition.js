/**
 * Page Transition Script
 * Fades the page content out on navigation, fades in on load.
 */
(function() {
    // On fresh page load: fade in
    window.addEventListener('pageshow', function() {
        document.body.classList.remove('fade-out');
        document.body.classList.add('fade-in');
        setTimeout(function() {
            document.body.classList.remove('fade-in');
        }, 400);
    });

    document.addEventListener('DOMContentLoaded', function() {
        // Fade in on initial load
        document.body.classList.remove('fade-out');
        document.body.classList.add('fade-in');
        setTimeout(function() {
            document.body.classList.remove('fade-in');
        }, 400);

        // Intercept internal link clicks
        document.addEventListener('click', function(e) {
            var link = e.target.closest('a[href]');
            if (!link) return;

            var href = link.getAttribute('href');
            if (!href) return;

            // Skip external, anchors, javascript, mailto, tel, downloads, blank targets
            if (href.startsWith('http') || href.startsWith('mailto:') || href.startsWith('tel:') ||
                href.startsWith('javascript:') || href.charAt(0) === '#') return;
            if (link.hasAttribute('download') || link.target === '_blank') return;
            if (e.ctrlKey || e.shiftKey || e.metaKey || e.altKey) return;

            // Don't transition if already on the same page
            var currentFile = window.location.pathname.split('/').pop().split('?')[0];
            var targetFile = href.split('/').pop().split('?')[0];
            if (currentFile === targetFile) return;

            e.preventDefault();

            // Fade out then navigate
            document.body.classList.add('fade-out');
            setTimeout(function() {
                window.location.href = href;
            }, 300);
        });
    });
})();
