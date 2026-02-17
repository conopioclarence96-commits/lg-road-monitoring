-- Create road_transportation_reports table
CREATE TABLE IF NOT EXISTS road_transportation_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id VARCHAR(50) UNIQUE NOT NULL,
    report_type ENUM('monthly', 'traffic', 'maintenance', 'safety', 'budget') NOT NULL,
    title VARCHAR(255) NOT NULL,
    department ENUM('engineering', 'planning', 'maintenance', 'finance') NOT NULL,
    priority ENUM('high', 'medium', 'low') DEFAULT 'medium',
    status ENUM('pending', 'in-progress', 'completed', 'cancelled') DEFAULT 'pending',
    created_date DATE NOT NULL,
    due_date DATE,
    description TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert sample data for testing
INSERT INTO road_transportation_reports (report_id, report_type, title, department, priority, status, created_date, due_date, description) VALUES
('RPT-2024-001', 'monthly', 'January Road Condition Assessment', 'engineering', 'high', 'completed', '2024-01-15', '2024-01-31', 'Monthly assessment of road conditions across all major highways'),
('RPT-2024-002', 'traffic', 'Q4 2023 Traffic Flow Analysis', 'planning', 'medium', 'completed', '2024-01-10', '2024-01-25', 'Comprehensive traffic analysis for the fourth quarter of 2023'),
('RPT-2024-003', 'maintenance', 'February Maintenance Schedule', 'maintenance', 'low', 'in-progress', '2024-02-01', '2024-02-15', 'Scheduled maintenance activities for February 2024'),
('RPT-2024-004', 'safety', 'Road Safety Inspection Report', 'engineering', 'high', 'pending', '2024-02-05', '2024-02-20', 'Safety inspection of all major roadways and intersections'),
('RPT-2024-005', 'budget', 'Q1 2024 Budget Utilization', 'finance', 'medium', 'pending', '2024-02-08', '2024-02-22', 'First quarter budget analysis and utilization report'),
('RPT-2024-006', 'monthly', 'December 2023 Year-End Summary', 'engineering', 'low', 'cancelled', '2024-01-20', '2024-02-05', 'Year-end summary report for December 2023');
