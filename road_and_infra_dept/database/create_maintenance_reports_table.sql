-- Create road_maintenance_reports table
CREATE TABLE IF NOT EXISTS road_maintenance_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id VARCHAR(50) UNIQUE NOT NULL,
    report_type ENUM('routine', 'emergency', 'preventive', 'corrective', 'scheduled') NOT NULL,
    title VARCHAR(255) NOT NULL,
    department ENUM('engineering', 'planning', 'maintenance', 'finance') NOT NULL,
    priority ENUM('high', 'medium', 'low') DEFAULT 'medium',
    status ENUM('pending', 'in-progress', 'completed', 'cancelled') DEFAULT 'pending',
    created_date DATE NOT NULL,
    due_date DATE,
    description TEXT,
    location VARCHAR(255),
    estimated_cost DECIMAL(10,2),
    actual_cost DECIMAL(10,2),
    maintenance_team VARCHAR(100),
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert sample data for testing
INSERT INTO road_maintenance_reports (report_id, report_type, title, department, priority, status, created_date, due_date, description, location, estimated_cost, actual_cost, maintenance_team) VALUES
('MNT-2024-001', 'routine', 'Monthly Road Surface Inspection', 'maintenance', 'medium', 'completed', '2024-01-15', '2024-01-31', 'Routine inspection of all major road surfaces for cracks and damage', 'National Highway 1', 5000.00, 4500.00, 'Highway Maintenance Team A'),
('MNT-2024-002', 'emergency', 'Emergency Pothole Repair - Main Street', 'maintenance', 'high', 'completed', '2024-01-10', '2024-01-12', 'Emergency repair of dangerous potholes on Main Street', 'Main Street, Downtown', 2500.00, 2800.00, 'Emergency Response Team'),
('MNT-2024-003', 'preventive', 'Bridge Maintenance Schedule Q1', 'engineering', 'high', 'in-progress', '2024-02-01', '2024-03-31', 'Preventive maintenance for all city bridges', 'All City Bridges', 15000.00, NULL, 'Bridge Inspection Team'),
('MNT-2024-004', 'corrective', 'Drainage System Repair', 'maintenance', 'medium', 'pending', '2024-02-05', '2024-02-20', 'Corrective maintenance for blocked drainage systems', 'Highway 5 Drainage', 8000.00, NULL, 'Drainage Maintenance Team'),
('MNT-2024-005', 'scheduled', 'Annual Road Resurfacing Program', 'engineering', 'high', 'pending', '2024-02-08', '2024-06-30', 'Annual resurfacing of priority roads', 'City Center Roads', 50000.00, NULL, 'Road Resurfacing Team'),
('MNT-2024-006', 'routine', 'Street Light Maintenance Check', 'maintenance', 'low', 'cancelled', '2024-01-20', '2024-02-05', 'Routine check and maintenance of street lighting', 'All Municipal Streets', 3000.00, 0.00, 'Electrical Maintenance Team');
