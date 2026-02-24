-- =====================================================
-- LGU Road Monitoring System - Complete Database Setup
-- =====================================================
-- This file combines all database schema and data files
-- Created: 2026-02-24
-- Purpose: Complete database setup for LGU Road Monitoring System

-- =====================================================
-- 1. MAIN DATABASE SCHEMA
-- =====================================================

-- Users Table
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) UNIQUE NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `email` VARCHAR(100) UNIQUE NOT NULL,
  `full_name` VARCHAR(100) NOT NULL,
  `role` ENUM('admin', 'staff', 'verifier') DEFAULT 'staff',
  `department` VARCHAR(50),
  `is_active` BOOLEAN DEFAULT TRUE,
  `last_login` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_username` (`username`),
  INDEX `idx_email` (`email`),
  INDEX `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit Trails Table
CREATE TABLE IF NOT EXISTS `audit_trails` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT,
  `action` VARCHAR(255) NOT NULL,
  `details` TEXT,
  `ip_address` VARCHAR(45),
  `user_agent` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_action` (`action`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit Logs Table
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT,
  `action` VARCHAR(100) NOT NULL,
  `table_name` VARCHAR(50),
  `record_id` INT,
  `old_values` JSON,
  `new_values` JSON,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_action` (`action`),
  INDEX `idx_table_record` (`table_name`, `record_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 2. ROAD TRANSPORTATION REPORTS TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS `road_transportation_reports` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `report_id` VARCHAR(50) UNIQUE NOT NULL,
  `report_type` ENUM('monthly', 'traffic', 'maintenance', 'safety', 'budget', 'road_damage', 'traffic_violation', 'infrastructure_issue', 'maintenance_request') NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `department` ENUM('engineering', 'planning', 'maintenance', 'finance') NOT NULL,
  `priority` ENUM('high', 'medium', 'low') DEFAULT 'medium',
  `status` ENUM('pending', 'in-progress', 'completed', 'cancelled', 'approved', 'rejected') DEFAULT 'pending',
  `created_date` DATE NOT NULL,
  `due_date` DATE,
  `description` TEXT,
  `location` VARCHAR(255),
  `latitude` DECIMAL(10,8) NULL,
  `longitude` DECIMAL(11,8) NULL,
  `reporter_name` VARCHAR(100),
  `reporter_email` VARCHAR(100),
  `severity` ENUM('low', 'medium', 'high', 'critical'),
  `reported_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `resolved_date` TIMESTAMP NULL,
  `assigned_to` VARCHAR(100),
  `resolution_notes` TEXT,
  `attachments` JSON,
  `id_file_path` VARCHAR(255),
  `created_by` INT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_report_id` (`report_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_report_type` (`report_type`),
  INDEX `idx_priority` (`priority`),
  INDEX `idx_created_date` (`created_date`),
  INDEX `idx_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 3. ROAD MAINTENANCE REPORTS TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS `road_maintenance_reports` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `report_id` VARCHAR(50) UNIQUE NOT NULL,
  `report_type` ENUM('routine', 'emergency', 'preventive', 'corrective', 'scheduled') NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `department` ENUM('engineering', 'planning', 'maintenance', 'finance') NOT NULL,
  `priority` ENUM('high', 'medium', 'low') DEFAULT 'medium',
  `status` ENUM('pending', 'in-progress', 'completed', 'cancelled') DEFAULT 'pending',
  `created_date` DATE NOT NULL,
  `due_date` DATE,
  `description` TEXT,
  `location` VARCHAR(255),
  `estimated_cost` DECIMAL(10,2),
  `actual_cost` DECIMAL(10,2),
  `maintenance_team` VARCHAR(100),
  `id_file_path` VARCHAR(255),
  `created_by` INT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_report_id` (`report_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_report_type` (`report_type`),
  INDEX `idx_priority` (`priority`),
  INDEX `idx_created_date` (`created_date`),
  INDEX `idx_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 4. DOCUMENT MANAGEMENT TABLES
-- =====================================================

-- Documents Table
CREATE TABLE IF NOT EXISTS `documents` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `file_path` VARCHAR(255) NOT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `file_size` INT,
  `file_type` VARCHAR(100),
  `category` VARCHAR(100),
  `department` VARCHAR(50),
  `uploaded_by` INT,
  `is_public` BOOLEAN DEFAULT FALSE,
  `download_count` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_title` (`title`),
  INDEX `idx_category` (`category`),
  INDEX `idx_department` (`department`),
  INDEX `idx_uploaded_by` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Document Views Table
CREATE TABLE IF NOT EXISTS `document_views` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `document_id` INT NOT NULL,
  `user_id` INT,
  `viewed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `ip_address` VARCHAR(45),
  FOREIGN KEY (`document_id`) REFERENCES `documents`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_document_id` (`document_id`),
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_viewed_at` (`viewed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Document Downloads Table
CREATE TABLE IF NOT EXISTS `document_downloads` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `document_id` INT NOT NULL,
  `user_id` INT,
  `downloaded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `ip_address` VARCHAR(45),
  FOREIGN KEY (`document_id`) REFERENCES `documents`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_document_id` (`document_id`),
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_downloaded_at` (`downloaded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 5. BUDGET ALLOCATION TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS `budget_allocation` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `fiscal_year` VARCHAR(10) NOT NULL,
  `department` VARCHAR(50) NOT NULL,
  `allocated_amount` DECIMAL(15,2) NOT NULL,
  `spent_amount` DECIMAL(15,2) DEFAULT 0.00,
  `remaining_amount` DECIMAL(15,2) GENERATED ALWAYS AS (allocated_amount - spent_amount) STORED,
  `description` TEXT,
  `created_by` INT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_fiscal_year` (`fiscal_year`),
  INDEX `idx_department` (`department`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 6. INFRASTRUCTURE PROJECTS TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS `infrastructure_projects` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `project_name` VARCHAR(255) NOT NULL,
  `project_code` VARCHAR(50) UNIQUE NOT NULL,
  `description` TEXT,
  `location` VARCHAR(255),
  `department` VARCHAR(50),
  `budget_allocation_id` INT,
  `estimated_cost` DECIMAL(15,2),
  `actual_cost` DECIMAL(15,2) DEFAULT 0.00,
  `status` ENUM('planning', 'ongoing', 'completed', 'suspended', 'cancelled') DEFAULT 'planning',
  `start_date` DATE,
  `completion_date` DATE,
  `contractor` VARCHAR(255),
  `progress_percentage` DECIMAL(5,2) DEFAULT 0.00,
  `created_by` INT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`budget_allocation_id`) REFERENCES `budget_allocation`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_project_code` (`project_code`),
  INDEX `idx_status` (`status`),
  INDEX `idx_department` (`department`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 7. SAMPLE DATA
-- =====================================================

-- Default Admin User
INSERT INTO `users` (`username`, `password`, `email`, `full_name`, `role`, `department`) VALUES 
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@lgu.gov.ph', 'System Administrator', 'admin', 'IT'),
('lgu_staff', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'staff@lgu.gov.ph', 'LGU Staff', 'staff', 'Engineering'),
('verifier', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'verifier@lgu.gov.ph', 'Report Verifier', 'verifier', 'Planning');

-- Sample Budget Allocation
INSERT INTO `budget_allocation` (`fiscal_year`, `department`, `allocated_amount`, `description`, `created_by`) VALUES
('2024', 'Engineering', 50000000.00, 'Annual road maintenance and infrastructure budget', 1),
('2024', 'Planning', 15000000.00, 'Urban planning and development budget', 1),
('2024', 'Maintenance', 25000000.00, 'Routine maintenance operations budget', 1);

-- Sample Infrastructure Projects
INSERT INTO `infrastructure_projects` (`project_name`, `project_code`, `description`, `location`, `department`, `budget_allocation_id`, `estimated_cost`, `status`, `start_date`, `completion_date`, `contractor`, `created_by`) VALUES
('Main Street Rehabilitation', 'PRJ-2024-001', 'Complete rehabilitation of Main Street including resurfacing and drainage improvements', 'Main Street, Downtown District', 'Engineering', 1, 15000000.00, 'ongoing', '2024-01-15', '2024-06-30', 'Highway Construction Corp', 1),
('Bridge Maintenance Program', 'PRJ-2024-002', 'Annual inspection and maintenance of all city bridges', 'All City Bridges', 'Engineering', 1, 8000000.00, 'planning', '2024-03-01', '2024-12-31', 'Bridge Maintenance Services', 1),
('Traffic Signal Upgrade', 'PRJ-2024-003', 'Installation of modern traffic signal systems', 'Major Intersections', 'Planning', 2, 5000000.00, 'completed', '2024-01-01', '2024-02-28', 'Traffic Solutions Inc', 1);

-- Sample Documents
INSERT INTO `documents` (`title`, `description`, `file_path`, `file_name`, `file_type`, `category`, `department`, `uploaded_by`, `is_public`) VALUES
('Annual Road Report 2024', 'Comprehensive annual report on road conditions and maintenance activities', 'documents/annual_road_report_2024.pdf', 'annual_road_report_2024.pdf', 'application/pdf', 'Reports', 'Engineering', 1, TRUE),
('Maintenance Schedule Q1', 'Quarterly maintenance schedule for Q1 2024', 'documents/maintenance_schedule_q1.pdf', 'maintenance_schedule_q1.pdf', 'application/pdf', 'Schedules', 'Maintenance', 2, TRUE),
('Budget Allocation 2024', 'Detailed budget allocation for fiscal year 2024', 'documents/budget_allocation_2024.xlsx', 'budget_allocation_2024.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Budget', 'Finance', 1, FALSE);

-- Sample Transportation Reports
INSERT INTO `road_transportation_reports` (report_id, report_type, title, department, priority, status, created_date, description, location, latitude, longitude, reporter_name, reporter_email, created_by, created_at) VALUES
('RTR-2024-001', 'infrastructure_issue', 'Street Light Maintenance', 'engineering', 'medium', 'pending', CURDATE(), 'Multiple street lights reported as non-functional along Main Street', 'Main Street, Downtown District', 14.5995, 120.9842, 'Juan Santos', 'juan.santos@lgu.gov.ph', 1, NOW()),
('RTR-2024-002', 'infrastructure_issue', 'Traffic Accident on Highway 1', 'engineering', 'high', 'completed', DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'Multi-vehicle accident reported on Highway 1 near KM 45', 'Highway 1, KM 45', 14.6123, 120.9765, 'Maria Reyes', 'maria.reyes@lgu.gov.ph', 1, DATE_SUB(NOW(), INTERVAL 2 DAY)),
('RTR-2024-003', 'infrastructure_issue', 'Road Damage Report', 'engineering', 'high', 'in-progress', DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'Large pothole reported on Main Street causing traffic disruption', 'Main Street, Commercial District', 14.6052, 120.9823, 'Roberto dela Cruz', 'roberto.delacruz@lgu.gov.ph', 1, DATE_SUB(NOW(), INTERVAL 1 DAY)),
('RTR-2024-004', 'maintenance_request', 'Bridge Inspection Required', 'engineering', 'high', 'pending', CURDATE(), 'Quarterly bridge inspection scheduled for City Bridge #3', 'City Bridge #3', 14.6089, 120.9791, 'Engr. Juan Santos', 'juan.santos@lgu.gov.ph', 1, NOW());

-- Sample Maintenance Reports
INSERT INTO `road_maintenance_reports` (report_id, report_type, title, department, priority, status, created_date, description, location, created_by, created_at) VALUES
('MNT-2024-001', 'emergency', 'Pothole Repair Request', 'maintenance', 'high', 'in-progress', DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'Large pothole causing traffic disruption on Avenue Street', 'Avenue Street, Commercial District', 1, DATE_SUB(NOW(), INTERVAL 1 DAY)),
('MNT-2024-002', 'routine', 'Road Surface Inspection', 'maintenance', 'low', 'pending', CURDATE(), 'Routine inspection needed for road surface conditions', 'National Highway, Section 2', 1, NOW()),
('MNT-2024-003', 'preventive', 'Drainage Cleaning', 'maintenance', 'medium', 'completed', DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'Preventive maintenance for drainage systems before rainy season', 'All Districts', 1, DATE_SUB(NOW(), INTERVAL 3 DAY)),
('MNT-2024-004', 'corrective', 'Street Light Repair', 'maintenance', 'medium', 'pending', CURDATE(), 'Corrective maintenance for damaged street light fixtures', 'Residential Area, District 2', 1, NOW());

-- =====================================================
-- 8. SAMPLE AUDIT LOGS
-- =====================================================

INSERT INTO `audit_trails` (`user_id`, `action`, `details`, `ip_address`, `user_agent`) VALUES
(1, 'User Login', 'Admin user logged in successfully', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'),
(2, 'Report Created', 'Created new road transportation report: RTR-2024-001', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'),
(1, 'Budget Updated', 'Updated budget allocation for Engineering department', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'),
(3, 'Report Verified', 'Verified and approved report: RTR-2024-002', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');

-- =====================================================
-- 9. FINAL SETUP
-- =====================================================

-- Create indexes for better performance
CREATE INDEX IF NOT EXISTS idx_transport_reports_composite ON road_transportation_reports(status, priority, created_date);
CREATE INDEX IF NOT EXISTS idx_maintenance_reports_composite ON road_maintenance_reports(status, priority, created_date);
CREATE INDEX IF NOT EXISTS idx_documents_composite ON documents(category, department, created_at);
CREATE INDEX IF NOT EXISTS idx_projects_composite ON infrastructure_projects(status, department, start_date);

-- Create views for common queries
CREATE OR REPLACE VIEW v_pending_reports AS
SELECT 'transportation' as report_type, id, report_id, title, department, priority, status, created_date, location, reporter_name, created_at
FROM road_transportation_reports WHERE status = 'pending'
UNION ALL
SELECT 'maintenance' as report_type, id, report_id, title, department, priority, status, created_date, location, maintenance_team as reporter_name, created_at
FROM road_maintenance_reports WHERE status = 'pending';

CREATE OR REPLACE VIEW v_completed_projects AS
SELECT project_name, project_code, location, department, actual_cost, completion_date
FROM infrastructure_projects WHERE status = 'completed';

-- =====================================================
-- COMPLETED SUCCESSFULLY
-- =====================================================
-- Database setup complete!
-- Total tables created: 11
-- Total sample records inserted: 25+
-- Views created: 2
-- Indexes created: 15+

-- Next steps:
-- 1. Verify all tables exist: SHOW TABLES;
-- 2. Check sample data: SELECT COUNT(*) FROM users;
-- 3. Test application functionality
-- =====================================================
