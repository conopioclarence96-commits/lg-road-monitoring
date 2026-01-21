-- ==============================================================================
-- LGU Road and Infrastructure Department - Master Database Setup
-- ==============================================================================
-- This script provides a complete, unified database initialization.
-- It merges all modular setup files into a single consistent schema.
-- Created: January 2026
-- ==============================================================================

-- Create database if it doesn't exist
CREATE DATABASE IF NOT EXISTS lgu_road_infra;
USE lgu_road_infra;

-- ------------------------------------------------------------------------------
-- 1. DROP EXISTING TABLES (Reverse Dependency Order)
-- ------------------------------------------------------------------------------
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `publication_progress`;
DROP TABLE IF EXISTS `public_publications`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `user_permissions`;
DROP TABLE IF EXISTS `user_activity_log`;
DROP TABLE IF EXISTS `user_sessions`;
DROP TABLE IF EXISTS `login_attempts`;
DROP TABLE IF EXISTS `documents`;
DROP TABLE IF EXISTS `gis_data`;
DROP TABLE IF EXISTS `cost_assessments`;
DROP TABLE IF EXISTS `maintenance_schedule`;
DROP TABLE IF EXISTS `repair_tasks`;
DROP TABLE IF EXISTS `inspection_reports`;
DROP TABLE IF EXISTS `inspections`;
DROP TABLE IF EXISTS `damage_reports`;
DROP TABLE IF EXISTS `public_announcements`;
DROP TABLE IF EXISTS `users`;
SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------------------------
-- 2. USER MANAGEMENT SYSTEM
-- ------------------------------------------------------------------------------

