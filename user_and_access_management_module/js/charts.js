// Dashboard Charts JavaScript
document.addEventListener('DOMContentLoaded', function() {
    // Chart configurations
    const chartOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    padding: 15,
                    font: {
                        size: 12
                    }
                }
            }
        }
    };

    // Pie Chart - Account Distribution
    const pieCtx = document.getElementById('pieChart');
    if (pieCtx) {
        new Chart(pieCtx.getContext('2d'), {
            type: 'pie',
            data: {
                labels: ['Active', 'Pending', 'Rejected', 'Suspended'],
                datasets: [{
                    data: [8, 3, 1, 0],
                    backgroundColor: ['#28a745', '#ffc107', '#dc3545', '#6c757d'],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                ...chartOptions,
                plugins: {
                    ...chartOptions.plugins,
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.label + ': ' + context.parsed + ' officers';
                            }
                        }
                    }
                }
            }
        });
    }

    // Bar Chart - Monthly Applications
    const barCtx = document.getElementById('barChart');
    if (barCtx) {
        new Chart(barCtx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                datasets: [{
                    label: 'Applications',
                    data: [12, 19, 8, 15, 22, 18],
                    backgroundColor: 'rgba(40, 92, 205, 0.8)',
                    borderColor: 'rgba(40, 92, 205, 1)',
                    borderWidth: 1,
                    borderRadius: 5
                }]
            },
            options: {
                ...chartOptions,
                scales: {
                    y: { beginAtZero: true, grid: { color: 'rgba(0, 0, 0, 0.05)' } },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    // Line Chart - Weekly Application Flow
    const lineCtx = document.getElementById('lineChart');
    if (lineCtx) {
        new Chart(lineCtx.getContext('2d'), {
            type: 'line',
            data: {
                labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'],
                datasets: [{
                    label: 'Approved',
                    data: [5, 8, 6, 9],
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    tension: 0.4,
                    fill: true
                }, {
                    label: 'Rejected',
                    data: [2, 1, 3, 1],
                    borderColor: '#dc3545',
                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                    tension: 0.4,
                    fill: true
                }, {
                    label: 'Pending',
                    data: [3, 4, 2, 3],
                    borderColor: '#ffc107',
                    backgroundColor: 'rgba(255, 193, 7, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                ...chartOptions,
                scales: {
                    y: { beginAtZero: true, grid: { color: 'rgba(0, 0, 0, 0.05)' } },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    // Donut Chart - Officers by Department
    const donutCtx = document.getElementById('donutChart');
    if (donutCtx) {
        new Chart(donutCtx.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Public Works', 'Engineering', 'Planning', 'Maintenance'],
                datasets: [{
                    data: [5, 3, 2, 2],
                    backgroundColor: ['#36b9cc', '#4e73df', '#1cc88a', '#f6c23e'],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                ...chartOptions,
                cutout: '60%',
                plugins: {
                    ...chartOptions.plugins,
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.label + ': ' + context.parsed + ' officers';
                            }
                        }
                    }
                }
            }
        });
    }

    // Radar Chart - Role Performance
    const radarCtx = document.getElementById('radarChart');
    if (radarCtx) {
        new Chart(radarCtx.getContext('2d'), {
            type: 'radar',
            data: {
                labels: ['Efficiency', 'Quality', 'Response Time', 'Compliance', 'Satisfaction'],
                datasets: [{
                    label: 'Current Month',
                    data: [85, 92, 78, 88, 90],
                    borderColor: 'rgba(40, 92, 205, 1)',
                    backgroundColor: 'rgba(40, 92, 205, 0.2)',
                    pointBackgroundColor: 'rgba(40, 92, 205, 1)',
                    pointBorderColor: '#fff',
                    pointHoverBackgroundColor: '#fff',
                    pointHoverBorderColor: 'rgba(40, 92, 205, 1)'
                }, {
                    label: 'Previous Month',
                    data: [78, 88, 82, 85, 86],
                    borderColor: 'rgba(255, 193, 7, 1)',
                    backgroundColor: 'rgba(255, 193, 7, 0.2)',
                    pointBackgroundColor: 'rgba(255, 193, 7, 1)',
                    pointBorderColor: '#fff',
                    pointHoverBackgroundColor: '#fff',
                    pointHoverBorderColor: 'rgba(255, 193, 7, 1)'
                }]
            },
            options: {
                ...chartOptions,
                scales: {
                    r: {
                        beginAtZero: true,
                        max: 100,
                        grid: { color: 'rgba(0, 0, 0, 0.05)' },
                        ticks: { stepSize: 20 }
                    }
                }
            }
        });
    }
});
