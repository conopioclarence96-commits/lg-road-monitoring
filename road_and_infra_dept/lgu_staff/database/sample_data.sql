-- ========================================
-- Sample Data for LGU Road & Infrastructure Database
-- ========================================

USE lgu_road_infrastructure;

-- ========================================
-- Additional Sample Users
-- ========================================

INSERT INTO staff_users (username, password_hash, email, first_name, last_name, role, department, phone, is_active) VALUES
('jdelacruz', '$2b$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/LewdBPj6ukx.LrUpm', 'juan.delacruz@lgu.gov.ph', 'Juan', 'Dela Cruz', 'supervisor', 'Road & Infrastructure', '09123456789', TRUE),
('msantos', '$2b$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/LewdBPj6ukx.LrUpm', 'maria.santos@lgu.gov.ph', 'Maria', 'Santos', 'staff', 'Road & Infrastructure', '09123456788', TRUE),
('rreyes', '$2b$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/LewdBPj6ukx.LrUpm', 'roberto.reyes@lgu.gov.ph', 'Roberto', 'Reyes', 'technician', 'Road & Infrastructure', '09123456787', TRUE),
('emartinez', '$2b$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/LewdBPj6ukx.LrUpm', 'elena.martinez@lgu.gov.ph', 'Elena', 'Martinez', 'staff', 'Road & Infrastructure', '09123456786', TRUE),
('clim', '$2b$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/LewdBPj6ukx.LrUpm', 'carlos.lim@lgu.gov.ph', 'Carlos', 'Lim', 'technician', 'Road & Infrastructure', '09123456785', TRUE),
('sgarcia', '$2b$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/LewdBPj6ukx.LrUpm', 'sandra.garcia@lgu.gov.ph', 'Sandra', 'Garcia', 'admin', 'Road & Infrastructure', '09123456784', TRUE);

-- ========================================
-- Additional Sample Roads
-- ========================================

INSERT INTO roads (road_name, road_type, location_description, latitude, longitude, length_km, width_meters, surface_type, construction_year, last_maintenance_date, condition_rating, traffic_volume) VALUES
('Quezon Avenue', 'avenue', 'Major east-west corridor', 14.6195, 120.9642, 8.75, 12.0, 'asphalt', 2015, '2024-01-15', 'good', 'heavy'),
('Rizal Street', 'main_street', 'Historic downtown area', 14.6295, 120.9542, 3.25, 10.0, 'concrete', 2018, '2023-11-20', 'excellent', 'moderate'),
('Mabini Highway', 'highway', 'Coastal highway route', 14.5495, 121.0042, 45.50, 15.0, 'asphalt', 2012, '2024-02-01', 'fair', 'heavy'),
('Bonifacio Street', 'secondary_street', 'Commercial district', 14.6395, 120.9442, 2.15, 8.0, 'asphalt', 2020, '2023-12-10', 'excellent', 'moderate'),
('Luna Avenue', 'avenue', 'University belt area', 14.5695, 120.9342, 4.80, 10.0, 'concrete', 2019, '2024-01-20', 'good', 'light'),
('Aguirre Street', 'secondary_street', 'Residential subdivision', 14.5795, 120.9242, 1.95, 6.0, 'asphalt', 2021, '2023-10-15', 'excellent', 'light'),
('Macapagal Bridge', 'bridge', 'River crossing', 14.5895, 120.9142, 0.85, 20.0, 'concrete', 2017, '2023-09-05', 'good', 'moderate'),
('España Boulevard', 'avenue', 'University main road', 14.5995, 120.9042, 6.20, 12.0, 'asphalt', 2016, '2024-02-05', 'fair', 'heavy');

-- ========================================
-- Sample Road Incidents
-- ========================================

