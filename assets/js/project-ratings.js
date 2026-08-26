/**
 * Project rating panels on public_transparency.php cards.
 * Depends on Font Awesome. API: lgu_staff/pages/api/project_ratings_api.php
 */
(function () {
    'use strict';

    var API = window.PR_RATINGS_API || 'lgu_staff/pages/api/project_ratings_api.php';
    var voterToken = '';

    function renderAvgStars(avg) {
        var html = '';
        for (var i = 1; i <= 5; i++) {
            if (avg >= i) {
                html += '<i class="fas fa-star pr-star filled"></i>';
            } else if (avg >= i - 0.5) {
                html += '<i class="fas fa-star pr-star half"></i>';
            } else {
                html += '<i class="fas fa-star pr-star"></i>';
            }
        }
        return html;
    }

    function setStatus(panel, msg, kind) {
        var el = panel.querySelector('.pr-status');
        if (!el) return;
        el.textContent = msg || '';
        el.className = 'pr-status' + (msg ? ' show is-' + (kind || 'info') : '');
    }

    function updateAvgDisplay(panel, average, count) {
        var avgEl = panel.querySelector('.pr-avg-stars');
        var scoreEl = panel.querySelector('.pr-avg-score');
        var countEl = panel.querySelector('.pr-avg-count');
        var avg = Number(average) || 0;
        var cnt = Number(count) || 0;
        if (avgEl) avgEl.innerHTML = renderAvgStars(avg);
        if (scoreEl) scoreEl.textContent = cnt ? avg.toFixed(1) : '—';
        if (countEl) {
            countEl.textContent = cnt === 1 ? '(1 rating)' : '(' + cnt + ' ratings)';
        }
    }

    function lockPanel(panel, selectedRating, hideInputs) {
        panel.classList.add('pr-locked');
        var buttons = panel.querySelectorAll('.pr-rate-stars button');
        buttons.forEach(function (btn) {
            btn.disabled = true;
            var v = parseInt(btn.getAttribute('data-value'), 10);
            btn.classList.toggle('is-active', !!selectedRating && v <= selectedRating);
        });
        var comment = panel.querySelector('.pr-comment');
        var submit = panel.querySelector('.pr-submit');
        if (hideInputs) {
            if (comment) comment.style.display = 'none';
            if (submit) submit.style.display = 'none';
        } else {
            if (comment) comment.disabled = true;
            if (submit) submit.disabled = true;
        }
    }

    function applySummary(panel, data) {
        updateAvgDisplay(panel, data.average, data.count);
        if (data.rated) {
            lockPanel(panel, data.my_rating, true);
            setStatus(panel, 'You have already rated this project.', 'info');
            return;
        }
        if (data.ip_blocked) {
            lockPanel(panel, null, true);
            setStatus(panel, 'Rating limit reached for this project on this network. Thanks for your feedback!', 'error');
        }
    }

    function wirePanel(panel) {
        var projectId = parseInt(panel.getAttribute('data-project-id'), 10);
        var rateWrap = panel.querySelector('.pr-rate-stars');
        var buttons = rateWrap ? rateWrap.querySelectorAll('button') : [];
        var selected = 0;

        buttons.forEach(function (btn) {
            btn.addEventListener('mouseenter', function () {
                if (panel.classList.contains('pr-locked')) return;
                var v = parseInt(btn.getAttribute('data-value'), 10);
                buttons.forEach(function (b) {
                    b.classList.toggle('is-hover', parseInt(b.getAttribute('data-value'), 10) <= v);
                });
            });
            btn.addEventListener('mouseleave', function () {
                buttons.forEach(function (b) { b.classList.remove('is-hover'); });
            });
            btn.addEventListener('click', function () {
                if (panel.classList.contains('pr-locked')) return;
                selected = parseInt(btn.getAttribute('data-value'), 10);
                panel.setAttribute('data-selected', String(selected));
                buttons.forEach(function (b) {
                    b.classList.toggle('is-active', parseInt(b.getAttribute('data-value'), 10) <= selected);
                });
                setStatus(panel, '', '');
            });
        });

        var form = panel.querySelector('.pr-form');
        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                if (panel.classList.contains('pr-locked')) return;

                var rating = parseInt(panel.getAttribute('data-selected') || '0', 10);
                if (!rating) {
                    setStatus(panel, 'Please select a rating from 1 to 5 stars.', 'error');
                    return;
                }

                var commentEl = panel.querySelector('.pr-comment');
                var submitBtn = panel.querySelector('.pr-submit');
                var prevHtml = submitBtn ? submitBtn.innerHTML : '';
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
                }

                var fd = new FormData();
                fd.append('action', 'submit_rating');
                fd.append('project_id', String(projectId));
                fd.append('rating', String(rating));
                fd.append('comment', commentEl ? commentEl.value.trim() : '');
                fd.append('voter_token', voterToken);

                fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
                    .then(function (res) {
                        var data = res.data || {};
                        if (data.success) {
                            updateAvgDisplay(panel, data.average, data.count);
                            lockPanel(panel, data.my_rating || rating, true);
                            setStatus(panel, data.message || 'Thank you for your rating!', 'ok');
                            return;
                        }
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = prevHtml;
                        }
                        var kind = 'error';
                        if (data.code === 'already_rated') {
                            lockPanel(panel, rating, true);
                            kind = 'info';
                        } else if (data.code === 'spam_limited') {
                            lockPanel(panel, null, true);
                        }
                        setStatus(panel, data.message || 'Could not submit rating.', kind);
                    })
                    .catch(function () {
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = prevHtml;
                        }
                        setStatus(panel, 'Network error. Please try again.', 'error');
                    });
            });
        }
    }

    function buildPanelHtml(projectId) {
        return (
            '<div class="pr-panel" data-project-id="' + projectId + '">' +
                '<div class="pr-avg-row">' +
                    '<span class="pr-stars pr-avg-stars">' + renderAvgStars(0) + '</span>' +
                    '<span class="pr-avg-score">—</span>' +
                    '<span class="pr-avg-count">(0 ratings)</span>' +
                '</div>' +
                '<form class="pr-form">' +
                    '<label class="pr-rate-label">Rate this project</label>' +
                    '<div class="pr-rate-stars" role="group" aria-label="Rate 1 to 5 stars">' +
                        [1, 2, 3, 4, 5].map(function (n) {
                            return '<button type="button" data-value="' + n + '" aria-label="' + n + ' star' + (n > 1 ? 's' : '') + '"><i class="fas fa-star"></i></button>';
                        }).join('') +
                    '</div>' +
                    '<textarea class="pr-comment" maxlength="500" rows="2" placeholder="Optional comment..."></textarea>' +
                    '<button type="submit" class="pr-submit"><i class="fas fa-paper-plane"></i> Submit rating</button>' +
                '</form>' +
                '<div class="pr-status" role="status"></div>' +
            '</div>'
        );
    }

    function init() {
        var cards = document.querySelectorAll('.project-item[data-id], .project-card[data-id]');
        if (!cards.length) return;

        var ids = [];
        cards.forEach(function (card) {
            var id = parseInt(card.getAttribute('data-id'), 10);
            if (!id) return;
            ids.push(id);
            if (card.querySelector('.pr-panel')) return;
            var details = card.querySelector('.project-details, .project-info');
            var mount = details || card;
            mount.insertAdjacentHTML('beforeend', buildPanelHtml(id));
            wirePanel(mount.querySelector('.pr-panel'));
        });

        if (!ids.length) return;

        fetch(API + '?action=summary&ids=' + ids.join(','), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) return;
                voterToken = data.voter_token || '';
                (data.projects || []).forEach(function (p) {
                    var panel = document.querySelector('.pr-panel[data-project-id="' + p.project_id + '"]');
                    if (panel) applySummary(panel, p);
                });
            })
            .catch(function () {});
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
