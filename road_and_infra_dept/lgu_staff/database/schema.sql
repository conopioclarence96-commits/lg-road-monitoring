-- ========================================
-- LGU Road & Infrastructure Department Database
-- Comprehensive Database Schema for Staff Management System
-- ========================================

-- Create database
CREATE DATABASE IF NOT EXISTS lgu_road_infrastructure;
USE lgu_road_infrastructure;

-- ========================================
-- 1. USER MANAGEMENT TABLES
-- ========================================

-- Staff users table
CREATE TABLE staff_users (
    user_id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    role ENUM('admin', 'supervisor', 'staff', 'technician') NOT NULL DEFAULT 'staff',
    department VARCHAR(100) DEFAULT 'Road & Infrastructure',
    phone VARCHAR(20),
    is_active BOOLEAN DEFAULT TRUE,
    last_login DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- User sessions table
CREATE TABLE user_sessions (
    session_id VARCHAR(128) PRIMARY KEY,
    user_id INT NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (user_id) REFERENCES staff_users(user_id) ON DELETE CASCADE
);

-- ========================================
-- 2. ROAD INFRASTRUCTURE TABLES
-- ========================================

-- Roads master table
CREATE TABLE roads (
    road_id INT PRIMARY KEY AUTO_INCREMENT,
    road_name VARCHAR(100) NOT NULL,
    road_type ENUM('highway', 'main_street', 'secondary_street', 'avenue', 'bridge') NOT NULL,
    location_description TEXT,
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    length_km DECIMAL(8, 2),
    width_meters DECIMAL(6, 2),
    surface_type ENUM('asphalt', 'concrete', 'gravel', 'dirt') DEFAULT 'asphalt',
    construction_year YEAR,
    last_maintenance_date DATE,
    condition_rating ENUM('excellent', 'good', 'fair', 'poor', 'critical') DEFAULT 'good',
    traffic_volume ENUM('light', 'moderate', 'heavy') DEFAULT 'moderate',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Road incidents/reports table
CREATE TABLE road_incidents (
    incident_id INT PRIMARY KEY AUTO_INCREMENT,
    road_id INT NOT NULL,
    incident_type ENUM('pothole', 'crack', 'erosion', 'flooding', 'accident', 'debris', 'light_malfunction', 'sign_damage', 'other') NOT NULL,
    severity_level ENUM('low', 'medium', 'high', 'critical') NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    reported_by VARCHAR(100),
    reporter_contact VARCHAR(20),
    incident_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    status ENUM('pending', 'under_review', 'approved', 'in_progress', 'resolved', 'rejected') DEFAULT 'pending',
    estimated_repair_cost DECIMAL(10, 2),
    actual_repair_cost DECIMAL(10, 2),
    resolution_date DATETIME,
    resolution_notes TEXT,
    assigned_staff_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (road_id) REFERENCES roads(road_id),
    FOREIGN KEY (assigned_staff_id) REFERENCES staff_users(user_id)
);

-- Incident photos/evidence table
CREATE TABLE incident_photos (
    photo_id INT PRIMARY KEY AUTO_INCREMENT,
    incident_id INT NOT NULL,
    photo_url VARCHAR(255) NOT NULL,
    photo_description TEXT,
    uploaded_by INT NOT NULL,
    upload_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    file_size INT,
    mime_type VARCHAR(50),
    FOREIGN KEY (incident_id) REFERENCES road_incidents(incident_id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES staff_users(user_id)
);

-- ========================================
-- 3. VERIFICATION WORKFLOW TABLES
-- ========================================

-- Verification requests table
CREATE TABLE verification_requests (
    request_id INT PRIMARY KEY AUTO_INCREMENT,
    incident_id INT NOT NULL,
    request_type ENUM('road_damage', 'traffic_light', 'maintenance', 'construction', 'other') NOT NULL,
    priority_level ENUM('low', 'medium', 'high') DEFAULT 'medium',
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    requested_by INT NOT NULL,
    assigned_verifier INT,
    status ENUM('pending', 'in_review', 'approved', 'rejected', 'requires_more_info') DEFAULT 'pending',
    verification_notes TEXT,
    rejection_reason TEXT,
    verification_date DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (incident_id) REFERENCES road_incidents(incident_id),
    FOREIGN KEY (requested_by) REFERENCES staff_users(user_id),
    FOREIGN KEY (assigned_verifier) REFERENCES staff_users(user_id)
);

-- Verification timeline/history table
CREATE TABLE verification_timeline (
    timeline_id INT PRIMARY KEY AUTO_INCREMENT,
    request_id INT NOT NULL,
    action_type ENUM('created', 'assigned', 'review_started', 'approved', 'rejected', 'resubmitted', 'closed') NOT NULL,
    action_by INT NOT NULL,
    action_notes TEXT,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES verification_requests(request_id) ON DELETE CASCADE,
    FOREIGN KEY (action_by) REFERENCES staff_users(user_id)
);

-- ========================================
-- 4. MAINTENANCE AND WORK ORDERS
-- ========================================

-- Maintenance schedules table
CREATE TABLE maintenance_schedules (
    schedule_id INT PRIMARY KEY AUTO_INCREMENT,
    road_id INT NOT NULL,
    maintenance_type ENUM('routine', 'emergency', 'preventive', 'corrective') NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    scheduled_date DATE NOT NULL,
    estimated_duration_days INT,
    estimated_cost DECIMAL(10, 2),
    actual_cost DECIMAL(10, 2),
    status ENUM('scheduled', 'in_progress', 'completed', 'cancelled', 'postponed') DEFAULT 'scheduled',
    assigned_team_lead INT,
    completion_date DATE,
    completion_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (road_id) REFERENCES roads(road_id),
    FOREIGN KEY (assigned_team_lead) REFERENCES staff_users(user_id)
);

-- Work orders table
CREATE TABLE work_orders (
    work_order_id INT PRIMARY KEY AUTO_INCREMENT,
    schedule_id INT,
    incident_id INT,
    work_order_number VARCHAR(50) UNIQUE NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
    status ENUM('pending', 'assigned', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
    assigned_to INT,
    created_by INT NOT NULL,
    created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    due_date DATETIME,
    completion_date DATETIME,
    completion_notes TEXT,
    materials_used TEXT,
    labor_hours DECIMAL(5, 2),
    FOREIGN KEY (schedule_id) REFERENCES maintenance_schedules(schedule_id),
    FOREIGN KEY (incident_id) REFERENCES road_incidents(incident_id),
    FOREIGN KEY (assigned_to) REFERENCES staff_users(user_id),
    FOREIGN KEY (created_by) REFERENCES staff_users(user_id)
);

-- ========================================
-- 5. TRANSPARENCY AND PUBLICATIONS
-- ========================================

-- Public documents table
CREATE TABLE public_documents (
    document_id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(200) NOT NULL,
    document_type ENUM('annual_report', 'budget_report', 'performance_metrics', 'policy_document', 'project_update', 'transparency_report') NOT NULL,
    description TEXT,
    file_url VARCHAR(255) NOT NULL,
    file_size INT,
    mime_type VARCHAR(50),
    publication_date DATE NOT NULL,
    expiry_date DATE,
    is_public BOOLEAN DEFAULT TRUE,
    view_count INT DEFAULT 0,
    download_count INT DEFAULT 0,
    uploaded_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES staff_users(user_id)
);

-- Budget allocations table
CREATE TABLE budget_allocations (
    allocation_id INT PRIMARY KEY AUTO_INCREMENT,
    fiscal_year YEAR NOT NULL,
    department VARCHAR(100) DEFAULT 'Road & Infrastructure',
    category ENUM('maintenance', 'construction', 'operations', 'equipment', 'personnel', 'other') NOT NULL,
    allocated_amount DECIMAL(12, 2) NOT NULL,
    spent_amount DECIMAL(12, 2) DEFAULT 0,
    remaining_amount DECIMAL(12, 2) GENERATED ALWAYS AS (allocated_amount - spent_amount) STORED,
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Projects table
CREATE TABLE projects (
    project_id INT PRIMARY KEY AUTO_INCREMENT,
    project_name VARCHAR(200) NOT NULL,
    project_code VARCHAR(50) UNIQUE NOT NULL,
    description TEXT,
    project_type ENUM('road_construction', 'road_rehabilitation', 'bridge_repair', 'drainage', 'traffic_systems', 'other') NOT NULL,
    budget_allocation_id INT,
    total_budget DECIMAL(12, 2) NOT NULL,
    amount_spent DECIMAL(12, 2) DEFAULT 0,
    start_date DATE,
    planned_completion_date DATE,
    actual_completion_date DATE,
    status ENUM('planning', 'approved', 'in_progress', 'completed', 'suspended', 'cancelled') DEFAULT 'planning',
    progress_percentage DECIMAL(5, 2) DEFAULT 0,
    project_manager INT,
    contractor_name VARCHAR(200),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (budget_allocation_id) REFERENCES budget_allocations(allocation_id),
    FOREIGN KEY (project_manager) REFERENCES staff_users(user_id)
);

-- ========================================
-- 6. REPORTS AND ANALYTICS
-- ========================================

-- Generated reports table
CREATE TABLE generated_reports (
    report_id INT PRIMARY KEY AUTO_INCREMENT,
    report_name VARCHAR(200) NOT NULL,
    report_type ENUM('daily', 'weekly', 'monthly', 'quarterly', 'annual', 'custom') NOT NULL,
    report_category ENUM('incidents', 'maintenance', 'budget', 'performance', 'transparency', 'comprehensive') NOT NULL,
    parameters JSON,
    file_url VARCHAR(255),
    generated_by INT NOT NULL,
    generation_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_from DATE,
    date_to DATE,
    file_size INT,
    is_public BOOLEAN DEFAULT FALSE,
    view_count INT DEFAULT 0,
    download_count INT DEFAULT 0,
    FOREIGN KEY (generated_by) REFERENCES staff_users(user_id)
);

-- Performance metrics table
CREATE TABLE performance_metrics (
    metric_id INT PRIMARY KEY AUTO_INCREMENT,
    metric_name VARCHAR(100) NOT NULL,
    metric_category ENUM('response_time', 'resolution_rate', 'budget_utilization', 'service_delivery', 'citizen_satisfaction') NOT NULL,
    metric_value DECIMAL(10, 2) NOT NULL,
    metric_unit VARCHAR(20),
    target_value DECIMAL(10, 2),
    measurement_date DATE NOT NULL,
    period_type ENUM('daily', 'weekly', 'monthly', 'quarterly', 'annual') NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (metric_name, measurement_date, period_type) UNIQUE (metric_name, measurement_date, period_type)
);

-- ========================================
-- 7. SYSTEM LOGS AND AUDIT
-- ========================================

-- Activity logs table
CREATE TABLE activity_logs (
    log_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    action_type VARCHAR(50) NOT NULL,
    table_name VARCHAR(50),
    record_id INT,
    old_values JSON,
    new_values JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES staff_users(user_id)
);

-- System notifications table
CREATE TABLE system_notifications (
    notification_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    notification_type ENUM('info', 'warning', 'error', 'success') DEFAULT 'info',
    related_table VARCHAR(50),
    related_record_id INT,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES staff_users(user_id) ON DELETE CASCADE
);

-- ========================================
-- INDEXES FOR PERFORMANCE
-- ========================================

-- User management indexes
CREATE INDEX idx_staff_users_username ON staff_users(username);
CREATE INDEX idx_staff_users_email ON staff_users(email);
CREATE INDEX idx_staff_users_role ON staff_users(role);
CREATE INDEX idx_user_sessions_user_id ON user_sessions(user_id);
CREATE INDEX idx_user_sessions_expires_at ON user_sessions(expires_at);

-- Road infrastructure indexes
CREATE INDEX idx_roads_name ON roads(road_name);
CREATE INDEX idx_roads_type ON roads(road_type);
CREATE INDEX idx_roads_condition ON roads(condition_rating);
CREATE INDEX idx_road_incidents_road_id ON road_incidents(road_id);
CREATE INDEX idx_road_incidents_status ON road_incidents(status);
CREATE INDEX idx_road_incidents_type ON road_incidents(incident_type);
CREATE INDEX idx_road_incidents_severity ON road_incidents(severity_level);
CREATE INDEX idx_road_incidents_date ON road_incidents(incident_date);
CREATE INDEX idx_incident_photos_incident_id ON incident_photos(incident_id);

-- Verification workflow indexes
CREATE INDEX idx_verification_requests_incident ON verification_requests(incident_id);
CREATE INDEX idx_verification_requests_status ON verification_requests(status);
CREATE INDEX idx_verification_requests_priority ON verification_requests(priority_level);
CREATE INDEX idx_verification_requests_verifier ON verification_requests(assigned_verifier);
CREATE INDEX idx_verification_timeline_request ON verification_timeline(request_id);

-- Maintenance indexes
CREATE INDEX idx_maintenance_schedules_road ON maintenance_schedules(road_id);
CREATE INDEX idx_maintenance_schedules_status ON maintenance_schedules(status);
CREATE INDEX idx_maintenance_schedules_date ON maintenance_schedules(scheduled_date);
CREATE INDEX idx_work_orders_assigned ON work_orders(assigned_to);
CREATE INDEX idx_work_orders_status ON work_orders(status);
CREATE INDEX idx_work_orders_due_date ON work_orders(due_date);

-- Transparency indexes
CREATE INDEX idx_public_documents_type ON public_documents(document_type);
CREATE INDEX idx_public_documents_date ON public_documents(publication_date);
CREATE INDEX idx_budget_allocations_year ON budget_allocations(fiscal_year);
CREATE INDEX idx_budget_allocations_category ON budget_allocations(category);
CREATE INDEX idx_projects_status ON projects(status);
CREATE INDEX idx_projects_manager ON projects(project_manager);

-- Reports and analytics indexes
CREATE INDEX idx_generated_reports_type ON generated_reports(report_type);
CREATE INDEX idx_generated_reports_category ON generated_reports(report_category);
CREATE INDEX idx_generated_reports_date ON generated_reports(generation_date);
CREATE INDEX idx_performance_metrics_name_date ON performance_metrics(metric_name, measurement_date);
CREATE INDEX idx_performance_metrics_category ON performance_metrics(metric_category);

-- System logs indexes
CREATE INDEX idx_activity_logs_user ON activity_logs(user_id);
CREATE INDEX idx_activity_logs_timestamp ON activity_logs(timestamp);
CREATE INDEX idx_activity_logs_action ON activity_logs(action_type);
CREATE INDEX idx_system_notifications_user ON system_notifications(user_id);
CREATE INDEX idx_system_notifications_read ON system_notifications(is_read);
CREATE INDEX idx_system_notifications_created ON system_notifications(created_at);

-- ========================================
-- VIEWS FOR COMMON QUERIES
-- ========================================

-- View for active incidents summary
CREATE VIEW active_incidents_summary AS
SELECT 
    ri.incident_id,
    ri.title,
    ri.incident_type,
    ri.severity_level,
    ri.status,
    ri.incident_date,
    r.road_name,
    r.location_description,
    CONCAT(su.first_name, ' ', su.last_name) AS assigned_staff_name
FROM road_incidents ri
LEFT JOIN roads r ON ri.road_id = r.road_id
LEFT JOIN staff_users su ON ri.assigned_staff_id = su.user_id
WHERE ri.status IN ('pending', 'under_review', 'approved', 'in_progress');

-- View for verification workload
CREATE VIEW verification_workload AS
SELECT 
    su.user_id,
    CONCAT(su.first_name, ' ', su.last_name) AS staff_name,
    COUNT(vr.request_id) AS total_requests,
    SUM(CASE WHEN vr.status = 'pending' THEN 1 ELSE 0 END) AS pending_requests,
    SUM(CASE WHEN vr.status = 'in_review' THEN 1 ELSE 0 END) AS in_review_requests,
    SUM(CASE WHEN vr.status = 'approved' THEN 1 ELSE 0 END) AS approved_requests,
    SUM(CASE WHEN vr.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_requests
FROM staff_users su
LEFT JOIN verification_requests vr ON su.user_id = vr.assigned_verifier
WHERE su.role IN ('supervisor', 'staff')
GROUP BY su.user_id, su.first_name, su.last_name;

-- View for budget utilization
CREATE VIEW budget_utilization AS
SELECT 
    ba.allocation_id,
    ba.fiscal_year,
    ba.category,
    ba.allocated_amount,
    ba.spent_amount,
    ba.remaining_amount,
    ROUND((ba.spent_amount / ba.allocated_amount) * 100, 2) AS utilization_percentage
FROM budget_allocations ba
WHERE ba.is_active = TRUE;

-- View for project progress
CREATE VIEW project_progress_summary AS
SELECT 
    p.project_id,
    p.project_name,
    p.project_code,
    p.status,
    p.progress_percentage,
    p.total_budget,
    p.amount_spent,
    p.start_date,
    p.planned_completion_date,
    p.actual_completion_date,
    CONCAT(su.first_name, ' ', su.last_name) AS project_manager_name,
    DATEDIFF(p.planned_completion_date, CURDATE()) AS days_remaining
FROM projects p
LEFT JOIN staff_users su ON p.project_manager = su.user_id;

-- ========================================
-- STORED PROCEDURES
-- ========================================

DELIMITER //

-- Procedure to create new incident and verification request
CREATE PROCEDURE CreateIncidentWithVerification(
    IN p_road_id INT,
    IN p_incident_type VARCHAR(50),
    IN p_severity_level VARCHAR(20),
    IN p_title VARCHAR(200),
    IN p_description TEXT,
    IN p_reported_by VARCHAR(100),
    IN p_reporter_contact VARCHAR(20),
    IN p_latitude DECIMAL(10,8),
    IN p_longitude DECIMAL(11,8),
    IN p_verification_type VARCHAR(50),
    IN p_priority_level VARCHAR(20),
    IN p_requested_by INT
)
BEGIN
    DECLARE v_incident_id INT;
    
    -- Create incident
    INSERT INTO road_incidents (
        road_id, incident_type, severity_level, title, description,
        reported_by, reporter_contact, latitude, longitude
    ) VALUES (
        p_road_id, p_incident_type, p_severity_level, p_title, p_description,
        p_reported_by, p_reporter_contact, p_latitude, p_longitude
    );
    
    SET v_incident_id = LAST_INSERT_ID();
    
    -- Create verification request
    INSERT INTO verification_requests (
        incident_id, request_type, priority_level, title, description, requested_by
    ) VALUES (
        v_incident_id, p_verification_type, p_priority_level, p_title, p_description, p_requested_by
    );
    
    SELECT v_incident_id as incident_id, LAST_INSERT_ID() as verification_request_id;
END //

-- Procedure to update incident status and log activity
CREATE PROCEDURE UpdateIncidentStatus(
    IN p_incident_id INT,
    IN p_new_status VARCHAR(50),
    IN p_updated_by INT,
    IN p_notes TEXT
)
BEGIN
    DECLARE v_old_status VARCHAR(50);
    
    -- Get old status
    SELECT status INTO v_old_status FROM road_incidents WHERE incident_id = p_incident_id;
    
    -- Update incident
    UPDATE road_incidents 
    SET status = p_new_status, updated_at = CURRENT_TIMESTAMP
    WHERE incident_id = p_incident_id;
    
    -- Log activity
    INSERT INTO activity_logs (
        user_id, action_type, table_name, record_id, 
        old_values, new_values
    ) VALUES (
        p_updated_by, 'STATUS_UPDATE', 'road_incidents', p_incident_id,
        JSON_OBJECT('status', v_old_status),
        JSON_OBJECT('status', p_new_status)
    );
    
    -- Add timeline entry if verification exists
    IF EXISTS (SELECT 1 FROM verification_requests WHERE incident_id = p_incident_id) THEN
        INSERT INTO verification_timeline (
            request_id, action_type, action_by, action_notes
        ) VALUES (
            (SELECT request_id FROM verification_requests WHERE incident_id = p_incident_id LIMIT 1),
            CASE p_new_status
                WHEN 'approved' THEN 'approved'
                WHEN 'rejected' THEN 'rejected'
                WHEN 'in_progress' THEN 'review_started'
                ELSE 'updated'
            END,
            p_updated_by, p_notes
        );
    END IF;
END //

-- Procedure to generate monthly report
CREATE PROCEDURE GenerateMonthlyReport(
    IN p_report_month DATE,
    IN p_generated_by INT
)
BEGIN
    DECLARE v_report_id INT;
    
    -- Create report record
    INSERT INTO generated_reports (
        report_name, report_type, report_category, 
        date_from, date_to, generated_by, parameters
    ) VALUES (
        CONCAT('Monthly Road Infrastructure Report - ', DATE_FORMAT(p_report_month, '%M %Y')),
        'monthly', 'comprehensive',
        DATE_FORMAT(p_report_month, '%Y-%m-01'),
        LAST_DAY(p_report_month),
        p_generated_by,
        JSON_OBJECT('report_month', p_report_month)
    );
    
    SET v_report_id = LAST_INSERT_ID();
    
    -- This would typically generate a file and update the file_url
    -- For now, just return the report ID
    SELECT v_report_id as report_id;
END //

DELIMITER ;

-- ========================================
-- TRIGGERS FOR AUDIT
-- ========================================

DELIMITER //

-- Trigger for road incidents audit
CREATE TRIGGER road_incidents_audit_insert
AFTER INSERT ON road_incidents
FOR EACH ROW
BEGIN
    INSERT INTO activity_logs (
        user_id, action_type, table_name, record_id, new_values
    ) VALUES (
        NEW.assigned_staff_id, 'INSERT', 'road_incidents', NEW.incident_id,
        JSON_OBJECT(
            'road_id', NEW.road_id,
            'incident_type', NEW.incident_type,
            'severity_level', NEW.severity_level,
            'title', NEW.title,
            'status', NEW.status
        )
    );
END //

CREATE TRIGGER road_incidents_audit_update
AFTER UPDATE ON road_incidents
FOR EACH ROW
BEGIN
    INSERT INTO activity_logs (
        user_id, action_type, table_name, record_id, old_values, new_values
    ) VALUES (
        NEW.assigned_staff_id, 'UPDATE', 'road_incidents', NEW.incident_id,
        JSON_OBJECT(
            'status', OLD.status,
            'assigned_staff_id', OLD.assigned_staff_id
        ),
        JSON_OBJECT(
            'status', NEW.status,
            'assigned_staff_id', NEW.assigned_staff_id
        )
    );
END //

-- Trigger for verification requests audit
CREATE TRIGGER verification_requests_audit_insert
AFTER INSERT ON verification_requests
FOR EACH ROW
BEGIN
    INSERT INTO activity_logs (
        user_id, action_type, table_name, record_id, new_values
    ) VALUES (
        NEW.requested_by, 'INSERT', 'verification_requests', NEW.request_id,
        JSON_OBJECT(
            'incident_id', NEW.incident_id,
            'request_type', NEW.request_type,
            'priority_level', NEW.priority_level,
            'status', NEW.status
        )
    );
END //

DELIMITER ;

-- ========================================
-- SAMPLE DATA (Optional - for testing)
-- ========================================

-- Insert sample staff users
INSERT INTO staff_users (username, password_hash, email, first_name, last_name, role, phone) VALUES
('admin', '$2b$12$example_hash', 'admin@lgu.gov.ph', 'Juan', 'Dela Cruz', 'admin', '09123456789'),
('jsantos', '$2b$12$example_hash', 'jsantos@lgu.gov.ph', 'Maria', 'Santos', 'supervisor', '09123456788'),
('rreyes', '$2b$12$example_hash', 'rreyes@lgu.gov.ph', 'Roberto', 'Reyes', 'staff', '09123456787'),
('emartinez', '$2b$12$example_hash', 'emartinez@lgu.gov.ph', 'Elena', 'Martinez', 'technician', '09123456786');

-- Insert sample roads
INSERT INTO roads (road_name, road_type, location_description, latitude, longitude, length_km, condition_rating, traffic_volume) VALUES
('Highway 101', 'highway', 'Main north-south highway', 14.5995, 120.9842, 25.50, 'good', 'heavy'),
('Main Street', 'main_street', 'Central business district', 14.6095, 120.9742, 5.25, 'fair', 'moderate'),
('Oak Avenue', 'avenue', 'Residential area', 14.5895, 120.9942, 3.75, 'good', 'light'),
('Elm Street', 'secondary_street', 'Suburban area', 14.5795, 120.9642, 2.10, 'excellent', 'light');

-- Insert sample budget allocation
INSERT INTO budget_allocations (fiscal_year, category, allocated_amount, description) VALUES
(2024, 'maintenance', 50000000.00, 'Annual road maintenance budget'),
(2024, 'construction', 75000000.00, 'Road construction and rehabilitation'),
(2024, 'operations', 25000000.00, 'Department operational expenses');

-- ========================================
-- DATABASE SETUP COMPLETE
-- ========================================

-- Show summary
SELECT 'LGU Road & Infrastructure Database Setup Complete' as status;
SELECT COUNT(*) as total_tables FROM information_schema.tables 
WHERE table_schema = 'lgu_road_infrastructure';