INSERT INTO road_incidents (road_id, incident_type, severity_level, title, description, latitude, longitude, reported_by, reporter_contact, incident_date, status, assigned_staff_id) VALUES
(1, 'pothole', 'high', 'Large pothole on Highway 101', 'Multiple large potholes causing traffic disruption and vehicle damage', 14.5995, 120.9842, 'Citizen Report', '09123456789', '2024-02-17 08:30:00', 'pending', 2),
(2, 'traffic_light', 'medium', 'Traffic light malfunction', 'Traffic lights not functioning properly at Main Street intersection', 14.6095, 120.9742, 'Maria Santos', '09123456788', '2024-02-17 09:15:00', 'under_review', 3),
(3, 'flooding', 'critical', 'Severe flooding on Oak Avenue', 'Road completely flooded due to heavy rain, impassable', 14.5895, 120.9942, 'Emergency Call', '09123456787', '2024-02-17 07:45:00', 'in_progress', 4),
(4, 'crack', 'medium', 'Longitudinal crack on Elm Street', 'Extensive cracking along the center line of the road', 14.5795, 120.9642, 'Juan Dela Cruz', '09123456786', '2024-02-17 10:00:00', 'pending', 5),
(5, 'accident', 'high', 'Multi-vehicle accident', 'Traffic accident involving 3 vehicles, road partially blocked', 14.6195, 120.9642, 'Police Report', '09123456785', '2024-02-17 06:30:00', 'resolved', 6),
(6, 'debris', 'low', 'Road debris on Quezon Avenue', 'Fallen branches and debris on the road shoulder', 14.6295, 120.9542, 'Citizen Report', '09123456784', '2024-02-17 11:20:00', 'pending', 2),
(7, 'sign_damage', 'medium', 'Damaged traffic sign', 'Stop sign damaged and barely visible', 14.6395, 120.9442, 'Traffic Patrol', '09123456783', '2024-02-17 12:00:00', 'approved', 3),
(8, 'erosion', 'high', 'Road shoulder erosion', 'Severe erosion of road shoulder, risk of collapse', 14.5495, 121.0042, 'Engineering Team', '09123456782', '2024-02-17 13:30:00', 'in_review', 4);

-- ========================================
-- Sample Incident Photos
-- ========================================

INSERT INTO incident_photos (incident_id, photo_url, photo_description, uploaded_by, file_size, mime_type) VALUES
(1, '/uploads/incidents/pothole_highway101_1.jpg', 'Overview of large pothole cluster', 2, 2048576, 'image/jpeg'),
(1, '/uploads/incidents/pothole_highway101_2.jpg', 'Close-up of pothole depth', 2, 1536789, 'image/jpeg'),
(2, '/uploads/incidents/traffic_light_mainstreet_1.jpg', 'Malfunctioning traffic light', 3, 1024567, 'image/jpeg'),
(3, '/uploads/incidents/flooding_oakave_1.jpg', 'Aerial view of flooded area', 4, 3072456, 'image/jpeg'),
(3, '/uploads/incidents/flooding_oakave_2.jpg', 'Water level measurement', 4, 1847293, 'image/jpeg'),
(4, '/uploads/incidents/crack_elmstreet_1.jpg', 'Longitudinal crack pattern', 5, 1567892, 'image/jpeg'),
(5, '/uploads/incidents/accident_quezon_1.jpg', 'Accident scene overview', 6, 2567891, 'image/jpeg'),
(8, '/uploads/incidents/erosion_mabini_1.jpg', 'Eroded road shoulder', 4, 2245678, 'image/jpeg');

-- ========================================
-- Sample Verification Requests
-- ========================================

INSERT INTO verification_requests (incident_id, request_type, priority_level, title, description, requested_by, assigned_verifier, status, verification_notes) VALUES
(1, 'road_damage', 'high', 'Highway 101 Pothole Repair', 'Emergency repair needed for multiple large potholes', 2, 2, 'pending', 'Awaiting site inspection'),
(2, 'traffic_light', 'medium', 'Traffic Light Repair', 'Repair malfunctioning traffic signals', 3, 3, 'in_review', 'Technician assigned for inspection'),
(3, 'maintenance', 'critical', 'Oak Avenue Flood Response', 'Emergency flood response and drainage clearing', 4, 4, 'approved', 'Emergency work order issued'),
(4, 'road_damage', 'medium', 'Elm Street Crack Repair', 'Repair longitudinal cracks before they expand', 5, 5, 'pending', 'Scheduled for next inspection'),
(5, 'maintenance', 'high', 'Quezon Avenue Accident Cleanup', 'Post-accident cleanup and road inspection', 6, 6, 'completed', 'Road cleared and inspected'),
(6, 'maintenance', 'low', 'Quezon Avenue Debris Removal', 'Remove fallen branches and debris', 2, 2, 'pending', 'Awaiting maintenance schedule'),
(7, 'maintenance', 'medium', 'Traffic Sign Replacement', 'Replace damaged stop sign', 3, 3, 'approved', 'Sign replacement scheduled'),
(8, 'road_damage', 'high', 'Mabini Highway Erosion Control', 'Urgent erosion control needed', 4, 4, 'in_review', 'Engineering assessment in progress');

