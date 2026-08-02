-- Create report_assignments table to track user-to-project assignments
-- This allows multiple users to be assigned to a single project

CREATE TABLE IF NOT EXISTS report_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    report_type VARCHAR(50) NOT NULL COMMENT 'road_transportation_reports, road_maintenance_reports, cimm_verification_reports',
    user_id INT NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    assigned_by INT NOT NULL COMMENT 'ID of admin who made the assignment',
    status ENUM('active', 'completed', 'cancelled') DEFAULT 'active',
    notes TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX (report_id, report_type),
    INDEX (user_id),
    INDEX (status),
    INDEX (assigned_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
