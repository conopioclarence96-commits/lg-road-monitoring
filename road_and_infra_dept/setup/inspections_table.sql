-- Inspections Table for Road Infrastructure Management
-- Stores inspection reports and their review status

CREATE TABLE IF NOT EXISTS inspections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inspection_id VARCHAR(20) UNIQUE NOT NULL,
    location VARCHAR(255) NOT NULL,
    inspection_date DATE NOT NULL,
    inspector_id INT NOT NULL,
    description TEXT NOT NULL,
    severity ENUM('low', 'medium', 'high') NOT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    review_date DATE NULL,
    reviewed_by INT NULL,
    review_notes TEXT NULL,
    priority VARCHAR(10) NULL,
    estimated_cost DECIMAL(10,2) NULL,
    photos JSON NULL, -- Store photo filenames as JSON array
    estimated_damage VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Repair Tasks Table
-- Stores repair tasks created from approved inspections

CREATE TABLE IF NOT EXISTS repair_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id VARCHAR(20) UNIQUE NOT NULL,
    inspection_id VARCHAR(20) NOT NULL,
    assigned_to VARCHAR(255) NULL,
    status ENUM('pending', 'in_progress', 'completed') DEFAULT 'pending',
    priority ENUM('low', 'medium', 'high') NOT NULL,
    estimated_cost DECIMAL(10,2) NULL,
    created_date DATE NOT NULL,
    estimated_completion DATE NULL,
    actual_completion DATE NULL,
    notes TEXT NULL,
    created_by INT NOT NULL
);

-- Add foreign key constraints only if users table exists and has data
-- Check if there are existing users first
SET @user_count = (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users');

-- If users table exists, add foreign keys
SET @sql = IF(@user_count > 0, 
    'ALTER TABLE inspections ADD CONSTRAINT fk_inspections_inspector FOREIGN KEY (inspector_id) REFERENCES users(id)',
    'SELECT "Skipping foreign key - users table not found" as message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;

SET @sql = IF(@user_count > 0, 
    'ALTER TABLE inspections ADD CONSTRAINT fk_inspections_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id)',
    'SELECT "Skipping foreign key - users table not found" as message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;

SET @sql = IF(@user_count > 0, 
    'ALTER TABLE repair_tasks ADD CONSTRAINT fk_repair_tasks_inspection FOREIGN KEY (inspection_id) REFERENCES inspections(inspection_id)',
    'SELECT "Skipping foreign key - users table not found" as message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;

SET @sql = IF(@user_count > 0, 
    'ALTER TABLE repair_tasks ADD CONSTRAINT fk_repair_tasks_creator FOREIGN KEY (created_by) REFERENCES users(id)',
    'SELECT "Skipping foreign key - users table not found" as message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;

-- Insert sample data for testing (only if users exist)
-- First, let's try to get existing user IDs
SET @inspector_id_1 = IF(@user_count > 0, (SELECT MIN(id) FROM users LIMIT 1), 1);
SET @inspector_id_2 = IF(@user_count > 0, (SELECT MAX(id) FROM users LIMIT 1), 2);
SET @reviewer_id = IF(@user_count > 0, (SELECT id FROM users WHERE role = 'engineer' LIMIT 1), @inspector_id_1);

-- Insert sample inspections
INSERT INTO inspections (
    inspection_id, location, inspection_date, inspector_id, description, 
    severity, status, photos, estimated_damage
) VALUES 
('INSP-1023', 'Main Road, Brgy. 3', '2025-12-10', @inspector_id_1, 
 'Large pothole approximately 2 feet in diameter causing traffic hazards. Immediate repair recommended.', 
 'high', 'pending', 
 '["pothole1.jpg", "pothole2.jpg"]', 
 'Road surface damage requiring asphalt patching'),

('INSP-1024', 'Market Street', '2025-12-11', @inspector_id_2, 
 'Minor crack in road surface approximately 1 meter long. No immediate danger but should be monitored.', 
 'low', 'approved', 
 '["crack1.jpg", "crack2.jpg"]', 
 'Surface crack requiring sealant application'),

('INSP-1025', 'Highway 1, Brgy. 5', '2025-12-09', @inspector_id_1, 
 'Multiple small potholes along 100-meter stretch causing vehicle damage. Requires immediate attention.', 
 'medium', 'pending', 
 '["potholes_highway.jpg", "damage_closeup.jpg"]', 
 'Asphalt resurfacing needed for affected section'),

('INSP-1026', 'School Zone Street', '2025-12-08', @inspector_id_2, 
 'Faded road markings and damaged crosswalk near elementary school. Safety hazard for children.', 
 'high', 'pending', 
 '["school_crosswalk.jpg", "faded_markings.jpg"]', 
 'Road marking repainting and crosswalk replacement');

-- Update the approved inspection with review details
UPDATE inspections 
SET status = 'approved', 
    review_date = '2025-12-12', 
    reviewed_by = @reviewer_id, 
    review_notes = 'Approved for routine maintenance. Schedule for next maintenance cycle.',
    priority = 'low',
    estimated_cost = 5000.00
WHERE inspection_id = 'INSP-1024';

-- Insert sample repair task for approved inspection
INSERT INTO repair_tasks (
    task_id, inspection_id, assigned_to, status, priority, 
    estimated_cost, created_date, estimated_completion, created_by
) VALUES (
    'REP-552', 'INSP-1024', 'Maintenance Team A', 'pending', 'low',
    5000.00, '2025-12-12', '2025-12-20', @reviewer_id
);

-- Create indexes for better performance
CREATE INDEX idx_inspections_status ON inspections(status);
CREATE INDEX idx_inspections_date ON inspections(inspection_date);
CREATE INDEX idx_repair_tasks_status ON repair_tasks(status);
CREATE INDEX idx_repair_tasks_inspection ON repair_tasks(inspection_id);
