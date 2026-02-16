/**
 * Database Integration JavaScript
 * Handles all API calls and database interactions for LGU Staff System
 */

// API Base URL
const API_BASE_URL = '../api/';

// Global state
let currentUser = null;
let authToken = null;

/**
 * Initialize database integration
 */
function initDatabaseIntegration() {
    // Check for existing session
    checkSession();
    
    // Set up global error handlers
    setupErrorHandlers();
    
    // Initialize page-specific data
    initializePageData();
}

/**
 * Check user session
 */
function checkSession() {
    const sessionId = localStorage.getItem('session_id');
    if (sessionId) {
        // Validate session with server
        fetch(`${API_BASE_URL}auth.php?action=validate_session`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${sessionId}`
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                currentUser = data.user;
                authToken = sessionId;
                updateUIForAuthenticatedUser();
            } else {
                localStorage.removeItem('session_id');
                redirectToLogin();
            }
        })
        .catch(error => {
            console.error('Session validation error:', error);
            localStorage.removeItem('session_id');
        });
    }
}

/**
 * Setup global error handlers
 */
function setupErrorHandlers() {
    window.addEventListener('unhandledrejection', event => {
        console.error('Unhandled promise rejection:', event.reason);
        showNotification('An unexpected error occurred', 'error');
    });
    
    window.addEventListener('error', event => {
        console.error('Global error:', event.error);
        showNotification('An unexpected error occurred', 'error');
    });
}

/**
 * Initialize page-specific data
 */
function initializePageData() {
    const currentPage = getCurrentPage();
    
    switch (currentPage) {
        case 'dashboard':
            initDashboardData();
            break;
        case 'road_monitoring':
            initRoadMonitoringData();
            break;
        case 'verification':
            initVerificationData();
            break;
        case 'transparency':
            initTransparencyData();
            break;
        default:
            console.log('Unknown page:', currentPage);
    }
}

/**
 * Get current page name
 */
function getCurrentPage() {
    const path = window.location.pathname;
    const filename = path.split('/').pop();
    
    if (filename.includes('dashboard')) return 'dashboard';
    if (filename.includes('road_transportation_monitoring')) return 'road_monitoring';
    if (filename.includes('verification')) return 'verification';
    if (filename.includes('public_transparency')) return 'transparency';
    
    return 'unknown';
}

/**
 * Generic API call function
 */
async function apiCall(endpoint, options = {}) {
    const defaultOptions = {
        headers: {
            'Content-Type': 'application/json',
            ...(authToken && { 'Authorization': `Bearer ${authToken}` })
        }
    };
    
    const finalOptions = { ...defaultOptions, ...options };
    
    try {
        const response = await fetch(`${API_BASE_URL}${endpoint}`, finalOptions);
        const data = await response.json();
        
        if (!response.ok) {
            throw new Error(data.error || `HTTP error! status: ${response.status}`);
        }
        
        return data;
    } catch (error) {
        console.error(`API call to ${endpoint} failed:`, error);
        throw error;
    }
}

/**
 * Show notification
 */
function showNotification(message, type = 'info', duration = 5000) {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.innerHTML = `
        <div class="notification-content">
            <span class="notification-message">${message}</span>
            <button class="notification-close" onclick="this.parentElement.parentElement.remove()">×</button>
        </div>
    `;
    
    // Add styles if not already present
    if (!document.querySelector('#notification-styles')) {
        const style = document.createElement('style');
        style.id = 'notification-styles';
        style.textContent = `
            .notification {
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 9999;
                max-width: 400px;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                animation: slideIn 0.3s ease-out;
            }
            
            .notification-content {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 12px 16px;
            }
            
            .notification-message {
                flex: 1;
                margin-right: 10px;
            }
            
            .notification-close {
                background: none;
                border: none;
                font-size: 18px;
                cursor: pointer;
                opacity: 0.7;
            }
            
            .notification-close:hover {
                opacity: 1;
            }
            
            .notification-info {
                background: #e3f2fd;
                color: #1976d2;
                border-left: 4px solid #1976d2;
            }
            
            .notification-success {
                background: #e8f5e8;
                color: #2e7d32;
                border-left: 4px solid #2e7d32;
            }
            
            .notification-warning {
                background: #fff3e0;
                color: #f57c00;
                border-left: 4px solid #f57c00;
            }
            
            .notification-error {
                background: #ffebee;
                color: #c62828;
                border-left: 4px solid #c62828;
            }
            
            @keyframes slideIn {
                from {
                    transform: translateX(100%);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }
        `;
        document.head.appendChild(style);
    }
    
    // Add to page
    document.body.appendChild(notification);
    
    // Auto remove after duration
    setTimeout(() => {
        if (notification.parentElement) {
            notification.remove();
        }
    }, duration);
}

/**
 * Format date
 */
function formatDate(dateString, format = 'short') {
    const date = new Date(dateString);
    
    switch (format) {
        case 'short':
            return date.toLocaleDateString();
        case 'long':
            return date.toLocaleDateString('en-US', { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
        case 'time':
            return date.toLocaleTimeString();
        case 'datetime':
            return date.toLocaleString();
        default:
            return date.toLocaleDateString();
    }
}

/**
 * Format time ago
 */
function formatTimeAgo(dateString) {
    const date = new Date(dateString);
    const now = new Date();
    const diff = now - date;
    
    const minutes = Math.floor(diff / 60000);
    const hours = Math.floor(diff / 3600000);
    const days = Math.floor(diff / 86400000);
    
    if (minutes < 1) return 'Just now';
    if (minutes < 60) return `${minutes} minute${minutes > 1 ? 's' : ''} ago`;
    if (hours < 24) return `${hours} hour${hours > 1 ? 's' : ''} ago`;
    if (days < 30) return `${days} day${days > 1 ? 's' : ''} ago`;
    
    return formatDate(dateString, 'short');
}

/**
 * Format file size
 */
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

/**
 * Debounce function
 */
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

/**
 * Throttle function
 */
function throttle(func, limit) {
    let inThrottle;
    return function() {
        const args = arguments;
        const context = this;
        if (!inThrottle) {
            func.apply(context, args);
            inThrottle = true;
            setTimeout(() => inThrottle = false, limit);
        }
    };
}

/**
 * Loading states
 */
function showLoading(element) {
    if (element) {
        element.classList.add('loading');
        element.disabled = true;
    }
}

function hideLoading(element) {
    if (element) {
        element.classList.remove('loading');
        element.disabled = false;
    }
}

/**
 * Redirect to login
 */
function redirectToLogin() {
    window.location.href = '../login.html';
}

/**
 * Update UI for authenticated user
 */
function updateUIForAuthenticatedUser() {
    // Update user info in sidebar if available
    const userNameElement = document.querySelector('.user-name');
    if (userNameElement && currentUser) {
        userNameElement.textContent = `${currentUser.first_name} ${currentUser.last_name}`;
    }
    
    // Show/hide elements based on role
    const role = currentUser?.role;
    document.querySelectorAll('[data-role]').forEach(element => {
        const requiredRoles = element.dataset.role.split(',');
        element.style.display = requiredRoles.includes(role) ? '' : 'none';
    });
}

// ========================================
// Dashboard Functions
// ========================================

function initDashboardData() {
    loadDashboardStats();
    loadRecentIncidents();
    loadRecentActivity();
    loadPriorityTasks();
    loadChartData();
}

async function loadDashboardStats() {
    try {
        const stats = await apiCall('dashboard.php?action=stats');
        updateDashboardStats(stats);
    } catch (error) {
        console.error('Error loading dashboard stats:', error);
    }
}

function updateDashboardStats(stats) {
    const elements = {
        '.stat-number.road-reports': stats.roadReportsToday || 0,
        '.stat-number.pending-verifications': stats.pendingVerifications || 0,
        '.stat-number.under-maintenance': stats.underMaintenance || 0,
        '.stat-number.completed': stats.completedThisMonth || 0
    };
    
    Object.entries(elements).forEach(([selector, value]) => {
        const element = document.querySelector(selector);
        if (element) {
            animateNumber(element, value);
        }
    });
}

function animateNumber(element, target) {
    const duration = 1000;
    const start = parseInt(element.textContent) || 0;
    const increment = (target - start) / (duration / 16);
    let current = start;
    
    const timer = setInterval(() => {
        current += increment;
        if ((increment > 0 && current >= target) || (increment < 0 && current <= target)) {
            element.textContent = target;
            clearInterval(timer);
        } else {
            element.textContent = Math.round(current);
        }
    }, 16);
}

async function loadRecentIncidents() {
    try {
        const data = await apiCall('dashboard.php?action=incidents&limit=5');
        updateRecentIncidents(data.incidents);
    } catch (error) {
        console.error('Error loading recent incidents:', error);
    }
}

function updateRecentIncidents(incidents) {
    const container = document.querySelector('.activity-feed');
    if (!container) return;
    
    const html = incidents.map(incident => `
        <div class="activity-item">
            <div class="activity-icon ${getIncidentIconClass(incident.incident_type)}">
                <i class="fas ${getIncidentIcon(incident.incident_type)}"></i>
            </div>
            <div class="activity-content">
                <div class="activity-title">${incident.title}</div>
                <div class="activity-time">${formatTimeAgo(incident.incident_date)}</div>
            </div>
        </div>
    `).join('');
    
    container.innerHTML = html || '<p>No recent incidents</p>';
}

async function loadChartData() {
    try {
        const data = await apiCall('dashboard.php?action=charts&chart=weekly');
        updateChart(data);
    } catch (error) {
        console.error('Error loading chart data:', error);
    }
}

function updateChart(data) {
    const ctx = document.getElementById('reportsChart');
    if (!ctx) return;
    
    // Destroy existing chart if it exists
    if (window.reportsChart) {
        window.reportsChart.destroy();
    }
    
    window.reportsChart = new Chart(ctx, {
        type: 'line',
        data: data,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'bottom'
                }
            },
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
}

// ========================================
// Road Monitoring Functions
// ========================================

function initRoadMonitoringData() {
    loadRoads();
    loadIncidents();
    loadMapData();
    loadMonitoringStats();
    loadAlerts();
    loadRoadStatus();
}

async function loadRoads() {
    try {
        const roads = await apiCall('road_monitoring.php?action=roads');
        updateRoadsList(roads);
    } catch (error) {
        console.error('Error loading roads:', error);
    }
}

async function loadMapData() {
    try {
        const mapData = await apiCall('road_monitoring.php?action=map_data');
        updateMapMarkers(mapData);
    } catch (error) {
        console.error('Error loading map data:', error);
    }
}

function updateMapMarkers(markers) {
    // This would update the Leaflet map with incident markers
    if (window.map && markers) {
        // Clear existing markers
        window.map.eachLayer(layer => {
            if (layer instanceof L.Marker) {
                window.map.removeLayer(layer);
            }
        });
        
        // Add new markers
        markers.forEach(marker => {
            const icon = L.divIcon({
                html: `<div style="background: ${marker.color}; color: white; border-radius: 50%; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; font-size: 16px;">
                    <i class="fas ${marker.icon}"></i>
                </div>`,
                className: 'custom-marker',
                iconSize: [30, 30]
            });
            
            L.marker([marker.lat, marker.lng], { icon })
                .addTo(window.map)
                .bindPopup(`<b>${marker.title}</b><br>${marker.description}`);
        });
    }
}

// ========================================
// Verification Functions
// ========================================

function initVerificationData() {
    loadVerificationRequests();
    loadVerificationStats();
    loadVerificationWorkload();
}

async function loadVerificationRequests() {
    try {
        const data = await apiCall('verification.php?action=requests&limit=20');
        updateVerificationRequests(data.requests);
    } catch (error) {
        console.error('Error loading verification requests:', error);
    }
}

function updateVerificationRequests(requests) {
    const container = document.querySelector('.verification-container');
    if (!container) return;
    
    const html = requests.map(request => `
        <div class="verification-item" data-id="${request.request_id}">
            <div class="verification-priority priority-${request.priority_level}"></div>
            <div class="verification-icon">
                <i class="fas ${getVerificationIcon(request.request_type)}"></i>
            </div>
            <div class="verification-content">
                <div class="verification-title">${request.title}</div>
                <div class="verification-meta">
                    <div class="meta-item">
                        <i class="fas fa-user"></i>
                        ${request.requested_by}
                    </div>
                    <div class="meta-item">
                        <i class="fas fa-calendar"></i>
                        ${formatTimeAgo(request.created_at)}
                    </div>
                    <div class="meta-item">
                        <i class="fas fa-map-marker-alt"></i>
                        ${request.road_name}
                    </div>
                </div>
                <div class="verification-description">${request.description}</div>
                <div class="verification-actions">
                    <button class="btn-review" onclick="reviewVerification(${request.request_id})">
                        <i class="fas fa-eye"></i>
                        Review Details
                    </button>
                    <button class="btn-verify" onclick="approveVerification(${request.request_id})">
                        <i class="fas fa-check"></i>
                        Approve
                    </button>
                    <button class="btn-reject" onclick="rejectVerification(${request.request_id})">
                        <i class="fas fa-times"></i>
                        Reject
                    </button>
                </div>
            </div>
        </div>
    `).join('');
    
    container.innerHTML = html || '<p>No verification requests</p>';
}

// ========================================
// Transparency Functions
// ========================================

function initTransparencyData() {
    loadTransparencyStats();
    loadPublicDocuments();
    loadBudgetData();
    loadProjects();
    loadPerformanceMetrics();
}

async function loadPublicDocuments() {
    try {
        const data = await apiCall('transparency.php?action=documents&limit=12');
        updatePublicDocuments(data.documents);
    } catch (error) {
        console.error('Error loading public documents:', error);
    }
}

function updatePublicDocuments(documents) {
    const container = document.querySelector('.publications-grid');
    if (!container) return;
    
    const html = documents.map(doc => `
        <div class="publication-card" onclick="viewDocument(${doc.document_id})">
            <span class="publication-type">${formatDocumentType(doc.document_type)}</span>
            <div class="publication-title">${doc.title}</div>
            <div class="publication-meta">
                <span><i class="fas fa-calendar"></i> ${formatDate(doc.publication_date)}</span>
                <span><i class="fas fa-eye"></i> ${doc.view_count} views</span>
            </div>
            <div class="publication-description">${doc.description}</div>
        </div>
    `).join('');
    
    container.innerHTML = html || '<p>No publications available</p>';
}

// ========================================
// Helper Functions
// ========================================

function getIncidentIcon(type) {
    const icons = {
        'pothole': 'fa-road',
        'accident': 'fa-car-crash',
        'flooding': 'fa-water',
        'traffic_light': 'fa-traffic-light',
        'debris': 'fa-trash'
    };
    return icons[type] || 'fa-exclamation-triangle';
}

function getIncidentIconClass(type) {
    const classes = {
        'pothole': 'road',
        'accident': 'report',
        'flooding': 'verification',
        'traffic_light': 'road',
        'debris': 'road'
    };
    return classes[type] || 'road';
}

function getVerificationIcon(type) {
    const icons = {
        'road_damage': 'fa-road',
        'traffic_light': 'fa-traffic-light',
        'maintenance': 'fa-tools',
        'construction': 'fa-hard-hat'
    };
    return icons[type] || 'fa-clipboard-check';
}

function formatDocumentType(type) {
    return type.split('_').map(word => 
        word.charAt(0).toUpperCase() + word.slice(1)
    ).join(' ');
}

// ========================================
// Action Handlers
// ========================================

async function reviewVerification(requestId) {
    try {
        const details = await apiCall(`verification.php?action=details&request_id=${requestId}`);
        showVerificationModal(details);
    } catch (error) {
        showNotification('Failed to load verification details', 'error');
    }
}

async function approveVerification(requestId) {
    if (!confirm('Are you sure you want to approve this verification?')) return;
    
    try {
        await apiCall('verification.php', {
            method: 'PUT',
            body: JSON.stringify({
                action: 'approve',
                request_id: requestId,
                approved_by: currentUser?.user_id,
                notes: 'Approved by staff'
            })
        });
        
        showNotification('Verification approved successfully', 'success');
        loadVerificationRequests(); // Reload the list
    } catch (error) {
        showNotification('Failed to approve verification', 'error');
    }
}

async function rejectVerification(requestId) {
    const reason = prompt('Please provide a reason for rejection:');
    if (!reason) return;
    
    try {
        await apiCall('verification.php', {
            method: 'PUT',
            body: JSON.stringify({
                action: 'reject',
                request_id: requestId,
                rejected_by: currentUser?.user_id,
                reason: reason
            })
        });
        
        showNotification('Verification rejected', 'success');
        loadVerificationRequests(); // Reload the list
    } catch (error) {
        showNotification('Failed to reject verification', 'error');
    }
}

async function viewDocument(documentId) {
    try {
        const details = await apiCall(`transparency.php?action=document_details&document_id=${documentId}`);
        showDocumentModal(details);
    } catch (error) {
        showNotification('Failed to load document details', 'error');
    }
}

// ========================================
// Modal Functions
// ========================================

function showVerificationModal(details) {
    // Create and show modal with verification details
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.innerHTML = `
        <div class="modal-content">
            <div class="modal-header">
                <h2>Verification Details</h2>
                <button class="modal-close" onclick="this.closest('.modal').remove()">×</button>
            </div>
            <div class="modal-body">
                <h3>${details.request.title}</h3>
                <p><strong>Type:</strong> ${details.request.request_type}</p>
                <p><strong>Priority:</strong> ${details.request.priority_level}</p>
                <p><strong>Status:</strong> ${details.request.status}</p>
                <p><strong>Description:</strong> ${details.request.description}</p>
                <p><strong>Road:</strong> ${details.incident.road_name}</p>
                <p><strong>Requested by:</strong> ${details.requested_by.name}</p>
                ${details.photos.length > 0 ? '<h4>Photos</h4>' : ''}
                ${details.photos.map(photo => `
                    <img src="${photo.photo_url}" alt="Incident photo" style="max-width: 200px; margin: 5px;">
                `).join('')}
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
}

function showDocumentModal(details) {
    // Create and show modal with document details
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.innerHTML = `
        <div class="modal-content">
            <div class="modal-header">
                <h2>${details.title}</h2>
                <button class="modal-close" onclick="this.closest('.modal').remove()">×</button>
            </div>
            <div class="modal-body">
                <p><strong>Type:</strong> ${formatDocumentType(details.document_type)}</p>
                <p><strong>Published:</strong> ${details.formatted_date}</p>
                <p><strong>File Size:</strong> ${details.formatted_size}</p>
                <p><strong>Views:</strong> ${details.view_count}</p>
                <p><strong>Downloads:</strong> ${details.download_count}</p>
                <p><strong>Description:</strong> ${details.description}</p>
                ${details.is_downloadable ? `
                    <button class="btn-action" onclick="downloadDocument(${details.document_id})">
                        <i class="fas fa-download"></i>
                        Download Document
                    </button>
                ` : '<p>This document is no longer available for download</p>'}
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
}

async function downloadDocument(documentId) {
    try {
        // Increment download count
        await apiCall('transparency.php', {
            method: 'PUT',
            body: JSON.stringify({
                action: 'increment_download',
                document_id: documentId
            })
        });
        
        // Open document in new tab
        window.open(`/uploads/documents/view.php?id=${documentId}`, '_blank');
    } catch (error) {
        showNotification('Failed to download document', 'error');
    }
}

// ========================================
// Initialize on page load
// ========================================

document.addEventListener('DOMContentLoaded', initDatabaseIntegration);

// Export functions for global access
window.databaseIntegration = {
    apiCall,
    showNotification,
    formatDate,
    formatTimeAgo,
    reviewVerification,
    approveVerification,
    rejectVerification,
    viewDocument,
    downloadDocument
};
