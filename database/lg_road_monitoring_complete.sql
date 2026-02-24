-- =====================================================
-- LGU ROAD MONITORING SYSTEM - COMPLETE DATABASE DUMP
-- =====================================================
-- Generated: February 23, 2026
-- Database: lg_road_monitoring
-- Purpose: Complete database import for phpMyAdmin
-- =====================================================

-- Drop database if exists (uncomment if needed)
-- DROP DATABASE IF EXISTS lg_road_monitoring;

-- Create database
CREATE DATABASE IF NOT EXISTS lg_road_monitoring;
USE lg_road_monitoring;

-- =====================================================
-- SET CONFIGURATION
-- =====================================================
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = '+00:00';

-- =====================================================
-- TABLE: USERS
-- =====================================================
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) UNIQUE NOT NULL,
  `email` VARCHAR(100) UNIQUE NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(100) NOT NULL,
  `role` ENUM('system_admin', 'lgu_staff', 'citizen') DEFAULT 'citizen',
  `department` VARCHAR(50),
  `is_active` BOOLEAN DEFAULT TRUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_email` (`email`),
  INDEX `idx_role` (`role`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert sample users (Passwords: Test@1234)
INSERT IGNORE INTO `users` (`username`, `email`, `password`, `full_name`, `role`, `department`, `is_active`) VALUES
('admin', 'admin@lgu.gov.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'system_admin', 'System Administration', 1),
('jsantos', 'jsantos@lgu.gov.ph', '$2y$10$LmhglHAY63tmCwfBI7q0AO9DTFQU.6OWcKuSqzlAEtIlcVZRLyqF2', 'Engr. Juan Santos', 'lgu_staff', 'LGU Services', 1),
('mreyes', 'mreyes@lgu.gov.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Maria Reyes', 'citizen', 'Citizen Services', 1),
('rdela', 'rdela@lgu.gov.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Roberto dela Cruz', 'citizen', 'Citizen Services', 1);

-- =====================================================
-- TABLE: AUDIT_TRAILS
-- =====================================================
CREATE TABLE IF NOT EXISTS `audit_trails` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `audit_id` VARCHAR(20) UNIQUE NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `audit_type` ENUM('infrastructure', 'safety', 'compliance', 'quality') NOT NULL,
  `status` ENUM('pending', 'approved', 'rejected', 'completed') DEFAULT 'pending',
  `auditor` VARCHAR(100) NOT NULL,
  `reviewer` VARCHAR(100),
  `description` TEXT,
  `findings` TEXT,
  `recommendations` TEXT,
  `location` VARCHAR(255),
  `priority` ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `review_date` TIMESTAMP NULL,
  INDEX `idx_audit_id` (`audit_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_audit_type` (`audit_type`),
  INDEX `idx_priority` (`priority`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert sample audit trails
INSERT INTO `audit_trails` (`audit_id`, `title`, `audit_type`, `status`, `auditor`, `reviewer`, `description`, `findings`, `recommendations`, `location`, `priority`, `review_date`) VALUES
('AUD-2024-045', 'Road Infrastructure Audit Completed', 'infrastructure', 'approved', 'Engr. Juan Santos', 'Engineering Director', 'Comprehensive audit of Highway 101 infrastructure completed. All 45 inspection points verified and certified compliant with LGU standards. Minor issues identified and scheduled for maintenance.', 'All structural components meet safety standards. Minor surface cracks detected on 3 sections.', 'Schedule maintenance for identified crack sections within 30 days. Continue regular monitoring.', 'Highway 101, Main District', 'medium', '2024-02-15 14:30:00'),
('AUD-2024-044', 'Bridge Safety Audit - Non-Compliant', 'safety', 'rejected', 'Maria Reyes', 'Safety Committee', 'North District Bridge safety audit failed compliance check. Structural integrity issues identified requiring immediate attention. Bridge rated as high-risk and recommended for closure pending repairs.', 'Critical structural deficiencies found in support beams. Corrosion detected in main cables.', 'Immediate closure recommended. Emergency repairs required within 7 days. Full structural assessment needed.', 'North District Bridge', 'critical', '2024-02-14 10:15:00'),
('AUD-2024-043', 'Traffic System Compliance Review', 'compliance', 'pending', 'Roberto dela Cruz', NULL, 'Traffic signal system compliance review in progress. 25 intersections evaluated for signal timing and functionality. Preliminary findings show 92% compliance rate.', '23 of 25 intersections fully compliant. 2 intersections have timing issues during peak hours.', 'Adjust signal timing at non-compliant intersections. Install additional sensors for better traffic flow management.', 'Central Business District', 'medium', NULL),
('AUD-2024-042', 'Drainage System Quality Audit', 'quality', 'completed', 'Quality Assurance Team', 'Quality Director', 'City drainage system quality audit completed. All 68 drainage points inspected with 95% pass rate. 3 points requiring immediate maintenance identified and work orders issued.', '65 drainage points fully functional. 3 points have blockages and debris accumulation.', 'Clear blockages immediately. Schedule regular cleaning every 3 months. Install trash screens at problem areas.', 'Citywide Drainage Network', 'low', '2024-02-12 11:20:00'),
('AUD-2024-041', 'Street Lighting Infrastructure Audit', 'infrastructure', 'approved', 'Engr. Juan Santos', 'Public Works Director', 'Comprehensive audit of street lighting infrastructure across 12 barangays. 85% compliance rate achieved with most issues being minor.', '1,200 of 1,410 street lights fully functional. 210 lights require maintenance or replacement.', 'Replace faulty bulbs and ballasts within 2 weeks. Upgrade to LED lighting for energy efficiency.', 'All Barangays', 'low', '2024-02-10 16:45:00'),
('AUD-2024-040', 'Road Marking Compliance Check', 'safety', 'approved', 'Maria Reyes', 'Traffic Safety Chief', 'Road marking compliance audit completed focusing on pedestrian crossings and lane markings. 78% compliance rate achieved.', 'Pedestrian crossings: 85% compliant. Lane markings: 72% compliant. Fading issues identified in high-traffic areas.', 'Repaint faded markings immediately. Use high-reflectivity paint for better visibility. Quarterly inspection schedule implemented.', 'Major Roads and Highways', 'medium', '2024-02-08 09:30:00'),
('AUD-2024-039', 'Sidewalk Accessibility Audit', 'compliance', 'pending', 'Accessibility Officer', NULL, 'Audit of sidewalk accessibility compliance with PWD Act requirements. Focus on ramp installations, tactile paving, and obstruction clearance.', 'Initial assessment shows 65% compliance. Major issues include missing ramps and tactile paving.', 'Install missing ramps and tactile paving. Clear obstructions. Conduct accessibility training for maintenance staff.', 'Commercial District', 'high', NULL),
('AUD-2024-038', 'Road Surface Quality Assessment', 'quality', 'completed', 'Quality Assurance Team', 'Quality Director', 'Quarterly road surface quality assessment using pavement condition index. Overall rating of 7.2/10 achieved.', 'Main roads: 8.5/10 rating. Secondary roads: 6.8/10 rating. Rural roads: 5.9/10 rating.', 'Resurface critical sections with PCI below 6.0. Implement preventive maintenance program. Increase inspection frequency.', 'All Road Networks', 'medium', '2024-02-05 14:15:00');

-- =====================================================
-- TABLE: AUDIT_LOGS
-- =====================================================
CREATE TABLE IF NOT EXISTS `audit_logs` (
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

-- Insert sample audit logs
INSERT INTO `audit_logs` (`user_id`, `action`, `details`, `ip_address`, `user_agent`) VALUES
(2, 'Created audit trail', 'Created audit AUD-2024-045 for Highway 101 infrastructure', '192.168.1.100', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'),
(3, 'Updated audit status', 'Changed status of AUD-2024-044 to rejected', '192.168.1.101', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'),
(4, 'Created audit trail', 'Created audit AUD-2024-043 for traffic system review', '192.168.1.102', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'),
(2, 'Exported audit data', 'Exported audit trails to CSV format', '192.168.1.100', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'),
(1, 'User login', 'Admin user logged into system', '192.168.1.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');

-- =====================================================
-- TABLE: AUDIT_ATTACHMENTS
-- =====================================================
CREATE TABLE IF NOT EXISTS `audit_attachments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `audit_trail_id` INT NOT NULL,
  `filename` VARCHAR(255) NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `file_type` VARCHAR(50) NOT NULL,
  `file_size` INT NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `uploaded_by` INT NOT NULL,
  `uploaded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_audit_trail_id` (`audit_trail_id`),
  INDEX `idx_uploaded_by` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE: ROAD_TRANSPORTATION_REPORTS
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
  `created_by` INT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_report_id` (`report_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_report_type` (`report_type`),
  INDEX `idx_priority` (`priority`),
  INDEX `idx_created_date` (`created_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sample reports removed - only user-created reports with images will remain
