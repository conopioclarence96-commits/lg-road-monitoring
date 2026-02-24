-- Add sample reports for testing the Edit modal functionality
USE lg_road_monitoring;

-- Clear existing sample data (if any)
DELETE FROM road_transportation_reports WHERE report_id LIKE 'RTR-2024-%';
DELETE FROM road_maintenance_reports WHERE report_id LIKE 'MNT-2024-%';

-- Add sample transportation reports
INSERT INTO road_transportation_reports (report_id, report_type, title, department, priority, status, created_date, description, location, latitude, longitude, reporter_name, reporter_email, created_by, created_at) VALUES
('RTR-2024-001', 'infrastructure_issue', 'Street Light Maintenance', 'engineering', 'medium', 'pending', CURDATE(), 'Multiple street lights reported as non-functional along Main Street', 'Main Street, Downtown District', 14.5995, 120.9842, 'Juan Santos', 'juan.santos@lgu.gov.ph', 1, NOW()),
('RTR-2024-002', 'infrastructure_issue', 'Traffic Accident on Highway 1', 'engineering', 'high', 'completed', DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'Multi-vehicle accident reported on Highway 1 near KM 45', 'Highway 1, KM 45', 14.6123, 120.9765, 'Maria Reyes', 'maria.reyes@lgu.gov.ph', 1, DATE_SUB(NOW(), INTERVAL 2 DAY)),
('RTR-2024-003', 'infrastructure_issue', 'Road Damage Report', 'engineering', 'high', 'in-progress', DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'Large pothole reported on Main Street causing traffic disruption', 'Main Street, Commercial District', 14.6052, 120.9823, 'Roberto dela Cruz', 'roberto.delacruz@lgu.gov.ph', 1, DATE_SUB(NOW(), INTERVAL 1 DAY)),
('RTR-2024-004', 'maintenance_request', 'Bridge Inspection Required', 'engineering', 'high', 'pending', CURDATE(), 'Quarterly bridge inspection scheduled for City Bridge #3', 'City Bridge #3', 14.6089, 120.9791, 'Engr. Juan Santos', 'juan.santos@lgu.gov.ph', 1, NOW());

-- Add sample maintenance reports
INSERT INTO road_maintenance_reports (report_id, report_type, title, department, priority, status, created_date, description, location, created_by, created_at) VALUES
('MNT-2024-001', 'emergency', 'Pothole Repair Request', 'maintenance', 'high', 'in-progress', DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'Large pothole causing traffic disruption on Avenue Street', 'Avenue Street, Commercial District', 1, DATE_SUB(NOW(), INTERVAL 1 DAY)),
('MNT-2024-002', 'routine', 'Road Surface Inspection', 'maintenance', 'low', 'pending', CURDATE(), 'Routine inspection needed for road surface conditions', 'National Highway, Section 2', 1, NOW()),
('MNT-2024-003', 'preventive', 'Drainage Cleaning', 'maintenance', 'medium', 'completed', DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'Preventive maintenance for drainage systems before rainy season', 'All Districts', 1, DATE_SUB(NOW(), INTERVAL 3 DAY)),
('MNT-2024-004', 'corrective', 'Street Light Repair', 'maintenance', 'medium', 'pending', CURDATE(), 'Corrective maintenance for damaged street light fixtures', 'Residential Area, District 2', 1, NOW());

-- Display results
SELECT 'Transportation Reports Added:' as Info;
SELECT COUNT(*) as count FROM road_transportation_reports WHERE report_id LIKE 'RTR-2024-%';

SELECT 'Maintenance Reports Added:' as Info;
SELECT COUNT(*) as count FROM road_maintenance_reports WHERE report_id LIKE 'MNT-2024-%';

-- Show sample data
SELECT 'Sample Transportation Reports:' as Info;
SELECT id, report_id, title, status, priority, location FROM road_transportation_reports WHERE report_id LIKE 'RTR-2024-%' ORDER BY id;

SELECT 'Sample Maintenance Reports:' as Info;
SELECT id, report_id, title, status, priority, location FROM road_maintenance_reports WHERE report_id LIKE 'MNT-2024-%' ORDER BY id;
