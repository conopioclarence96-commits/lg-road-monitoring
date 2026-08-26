<div class="a11y-fab" id="a11yFab">
    <div class="a11y-panel" id="a11yPanel">
        <h5><i class="fas fa-universal-access"></i> Accessibility Options</h5>
        <div class="a11y-option">
            <label>Font Size</label>
            <div class="a11y-btn-group">
                <button onclick="adjustFontSize(-1)" title="Decrease font size">A-</button>
                <button onclick="adjustFontSize(1)" title="Increase font size">A+</button>
            </div>
        </div>
        <div class="a11y-option">
            <label class="a11y-option-row" for="contrastToggle">
                <span class="a11y-option-text">High Contrast</span>
                <span class="a11y-switch">
                    <input type="checkbox" id="contrastToggle" onchange="toggleHighContrast()" aria-label="High Contrast">
                    <span class="a11y-switch-slider"></span>
                </span>
            </label>
        </div>
        <div class="a11y-option">
            <label class="a11y-option-row" for="largeTextToggle">
                <span class="a11y-option-text">Large Text</span>
                <span class="a11y-switch">
                    <input type="checkbox" id="largeTextToggle" onchange="toggleLargeText()" aria-label="Large Text">
                    <span class="a11y-switch-slider"></span>
                </span>
            </label>
        </div>
        <div class="a11y-option">
            <label class="a11y-option-row" for="readableFontToggle">
                <span class="a11y-option-text">Readable Font</span>
                <span class="a11y-switch">
                    <input type="checkbox" id="readableFontToggle" onchange="toggleReadableFont()" aria-label="Readable Font">
                    <span class="a11y-switch-slider"></span>
                </span>
            </label>
        </div>
        <div class="a11y-option">
            <label class="a11y-option-row" for="darkModeToggle">
                <span class="a11y-option-text"><i class="fas fa-moon" id="themeIcon"></i> Dark Mode</span>
                <span class="a11y-switch">
                    <input type="checkbox" id="darkModeToggle" onchange="toggleDarkMode()" aria-label="Dark Mode">
                    <span class="a11y-switch-slider"></span>
                </span>
            </label>
        </div>
        <button class="a11y-reset" onclick="resetAccessibility()"><i class="fas fa-undo"></i> Reset All</button>
    </div>
    <button class="a11y-fab-btn" id="a11yBtn" aria-label="Accessibility Options" title="Accessibility Options" aria-expanded="false" aria-controls="a11yPanel">
        <i class="fas fa-universal-access"></i>
    </button>
</div>
