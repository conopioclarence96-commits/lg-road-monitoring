// Publication card interactions
function viewPublication(pubId) {
    console.log('Opening publication:', pubId);
    // Log view to database
    fetch('', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=view_publication&pub_id=${pubId}`
    });
    alert(`Opening publication ID: ${pubId}`);
}

// Contact action buttons
function callHotline() {
    window.location.href = 'tel:+1234567890';
}

function sendEmail() {
    window.location.href = 'mailto:transparency@lgu.gov.ph';
}

function viewMap() {
    window.open('https://maps.google.com/?q=LGU+Office+Locations', '_blank');
}

function joinForum() {
    window.open('/public-forum', '_blank');
}

// Modal functions
function openModal(modalId) {
    document.getElementById(modalId).classList.add('show');
    document.body.style.overflow = 'hidden';
    
    // Initialize charts when modal opens
    if (modalId === 'budgetModal') {
        setTimeout(initBudgetChart, 100);
    } else if (modalId === 'projectsModal') {
        setTimeout(initProjectsChart, 100);
    } else if (modalId === 'analyticsModal') {
        setTimeout(initAnalyticsChart, 100);
    }
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
    document.body.style.overflow = 'auto';
}

// Close modal when clicking outside
document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal(this.id);
        }
    });
});

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal.show').forEach(modal => {
            closeModal(modal.id);
        });
    }
});

// Refresh data function
function refreshData() {
    const btn = event.target.closest('button');
    const originalContent = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Refreshing...';
    btn.disabled = true;

    fetch('', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=refresh_data'
    })
    .then(response => response.json())
    .then(data => {
        // Update statistics
        document.querySelector('.transparency-stats').innerHTML = `
            <div class="transparency-stat">
                <div class="stat-number">${data.documents.toLocaleString()}</div>
                <div class="stat-label">Public Documents</div>
            </div>
            <div class="transparency-stat">
                <div class="stat-number">${data.views.toLocaleString()}</div>
                <div class="stat-label">Total Views</div>
            </div>
            <div class="transparency-stat">
                <div class="stat-number">${data.downloads.toLocaleString()}</div>
                <div class="stat-label">Downloads</div>
            </div>
            <div class="transparency-stat">
                <div class="stat-number">${data.score}%</div>
                <div class="stat-label">Transparency Score</div>
            </div>
        `;
    })
    .catch(error => {
        console.error('Error refreshing data:', error);
        alert('Failed to refresh data. Please try again.');
    })
    .finally(() => {
        btn.innerHTML = originalContent;
        btn.disabled = false;
    });
}

// Chart initialization functions
function initBudgetChart() {
    const ctx = document.getElementById('budgetChart');
    if (!ctx) return;
    
    new Chart(ctx.getContext('2d'), {
        type: 'line',
        data: {
            labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
            datasets: [{
                label: 'Monthly Spending',
                data: [12000000, 14500000, 13800000, 15200000, 14800000, 16500000],
                borderColor: '#3762c8',
                backgroundColor: 'rgba(55, 98, 200, 0.1)',
                tension: 0.4,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '₱' + (value / 1000000).toFixed(1) + 'M';
                        }
                    }
                }
            }
        }
    });
}

function initProjectsChart() {
    const ctx = document.getElementById('projectsChart');
    if (!ctx) return;
    
    new Chart(ctx.getContext('2d'), {
        type: 'bar',
        data: {
            labels: ['Q1', 'Q2', 'Q3', 'Q4'],
            datasets: [
                {
                    label: 'Completed',
                    data: [5, 8, 6, 4],
                    backgroundColor: '#28a745'
                },
                {
                    label: 'In Progress',
                    data: [12, 15, 13, 7],
                    backgroundColor: '#3762c8'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true }
            }
        }
    });
}

function initAnalyticsChart() {
    const ctx = document.getElementById('analyticsChart');
    if (!ctx) return;
    
    new Chart(ctx.getContext('2d'), {
        type: 'line',
        data: {
            labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
            datasets: [
                {
                    label: 'Service Delivery %',
                    data: [88, 90, 92, 91, 93, 94],
                    borderColor: '#3762c8',
                    backgroundColor: 'rgba(55, 98, 200, 0.1)',
                    tension: 0.4,
                    fill: true
                },
                {
                    label: 'Citizen Rating',
                    data: [4.2, 4.3, 4.4, 4.5, 4.5, 4.6],
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    tension: 0.4,
                    fill: true
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true }
            }
        }
    });
}

// Header actions
document.querySelectorAll('.btn-action').forEach(btn => {
    btn.addEventListener('click', function() {
        const action = this.textContent.trim();
        console.log('Header action:', action);
        
        if (action.includes('Public View')) {
            window.open('/public-transparency-view', '_blank');
        } else if (action.includes('Filter')) {
            alert('Filter functionality coming soon!');
        } else if (action.includes('Export All')) {
            window.open('/export-publications', '_blank');
        }
    });
});
