<?php
/**
 * LGU Road & Infrastructure Department Database Configuration
 * Handles database connection and configuration settings
 */

class Database {
    private $host = 'localhost';
    private $db_name = 'lgu_road_infrastructure';
    private $username = 'lgu_app';
    private $password = 'SecurePassword123!';
    private $charset = 'utf8mb4';
    
    private $pdo;
    private $stmt;
    
    public function __construct() {
        $dsn = "mysql:host={$this->host};dbname={$this->db_name};charset={$this->charset}";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        
        try {
            $this->pdo = new PDO($dsn, $this->username, $this->password, $options);
        } catch (PDOException $e) {
            throw new PDOException($e->getMessage(), (int)$e->getCode());
        }
    }
    
    /**
     * Prepare SQL statement
     */
    public function prepare($sql) {
        $this->stmt = $this->pdo->prepare($sql);
        return $this;
    }
    
    /**
     * Bind parameters to prepared statement
     */
    public function bind($param, $value, $type = null) {
        if (is_null($type)) {
            switch (true) {
                case is_int($value):
                    $type = PDO::PARAM_INT;
                    break;
                case is_bool($value):
                    $type = PDO::PARAM_BOOL;
                    break;
                case is_null($value):
                    $type = PDO::PARAM_NULL;
                    break;
                default:
                    $type = PDO::PARAM_STR;
            }
        }
        
        $this->stmt->bindValue($param, $value, $type);
        return $this;
    }
    
    /**
     * Execute prepared statement
     */
    public function execute() {
        return $this->stmt->execute();
    }
    
    /**
     * Get multiple records
     */
    public function get() {
        $this->execute();
        return $this->stmt->fetchAll();
    }
    
    /**
     * Get single record
     */
    public function single() {
        $this->execute();
        return $this->stmt->fetch();
    }
    
    /**
     * Get row count
     */
    public function rowCount() {
        return $this->stmt->rowCount();
    }
    
    /**
     * Get last inserted ID
     */
    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Begin transaction
     */
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }
    
    /**
     * Commit transaction
     */
    public function commit() {
        return $this->pdo->commit();
    }
    
    /**
     * Rollback transaction
     */
    public function rollBack() {
        return $this->pdo->rollBack();
    }
    
    /**
     * Get PDO instance for direct queries
     */
    public function getPdo() {
        return $this->pdo;
    }
    
    /**
     * Close connection
     */
    public function close() {
        $this->pdo = null;
    }
}

/**
 * Database helper functions
 */
class DBHelper {
    private $db;
    
    public function __construct() {
        $this->db = new Database();
    }
    
    /**
     * Get dashboard statistics
     */
    public function getDashboardStats() {
        $sql = "CALL GetDashboardData()";
        $this->db->prepare($sql);
        return $this->db->get();
    }
    
    /**
     * Get active incidents
     */
    public function getActiveIncidents($limit = 10) {
        $sql = "SELECT * FROM active_incidents_summary ORDER BY incident_date DESC LIMIT ?";
        $this->db->prepare($sql);
        $this->db->bind(1, $limit);
        return $this->db->get();
    }
    
    /**
     * Get road incidents by date range
     */
    public function getIncidentsByDateRange($startDate, $endDate) {
        $sql = "SELECT ri.*, r.road_name, r.location_description 
                FROM road_incidents ri 
                JOIN roads r ON ri.road_id = r.road_id 
                WHERE DATE(ri.incident_date) BETWEEN ? AND ? 
                ORDER BY ri.incident_date DESC";
        $this->db->prepare($sql);
        $this->db->bind(1, $startDate);
        $this->db->bind(2, $endDate);
        return $this->db->get();
    }
    
    /**
     * Get verification requests
     */
    public function getVerificationRequests($status = null, $limit = 20) {
        $sql = "SELECT vr.*, ri.title as incident_title, ri.severity_level,
                CONCAT(req.first_name, ' ', req.last_name) as requested_by_name,
                CONCAT(ver.first_name, ' ', ver.last_name) as verifier_name
                FROM verification_requests vr
                JOIN road_incidents ri ON vr.incident_id = ri.incident_id
                LEFT JOIN staff_users req ON vr.requested_by = req.user_id
                LEFT JOIN staff_users ver ON vr.assigned_verifier = ver.user_id";
        
        if ($status) {
            $sql .= " WHERE vr.status = ?";
            $this->db->prepare($sql);
            $this->db->bind(1, $status);
        } else {
            $this->db->prepare($sql);
        }
        
        $sql .= " ORDER BY vr.created_at DESC LIMIT ?";
        $this->db->bind(count($status) ? 2 : 1, $limit);
        
        return $this->db->get();
    }
    
    /**
     * Get roads list
     */
    public function getRoads($activeOnly = true) {
        $sql = "SELECT * FROM roads";
        if ($activeOnly) {
            $sql .= " WHERE is_active = TRUE";
        }
        $sql .= " ORDER BY road_name";
        
        $this->db->prepare($sql);
        return $this->db->get();
    }
    