-- INSERT INTO `road_transportation_reports` (`report_id`, `report_type`, `title`, `department`, `priority`, `status`, `created_date`, `due_date`, `description`) VALUES
-- Sample data commented out - use remove_dummy_reports.sql to clean existing database

-- =====================================================
-- TABLE: ROAD_MAINTENANCE_REPORTS
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
  `created_by` INT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_report_id` (`report_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_report_type` (`report_type`),
  INDEX `idx_priority` (`priority`),
  INDEX `idx_created_date` (`created_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert sample maintenance reports
INSERT INTO `road_maintenance_reports` (`report_id`, `report_type`, `title`, `department`, `priority`, `status`, `created_date`, `due_date`, `description`, `location`, `estimated_cost`, `actual_cost`, `maintenance_team`) VALUES
('MNT-2024-001', 'routine', 'Monthly Road Surface Inspection', 'maintenance', 'medium', 'completed', '2024-01-15', '2024-01-31', 'Routine inspection of all major road surfaces for cracks and damage', 'National Highway 1', 5000.00, 4500.00, 'Highway Maintenance Team A'),
('MNT-2024-002', 'emergency', 'Emergency Pothole Repair - Main Street', 'maintenance', 'high', 'completed', '2024-01-10', '2024-01-12', 'Emergency repair of dangerous potholes on Main Street', 'Main Street, Downtown', 2500.00, 2800.00, 'Emergency Response Team'),
('MNT-2024-003', 'preventive', 'Bridge Maintenance Schedule Q1', 'engineering', 'high', 'in-progress', '2024-02-01', '2024-03-31', 'Preventive maintenance for all city bridges', 'All City Bridges', 15000.00, NULL, 'Bridge Inspection Team'),
('MNT-2024-004', 'corrective', 'Drainage System Repair', 'maintenance', 'medium', 'pending', '2024-02-05', '2024-02-20', 'Corrective maintenance for blocked drainage systems', 'Highway 5 Drainage', 8000.00, NULL, 'Drainage Maintenance Team'),
('MNT-2024-005', 'scheduled', 'Annual Road Resurfacing Program', 'engineering', 'high', 'pending', '2024-02-08', '2024-06-30', 'Annual resurfacing of priority roads', 'City Center Roads', 50000.00, NULL, 'Road Resurfacing Team'),
('MNT-2024-006', 'routine', 'Street Light Maintenance Check', 'maintenance', 'low', 'cancelled', '2024-01-20', '2024-02-05', 'Routine check and maintenance of street lighting', 'All Municipal Streets', 3000.00, 0.00, 'Electrical Maintenance Team');

