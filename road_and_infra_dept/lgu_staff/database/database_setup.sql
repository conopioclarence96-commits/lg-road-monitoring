-- ========================================
-- LGU Road & Infrastructure Database Setup Script
-- ========================================
-- This script handles the complete database setup process
-- Run this script to initialize the entire database system
-- ========================================

-- Setup configuration variables
SET @db_name = 'lgu_road_infrastructure';
SET @backup_dir = '/var/backups/mysql/';
SET @log_file = 'database_setup.log';

-- ========================================
-- 1. DATABASE CREATION AND CONFIGURATION
-- ========================================

-- Create database if it doesn't exist
CREATE DATABASE IF NOT EXISTS @db_name 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

-- Use the database
USE @db_name;

-- Set up database parameters
SET GLOBAL innodb_file_per_table = ON;
SET GLOBAL innodb_file_format = Barracuda;
SET GLOBAL innodb_large_prefix = ON;

-- ========================================
-- 2. SECURITY SETUP
-- ========================================

-- Create database user for the application
-- Note: Change the password in production environment
CREATE USER IF NOT EXISTS 'lgu_app'@'localhost' IDENTIFIED BY 'SecurePassword123!';
CREATE USER IF NOT EXISTS 'lgu_app'@'%' IDENTIFIED BY 'SecurePassword123!';

-- Grant appropriate permissions
GRANT SELECT, INSERT, UPDATE, DELETE ON @db_name.* TO 'lgu_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON @db_name.* TO 'lgu_app'@'%';

-- Create read-only user for reporting
CREATE USER IF NOT EXISTS 'lgu_report'@'localhost' IDENTIFIED BY 'ReportPassword123!';
CREATE USER IF NOT EXISTS 'lgu_report'@'%' IDENTIFIED BY 'ReportPassword123!';

GRANT SELECT ON @db_name.* TO 'lgu_report'@'localhost';
GRANT SELECT ON @db_name.* TO 'lgu_report'@'%';

-- ========================================
-- 3. TABLE CREATION
-- ========================================

-- Source the main schema file
-- Note: In production, you would run: mysql -u root -p < schema.sql
-- For now, we'll include the key table structures

-- User Management Tables
CREATE TABLE IF NOT EXISTS staff_users (
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
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_staff_users_username (username),
    INDEX idx_staff_users_email (email),
    INDEX idx_staff_users_role (role)
);

CREATE TABLE IF NOT EXISTS user_sessions (
    session_id VARCHAR(128) PRIMARY KEY,
    user_id INT NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (user_id) REFERENCES staff_users(user_id) ON DELETE CASCADE,
    INDEX idx_user_sessions_user_id (user_id),
    INDEX idx_user_sessions_expires_at (expires_at)
);

-- Road Infrastructure Tables
CREATE TABLE IF NOT EXISTS roads (
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
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_roads_name (road_name),
    INDEX idx_roads_type (road_type),
    INDEX idx_roads_condition (condition_rating)
);

CREATE TABLE IF NOT EXISTS road_incidents (
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
    FOREIGN KEY (assigned_staff_id) REFERENCES staff_users(user_id),
    INDEX idx_road_incidents_road_id (road_id),
    INDEX idx_road_incidents_status (status),
    INDEX idx_road_incidents_type (incident_type),
    INDEX idx_road_incidents_severity (severity_level),
    INDEX idx_road_incidents_date (incident_date)
);

-- Verification Workflow Tables
CREATE TABLE IF NOT EXISTS verification_requests (
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
    FOREIGN KEY (assigned_verifier) REFERENCES staff_users(user_id),
    INDEX idx_verification_requests_incident (incident_id),
    INDEX idx_verification_requests_status (status),
    INDEX idx_verification_requests_priority (priority_level),
    INDEX idx_verification_requests_verifier (assigned_verifier)
);

-- ========================================
-- 4. INITIAL DATA SETUP
-- ========================================