    /**
     * Create new incident
     */
    public function createIncident($data) {
        $sql = "CALL CreateIncident(?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $this->db->prepare($sql);
        
        $this->db->bind(1, $data['road_id']);
        $this->db->bind(2, $data['incident_type']);
        $this->db->bind(3, $data['severity_level']);
        $this->db->bind(4, $data['title']);
        $this->db->bind(5, $data['description']);
        $this->db->bind(6, $data['reported_by']);
        $this->db->bind(7, $data['reporter_contact']);
        $this->db->bind(8, $data['latitude']);
        $this->db->bind(9, $data['longitude']);
        
        $result = $this->db->single();
        return $result['incident_id'];
    }
    
    /**
     * Update incident status
     */
    public function updateIncidentStatus($incidentId, $status, $updatedBy, $notes = null) {
        $sql = "CALL UpdateIncidentStatus(?, ?, ?, ?)";
        $this->db->prepare($sql);
        
        $this->db->bind(1, $incidentId);
        $this->db->bind(2, $status);
        $this->db->bind(3, $updatedBy);
        $this->db->bind(4, $notes);
        
        return $this->db->execute();
    }
    
    /**
     * Get public documents
     */
    public function getPublicDocuments($type = null, $limit = 20) {
        $sql = "SELECT * FROM public_documents WHERE is_public = TRUE";
        
        if ($type) {
            $sql .= " AND document_type = ?";
            $this->db->prepare($sql);
            $this->db->bind(1, $type);
        } else {
            $this->db->prepare($sql);
        }
        
        $sql .= " ORDER BY publication_date DESC LIMIT ?";
        $this->db->bind(count($type) ? 2 : 1, $limit);
        
        return $this->db->get();
    }
    
    /**
     * Get budget utilization
     */
    public function getBudgetUtilization($year = null) {
        $sql = "SELECT * FROM budget_utilization";
        if ($year) {
            $sql .= " WHERE fiscal_year = ?";
            $this->db->prepare($sql);
            $this->db->bind(1, $year);
        } else {
            $this->db->prepare($sql);
        }
        
        return $this->db->get();
    }
    
    /**
     * Get projects
     */
    public function getProjects($status = null) {
        $sql = "SELECT * FROM project_progress_summary";
        if ($status) {
            $sql .= " WHERE status = ?";
            $this->db->prepare($sql);
            $this->db->bind(1, $status);
        } else {
            $this->db->prepare($sql);
        }
        
        return $this->db->get();
    }
    
    /**
     * Get performance metrics
     */
    public function getPerformanceMetrics($category = null, $period = 'monthly') {
        $sql = "SELECT * FROM performance_metrics WHERE period_type = ?";
        $this->db->prepare($sql);
        $this->db->bind(1, $period);
        
        if ($category) {
            $sql .= " AND metric_category = ?";
            $this->db->prepare($sql);
            $this->db->bind(1, $period);
            $this->db->bind(2, $category);
        }
        
        $sql .= " ORDER BY measurement_date DESC LIMIT 50";
        $this->db->prepare($sql);
        
        return $this->db->get();
    }
    
    /**
     * Authenticate user
     */
    public function authenticateUser($username, $password) {
        $sql = "CALL AuthenticateUser(?, ?)";
        $this->db->prepare($sql);
        $this->db->bind(1, $username);
        $this->db->bind(2, $password);
        
        return $this->db->single();
    }
    
    /**
     * Get user notifications
     */
    public function getUserNotifications($userId, $unreadOnly = false) {
        $sql = "SELECT * FROM system_notifications WHERE user_id = ?";
        if ($unreadOnly) {
            $sql .= " AND is_read = FALSE";
        }
        $sql .= " ORDER BY created_at DESC LIMIT 20";
        
        $this->db->prepare($sql);
        $this->db->bind(1, $userId);
        
        return $this->db->get();
    }
    
    /**
     * Mark notification as read
     */
    public function markNotificationRead($notificationId) {
        $sql = "UPDATE system_notifications SET is_read = TRUE, read_at = NOW() WHERE notification_id = ?";
        $this->db->prepare($sql);
        $this->db->bind(1, $notificationId);
        
        return $this->db->execute();
    }
    
    /**
     * Log activity
     */
    public function logActivity($userId, $action, $table, $recordId, $oldValues = null, $newValues = null) {
        $sql = "INSERT INTO activity_logs (user_id, action_type, table_name, record_id, old_values, new_values) 
                VALUES (?, ?, ?, ?, ?, ?)";
        $this->db->prepare($sql);
        
        $this->db->bind(1, $userId);
        $this->db->bind(2, $action);
        $this->db->bind(3, $table);
        $this->db->bind(4, $recordId);
        $this->db->bind(5, $oldValues ? json_encode($oldValues) : null);
        $this->db->bind(6, $newValues ? json_encode($newValues) : null);
        
        return $this->db->execute();
    }
}

/**
 * Error handling and logging
 */
function logError($message, $context = []) {
    $logMessage = date('Y-m-d H:i:s') . " - " . $message;
    if (!empty($context)) {
        $logMessage .= " - Context: " . json_encode($context);
    }
    error_log($logMessage . PHP_EOL, 3, __DIR__ . '/../logs/error.log');
}

/**
 * JSON response helper
 */
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * CORS headers for API
 */
function setCorsHeaders() {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    
    if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

/**
 * Check if user is authenticated
 */
function requireAuth() {
    session_start();
    if (!isset($_SESSION['user_id'])) {
        jsonResponse(['error' => 'Authentication required'], 401);
    }
    return $_SESSION['user_id'];
}

?>
