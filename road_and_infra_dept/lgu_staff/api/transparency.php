<?php
/**
 * Public Transparency API Endpoints
 * Provides data for the Public Transparency page
 */

require_once '../config/database.php';

// Set CORS headers
setCorsHeaders();

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = new DBHelper();
    
    switch ($method) {
        case 'GET':
            handleGetRequests($db);
            break;
        case 'POST':
            handlePostRequests($db);
            break;
        case 'PUT':
            handlePutRequests($db);
            break;
        default:
            jsonResponse(['error' => 'Method not allowed'], 405);
    }
} catch (Exception $e) {
    logError('Transparency API Error: ' . $e->getMessage());
    jsonResponse(['error' => 'Internal server error'], 500);
}

/**
 * Handle GET requests
 */
function handleGetRequests($db) {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'stats':
            getTransparencyStats($db);
            break;
        case 'documents':
            getPublicDocuments($db);
            break;
        case 'budget':
            getBudgetData($db);
            break;
        case 'projects':
            getProjects($db);
            break;
        case 'performance':
            getPerformanceMetrics($db);
            break;
        case 'contact':
            getContactInfo($db);
            break;
        case 'document_details':
            getDocumentDetails($db);
            break;
        default:
            jsonResponse(['error' => 'Invalid action'], 400);
    }
}

/**
 * Handle POST requests
 */
function handlePostRequests($db) {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'upload_document':
            uploadDocument($db);
            break;
        case 'create_publication':
            createPublication($db);
            break;
        default:
            jsonResponse(['error' => 'Invalid action'], 400);
    }
}

/**
 * Handle PUT requests
 */
function handlePutRequests($db) {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';
    
    switch ($action) {
        case 'update_document':
            updateDocument($db, $data);
            break;
        case 'update_budget':
            updateBudget($db, $data);
            break;
        case 'update_project':
            updateProject($db, $data);
            break;
        default:
            jsonResponse(['error' => 'Invalid action'], 400);
    }
}

/**
 * Get transparency statistics
 */
function getTransparencyStats($db) {
    try {
        $stats = [];
        
        // Public documents count
        $sql = "SELECT COUNT(*) as count FROM public_documents WHERE is_public = TRUE";
        $db->prepare($sql);
        $result = $db->single();
        $stats['public_documents'] = (int) $result['count'];
        
        // Total views
        $sql = "SELECT SUM(view_count) as total FROM public_documents WHERE is_public = TRUE";
        $db->prepare($sql);
        $result = $db->single();
        $stats['total_views'] = (int) ($result['total'] ?? 0);
        
        // Total downloads
        $sql = "SELECT SUM(download_count) as total FROM public_documents WHERE is_public = TRUE";
        $db->prepare($sql);
        $result = $db->single();
        $stats['total_downloads'] = (int) ($result['total'] ?? 0);
        
        // Transparency score (based on various factors)
        $sql = "SELECT 
                (COUNT(CASE WHEN is_public = TRUE THEN 1 END) * 20) +
                (AVG(view_count) > 100 * 15) +
                (COUNT(CASE WHEN publication_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY) THEN 1 END) * 25) +
                (COUNT(CASE WHEN document_type IN ('annual_report', 'budget_report', 'performance_metrics') THEN 1 END) * 40) as score
                FROM public_documents";
        $db->prepare($sql);
        $result = $db->single();
        $stats['transparency_score'] = min(100, round($result['score'] ?? 85, 1));
        
        jsonResponse($stats);
    } catch (Exception $e) {
        logError('Error getting transparency stats: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to get transparency stats'], 500);
    }
}

/**
 * Get public documents
 */
