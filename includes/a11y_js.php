<script>
(function() {
    // Reset transition overlay on back-forward cache navigation
    window.addEventListener('pageshow', function(e) {
        var overlay = document.getElementById('pageTransitionOverlay');
        if (overlay) {
            overlay.classList.remove('active');
        }
        // Reset scroll animations for visible elements
        document.querySelectorAll('.scroll-animate').forEach(function(el) {
            var rect = el.getBoundingClientRect();
            if (rect.top < window.innerHeight && rect.bottom > 0) {
                el.classList.add('animate-in');
            }
        });
    });

    const a11yBtn = document.getElementById('a11yBtn');
    const a11yPanel = document.getElementById('a11yPanel');
    if (a11yBtn && a11yPanel) {
        a11yBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            a11yPanel.classList.toggle('active');
            a11yBtn.setAttribute('aria-expanded', a11yPanel.classList.contains('active') ? 'true' : 'false');
        });
        document.addEventListener('click', function(e) {
            if (!document.getElementById('a11yFab').contains(e.target)) {
                a11yPanel.classList.remove('active');
                a11yBtn.setAttribute('aria-expanded', 'false');
            }
        });
    }

    var currentFontSize = parseInt(localStorage.getItem('a11y_fontSize') || '100');
    var highContrast = localStorage.getItem('a11y_highContrast') === 'true';
    var largeText = localStorage.getItem('a11y_largeText') === 'true';
    var readableFont = localStorage.getItem('a11y_readableFont') === 'true';
    var darkMode = localStorage.getItem('a11y_darkMode') === 'true';

    function updateThemeIcon() {
        var icon = document.getElementById('themeIcon');
        if (icon) {
            icon.className = darkMode ? 'fas fa-sun' : 'fas fa-moon';
        }
    }

    window.adjustFontSize = function(delta) {
        currentFontSize += delta * 5;
        currentFontSize = Math.max(80, Math.min(150, currentFontSize));
        document.documentElement.style.fontSize = currentFontSize + '%';
        localStorage.setItem('a11y_fontSize', currentFontSize);
    };

    window.toggleHighContrast = function() {
        var el = document.getElementById('contrastToggle');
        highContrast = el ? el.checked : !highContrast;
        document.body.classList.toggle('high-contrast', highContrast);
        localStorage.setItem('a11y_highContrast', highContrast);
    };

    window.toggleLargeText = function() {
        var el = document.getElementById('largeTextToggle');
        largeText = el ? el.checked : !largeText;
        document.body.classList.toggle('large-text', largeText);
        localStorage.setItem('a11y_largeText', largeText);
    };

    window.toggleReadableFont = function() {
        var el = document.getElementById('readableFontToggle');
        readableFont = el ? el.checked : !readableFont;
        document.body.classList.toggle('readable-font', readableFont);
        localStorage.setItem('a11y_readableFont', readableFont);
    };

    window.toggleDarkMode = function() {
        document.body.classList.add('theme-transition');
        var el = document.getElementById('darkModeToggle');
        darkMode = el ? el.checked : !darkMode;
        document.documentElement.classList.toggle('dark-mode', darkMode);
        document.body.classList.toggle('dark-mode', darkMode);
        updateThemeIcon();
        localStorage.setItem('a11y_darkMode', darkMode);
        setTimeout(function() { document.body.classList.remove('theme-transition'); }, 400);
    };

    window.resetAccessibility = function() {
        currentFontSize = 100; highContrast = false; largeText = false; readableFont = false; darkMode = false;
        document.documentElement.style.fontSize = '100%';
        document.documentElement.classList.remove('dark-mode');
        document.body.classList.remove('high-contrast', 'large-text', 'readable-font', 'dark-mode');
        document.getElementById('contrastToggle').checked = false;
        document.getElementById('largeTextToggle').checked = false;
        document.getElementById('readableFontToggle').checked = false;
        document.getElementById('darkModeToggle').checked = false;
        updateThemeIcon();
        localStorage.removeItem('a11y_fontSize');
        localStorage.removeItem('a11y_highContrast');
        localStorage.removeItem('a11y_largeText');
        localStorage.removeItem('a11y_readableFont');
        localStorage.removeItem('a11y_darkMode');
    };

    if (currentFontSize !== 100) document.documentElement.style.fontSize = currentFontSize + '%';
    if (highContrast) { document.body.classList.add('high-contrast'); document.getElementById('contrastToggle').checked = true; }
    if (largeText) { document.body.classList.add('large-text'); document.getElementById('largeTextToggle').checked = true; }
    if (readableFont) { document.body.classList.add('readable-font'); document.getElementById('readableFontToggle').checked = true; }
    if (darkMode) { document.documentElement.classList.add('dark-mode'); document.body.classList.add('dark-mode'); document.getElementById('darkModeToggle').checked = true; updateThemeIcon(); }
})();
</script>
<script>window.CSF_FEEDBACK_API = 'lgu_staff/pages/api/citizen_service_feedback_api.php';</script>
<script src="assets/js/citizen-service-feedback.js?v=<?php echo (int)(@filemtime(__DIR__ . '/../assets/js/citizen-service-feedback.js') ?: time()); ?>"></script>