-- ========================================
-- Sample Verification Timeline
-- ========================================

INSERT INTO verification_timeline (request_id, action_type, action_by, action_notes, timestamp) VALUES
(1, 'created', 2, 'Initial verification request created', '2024-02-17 08:35:00'),
(3, 'created', 4, 'Emergency flood response request', '2024-02-17 07:50:00'),
(3, 'approved', 4, 'Emergency approval granted', '2024-02-17 08:15:00'),
(5, 'created', 6, 'Post-accident cleanup request', '2024-02-17 06:35:00'),
(5, 'approved', 6, 'Cleanup approved and completed', '2024-02-17 07:30:00'),
(7, 'created', 3, 'Traffic sign damage reported', '2024-02-17 12:05:00'),
(7, 'approved', 3, 'Sign replacement approved', '2024-02-17 12:45:00'),
(2, 'created', 3, 'Traffic light malfunction report', '2024-02-17 09:20:00'),
(2, 'assigned', 3, 'Technician assigned for inspection', '2024-02-17 10:00:00'),
(2, 'review_started', 3, 'Inspection in progress', '2024-02-17 11:30:00');

-- ========================================
-- Sample Maintenance Schedules
-- ========================================

INSERT INTO maintenance_schedules (road_id, maintenance_type, title, description, scheduled_date, estimated_duration_days, estimated_cost, status, assigned_team_lead) VALUES
(1, 'emergency', 'Highway 101 Pothole Repair', 'Emergency repair of critical potholes', '2024-02-18', 2, 15000.00, 'scheduled', 5),
(2, 'routine', 'Traffic Light Maintenance', 'Quarterly inspection and maintenance', '2024-02-20', 1, 5000.00, 'scheduled', 3),
(3, 'emergency', 'Oak Avenue Flood Response', 'Emergency drainage clearing and flood response', '2024-02-17', 1, 8000.00, 'in_progress', 4),
(4, 'preventive', 'Crack Sealing Program', 'Preventive crack sealing to prevent further damage', '2024-02-25', 3, 12000.00, 'scheduled', 5),
(6, 'routine', 'Debris Removal', 'Regular debris clearing and cleanup', '2024-02-19', 1, 3000.00, 'scheduled', 2),
(7, 'corrective', 'Sign Replacement', 'Replace damaged traffic signs', '2024-02-21', 1, 2500.00, 'scheduled', 3),
(8, 'emergency', 'Erosion Control', 'Urgent erosion control measures', '2024-02-22', 5, 25000.00, 'scheduled', 4);

-- ========================================
-- Sample Work Orders
-- ========================================

INSERT INTO work_orders (schedule_id, incident_id, work_order_number, title, description, priority, status, assigned_to, created_by, due_date) VALUES
(1, 1, 'WO-2024-0217-001', 'Highway 101 Pothole Repair', 'Repair multiple large potholes on Highway 101', 'high', 'assigned', 5, 2, '2024-02-19 17:00:00'),
(2, 2, 'WO-2024-0217-002', 'Traffic Light Repair', 'Repair malfunctioning traffic signals at Main Street', 'medium', 'pending', 3, 3, '2024-02-21 17:00:00'),
(3, 3, 'WO-2024-0217-003', 'Flood Response', 'Emergency flood response and drainage clearing', 'urgent', 'in_progress', 4, 4, '2024-02-17 23:59:00'),
(4, 4, 'WO-2024-0217-004', 'Crack Sealing', 'Preventive crack sealing on Elm Street', 'medium', 'pending', 5, 5, '2024-02-28 17:00:00'),
(5, 5, 'WO-2024-0217-005', 'Accident Cleanup', 'Post-accident cleanup and inspection', 'high', 'completed', 6, 6, '2024-02-17 12:00:00'),
(6, 6, 'WO-2024-0217-006', 'Debris Removal', 'Remove fallen branches and debris', 'low', 'pending', 2, 2, '2024-02-20 17:00:00'),
(7, 7, 'WO-2024-0217-007', 'Sign Replacement', 'Replace damaged stop sign', 'medium', 'assigned', 3, 3, '2024-02-22 17:00:00'),
(8, 8, 'WO-2024-0217-008', 'Erosion Control', 'Implement erosion control measures', 'high', 'pending', 4, 4, '2024-02-27 17:00:00');