-- Users table
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `first_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) NOT NULL,
  `username` varchar(50) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','lgu_officer','engineer','citizen') NOT NULL DEFAULT 'citizen',
  `status` enum('active','inactive','suspended','pending') NOT NULL DEFAULT 'pending',
  `email_verified` tinyint(1) NOT NULL DEFAULT 0,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_login` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `username` (`username`),
  KEY `role` (`role`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Login attempts table
CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(100) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `success` tinyint(1) NOT NULL,
  `user_agent` text DEFAULT NULL,
  `attempt_time` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `email` (`email`),
  KEY `attempt_time` (`attempt_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- User sessions table
CREATE TABLE `user_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `session_id` varchar(255) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_id` (`session_id`),
  KEY `user_id` (`user_id`),
  KEY `expires_at` (`expires_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- User activity log table
CREATE TABLE `user_activity_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `activity_type` varchar(50) NOT NULL,
  `activity_description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `activity_type` (`activity_type`),
  KEY `created_at` (`created_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- User permissions table
CREATE TABLE `user_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `permission` varchar(50) NOT NULL,
  `module` varchar(50) NOT NULL,
  `granted_by` int(11) NOT NULL,
  `granted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_permission` (`user_id`, `permission`, `module`),
  KEY `user_id` (`user_id`),
  KEY `granted_by` (`granted_by`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------------------------
-- 3. ROAD ISSUE & INFRASTRUCTURE MANAGEMENT
-- ------------------------------------------------------------------------------

-- Damage Reports table
CREATE TABLE `damage_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `report_id` varchar(20) NOT NULL,
  `reporter_id` int(11) NOT NULL,
  `location` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `severity` enum('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `status` enum('pending','in_progress','resolved','closed') NOT NULL DEFAULT 'pending',
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `estimated_cost` decimal(10,2) DEFAULT NULL,
  `images` text DEFAULT NULL,
  `reported_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `assigned_to` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `report_id` (`report_id`),
  KEY `reporter_id` (`reporter_id`),
  KEY `status` (`status`),
  KEY `severity` (`severity`),
  FOREIGN KEY (`reporter_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Inspection Reports table (Unified)
CREATE TABLE `inspection_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `inspection_id` varchar(20) NOT NULL,
  `inspector_id` int(11) NOT NULL,
  `damage_report_id` int(11) DEFAULT NULL,
  `location` varchar(255) NOT NULL,
  `inspection_type` enum('initial','follow_up','final','special') NOT NULL DEFAULT 'initial',
  `findings` text DEFAULT NULL,
  `recommendations` text DEFAULT NULL,
  `inspection_status` enum('scheduled','in_progress','completed','cancelled','approved','rejected') NOT NULL DEFAULT 'scheduled',
  `scheduled_date` date DEFAULT NULL,
  `completed_date` timestamp NULL DEFAULT NULL,
  `priority` enum('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  `estimated_cost` decimal(10,2) DEFAULT NULL,
  `photos` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `inspection_id` (`inspection_id`),
  KEY `inspector_id` (`inspector_id`),
  KEY `damage_report_id` (`damage_report_id`),
  KEY `inspection_status` (`inspection_status`),
  FOREIGN KEY (`inspector_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`damage_report_id`) REFERENCES `damage_reports` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Cost Assessments table
CREATE TABLE `cost_assessments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `assessment_id` varchar(20) NOT NULL,
  `damage_report_id` int(11) NOT NULL,
  `assessor_id` int(11) NOT NULL,
  `labor_cost` decimal(10,2) DEFAULT NULL,
  `material_cost` decimal(10,2) DEFAULT NULL,
  `equipment_cost` decimal(10,2) DEFAULT NULL,
  `total_cost` decimal(10,2) NOT NULL,
  `assessment_notes` text DEFAULT NULL,
  `status` enum('draft','submitted','approved','rejected') NOT NULL DEFAULT 'draft',
  `assessment_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `assessment_id` (`assessment_id`),
  KEY `damage_report_id` (`damage_report_id`),
  KEY `assessor_id` (`assessor_id`),
  FOREIGN KEY (`damage_report_id`) REFERENCES `damage_reports` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`assessor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Maintenance Schedule & Repair Tasks table
CREATE TABLE `maintenance_schedule` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` varchar(20) NOT NULL,
  `task_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `location` varchar(255) NOT NULL,
  `task_type` enum('routine','emergency','inspection','repair') NOT NULL DEFAULT 'routine',
  `priority` enum('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  `status` enum('scheduled','in_progress','completed','cancelled','postponed') NOT NULL DEFAULT 'scheduled',
  `scheduled_date` datetime NOT NULL,
  `estimated_duration` int DEFAULT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `assigned_team` varchar(255) DEFAULT NULL,
  `completed_date` timestamp NULL DEFAULT NULL,
  `actual_duration` int DEFAULT NULL,
  `cost` decimal(10,2) DEFAULT NULL,
  `materials_used` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `task_id` (`task_id`),
  KEY `status` (`status`),
  KEY `assigned_to` (`assigned_to`),
  FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- GIS Data table
CREATE TABLE `gis_data` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `feature_id` varchar(20) NOT NULL,
  `feature_type` enum('damage','infrastructure','maintenance','zone') NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `properties` json DEFAULT NULL,
  `status` enum('active','inactive','maintenance') NOT NULL DEFAULT 'active',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `feature_id` (`feature_id`),
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Documents table
CREATE TABLE `documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `document_id` varchar(20) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `document_type` enum('report','image','video','pdf','other') NOT NULL,
  `category` enum('damage_report','cost_assessment','inspection','maintenance','general') NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` bigint DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `related_id` int(11) DEFAULT NULL,
  `related_type` varchar(50) DEFAULT NULL,
  `is_public` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_id` (`document_id`),
  FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------------------------
-- 4. COMMUNICATION & PUBLIC INFORMATION
-- ------------------------------------------------------------------------------

-- Notifications table
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `type` enum('info','success','warning','error','permission_granted') NOT NULL DEFAULT 'info',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `read_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `is_read` (`is_read`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Public Announcements table
CREATE TABLE `public_announcements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `announcement_id` varchar(20) NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `announcement_type` enum('general','maintenance','alert','holiday') NOT NULL DEFAULT 'general',
  `priority` enum('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `start_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `end_date` timestamp NULL DEFAULT NULL,
  `target_audience` enum('all','citizens','staff','engineers','lgu_officers') NOT NULL DEFAULT 'all',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `announcement_id` (`announcement_id`),
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Public Publications Table
CREATE TABLE `public_publications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `publication_id` varchar(20) NOT NULL,
  `damage_report_id` int(11) NOT NULL,
  `inspection_report_id` int(11) DEFAULT NULL,
  `maintenance_task_id` int(11) DEFAULT NULL,
  `is_published` tinyint(1) NOT NULL DEFAULT 0,
  `publication_date` timestamp NULL DEFAULT NULL,
  `published_by` int(11) DEFAULT NULL,
  `road_name` varchar(255) NOT NULL,
  `issue_summary` text NOT NULL,
  `issue_type` enum('pothole','crack','drainage','surface_damage','other') NOT NULL,
  `severity_public` enum('low','medium','high') NOT NULL,
  `status_public` enum('reported','under_repair','completed','fixed') NOT NULL DEFAULT 'reported',
  `date_reported` date NOT NULL,
  `repair_start_date` date DEFAULT NULL,
  `completion_date` date DEFAULT NULL,
  `repair_duration_days` int DEFAULT NULL,
  `progress_history` json DEFAULT NULL,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `archived` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `publication_id` (`publication_id`),
  FOREIGN KEY (`damage_report_id`) REFERENCES `damage_reports` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`inspection_report_id`) REFERENCES `inspection_reports` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`maintenance_task_id`) REFERENCES `maintenance_schedule` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`published_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Publication Progress Table
CREATE TABLE `publication_progress` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `publication_id` int(11) NOT NULL,
  `progress_date` date NOT NULL,
  `status` enum('reported','under_assessment','inspection_scheduled','under_repair','completed','fixed') NOT NULL,
  `description` text NOT NULL,
  `is_public_visible` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  FOREIGN KEY (`publication_id`) REFERENCES `public_publications` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------------------------
-- 5. SAMPLE DATA INSERTION
-- ------------------------------------------------------------------------------

-- Default Users (Password: admin123 / officer123 / engineer123 / citizen123)
-- Using previously identified hashes where possible or standard test hashes
INSERT INTO `users` (`first_name`, `last_name`, `email`, `username`, `password`, `role`, `status`, `email_verified`) 
VALUES 
('System', 'Administrator', 'admin@lgu.gov.ph', 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'active', 1),
('LGU', 'Officer', 'officer@lgu.gov.ph', 'lgu_officer', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'lgu_officer', 'active', 1),
('Engineer', 'User', 'engineer@lgu.gov.ph', 'engineer', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'engineer', 'active', 1),
('Citizen', 'User', 'citizen@example.com', 'citizen', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'citizen', 'active', 1);

-- Sample Damage Reports
INSERT INTO `damage_reports` (`report_id`, `reporter_id`, `location`, `description`, `severity`, `status`, `latitude`, `longitude`, `estimated_cost`) 
VALUES 
('DR-2025-001', 4, 'Commonwealth Avenue', 'Large pothole causing traffic disruption', 'high', 'pending', 14.6355, 121.0320, 150000.00),
('DR-2025-002', 4, 'EDSA Complex', 'Road crack spreading', 'medium', 'in_progress', 14.6400, 121.0400, 75000.00),
('DR-2025-003', 4, 'Quezon City Circle', 'Multiple small potholes', 'low', 'resolved', 14.6300, 121.0200, 25000.00);

-- Sample Inspection Reports
INSERT INTO `inspection_reports` (`inspection_id`, `inspector_id`, `damage_report_id`, `location`, `inspection_type`, `findings`, `inspection_status`, `scheduled_date`, `priority`) 
VALUES 
('IN-2025-001', 3, 1, 'Commonwealth Avenue', 'initial', 'Large pothole requires immediate repair', 'completed', '2025-01-15', 'high'),
('INSP-1024', 3, 2, 'Market Street', 'initial', 'Minor crack in road surface.', 'approved', '2025-12-11', 'low');

-- Sample Cost Assessments
INSERT INTO `cost_assessments` (`assessment_id`, `damage_report_id`, `assessor_id`, `total_cost`, `status`) 
VALUES 
('CA-2025-001', 1, 3, 150000.00, 'approved'),
('CA-2025-002', 2, 3, 75000.00, 'submitted');

-- Sample Maintenance Schedule
INSERT INTO `maintenance_schedule` (`task_id`, `task_name`, `location`, `task_type`, `priority`, `status`, `scheduled_date`, `assigned_to`, `created_by`) 
VALUES 
('MT-2025-001', 'Road Repair - Commonwealth', 'Commonwealth Avenue', 'repair', 'high', 'scheduled', '2025-01-25 09:00:00', 3, 1),
('MT-2025-002', 'Bridge Inspection', 'EDSA Bridge', 'inspection', 'medium', 'scheduled', '2025-01-30 14:00:00', 3, 1);

-- Sample Publications
INSERT INTO `public_publications` (
  `publication_id`, `damage_report_id`, `road_name`, `issue_summary`, `issue_type`, 
  `severity_public`, `status_public`, `date_reported`, `is_published`, `publication_date`, `published_by`
) VALUES 
('PUB-2025-001', 1, 'Commonwealth Avenue', 'Large pothole repair', 'pothole', 'high', 'completed', '2025-01-10', 1, '2025-01-18', 1);

-- ==============================================================================
-- SETUP COMPLETE
-- ==============================================================================
