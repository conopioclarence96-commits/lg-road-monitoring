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
            <label>High Contrast</label>
            <button class="a11y-toggle-btn" id="contrastToggle" onclick="toggleHighContrast()">On / Off</button>
        </div>
        <div class="a11y-option">
            <label>Large Text</label>
            <button class="a11y-toggle-btn" id="largeTextToggle" onclick="toggleLargeText()">On / Off</button>
        </div>
        <div class="a11y-option">
            <label>Readable Font</label>
            <button class="a11y-toggle-btn" id="readableFontToggle" onclick="toggleReadableFont()">On / Off</button>
        </div>
        <button class="a11y-reset" onclick="resetAccessibility()"><i class="fas fa-undo"></i> Reset All</button>
    </div>
    <button class="a11y-fab-btn" id="a11yBtn" aria-label="Accessibility Options" title="Accessibility Options">
        <i class="fas fa-universal-access"></i>
    </button>
</div>