-- ========================================
-- Sample Public Documents
-- ========================================

INSERT INTO public_documents (title, document_type, description, file_url, publication_date, is_public, uploaded_by) VALUES
('Infrastructure Development Report 2024', 'annual_report', 'Comprehensive annual report on infrastructure development', '/documents/annual_report_2024.pdf', '2024-02-10', TRUE, 6),
('Q1 2024 Budget Allocation', 'budget_report', 'First quarter budget allocation breakdown', '/documents/q1_budget_2024.pdf', '2024-02-05', TRUE, 6),
('Service Delivery Performance Metrics', 'performance_metrics', 'Monthly performance metrics and KPIs', '/documents/performance_jan2024.pdf', '2024-01-31', TRUE, 2),
('Infrastructure Development Policy 2024-2028', 'policy_document', 'Long-term infrastructure development policy', '/documents/policy_2024_2028.pdf', '2024-01-15', TRUE, 6),
('Road Maintenance Guidelines', 'policy_document', 'Standard operating procedures for road maintenance', '/documents/maintenance_guidelines.pdf', '2024-01-20', TRUE, 2),
('Emergency Response Protocol', 'policy_document', 'Emergency response procedures for road incidents', '/documents/emergency_protocol.pdf', '2024-01-25', TRUE, 3);

-- ========================================
-- Sample Budget Allocations
-- ========================================

INSERT INTO budget_allocations (fiscal_year, department, category, allocated_amount, spent_amount, description) VALUES
(2024, 'Road & Infrastructure', 'maintenance', 50000000.00, 12500000.00, 'Annual road maintenance budget'),
(2024, 'Road & Infrastructure', 'construction', 75000000.00, 18500000.00, 'Road construction and rehabilitation projects'),
(2024, 'Road & Infrastructure', 'operations', 25000000.00, 6250000.00, 'Department operational expenses'),
(2024, 'Road & Infrastructure', 'equipment', 15000000.00, 3750000.00, 'Heavy equipment and tools procurement'),
(2024, 'Road & Infrastructure', 'personnel', 30000000.00, 7500000.00, 'Staff salaries and benefits'),
(2023, 'Road & Infrastructure', 'maintenance', 45000000.00, 43500000.00, 'Previous year maintenance budget'),
(2023, 'Road & Infrastructure', 'construction', 65000000.00, 62000000.00, 'Previous year construction budget');

-- ========================================
-- Sample Projects
-- ========================================

INSERT INTO projects (project_name, project_code, description, project_type, budget_allocation_id, total_budget, amount_spent, start_date, planned_completion_date, status, progress_percentage, project_manager, contractor_name) VALUES
('Highway 101 Rehabilitation', 'PROJ-2024-001', 'Complete rehabilitation of Highway 101', 'road_rehabilitation', 2, 25000000.00, 8500000.00, '2024-01-15', '2024-06-30', 'in_progress', 34.0, 2, 'ABC Construction Co.'),
('Quezon Avenue Widening', 'PROJ-2024-002', 'Widening of Quezon Avenue to 4 lanes', 'road_construction', 2, 30000000.00, 5000000.00, '2024-02-01', '2024-08-31', 'in_progress', 16.7, 3, 'Metro Builders Inc.'),
('Drainage System Upgrade', 'PROJ-2024-003', 'Upgrade drainage systems in flood-prone areas', 'drainage', 1, 15000000.00, 2000000.00, '2024-02-10', '2024-05-31', 'in_progress', 13.3, 4, 'WaterWorks Solutions'),
('Traffic Signal Modernization', 'PROJ-2024-004', 'Install modern traffic signals', 'traffic_systems', 1, 8000000.00, 1500000.00, '2024-01-20', '2024-04-30', 'in_progress', 18.8, 5, 'Smart Traffic Systems'),
('Bridge Repair Program', 'PROJ-2024-005', 'Repair and strengthen aging bridges', 'bridge_repair', 2, 12000000.00, 0.00, '2024-03-01', '2024-07-31', 'planning', 0.0, 6, 'Bridge Engineering Corp'),
('Street Lighting Project', 'PROJ-2023-001', 'Install LED street lights', 'traffic_systems', 1, 6000000.00, 5800000.00, '2023-09-01', '2024-01-31', 'completed', 96.7, 2, 'LightTech Solutions');