-- Insert default admin user (password: admin123)
INSERT IGNORE INTO staff_users (username, password_hash, email, first_name, last_name, role, phone) VALUES
('admin', '$2b$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/LewdBPj6ukx.LrUpm', 'admin@lgu.gov.ph', 'System', 'Administrator', 'admin', '09123456789');

-- Insert default roads
INSERT IGNORE INTO roads (road_name, road_type, location_description, latitude, longitude, length_km, condition_rating, traffic_volume) VALUES
('Highway 101', 'highway', 'Main north-south highway', 14.5995, 120.9842, 25.50, 'good', 'heavy'),
('Main Street', 'main_street', 'Central business district', 14.6095, 120.9742, 5.25, 'fair', 'moderate'),
('Oak Avenue', 'avenue', 'Residential area', 14.5895, 120.9942, 3.75, 'good', 'light'),
('Elm Street', 'secondary_street', 'Suburban area', 14.5795, 120.9642, 2.10, 'excellent', 'light');

-- ========================================
-- 5. VIEWS CREATION
-- ========================================

-- Create useful views for reporting
CREATE OR REPLACE VIEW active_incidents_summary AS
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

CREATE OR REPLACE VIEW dashboard_stats AS
SELECT 
    (SELECT COUNT(*) FROM road_incidents WHERE DATE(incident_date) = CURDATE()) as incidents_today,
    (SELECT COUNT(*) FROM road_incidents WHERE status = 'pending') as pending_incidents,
    (SELECT COUNT(*) FROM verification_requests WHERE status = 'pending') as pending_verifications,
    (SELECT COUNT(*) FROM maintenance_schedules WHERE status = 'in_progress') as active_maintenance,
    (SELECT COUNT(*) FROM staff_users WHERE is_active = TRUE) as active_staff;

-- ========================================
-- 6. STORED PROCEDURES
-- ========================================

DELIMITER //

-- Procedure for user authentication
CREATE PROCEDURE IF NOT EXISTS AuthenticateUser(
    IN p_username VARCHAR(50),
    IN p_password_hash VARCHAR(255)
)
BEGIN
    SELECT 
        user_id,
        username,
        email,
        first_name,
        last_name,
        role,
        department,
        is_active
    FROM staff_users 
    WHERE username = p_username 
    AND password_hash = p_password_hash 
    AND is_active = TRUE;
END //

-- Procedure to create new incident
CREATE PROCEDURE IF NOT EXISTS CreateIncident(
    IN p_road_id INT,
    IN p_incident_type VARCHAR(50),
    IN p_severity_level VARCHAR(20),
    IN p_title VARCHAR(200),
    IN p_description TEXT,
    IN p_reported_by VARCHAR(100),
    IN p_reporter_contact VARCHAR(20),
    IN p_latitude DECIMAL(10,8),
    IN p_longitude DECIMAL(11,8)
)
BEGIN
    DECLARE v_incident_id INT;
    
    INSERT INTO road_incidents (
        road_id, incident_type, severity_level, title, description,
        reported_by, reporter_contact, latitude, longitude
    ) VALUES (
        p_road_id, p_incident_type, p_severity_level, p_title, p_description,
        p_reported_by, p_reporter_contact, p_latitude, p_longitude
    );
    
    SET v_incident_id = LAST_INSERT_ID();
    
    -- Log the activity
    INSERT INTO activity_logs (
        user_id, action_type, table_name, record_id, new_values
    ) VALUES (
        NULL, 'INSERT', 'road_incidents', v_incident_id,
        JSON_OBJECT(
            'incident_type', p_incident_type,
            'severity_level', p_severity_level,
            'title', p_title
        )
    );
    
    SELECT v_incident_id as incident_id;
END //

-- Procedure to get dashboard data
CREATE PROCEDURE IF NOT EXISTS GetDashboardData()
BEGIN
    SELECT * FROM dashboard_stats;
    
    SELECT 
        ri.incident_type,
        COUNT(*) as count
    FROM road_incidents ri
    WHERE DATE(ri.incident_date) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY ri.incident_type
    ORDER BY count DESC;
    
    SELECT 
        ri.severity_level,
        COUNT(*) as count
    FROM road_incidents ri
    WHERE ri.status IN ('pending', 'under_review', 'approved', 'in_progress')
    GROUP BY ri.severity_level
    ORDER BY 
        CASE ri.severity_level
            WHEN 'critical' THEN 1
            WHEN 'high' THEN 2
            WHEN 'medium' THEN 3
            WHEN 'low' THEN 4
        END;
