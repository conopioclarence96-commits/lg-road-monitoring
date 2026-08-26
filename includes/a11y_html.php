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
    <button type="button" class="a11y-fab-btn" id="a11yBtn" aria-label="Accessibility Options" title="Accessibility Options" aria-expanded="false" aria-controls="a11yPanel">
        <i class="fas fa-universal-access"></i>
    </button>
    <button type="button" class="csf-fab-btn" id="csfFabBtn" aria-label="Rate our service" title="Rate our service">
        <i class="fas fa-star"></i>
    </button>
</div>

<div class="modal fade" id="csfFeedbackModal" tabindex="-1" aria-labelledby="csfFeedbackModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="csfFeedbackModalLabel"><i class="fas fa-star" style="color:#f59e0b;margin-right:8px;"></i> How are we doing?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3" style="font-size:0.9rem;color:#64748b;">Share your overall experience with our roads and transportation services.</p>
                <form id="csfFeedbackForm">
                    <label class="csf-rate-label" id="csfRateLabel">Your rating</label>
                    <div class="csf-rate-stars" id="csfRateStars" role="group" aria-label="Rate 1 to 5 stars">
                        <button type="button" data-value="1" aria-label="1 star"><i class="fas fa-star"></i></button>
                        <button type="button" data-value="2" aria-label="2 stars"><i class="fas fa-star"></i></button>
                        <button type="button" data-value="3" aria-label="3 stars"><i class="fas fa-star"></i></button>
                        <button type="button" data-value="4" aria-label="4 stars"><i class="fas fa-star"></i></button>
                        <button type="button" data-value="5" aria-label="5 stars"><i class="fas fa-star"></i></button>
                    </div>
                    <label class="visually-hidden" for="csfComment">Optional comment</label>
                    <textarea id="csfComment" class="csf-comment" maxlength="500" rows="3" placeholder="Optional comment..."></textarea>
                    <div class="mt-3">
                        <button type="submit" class="csf-submit" id="csfSubmit"><i class="fas fa-paper-plane"></i> Submit feedback</button>
                    </div>
                </form>
                <div class="csf-status" id="csfStatus" role="status"></div>
            </div>
        </div>
    </div>
</div>