-- ========================================
-- Sample Generated Reports
-- ========================================

INSERT INTO generated_reports (report_name, report_type, report_category, parameters, file_url, generated_by, date_from, date_to) VALUES
('Daily Incident Report - Feb 17, 2024', 'daily', 'incidents', '{"date": "2024-02-17"}', '/reports/daily_incident_20240217.pdf', 2, '2024-02-17', '2024-02-17'),
('Weekly Maintenance Summary - Feb 12-17, 2024', 'weekly', 'maintenance', '{"week": 7, "year": 2024}', '/reports/weekly_maintenance_20240217.pdf', 3, '2024-02-12', '2024-02-17'),
('Monthly Performance Report - January 2024', 'monthly', 'performance', '{"month": 1, "year": 2024}', '/reports/monthly_performance_202401.pdf', 6, '2024-01-01', '2024-01-31'),
('Budget Utilization Report - Q1 2024', 'quarterly', 'budget', '{"quarter": 1, "year": 2024}', '/reports/q1_budget_2024.pdf', 6, '2024-01-01', '2024-03-31'),
('Annual Transparency Report 2023', 'annual', 'transparency', '{"year": 2023}', '/reports/annual_transparency_2023.pdf', 6, '2023-01-01', '2023-12-31');

-- ========================================
-- Sample Performance Metrics
-- ========================================

INSERT INTO performance_metrics (metric_name, metric_category, metric_value, metric_unit, target_value, measurement_date, period_type, notes) VALUES
('Average Response Time', 'response_time', 2.5, 'hours', 2.0, '2024-02-17', 'daily', 'Response time for incident reports'),
('Resolution Rate', 'resolution_rate', 85.5, 'percent', 90.0, '2024-02-17', 'daily', 'Percentage of resolved incidents'),
('Budget Utilization', 'budget_utilization', 37.2, 'percent', 40.0, '2024-02-17', 'monthly', 'Q1 budget utilization'),
('Service Delivery Score', 'service_delivery', 94.2, 'percent', 95.0, '2024-02-17', 'monthly', 'Overall service delivery performance'),
('Citizen Satisfaction', 'citizen_satisfaction', 4.6, 'rating', 4.5, '2024-02-17', 'monthly', 'Citizen satisfaction survey results'),
('Maintenance Completion Rate', 'service_delivery', 92.8, 'percent', 90.0, '2024-02-17', 'weekly', 'Scheduled maintenance completion'),
('Emergency Response Time', 'response_time', 1.2, 'hours', 1.5, '2024-02-17', 'daily', 'Emergency incident response'),
('Inspection Compliance', 'service_delivery', 98.5, 'percent', 95.0, '2024-02-17', 'weekly', 'Regular inspection compliance');

-- ========================================
-- Sample Activity Logs
-- ========================================