function getPublicDocuments($db) {
    $type = $_GET['type'] ?? null;
    $year = $_GET['year'] ?? null;
    $search = $_GET['search'] ?? null;
    $limit = intval($_GET['limit'] ?? 20);
    $offset = intval($_GET['offset'] ?? 0);
    
    try {
        $sql = "SELECT pd.*, CONCAT(su.first_name, ' ', su.last_name) as uploaded_by_name
                FROM public_documents pd
                LEFT JOIN staff_users su ON pd.uploaded_by = su.user_id
                WHERE pd.is_public = TRUE";
        
        $params = [];
        $paramIndex = 1;
        
        if ($type) {
            $sql .= " AND pd.document_type = ?";
            $params[] = $type;
        }
        
        if ($year) {
            $sql .= " AND YEAR(pd.publication_date) = ?";
            $params[] = $year;
        }
        
        if ($search) {
            $sql .= " AND (pd.title LIKE ? OR pd.description LIKE ?)";
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        
        $sql .= " ORDER BY pd.publication_date DESC LIMIT ? OFFSET ?";
        
        $db->prepare($sql);
        foreach ($params as $i => $param) {
            $db->bind($i + 1, $param);
        }
        $db->bind(count($params) + 1, $limit, PDO::PARAM_INT);
        $db->bind(count($params) + 2, $offset, PDO::PARAM_INT);
        
        $documents = $db->get();
        
        // Get total count for pagination
        $countSql = "SELECT COUNT(*) as total FROM public_documents WHERE is_public = TRUE";
        $countParams = [];
        
        if ($type) {
            $countSql .= " AND document_type = ?";
            $countParams[] = $type;
        }
        
        if ($year) {
            $countSql .= " AND YEAR(publication_date) = ?";
            $countParams[] = $year;
        }
        
        if ($search) {
            $countSql .= " AND (title LIKE ? OR description LIKE ?)";
            $countParams[] = '%' . $search . '%';
            $countParams[] = '%' . $search . '%';
        }
        
        $db->prepare($countSql);
        foreach ($countParams as $i => $param) {
            $db->bind($i + 1, $param);
        }
        
        $countResult = $db->single();
        $total = $countResult['total'];
        
        // Format documents
        $formattedDocuments = [];
        foreach ($documents as $doc) {
            $formattedDocuments[] = [
                'document_id' => $doc['document_id'],
                'title' => $doc['title'],
                'document_type' => $doc['document_type'],
                'description' => $doc['description'],
                'file_url' => $doc['file_url'],
                'file_size' => $doc['file_size'],
                'mime_type' => $doc['mime_type'],
                'publication_date' => $doc['publication_date'],
                'expiry_date' => $doc['expiry_date'],
                'view_count' => (int) $doc['view_count'],
                'download_count' => (int) $doc['download_count'],
                'uploaded_by' => $doc['uploaded_by_name'],
                'created_at' => $doc['created_at'],
                'formatted_size' => formatFileSize($doc['file_size']),
                'formatted_date' => date('M j, Y', strtotime($doc['publication_date']))
            ];
        }
        
        jsonResponse([
            'documents' => $formattedDocuments,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ]);
    } catch (Exception $e) {
        logError('Error getting public documents: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to get public documents'], 500);
    }
}

/**
 * Get budget data
 */
function getBudgetData($db) {
    $year = $_GET['year'] ?? date('Y');
    
    try {
        // Get budget allocations
        $sql = "SELECT * FROM budget_utilization WHERE fiscal_year = ?";
        $db->prepare($sql);
        $db->bind(1, $year);
        $budgets = $db->get();
        
        // Get budget summary
        $sql = "SELECT 
                SUM(allocated_amount) as total_allocated,
                SUM(spent_amount) as total_spent,
                SUM(remaining_amount) as total_remaining,
                AVG((spent_amount / allocated_amount) * 100) as avg_utilization
                FROM budget_allocations 
                WHERE fiscal_year = ? AND is_active = TRUE";
        $db->prepare($sql);
        $db->bind(1, $year);
        $summary = $db->single();
        
        // Get monthly spending trend
        $sql = "SELECT 
                MONTH(created_at) as month,
                SUM(amount_spent) as monthly_spent
                FROM projects 
                WHERE fiscal_year = ? AND amount_spent > 0
                GROUP BY MONTH(created_at)
                ORDER BY month";
        $db->prepare($sql);
        $db->bind(1, $year);
        $monthlyTrend = $db->get();
        
        // Format response
        $budgetData = [
            'year' => $year,
            'summary' => [
                'total_allocated' => (float) $summary['total_allocated'],
                'total_spent' => (float) $summary['total_spent'],
                'total_remaining' => (float) $summary['total_remaining'],
                'avg_utilization' => round($summary['avg_utilization'], 1)
            ],
            'allocations' => $budgets,
            'monthly_trend' => $monthlyTrend
        ];
        
        jsonResponse($budgetData);
    } catch (Exception $e) {
        logError('Error getting budget data: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to get budget data'], 500);
    }
}

