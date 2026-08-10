/**
 * Page Transition Script
 * Fades the page content out on navigation, fades in on load.
 */
(function() {
    var loader = null;

    function ensureLoader() {
        if (loader) return;
        loader = document.createElement('div');
        loader.className = 'page-loader';
        loader.innerHTML = '<div class="loader-spinner"></div><div class="loader-text">Loading</div>';
        // Append to <html> so body.fade-out (opacity 0) doesn't hide the overlay
        document.documentElement.appendChild(loader);
    }

    function showLoader() {
        ensureLoader();
        // Force reflow so the opacity transition plays
        void loader.offsetWidth;
        loader.classList.add('active');
    }

    function hideLoader() {
        if (loader) loader.classList.remove('active');
    }

    // On fresh page load: hide loader, fade content in
    window.addEventListener('pageshow', function() {
        hideLoader();
        document.body.classList.remove('fade-out');
        document.body.classList.add('fade-in');
        setTimeout(function() {
            document.body.classList.remove('fade-in');
        }, 400);
    });

    document.addEventListener('DOMContentLoaded', function() {
        ensureLoader();
        hideLoader();

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

            // Fade out, show loader, then navigate
            document.body.classList.add('fade-out');
            showLoader();
            setTimeout(function() {
                window.location.href = href;
            }, 350);
        });
    });
})();