INSERT INTO activity_logs (user_id, action_type, table_name, record_id, old_values, new_values, ip_address, user_agent) VALUES
(2, 'INSERT', 'road_incidents', 1, NULL, '{"incident_type": "pothole", "severity_level": "high", "status": "pending"}', '192.168.1.100', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'),
(3, 'INSERT', 'verification_requests', 2, NULL, '{"request_type": "traffic_light", "priority_level": "medium", "status": "pending"}', '192.168.1.101', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'),
(4, 'UPDATE', 'verification_requests', 3, '{"status": "pending"}', '{"status": "approved"}', '192.168.1.102', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'),
(5, 'INSERT', 'work_orders', 4, NULL, '{"priority": "medium", "status": "pending"}', '192.168.1.103', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'),
(6, 'UPDATE', 'road_incidents', 5, '{"status": "pending"}', '{"status": "resolved"}', '192.168.1.104', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');

-- ========================================
-- Sample System Notifications
-- ========================================

INSERT INTO system_notifications (user_id, title, message, notification_type, related_table, related_record_id, is_read, created_at) VALUES
(2, 'New Incident Assigned', 'You have been assigned to incident #1: Highway 101 Pothole Repair', 'info', 'road_incidents', 1, FALSE, '2024-02-17 08:35:00'),
(3, 'Verification Request', 'New verification request #2 requires your attention', 'warning', 'verification_requests', 2, FALSE, '2024-02-17 09:25:00'),
(4, 'Emergency Incident', 'Critical incident #3 requires immediate attention', 'error', 'road_incidents', 3, FALSE, '2024-02-17 07:55:00'),
(5, 'Work Order Assigned', 'Work order #4 has been assigned to you', 'info', 'work_orders', 4, FALSE, '2024-02-17 10:15:00'),
(6, 'Task Completed', 'Work order #5 has been completed successfully', 'success', 'work_orders', 5, TRUE, '2024-02-17 12:30:00'),
(2, 'Schedule Update', 'Maintenance schedule updated for Highway 101', 'info', 'maintenance_schedules', 1, FALSE, '2024-02-17 14:20:00'),
(3, 'Approval Required', 'Verification request #7 requires your approval', 'warning', 'verification_requests', 7, FALSE, '2024-02-17 12:50:00'),
(4, 'Budget Alert', 'Budget utilization for Q1 2024 is at 37.2%', 'info', 'budget_allocations', 2, FALSE, '2024-02-17 16:45:00');

-- ========================================
-- Sample User Sessions (for testing)
-- ========================================

INSERT INTO user_sessions (session_id, user_id, ip_address, user_agent, expires_at, is_active) VALUES
('sess_abc123def456', 2, '192.168.1.100', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36', '2024-02-17 18:00:00', TRUE),
('sess_def789ghi012', 3, '192.168.1.101', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36', '2024-02-17 17:30:00', TRUE),
('sess_ghi345jkl678', 4, '192.168.1.102', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36', '2024-02-17 19:00:00', TRUE);

-- ========================================
-- Verification of Sample Data
-- ========================================

-- Display summary of inserted data
SELECT 'Sample Data Insertion Complete' as status;

SELECT CONCAT('Staff Users: ', COUNT(*)) as summary FROM staff_users;
SELECT CONCAT('Roads: ', COUNT(*)) as summary FROM roads;
SELECT CONCAT('Incidents: ', COUNT(*)) as summary FROM road_incidents;
SELECT CONCAT('Verification Requests: ', COUNT(*)) as summary FROM verification_requests;
SELECT CONCAT('Work Orders: ', COUNT(*)) as summary FROM work_orders;
SELECT CONCAT('Projects: ', COUNT(*)) as summary FROM projects;
SELECT CONCAT('Public Documents: ', COUNT(*)) as summary FROM public_documents;

-- Show some sample relationships
SELECT 
    ri.incident_id,
    ri.title,
    ri.status,
    r.road_name,
    CONCAT(su.first_name, ' ', su.last_name) as assigned_staff
FROM road_incidents ri
JOIN roads r ON ri.road_id = r.road_id
LEFT JOIN staff_users su ON ri.assigned_staff_id = su.user_id
ORDER BY ri.incident_date DESC
LIMIT 5;

SELECT 
    vr.request_id,
    vr.title,
    vr.status,
    vr.priority_level,
    CONCAT(req.first_name, ' ', req.last_name) as requested_by,
    CONCAT(ver.first_name, ' ', ver.last_name) as verifier
FROM verification_requests vr
LEFT JOIN staff_users req ON vr.requested_by = req.user_id
LEFT JOIN staff_users ver ON vr.assigned_verifier = ver.user_id
ORDER BY vr.created_at DESC
LIMIT 5;
