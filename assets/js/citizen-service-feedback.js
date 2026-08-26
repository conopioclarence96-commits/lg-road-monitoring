/**
 * Floating Rate FAB → service feedback modal.
 * API: lgu_staff/pages/api/citizen_service_feedback_api.php
 */
(function () {
    'use strict';

    var API = window.CSF_FEEDBACK_API || 'lgu_staff/pages/api/citizen_service_feedback_api.php';
    var voterToken = '';
    var selected = 0;
    var locked = false;

    function $(sel, root) {
        return (root || document).querySelector(sel);
    }

    function setStatus(msg, kind) {
        var el = $('#csfStatus');
        if (!el) return;
        el.textContent = msg || '';
        el.className = 'csf-status' + (msg ? ' show is-' + (kind || 'info') : '');
    }

    function lockForm(myRating, hideInputs) {
        locked = true;
        var buttons = document.querySelectorAll('#csfRateStars button');
        buttons.forEach(function (btn) {
            btn.disabled = true;
            var v = parseInt(btn.getAttribute('data-value'), 10);
            btn.classList.toggle('is-active', !!myRating && v <= myRating);
        });
        var comment = $('#csfComment');
        var submit = $('#csfSubmit');
        var label = $('#csfRateLabel');
        if (hideInputs) {
            if (comment) comment.style.display = 'none';
            if (submit) submit.style.display = 'none';
        } else {
            if (comment) comment.disabled = true;
            if (submit) submit.disabled = true;
        }
        if (label && myRating) {
            label.textContent = 'Your rating';
        }
    }

    function applyStatus(data) {
        if (!data) return;
        voterToken = data.voter_token || voterToken;
        if (data.rated) {
            selected = data.my_rating || 0;
            lockForm(data.my_rating, true);
            setStatus('You have already submitted feedback. Thank you!', 'info');
            return;
        }
        if (data.ip_blocked) {
            lockForm(null, true);
            setStatus('Rating limit reached on this network. Thanks for your feedback!', 'error');
        }
    }

    function loadStatus() {
        return fetch(API + '?action=status', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.success) applyStatus(data);
            })
            .catch(function () {});
    }

    function wireStars() {
        var wrap = $('#csfRateStars');
        if (!wrap) return;
        var buttons = wrap.querySelectorAll('button');
        buttons.forEach(function (btn) {
            btn.addEventListener('mouseenter', function () {
                if (locked) return;
                var v = parseInt(btn.getAttribute('data-value'), 10);
                buttons.forEach(function (b) {
                    b.classList.toggle('is-hover', parseInt(b.getAttribute('data-value'), 10) <= v);
                });
            });
            btn.addEventListener('mouseleave', function () {
                buttons.forEach(function (b) { b.classList.remove('is-hover'); });
            });
            btn.addEventListener('click', function () {
                if (locked) return;
                selected = parseInt(btn.getAttribute('data-value'), 10);
                buttons.forEach(function (b) {
                    b.classList.toggle('is-active', parseInt(b.getAttribute('data-value'), 10) <= selected);
                });
                setStatus('', '');
            });
        });
    }

    function wireForm() {
        var form = $('#csfFeedbackForm');
        if (!form) return;
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (locked) return;
            if (!selected) {
                setStatus('Please select a rating from 1 to 5 stars.', 'error');
                return;
            }
            var commentEl = $('#csfComment');
            var submitBtn = $('#csfSubmit');
            var prev = submitBtn ? submitBtn.innerHTML : '';
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
            }

            var fd = new FormData();
            fd.append('action', 'submit');
            fd.append('rating', String(selected));
            fd.append('comment', commentEl ? commentEl.value.trim() : '');
            fd.append('voter_token', voterToken);
            fd.append('page_url', window.location.href);

            fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json().then(function (data) { return { data: data }; }); })
                .then(function (res) {
                    var data = res.data || {};
                    if (data.success) {
                        lockForm(data.my_rating || selected, true);
                        setStatus(data.message || 'Thank you for your feedback!', 'ok');
                        return;
                    }
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = prev;
                    }
                    var kind = 'error';
                    if (data.code === 'already_rated') {
                        lockForm(selected, true);
                        kind = 'info';
                    } else if (data.code === 'spam_limited') {
                        lockForm(null, true);
                    }
                    setStatus(data.message || 'Could not submit feedback.', kind);
                })
                .catch(function () {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = prev;
                    }
                    setStatus('Network error. Please try again.', 'error');
                });
        });
    }

    function openModal() {
        var modalEl = $('#csfFeedbackModal');
        if (!modalEl || !window.bootstrap) return;
        loadStatus().then(function () {
            var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            modal.show();
        });
    }

    function init() {
        var fab = $('#csfFabBtn');
        if (!fab || !$('#csfFeedbackModal')) return;
        wireStars();
        wireForm();
        fab.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            openModal();
        });
        // Prefetch token so first open is snappy
        loadStatus();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
