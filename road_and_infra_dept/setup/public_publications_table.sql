-- Public Publications Table for LGU Road and Infrastructure Department
-- This table manages what road issue information is published for public viewing
-- Ensures proper data filtering based on LGU officer publication decisions

CREATE TABLE IF NOT EXISTS `public_publications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `publication_id` varchar(20) NOT NULL,
  `damage_report_id` int(11) NOT NULL,
  `inspection_report_id` int(11) DEFAULT NULL,
  `maintenance_task_id` int(11) DEFAULT NULL,
  
  -- Publication control
  `is_published` tinyint(1) NOT NULL DEFAULT 0,
  `publication_date` timestamp NULL DEFAULT NULL,
  `published_by` int(11) DEFAULT NULL,
  
  -- Public visible information (filtered for public viewing)
  `road_name` varchar(255) NOT NULL,
  `issue_summary` text NOT NULL,
  `issue_type` enum('pothole','crack','drainage','surface_damage','other') NOT NULL,
  `severity_public` enum('low','medium','high') NOT NULL,
  `status_public` enum('reported','under_repair','completed','fixed') NOT NULL DEFAULT 'reported',
  
  -- Timeline information for public viewing
  `date_reported` date NOT NULL,
  `repair_start_date` date DEFAULT NULL,
  `completion_date` date DEFAULT NULL,
  `repair_duration_days` int DEFAULT NULL,
  
  -- Progress history for public viewing (JSON array of milestones)
  `progress_history` json DEFAULT NULL,
  
  -- Publication metadata
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `archived` tinyint(1) NOT NULL DEFAULT 0,
  `archive_reason` varchar(255) DEFAULT NULL,
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `publication_id` (`publication_id`),
  KEY `damage_report_id` (`damage_report_id`),
  KEY `is_published` (`is_published`),
  KEY `publication_date` (`publication_date`),
  KEY `status_public` (`status_public`),
  KEY `archived` (`archived`),
  FOREIGN KEY (`damage_report_id`) REFERENCES `damage_reports` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`inspection_report_id`) REFERENCES `inspection_reports` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`maintenance_task_id`) REFERENCES `maintenance_schedule` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`published_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Create publication_progress table for detailed progress tracking
CREATE TABLE IF NOT EXISTS `publication_progress` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `publication_id` int(11) NOT NULL,
  `progress_date` date NOT NULL,
  `status` enum('reported','under_assessment','inspection_scheduled','under_repair','completed','fixed') NOT NULL,
  `description` text NOT NULL,
  `is_public_visible` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `publication_id` (`publication_id`),
  KEY `progress_date` (`progress_date`),
  KEY `is_public_visible` (`is_public_visible`),
  FOREIGN KEY (`publication_id`) REFERENCES `public_publications` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Insert sample publications for testing
INSERT INTO `public_publications` (
  `publication_id`, `damage_report_id`, `road_name`, `issue_summary`, `issue_type`, 
  `severity_public`, `status_public`, `date_reported`, `repair_start_date`, 
  `completion_date`, `repair_duration_days`, `is_published`, `publication_date`, `published_by`
) VALUES 
('PUB-2025-001', 1, 'Commonwealth Avenue', 'Large pothole causing traffic disruption repaired', 'pothole', 'high', 'completed', '2025-01-10', '2025-01-15', '2025-01-17', 7, 1, '2025-01-18', 1),
('PUB-2025-002', 2, 'EDSA Complex', 'Road crack repair completed', 'crack', 'medium', 'under_repair', '2025-01-12', '2025-01-20', NULL, NULL, 1, '2025-01-20', 1),
('PUB-2025-003', 3, 'Quezon City Circle', 'Multiple small potholes fixed', 'pothole', 'low', 'fixed', '2025-01-05', '2025-01-08', '2025-01-10', 5, 1, '2025-01-11', 1);

-- Insert sample progress history
INSERT INTO `publication_progress` (
  `publication_id`, `progress_date`, `status`, `description`, `created_by`
) VALUES 
(1, '2025-01-10', 'reported', 'Issue reported by citizen', 2),
(1, '2025-01-12', 'under_assessment', 'LGU team assessed the damage', 3),
(1, '2025-01-15', 'under_repair', 'Repair work started', 3),
(1, '2025-01-17', 'completed', 'Repair work completed', 3),
(1, '2025-01-18', 'fixed', 'Published as completed for public viewing', 1),
(2, '2025-01-12', 'reported', 'Road crack reported', 2),
(2, '2025-01-14', 'inspection_scheduled', 'Inspection scheduled', 3),
(2, '2025-01-20', 'under_repair', 'Repair work in progress', 3),
(3, '2025-01-05', 'reported', 'Multiple potholes reported', 3),
(3, '2025-01-08', 'under_repair', 'Repair team deployed', 3),
(3, '2025-01-10', 'fixed', 'All potholes repaired', 3);
