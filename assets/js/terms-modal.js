/**
 * Terms & Conditions gate before the citizen report modal (index.php).
 * Loaded after the #termsModal markup so it can bind immediately.
 */
(function () {
    'use strict';

    var termsModalEl = document.getElementById('termsModal');
    var termsModal = null;
    var termsCheckbox = document.getElementById('termsCheckbox');
    var termsContinueBtn = document.getElementById('termsContinueBtn');
    var termsCancelBtn = document.getElementById('termsCancelBtn');
    var makeReportBtn = document.getElementById('makeReportBtn');
    var citizenModalEl = document.getElementById('citizenReportModal');

    if (typeof bootstrap !== 'undefined' && termsModalEl) {
        termsModal = new bootstrap.Modal(termsModalEl, {
            backdrop: 'static',
            keyboard: false
        });
    }

    function openTermsModal() {
        termsCheckbox.checked = false;
        termsContinueBtn.disabled = true;
        if (termsModal) termsModal.show();
    }

    function closeTermsModal() {
        if (termsModal) termsModal.hide();
    }

    function getReportModal() {
        var reportModal = citizenModalEl ? bootstrap.Modal.getInstance(citizenModalEl) : null;
        if (!reportModal) reportModal = new bootstrap.Modal(citizenModalEl);
        return reportModal;
    }

    function acceptTermsAndOpenReport() {
        sessionStorage.setItem('tc_accepted', 'true');
        closeTermsModal();
        getReportModal().show();
    }

    // Check sessionStorage and either open T&C or go directly to report
    function handleMakeReport() {
        if (sessionStorage.getItem('tc_accepted') === 'true') {
            getReportModal().show();
        } else {
            openTermsModal();
        }
    }

    if (makeReportBtn) {
        makeReportBtn.addEventListener('click', handleMakeReport);
    }

    if (termsCheckbox) {
        termsCheckbox.addEventListener('change', function () {
            termsContinueBtn.disabled = !this.checked;
        });
    }

    if (termsContinueBtn) {
        termsContinueBtn.addEventListener('click', acceptTermsAndOpenReport);
    }

    if (termsCancelBtn) {
        termsCancelBtn.addEventListener('click', closeTermsModal);
    }

    // Focus trapping within the T&C modal
    if (termsModalEl) {
        termsModalEl.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                closeTermsModal();
                return;
            }
            if (e.key === 'Tab') {
                var focusable = termsModalEl.querySelectorAll(
                    'button, input, select, textarea, [tabindex]:not([tabindex="-1"])'
                );
                var first = focusable[0];
                var last = focusable[focusable.length - 1];
                if (e.shiftKey) {
                    if (document.activeElement === first) {
                        e.preventDefault();
                        last.focus();
                    }
                } else {
                    if (document.activeElement === last) {
                        e.preventDefault();
                        first.focus();
                    }
                }
            }
        });

        // Focus the first focusable element when the modal opens
        termsModalEl.addEventListener('shown.bs.modal', function () {
            setTimeout(function () {
                termsCheckbox.focus();
            }, 100);
        });
    }
})();