/**
 * Get projects data
 */
function getProjects($db) {
    $status = $_GET['status'] ?? null;
    $type = $_GET['type'] ?? null;
    $limit = intval($_GET['limit'] ?? 20);
    
    try {
        $sql = "SELECT * FROM project_progress_summary WHERE 1=1";
        $params = [];
        
        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }
        
        if ($type) {
            $sql .= " AND project_type = ?";
            $params[] = $type;
        }
        
        $sql .= " ORDER BY start_date DESC LIMIT ?";
        
        $db->prepare($sql);
        foreach ($params as $i => $param) {
            $db->bind($i + 1, $param);
        }
        $db->bind(count($params) + 1, $limit, PDO::PARAM_INT);
        
        $projects = $db->get();
        
        // Format projects
        $formattedProjects = [];
        foreach ($projects as $project) {
            $formattedProjects[] = [
                'project_id' => $project['project_id'],
                'project_name' => $project['project_name'],
                'project_code' => $project['project_code'],
                'project_type' => $project['project_type'],
                'status' => $project['status'],
                'progress_percentage' => (float) $project['progress_percentage'],
                'total_budget' => (float) $project['total_budget'],
                'amount_spent' => (float) $project['amount_spent'],
                'start_date' => $project['start_date'],
                'planned_completion_date' => $project['planned_completion_date'],
                'actual_completion_date' => $project['actual_completion_date'],
                'project_manager_name' => $project['project_manager_name'],
                'contractor_name' => $project['contractor_name'],
                'days_remaining' => (int) $project['days_remaining'],
                'budget_utilization' => round(($project['amount_spent'] / $project['total_budget']) * 100, 1),
                'status_badge' => getProjectStatusBadge($project['status']),
                'progress_color' => getProgressColor($project['progress_percentage'])
            ];
        }
        
        jsonResponse($formattedProjects);
    } catch (Exception $e) {
        logError('Error getting projects: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to get projects'], 500);
    }
}

/**
 * Get performance metrics
 */
function getPerformanceMetrics($db) {
    $category = $_GET['category'] ?? null;
    $period = $_GET['period'] ?? 'monthly';
    $limit = intval($_GET['limit'] ?? 50);
    
    try {
        $sql = "SELECT * FROM performance_metrics WHERE period_type = ?";
        $params = [$period];
        
        if ($category) {
            $sql .= " AND metric_category = ?";
            $params[] = $category;
        }
        
        $sql .= " ORDER BY measurement_date DESC LIMIT ?";
        $params[] = $limit;
        
        $db->prepare($sql);
        foreach ($params as $i => $param) {
            $db->bind($i + 1, $param);
        }
        
        $metrics = $db->get();
        
        // Group metrics by category
        $groupedMetrics = [];
        foreach ($metrics as $metric) {
            $category = $metric['metric_category'];
            if (!isset($groupedMetrics[$category])) {
                $groupedMetrics[$category] = [];
            }
            
            $groupedMetrics[$category][] = [
                'metric_id' => $metric['metric_id'],
                'metric_name' => $metric['metric_name'],
                'metric_value' => (float) $metric['metric_value'],
                'metric_unit' => $metric['metric_unit'],
                'target_value' => (float) $metric['target_value'],
                'measurement_date' => $metric['measurement_date'],
                'notes' => $metric['notes'],
                'performance_percentage' => calculatePerformancePercentage($metric),
                'status' => getMetricStatus($metric)
            ];
        }
        
        jsonResponse($groupedMetrics);
    } catch (Exception $e) {
        logError('Error getting performance metrics: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to get performance metrics'], 500);
    }
}

/**
 * Get contact information
 */
