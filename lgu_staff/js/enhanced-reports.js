function showNotification(message, type) {
    const n = document.createElement('div');
    n.style.cssText = 'position:fixed;top:20px;right:20px;z-index:10001;min-width:320px;padding:16px 20px;border-radius:10px;box-shadow:0 8px 32px rgba(0,0,0,0.15);animation:slideIn 0.3s ease;font-family:Poppins,sans-serif;display:flex;align-items:center;gap:12px;';
    const colors = {
        success: { bg: 'rgba(5,150,105,0.95)', icon: 'fa-check-circle' },
        error: { bg: 'rgba(220,38,38,0.95)', icon: 'fa-times-circle' },
        info: { bg: 'rgba(37,99,235,0.95)', icon: 'fa-info-circle' },
        warning: { bg: 'rgba(217,119,6,0.95)', icon: 'fa-exclamation-circle' }
    };
    const c = colors[type] || colors.info;
    n.style.background = c.bg;
    n.style.color = 'white';
    n.innerHTML = '<i class="fas ' + c.icon + '" style="font-size:18px;"></i><span style="flex:1;font-size:13px;">' + message + '</span><button onclick="this.parentElement.remove()" style="background:none;border:none;color:white;font-size:18px;cursor:pointer;opacity:0.8;">&times;</button>';
    document.body.appendChild(n);
    setTimeout(() => { if (n.parentElement) n.remove(); }, 5000);
}

function buildChart(canvasId, type, labels, datasets, options) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return null;
    const isDark = document.body.classList.contains('dark-mode');
    const defaults = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                labels: { color: isDark ? '#9ca3af' : '#64748b', font: { family: 'Poppins', size: 11 } }
            }
        },
        scales: type === 'pie' || type === 'doughnut' ? {} : {
            x: { ticks: { color: isDark ? '#9ca3af' : '#64748b', font: { family: 'Poppins', size: 10 } }, grid: { color: isDark ? '#2d323b' : '#e2e8f0' } },
            y: { ticks: { color: isDark ? '#9ca3af' : '#64748b', font: { family: 'Poppins', size: 10 } }, grid: { color: isDark ? '#2d323b' : '#e2e8f0' }, beginAtZero: true }
        }
    };
    const merged = deepMerge(defaults, options || {});
    return new Chart(ctx, { type, data: { labels, datasets }, options: merged });
}

function deepMerge(a, b) {
    const r = Object.assign({}, a);
    for (const k in b) {
        if (b[k] && typeof b[k] === 'object' && !Array.isArray(b[k]) && r[k]) {
            r[k] = deepMerge(r[k], b[k]);
        } else {
            r[k] = b[k];
        }
    }
    return r;
}

function formatNumber(n) {
    return Number(n).toLocaleString('en-US');
}

function formatPeso(n) {
    return '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function getDaysBetween(d1, d2) {
    const a = new Date(d1), b = new Date(d2);
    return Math.max(0, Math.floor((b - a) / (1000 * 60 * 60 * 24)));
}

function getSLAColor(days) {
    if (days <= 7) return '#059669';
    if (days <= 14) return '#d97706';
    return '#dc2626';
}
