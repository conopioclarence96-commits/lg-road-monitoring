<script>
(function() {
    const a11yBtn = document.getElementById('a11yBtn');
    const a11yPanel = document.getElementById('a11yPanel');
    if (a11yBtn && a11yPanel) {
        a11yBtn.addEventListener('click', function(e) { e.stopPropagation(); a11yPanel.classList.toggle('active'); });
        document.addEventListener('click', function(e) { if (!document.getElementById('a11yFab').contains(e.target)) a11yPanel.classList.remove('active'); });
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
        highContrast = !highContrast;
        document.body.classList.toggle('high-contrast', highContrast);
        document.getElementById('contrastToggle').classList.toggle('active', highContrast);
        localStorage.setItem('a11y_highContrast', highContrast);
    };

    window.toggleLargeText = function() {
        largeText = !largeText;
        document.body.classList.toggle('large-text', largeText);
        document.getElementById('largeTextToggle').classList.toggle('active', largeText);
        localStorage.setItem('a11y_largeText', largeText);
    };

    window.toggleReadableFont = function() {
        readableFont = !readableFont;
        document.body.classList.toggle('readable-font', readableFont);
        document.getElementById('readableFontToggle').classList.toggle('active', readableFont);
        localStorage.setItem('a11y_readableFont', readableFont);
    };

    window.toggleDarkMode = function() {
        darkMode = !darkMode;
        document.body.classList.toggle('dark-mode', darkMode);
        document.getElementById('darkModeToggle').classList.toggle('active', darkMode);
        updateThemeIcon();
        localStorage.setItem('a11y_darkMode', darkMode);
    };

    window.resetAccessibility = function() {
        currentFontSize = 100; highContrast = false; largeText = false; readableFont = false; darkMode = false;
        document.documentElement.style.fontSize = '100%';
        document.body.classList.remove('high-contrast', 'large-text', 'readable-font', 'dark-mode');
        document.getElementById('contrastToggle').classList.remove('active');
        document.getElementById('largeTextToggle').classList.remove('active');
        document.getElementById('readableFontToggle').classList.remove('active');
        document.getElementById('darkModeToggle').classList.remove('active');
        updateThemeIcon();
        localStorage.removeItem('a11y_fontSize');
        localStorage.removeItem('a11y_highContrast');
        localStorage.removeItem('a11y_largeText');
        localStorage.removeItem('a11y_readableFont');
        localStorage.removeItem('a11y_darkMode');
    };

    if (currentFontSize !== 100) document.documentElement.style.fontSize = currentFontSize + '%';
    if (highContrast) { document.body.classList.add('high-contrast'); document.getElementById('contrastToggle').classList.add('active'); }
    if (largeText) { document.body.classList.add('large-text'); document.getElementById('largeTextToggle').classList.add('active'); }
    if (readableFont) { document.body.classList.add('readable-font'); document.getElementById('readableFontToggle').classList.add('active'); }
    if (darkMode) { document.body.classList.add('dark-mode'); document.getElementById('darkModeToggle').classList.add('active'); updateThemeIcon(); }
})();
</script>