-- =====================================================
-- TABLE: PUBLIC_DOCUMENTS
-- =====================================================
CREATE TABLE IF NOT EXISTS `public_documents` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `document_id` VARCHAR(50) UNIQUE NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `document_type` VARCHAR(100),
  `file_path` VARCHAR(500),
  `file_size` INT,
  `upload_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `published_date` TIMESTAMP NULL,
  `download_count` INT DEFAULT 0,
  `is_published` BOOLEAN DEFAULT FALSE,
  INDEX `idx_published` (`is_published`),
  INDEX `idx_upload_date` (`upload_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE: DOCUMENT_DOWNLOADS
-- =====================================================
CREATE TABLE IF NOT EXISTS `document_downloads` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `document_id` VARCHAR(50),
  `download_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `ip_address` VARCHAR(45),
  INDEX `idx_document_id` (`document_id`),
  INDEX `idx_download_date` (`download_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE: PUBLISHED_COMPLETED_PROJECTS (Public Transparency)
-- =====================================================
CREATE TABLE IF NOT EXISTS `published_completed_projects` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `location` VARCHAR(255) DEFAULT NULL,
  `completed_date` DATE DEFAULT NULL,
  `cost` DECIMAL(12,2) DEFAULT NULL,
  `completed_by` VARCHAR(255) DEFAULT NULL,
  `photo` VARCHAR(500) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- FINALIZE DATABASE SETUP
-- =====================================================
SET FOREIGN_KEY_CHECKS = 1;
COMMIT;

-- =====================================================
-- END OF DATABASE DUMP
-- =====================================================