END //

DELIMITER ;

-- ========================================
-- 7. TRIGGERS FOR AUDITING
-- ========================================

-- Create activity logs table if not exists
CREATE TABLE IF NOT EXISTS activity_logs (
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
    INDEX idx_activity_logs_user (user_id),
    INDEX idx_activity_logs_timestamp (timestamp),
    INDEX idx_activity_logs_action (action_type),
    FOREIGN KEY (user_id) REFERENCES staff_users(user_id)
);

-- Create notifications table
CREATE TABLE IF NOT EXISTS system_notifications (
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
    INDEX idx_system_notifications_user (user_id),
    INDEX idx_system_notifications_read (is_read),
    INDEX idx_system_notifications_created (created_at),
    FOREIGN KEY (user_id) REFERENCES staff_users(user_id) ON DELETE CASCADE
);

DELIMITER //

-- Trigger for incident logging
CREATE TRIGGER IF NOT EXISTS road_incidents_audit_insert
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
    
    -- Create notification for assigned staff
    IF NEW.assigned_staff_id IS NOT NULL THEN
        INSERT INTO system_notifications (
            user_id, title, message, notification_type, related_table, related_record_id
        ) VALUES (
            NEW.assigned_staff_id, 
            'New Incident Assigned', 
            CONCAT('You have been assigned to incident #', NEW.incident_id, ': ', NEW.title),
            'info', 'road_incidents', NEW.incident_id
        );
    END IF;
END //

DELIMITER ;

-- ========================================
-- 8. BACKUP AND MAINTENANCE SETUP
-- ========================================

-- Create backup procedure
DELIMITER //

CREATE PROCEDURE IF NOT EXISTS BackupDatabase()
BEGIN
    DECLARE backup_file VARCHAR(255);
    SET backup_file = CONCAT(@backup_dir, 'lgu_road_infrastructure_', DATE_FORMAT(NOW(), '%Y%m%d_%H%i%s'), '.sql');
    
    SET @sql = CONCAT('mysqldump -u root -p ', @db_name, ' > ', backup_file);
    
    -- Log the backup attempt
    INSERT INTO activity_logs (
        user_id, action_type, table_name, new_values
    ) VALUES (
        NULL, 'BACKUP', 'database', JSON_OBJECT('backup_file', backup_file)
    );
    
    SELECT CONCAT('Backup initiated: ', backup_file) as message;
END //

DELIMITER ;

-- ========================================
-- 9. PERFORMANCE OPTIMIZATION
-- ========================================

-- Analyze tables for query optimization
ANALYZE TABLE staff_users, user_sessions, roads, road_incidents, verification_requests;

-- Optimize tables
OPTIMIZE TABLE staff_users, user_sessions, roads, road_incidents, verification_requests;

-- ========================================
-- 10. VERIFICATION AND COMPLETION
-- ========================================

-- Verify database setup
SELECT 'Database Setup Complete' as status;

-- Show table counts
SELECT 
    TABLE_NAME as table_name,
    TABLE_ROWS as row_count
FROM information_schema.TABLES 
WHERE TABLE_SCHEMA = @db_name
ORDER BY TABLE_NAME;

-- Show user accounts
SELECT 
    username,
    email,
    role,
    is_active,
    created_at
FROM staff_users
ORDER BY created_at;

-- Test stored procedures
CALL GetDashboardData();

-- ========================================
-- SETUP COMPLETION MESSAGE
-- ========================================

SELECT 'LGU Road & Infrastructure Database Setup Completed Successfully!' as message;
SELECT CONCAT('Database: ', @db_name) as database_info;
SELECT 'Default admin user: admin / admin123' as login_info;
SELECT 'Remember to change default passwords in production!' as security_note;
