/* Session Timeout Warning */

(function() {
    var SESSION_TIMEOUT = 300000;       // 5 min default, overridden by PHP
    var WARNING_BEFORE = 60000;         // warn 1 min before
    var warningTimer = null;
    var countdownInterval = null;
    var secondsLeft = 60;

    var modal = document.getElementById('sessionTimeoutModal');
    var overlay = document.getElementById('sessionTimeoutOverlay');

    function clearTimers() {
        if (warningTimer) { clearTimeout(warningTimer); warningTimer = null; }
        if (countdownInterval) { clearInterval(countdownInterval); countdownInterval = null; }
    }

    function extendSession() {
        fetch('../../pages/api/extend_session.php', { method: 'POST' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    hideModal();
                    resetTimers();
                }
            })
            .catch(function() {});
    }

    function logoutSession() {
        window.location.href = '../../logout.php';
    }

    function showModal() {
        if (modal && overlay) {
            modal.style.display = 'block';
            overlay.style.display = 'block';
            document.body.style.overflow = 'hidden';
        }
    }

    function hideModal() {
        if (modal && overlay) {
            modal.style.display = 'none';
            overlay.style.display = 'none';
            document.body.style.overflow = '';
        }
    }

    function updateCountdown() {
        var el = document.getElementById('sessionCountdown');
        if (el) el.textContent = secondsLeft;
        if (secondsLeft <= 0) {
            clearTimers();
            logoutSession();
        }
    }

    function startCountdown() {
        secondsLeft = Math.floor(WARNING_BEFORE / 1000);
        updateCountdown();
        countdownInterval = setInterval(function() {
            secondsLeft--;
            updateCountdown();
            if (secondsLeft <= 0) {
                clearInterval(countdownInterval);
                countdownInterval = null;
            }
        }, 1000);
    }

    function resetTimers() {
        clearTimers();
        warningTimer = setTimeout(function() {
            showModal();
            startCountdown();
        }, SESSION_TIMEOUT - WARNING_BEFORE);
    }

    // Debounce timer to avoid rapid resets
    var activityTimer = null;

    function updateActivity() {
        if (activityTimer) clearTimeout(activityTimer);
        activityTimer = setTimeout(function() {
            resetTimers();
            activityTimer = null;
        }, 1000);
    }

    // Set up from data attributes
    var scriptTag = document.getElementById('sessionTimeoutData');
    if (scriptTag) {
        var timeout = parseInt(scriptTag.getAttribute('data-timeout'), 10);
        if (!isNaN(timeout) && timeout > 0) {
            SESSION_TIMEOUT = timeout * 1000;
        }
    }

    // Listen for user activity to reset timer (no AJAX - just JS)
    document.addEventListener('click', updateActivity);
    document.addEventListener('keypress', updateActivity);
    document.addEventListener('scroll', updateActivity);

    // Button handlers
    document.addEventListener('click', function(e) {
        if (e.target.id === 'extendSessionBtn') {
            e.preventDefault();
            extendSession();
        }
        if (e.target.id === 'logoutSessionBtn') {
            e.preventDefault();
            logoutSession();
        }
    });

    // Start the timer
    resetTimers();

    // Clear on page unload
    window.addEventListener('beforeunload', function() {
        clearTimers();
    });
})();