function getContactInfo($db) {
    try {
        // This would typically come from a configuration table
        // For now, we'll return static contact information
        $contactInfo = [
            'hotline' => [
                'title' => '24/7 Citizen Support Hotline',
                'description' => 'For infrastructure concerns and emergency reports',
                'phone' => '1-800-LGU-ROAD',
                'email' => 'hotline@lgu.gov.ph',
                'hours' => '24/7'
            ],
            'email_support' => [
                'title' => 'Email Support',
                'description' => 'General inquiries and non-urgent concerns',
                'email' => 'transparency@lgu.gov.ph',
                'response_time' => '24-48 hours'
            ],
            'office_locations' => [
                'title' => 'Office Locations',
                'description' => '12 service centers citywide',
                'main_office' => 'City Hall, Main Street',
                'hours' => 'Monday-Friday, 8:00 AM - 5:00 PM'
            ],
            'public_forum' => [
                'title' => 'Public Forum',
                'description' => 'Community discussion platform',
                'website' => 'forum.lgu.gov.ph',
                'registration_required' => true
            ]
        ];
        
        jsonResponse($contactInfo);
    } catch (Exception $e) {
        logError('Error getting contact info: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to get contact info'], 500);
    }
}

/**
 * Get document details
 */
function getDocumentDetails($db) {
    $documentId = intval($_GET['document_id'] ?? 0);
    
    if ($documentId === 0) {
        jsonResponse(['error' => 'Missing document_id'], 400);
    }
    
    try {
        // Increment view count
        $sql = "UPDATE public_documents SET view_count = view_count + 1 WHERE document_id = ?";
        $db->prepare($sql);
        $db->bind(1, $documentId);
        $db->execute();
        
        // Get document details
        $sql = "SELECT pd.*, CONCAT(su.first_name, ' ', su.last_name) as uploaded_by_name
                FROM public_documents pd
                LEFT JOIN staff_users su ON pd.uploaded_by = su.user_id
                WHERE pd.document_id = ? AND pd.is_public = TRUE";
        
        $db->prepare($sql);
        $db->bind(1, $documentId);
        
        $document = $db->single();
        
        if (!$document) {
            jsonResponse(['error' => 'Document not found'], 404);
        }
        
        // Format response
        $details = [
            'document_id' => $document['document_id'],
            'title' => $document['title'],
            'document_type' => $document['document_type'],
            'description' => $document['description'],
            'file_url' => $document['file_url'],
            'file_size' => $document['file_size'],
            'mime_type' => $document['mime_type'],
            'publication_date' => $document['publication_date'],
            'expiry_date' => $document['expiry_date'],
            'view_count' => (int) $document['view_count'],
            'download_count' => (int) $document['download_count'],
            'uploaded_by' => $document['uploaded_by_name'],
            'created_at' => $document['created_at'],
            'updated_at' => $document['updated_at'],
            'formatted_size' => formatFileSize($document['file_size']),
            'formatted_date' => date('F j, Y', strtotime($document['publication_date'])),
            'is_downloadable' => $document['expiry_date'] ? 
                (new DateTime($document['expiry_date']) > new DateTime()) : true
        ];
        
        jsonResponse($details);
    } catch (Exception $e) {
        logError('Error getting document details: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to get document details'], 500);
    }
}

/**
 * Upload document
 */
