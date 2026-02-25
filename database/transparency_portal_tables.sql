-- =====================================================
-- LGU Road Monitoring System - Transparency Portal Tables
-- =====================================================
-- Created: 2026-02-25
-- Purpose: Database tables to support the public transparency portal
-- Based on: lgu_staff/pages/transparency/public_transparency.php

-- =====================================================
-- 1. PUBLICATIONS TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS `publications` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `publication_id` VARCHAR(50) UNIQUE NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `content` LONGTEXT,
  `publication_type` ENUM('report', 'announcement', 'policy', 'budget_report', 'performance_report', 'annual_report') NOT NULL,
  `category` VARCHAR(100),
  `department` VARCHAR(50),
  `author` VARCHAR(100),
  `publish_date` DATE NOT NULL,
  `expiry_date` DATE NULL,
  `file_path` VARCHAR(500),
  `file_name` VARCHAR(255),
  `file_size` INT,
  `file_type` VARCHAR(100),
  `is_published` BOOLEAN DEFAULT TRUE,
  `is_featured` BOOLEAN DEFAULT FALSE,
  `view_count` INT DEFAULT 0,
  `download_count` INT DEFAULT 0,
  `language` VARCHAR(10) DEFAULT 'en',
  `tags` JSON,
  `created_by` INT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_publication_id` (`publication_id`),
  INDEX `idx_publication_type` (`publication_type`),
  INDEX `idx_publish_date` (`publish_date`),
  INDEX `idx_department` (`department`),
  INDEX `idx_is_published` (`is_published`),
  INDEX `idx_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 2. PERFORMANCE METRICS TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS `performance_metrics` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `metric_id` VARCHAR(50) UNIQUE NOT NULL,
  `metric_name` VARCHAR(100) NOT NULL,
  `metric_type` ENUM('service_delivery', 'citizen_rating', 'response_time', 'efficiency_score', 'project_completion', 'budget_utilization') NOT NULL,
  `department` VARCHAR(50),
  `metric_value` DECIMAL(10,2) NOT NULL,
  `metric_unit` VARCHAR(20) DEFAULT '%',
  `target_value` DECIMAL(10,2),
  `measurement_period` ENUM('daily', 'weekly', 'monthly', 'quarterly', 'yearly') DEFAULT 'monthly',
  `measurement_date` DATE NOT NULL,
  `comparison_previous` DECIMAL(5,2),
  `trend` ENUM('improving', 'stable', 'declining') DEFAULT 'stable',
  `notes` TEXT,
  `data_source` VARCHAR(100),
  `created_by` INT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_metric_id` (`metric_id`),
  INDEX `idx_metric_type` (`metric_type`),
  INDEX `idx_department` (`department`),
  INDEX `idx_measurement_date` (`measurement_date`),
  INDEX `idx_measurement_period` (`measurement_period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 3. CITIZEN FEEDBACK TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS `citizen_feedback` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `feedback_id` VARCHAR(50) UNIQUE NOT NULL,
  `feedback_type` ENUM('complaint', 'suggestion', 'compliment', 'inquiry', 'service_rating') NOT NULL,
  `category` VARCHAR(100),
  `department` VARCHAR(50),
  `service_area` VARCHAR(100),
  `rating` INT CHECK (`rating` >= 1 AND `rating` <= 5),
  `subject` VARCHAR(255),
  `message` TEXT NOT NULL,
  `citizen_name` VARCHAR(100),
  `citizen_email` VARCHAR(100),
  `citizen_phone` VARCHAR(20),
  `anonymous` BOOLEAN DEFAULT FALSE,
  `status` ENUM('pending', 'in_review', 'responded', 'resolved', 'closed') DEFAULT 'pending',
  `priority` ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
  `response` TEXT,
  `response_date` TIMESTAMP NULL,
  `responded_by` INT,
  `satisfaction_rating` INT CHECK (`satisfaction_rating` >= 1 AND `satisfaction_rating` <= 5),
  `ip_address` VARCHAR(45),
  `user_agent` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`responded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_feedback_id` (`feedback_id`),
  INDEX `idx_feedback_type` (`feedback_type`),
  INDEX `idx_status` (`status`),
  INDEX `idx_department` (`department`),
  INDEX `idx_created_at` (`created_at`),
  INDEX `idx_rating` (`rating`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 4. TRANSPARENCY SCORE TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS `transparency_scores` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `score_id` VARCHAR(50) UNIQUE NOT NULL,
  `department` VARCHAR(50),
  `score_period` ENUM('monthly', 'quarterly', 'yearly') DEFAULT 'monthly',
  `score_date` DATE NOT NULL,
  `overall_score` DECIMAL(5,2) NOT NULL,
  `document_transparency` DECIMAL(5,2),
  `budget_transparency` DECIMAL(5,2),
  `project_transparency` DECIMAL(5,2),
  `performance_transparency` DECIMAL(5,2),
  `citizen_engagement` DECIMAL(5,2),
  `response_time_score` DECIMAL(5,2),
  `total_documents` INT DEFAULT 0,
  `public_documents` INT DEFAULT 0,
  `total_downloads` INT DEFAULT 0,
  `total_views` INT DEFAULT 0,
  `benchmark_score` DECIMAL(5,2),
  `grade` ENUM('A', 'B', 'C', 'D', 'F') DEFAULT 'C',
  `notes` TEXT,
  `calculated_by` INT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`calculated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_score_id` (`score_id`),
  INDEX `idx_department` (`department`),
  INDEX `idx_score_date` (`score_date`),
  INDEX `idx_score_period` (`score_period`),
  INDEX `idx_overall_score` (`overall_score`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 5. PUBLIC CONTACTS TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS `public_contacts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `contact_id` VARCHAR(50) UNIQUE NOT NULL,
  `contact_type` ENUM('hotline', 'email', 'office', 'social_media', 'website', 'forum') NOT NULL,
  `contact_name` VARCHAR(100) NOT NULL,
  `contact_value` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `department` VARCHAR(50),
  `service_hours` VARCHAR(100),
  `response_time` VARCHAR(50),
  `is_active` BOOLEAN DEFAULT TRUE,
  `is_primary` BOOLEAN DEFAULT FALSE,
  `contact_order` INT DEFAULT 0,
  `icon_class` VARCHAR(100),
  `created_by` INT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_contact_id` (`contact_id`),
  INDEX `idx_contact_type` (`contact_type`),
  INDEX `idx_department` (`department`),
  INDEX `idx_is_active` (`is_active`),
  INDEX `idx_contact_order` (`contact_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 6. TRANSPARENCY AUDIT LOG TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS `transparency_audit_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `audit_id` VARCHAR(50) UNIQUE NOT NULL,
  `action_type` ENUM('create', 'update', 'delete', 'publish', 'unpublish', 'view', 'download') NOT NULL,
  `table_name` VARCHAR(50),
  `record_id` INT,
  `record_title` VARCHAR(255),
  `department` VARCHAR(50),
  `user_id` INT,
  `user_name` VARCHAR(100),
  `ip_address` VARCHAR(45),
  `user_agent` TEXT,
  `changes_made` JSON,
  `reason` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_audit_id` (`audit_id`),
  INDEX `idx_action_type` (`action_type`),
  INDEX `idx_table_name` (`table_name`),
  INDEX `idx_department` (`department`),
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 7. SAMPLE DATA INSERTION
-- =====================================================

-- Sample Publications
INSERT INTO `publications` (`publication_id`, `title`, `description`, `publication_type`, `category`, `department`, `author`, `publish_date`, `is_published`, `created_by`) VALUES
('PUB-2024-001', 'Annual Road Infrastructure Report 2024', 'Comprehensive report on all road infrastructure projects and maintenance activities for 2024', 'annual_report', 'Reports', 'Engineering', 'LGU Engineering Department', '2024-12-31', TRUE, 1),
('PUB-2024-002', 'Q1 Budget Allocation Summary', 'Detailed breakdown of budget allocation for the first quarter of 2024', 'budget_report', 'Budget', 'Finance', 'Finance Department', '2024-03-31', TRUE, 1),
('PUB-2024-003', 'Citizen Satisfaction Survey Results', 'Results from the annual citizen satisfaction survey on LGU services', 'performance_report', 'Surveys', 'Planning', 'Planning Department', '2024-06-30', TRUE, 1);

-- Sample Performance Metrics
INSERT INTO `performance_metrics` (`metric_id`, `metric_name`, `metric_type`, `department`, `metric_value`, `metric_unit`, `target_value`, `measurement_period`, `measurement_date`, `trend`, `created_by`) VALUES
('MET-2024-001', 'Service Delivery Rate', 'service_delivery', 'Engineering', 85.5, '%', 90.0, 'monthly', '2024-02-01', 'improving', 1),
('MET-2024-002', 'Citizen Rating', 'citizen_rating', NULL, 4.6, 'rating', 4.5, 'monthly', '2024-02-01', 'stable', 1),
('MET-2024-003', 'Response Time', 'response_time', NULL, 2.3, 'hours', 2.0, 'monthly', '2024-02-01', 'stable', 1),
('MET-2024-004', 'Project Efficiency', 'efficiency_score', 'Engineering', 78.2, '%', 80.0, 'monthly', '2024-02-01', 'improving', 1);

-- Sample Citizen Feedback
INSERT INTO `citizen_feedback` (`feedback_id`, `feedback_type`, `category`, `department`, `rating`, `subject`, `message`, `citizen_name`, `citizen_email`, `status`, `created_by`) VALUES
('FB-2024-001', 'compliment', 'service_quality', 'Engineering', 5, 'Excellent Road Maintenance', 'The recent road resurfacing in our area was done very professionally. Thank you!', 'Juan Santos', 'juan.santos@email.com', 'resolved', NULL),
('FB-2024-002', 'suggestion', 'service_improvement', 'Planning', 4, 'More Traffic Signals', 'Suggest installing additional traffic signals at the main intersection to improve traffic flow', 'Maria Reyes', 'maria.reyes@email.com', 'responded', NULL),
('FB-2024-003', 'complaint', 'road_damage', 'Engineering', 2, 'Pothole Repair Needed', 'Large pothole on Main Street needs immediate repair before it causes accidents', 'Roberto Cruz', 'roberto.cruz@email.com', 'in_review', NULL);

-- Sample Transparency Scores
INSERT INTO `transparency_scores` (`score_id`, `department`, `score_period`, `score_date`, `overall_score`, `document_transparency`, `budget_transparency`, `project_transparency`, `performance_transparency`, `citizen_engagement`, `total_documents`, `public_documents`, `grade`, `calculated_by`) VALUES
('SCORE-2024-001', NULL, 'monthly', '2024-02-01', 92.5, 95.0, 90.0, 88.0, 94.0, 95.0, 156, 142, 'A', 1),
('SCORE-2024-002', 'Engineering', 'monthly', '2024-02-01', 89.2, 92.0, 85.0, 90.0, 88.0, 90.0, 89, 82, 'B', 1),
('SCORE-2024-003', 'Finance', 'monthly', '2024-02-01', 94.8, 96.0, 98.0, 92.0, 95.0, 93.0, 45, 44, 'A', 1);

-- Sample Public Contacts
INSERT INTO `public_contacts` (`contact_id`, `contact_type`, `contact_name`, `contact_value`, `description`, `department`, `service_hours`, `response_time`, `is_active`, `is_primary`, `contact_order`, `icon_class`, `created_by`) VALUES
('CONTACT-001', 'hotline', '24/7 Citizen Support', '123-456-7890', '24/7 hotline for infrastructure concerns and emergency reports', 'General', '24/7', 'Immediate', TRUE, TRUE, 1, 'fas fa-phone', 1),
('CONTACT-002', 'email', 'Transparency Email', 'transparency@lgu.gov.ph', 'Email support for transparency and information requests', 'General', 'Mon-Fri 8AM-5PM', '24-48 hours', TRUE, FALSE, 2, 'fas fa-envelope', 1),
('CONTACT-003', 'office', 'Main Office', 'City Hall, Main Street', 'Physical office location for in-person inquiries', 'General', 'Mon-Fri 8AM-5PM', 'Immediate', TRUE, FALSE, 3, 'fas fa-building', 1),
('CONTACT-004', 'forum', 'Public Forum', 'https://forum.lgu.gov.ph', 'Online community discussion platform', 'General', '24/7', 'Community response', TRUE, FALSE, 4, 'fas fa-comments', 1);

-- =====================================================
-- 8. VIEWS FOR COMMON QUERIES
-- =====================================================

-- View for latest transparency scores
CREATE OR REPLACE VIEW `v_latest_transparency_scores` AS
SELECT 
    department,
    overall_score,
    grade,
    score_date,
    CASE 
        WHEN overall_score >= 90 THEN 'Excellent'
        WHEN overall_score >= 80 THEN 'Good'
        WHEN overall_score >= 70 THEN 'Average'
        WHEN overall_score >= 60 THEN 'Below Average'
        ELSE 'Poor'
    END as performance_level
FROM transparency_scores ts1
WHERE score_date = (
    SELECT MAX(score_date) 
    FROM transparency_scores ts2 
    WHERE ts2.department = ts1.department OR (ts2.department IS NULL AND ts1.department IS NULL)
);

-- View for recent publications
CREATE OR REPLACE VIEW `v_recent_publications` AS
SELECT 
    publication_id,
    title,
    publication_type,
    category,
    department,
    publish_date,
    view_count,
    download_count,
    is_featured
FROM publications 
WHERE is_published = TRUE 
AND publish_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
ORDER BY publish_date DESC;

-- View for citizen feedback summary
CREATE OR REPLACE VIEW `v_citizen_feedback_summary` AS
SELECT 
    department,
    feedback_type,
    COUNT(*) as total_feedback,
    AVG(rating) as average_rating,
    COUNT(CASE WHEN status = 'resolved' THEN 1 END) as resolved_count,
    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count
FROM citizen_feedback 
WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
GROUP BY department, feedback_type;

-- =====================================================
-- COMPLETED SUCCESSFULLY
-- =====================================================
-- Total tables created: 7
-- Total views created: 3
-- Sample records inserted: 15+
-- Ready for transparency portal integration
