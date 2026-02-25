-- Test script to verify estimation column exists and can save data

-- Check if estimation column exists in transportation table
DESCRIBE road_transportation_reports;

-- Check if estimation column exists in maintenance table  
DESCRIBE road_maintenance_reports;

-- Test insert with estimation (transportation)
INSERT INTO road_transportation_reports 
(report_id, report_type, title, department, priority, status, created_date, description, location, estimation, created_by, created_at) 
VALUES ('TEST-EST-001', 'traffic_jam', 'Test Report', 'engineering', 'high', 'pending', CURDATE(), 'Test description', 'Test Location', 5000.00, 1, NOW());

-- Test insert with estimation (maintenance)
INSERT INTO road_maintenance_reports 
(report_id, report_type, title, department, priority, status, created_date, description, location, estimation, created_by, created_at) 
VALUES ('TEST-EST-002', 'road_damage', 'Test Maintenance', 'maintenance', 'medium', 'pending', CURDATE(), 'Test maintenance', 'Test Location', 3000.00, 1, NOW());

-- Verify the data was saved
SELECT id, report_id, title, estimation FROM road_transportation_reports WHERE report_id = 'TEST-EST-001';
SELECT id, report_id, title, estimation FROM road_maintenance_reports WHERE report_id = 'TEST-EST-002';

-- Clean up test data
DELETE FROM road_transportation_reports WHERE report_id = 'TEST-EST-001';
DELETE FROM road_maintenance_reports WHERE report_id = 'TEST-EST-002';