function uploadDocument($db) {
    if (!isset($_FILES['document'])) {
        jsonResponse(['error' => 'No document uploaded'], 400);
    }
    
    $title = $_POST['title'] ?? '';
    $documentType = $_POST['document_type'] ?? '';
    $description = $_POST['description'] ?? '';
    $uploadedBy = intval($_POST['uploaded_by'] ?? 0);
    $publicationDate = $_POST['publication_date'] ?? date('Y-m-d');
    $isPublic = $_POST['is_public'] ?? 'true';
    
    if (empty($title) || empty($documentType) || $uploadedBy === 0) {
        jsonResponse(['error' => 'Missing required fields'], 400);
    }
    
    $file = $_FILES['document'];
    $allowedTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'image/jpeg', 'image/png'];
    
    if (!in_array($file['type'], $allowedTypes)) {
        jsonResponse(['error' => 'Invalid file type'], 400);
    }
    
    if ($file['size'] > 10 * 1024 * 1024) { // 10MB limit
        jsonResponse(['error' => 'File too large'], 400);
    }
    
    // Create upload directory if it doesn't exist
    $uploadDir = '../uploads/documents/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    // Generate unique filename
    $filename = 'doc_' . time() . '_' . uniqid() . '_' . basename($file['name']);
    $filepath = $uploadDir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        try {
            $sql = "INSERT INTO public_documents 
                    (title, document_type, description, file_url, file_size, mime_type, 
                     publication_date, is_public, uploaded_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $db->prepare($sql);
            $db->bind(1, $title);
            $db->bind(2, $documentType);
            $db->bind(3, $description);
            $db->bind(4, '/uploads/documents/' . $filename);
            $db->bind(5, $file['size']);
            $db->bind(6, $file['type']);
            $db->bind(7, $publicationDate);
            $db->bind(8, $isPublic === 'true');
            $db->bind(9, $uploadedBy);
            
            $db->execute();
            
            jsonResponse(['success' => true, 'document_id' => $db->lastInsertId()]);
        } catch (Exception $e) {
            logError('Error saving document: ' . $e->getMessage());
            unlink($filepath);
            jsonResponse(['error' => 'Failed to save document'], 500);
        }
    } else {
        jsonResponse(['error' => 'Failed to upload document'], 500);
    }
}

/**
 * Update document
 */
function updateDocument($db, $data) {
    $required = ['document_id', 'updated_by'];
    foreach ($required as $field) {
        if (!isset($data[$field])) {
            jsonResponse(['error' => "Missing required field: $field"], 400);
        }
    }
    
    try {
        $sql = "UPDATE public_documents SET ";
        $updates = [];
        $params = [];
        
        if (isset($data['title'])) {
            $updates[] = "title = ?";
            $params[] = $data['title'];
        }
        
        if (isset($data['description'])) {
            $updates[] = "description = ?";
            $params[] = $data['description'];
        }
        
        if (isset($data['is_public'])) {
            $updates[] = "is_public = ?";
            $params[] = $data['is_public'];
        }
        
        if (isset($data['expiry_date'])) {
            $updates[] = "expiry_date = ?";
            $params[] = $data['expiry_date'];
        }
        
        $updates[] = "updated_at = CURRENT_TIMESTAMP";
        
        $sql .= implode(', ', $updates);
        $sql .= " WHERE document_id = ?";
        $params[] = $data['document_id'];
        
        $db->prepare($sql);
        foreach ($params as $i => $param) {
            $db->bind($i + 1, $param);
        }
        
        $success = $db->execute();
        
        if ($success) {
            $db->logActivity($data['updated_by'], 'UPDATE', 'public_documents', $data['document_id']);
            jsonResponse(['success' => true]);
        } else {
            jsonResponse(['error' => 'Failed to update document'], 500);
        }
    } catch (Exception $e) {
        logError('Error updating document: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to update document'], 500);
    }
}

/**
 * Helper functions
 */
function formatFileSize($bytes) {
    if ($bytes === null) return 'Unknown';
    
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= pow(1024, $pow);
    
    return round($bytes, 2) . ' ' . $units[$pow];
}

function getProjectStatusBadge($status) {
    $badges = [
        'planning' => 'secondary',
        'approved' => 'info',
        'in_progress' => 'primary',
        'completed' => 'success',
        'suspended' => 'warning',
        'cancelled' => 'danger'
    ];
    
    return $badges[$status] ?? 'secondary';
}

function getProgressColor($percentage) {
    if ($percentage < 25) return '#dc3545';
    if ($percentage < 50) return '#fd7e14';
    if ($percentage < 75) return '#ffc107';
    return '#28a745';
}

function calculatePerformancePercentage($metric) {
    if ($metric['target_value'] === null || $metric['target_value'] == 0) {
        return 100;
    }
    
    return min(100, round(($metric['metric_value'] / $metric['target_value']) * 100, 1));
}

function getMetricStatus($metric) {
    $percentage = calculatePerformancePercentage($metric);
    
    if ($percentage >= 100) return 'excellent';
    if ($percentage >= 90) return 'good';
    if ($percentage >= 75) return 'fair';
    return 'poor';
}

?>
