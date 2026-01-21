-- Complete Database Schema for LGU Road and Infrastructure Department
-- Users table
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `role` enum('admin', 'lgu_officer', 'engineer') NOT NULL DEFAULT 'engineer',
  `first_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Inspections table
CREATE TABLE IF NOT EXISTS `inspections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `inspection_id` varchar(20) NOT NULL,
  `location` text NOT NULL,
  `inspection_date` date NOT NULL,
  `severity` enum('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
  `description` text NOT NULL,
  `coordinates` varchar(100) DEFAULT NULL,
  `estimated_cost` decimal(10,2) DEFAULT NULL,
  `priority` enum('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
  `inspector_id` int(11) NOT NULL,
  `photos` json DEFAULT NULL,
  `status` enum('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  `review_date` date NULL DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inspection_id` (`inspection_id`),
  KEY `inspector_id` (`inspector_id`),
  KEY `status` (`status`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Repair tasks table
CREATE TABLE IF NOT EXISTS `repair_tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` varchar(20) NOT NULL,
  `inspection_id` varchar(20) NOT NULL,
  `assigned_to` varchar(100) DEFAULT NULL,
  `status` enum('pending', 'in_progress', 'completed') NOT NULL DEFAULT 'pending',
  `progress` int(3) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `task_id` (`task_id`),
  KEY `inspection_id` (`inspection_id`),
  KEY `status` (`status`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notifications table
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `data` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `read_status` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `type` (`type`),
  KEY `read_status` (`read_status`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert sample data
INSERT INTO users (username, password, role, first_name, last_name) VALUES
('admin', 'admin123', 'admin', 'Admin', 'User'),
('lgu_officer', 'lgu123', 'lgu_officer', 'LGU', 'Officer'),
('engineer', 'engineer123', 'engineer', 'Engineer', 'Smith');

INSERT INTO inspections (inspection_id, location, inspection_date, severity, description, priority, inspector_id, status, created_at) VALUES
('INSP-2025-001', 'Main Road, Brgy. 3', '2025-01-15', 'high', 'Large pothole causing traffic hazards', 'high', 'engineer1', 'pending', '2025-01-15'),
('INSP-2025-002', 'Market Street', '2025-01-16', 'medium', 'Cracks along the side of the road near the market entrance', 'medium', 'engineer1', 'approved', '2025-01-16'),
('INSP-2025-003', 'School Zone', '2025-01-17', 'low', 'Minor surface damage near school zone', 'low', 'engineer1', 'rejected', '2025-01-17'),
('INSP-2025-004', 'Highway Bridge', '2025-01-18', 'high', 'Structural damage to bridge supports', 'high', 'engineer1', 'pending', '2025-01-18');

INSERT INTO repair_tasks (task_id, inspection_id, assigned_to, status, progress, created_at) VALUES
('REP-2025-001', 'INSP-2025-002', 'Maintenance Team A', 'in_progress', 60, '2025-01-17'),
('REP-2025-002', 'INSP-2025-001', 'Maintenance Team B', 'pending', 0, '2025-01-17');
