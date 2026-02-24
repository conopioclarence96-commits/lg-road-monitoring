-- =====================================================
-- ADD SAMPLE DATA FOR TESTING
-- Purpose: Add sample data for documents, document_views and document_downloads
-- =====================================================

USE lg_road_monitoring;

-- Insert sample documents first (only if table is empty)
INSERT IGNORE INTO documents (document_id, title, description, document_type, is_published) VALUES
('DOC001', 'Annual Budget Report 2024', 'Comprehensive budget allocation and spending report for fiscal year 2024', 'PDF', 1),
('DOC002', 'Infrastructure Project Plan', 'Detailed roadmap for upcoming infrastructure projects including timelines and budgets', 'PDF', 1),
('DOC003', 'Road Maintenance Schedule', 'Quarterly road maintenance and repair schedule for all districts', 'Excel', 0),
('DOC004', 'Public Transparency Policy', 'Official policy document on government transparency and public information access', 'PDF', 1);

-- Insert sample document views (only if tables are empty)
INSERT IGNORE INTO document_views (document_id, user_id, views, ip_address) VALUES
(1, 1, 45, '192.168.1.100'),
(2, NULL, 23, '192.168.1.101'),
(3, 2, 67, '192.168.1.102'),
(1, NULL, 12, '192.168.1.103'),
(4, 3, 89, '192.168.1.104');

-- Insert sample document downloads (only if tables are empty)
INSERT IGNORE INTO document_downloads (document_id, ip_address) VALUES
('DOC001', '192.168.1.100'),
('DOC002', '192.168.1.101'),
('DOC003', '192.168.1.102'),
('DOC001', '192.168.1.103'),
('DOC004', '192.168.1.104'),
('DOC002', '192.168.1.105');

-- Insert sample budget allocation data
INSERT IGNORE INTO budget_allocation (year, annual_budget, allocation_percentage, department, allocated_amount, spent_amount) VALUES
(2024, 125000000.00, 89.00, 'Road Maintenance', 45000000.00, 38250000.00),
(2024, 125000000.00, 89.00, 'Infrastructure Development', 35000000.00, 28900000.00),
(2024, 125000000.00, 89.00, 'Bridge Construction', 25000000.00, 15300000.00),
(2024, 125000000.00, 89.00, 'Street Lighting', 12500000.00, 3000000.00),
(2024, 125000000.00, 89.00, 'Drainage Systems', 7500000.00, 0.00);

-- Insert sample infrastructure projects
INSERT IGNORE INTO infrastructure_projects (name, location, budget, progress, status, start_date, end_date) VALUES
('Main Street Rehabilitation', 'Downtown District', 8500000.00, 75, 'active', '2024-01-15', '2024-12-31'),
('Highway 101 Expansion', 'North Corridor', 12000000.00, 45, 'active', '2024-02-01', '2025-06-30'),
('Bridge Repair Project', 'River Crossing', 5200000.00, 90, 'active', '2023-11-01', '2024-03-31'),
('Street Lighting Upgrade', 'Residential Areas', 3800000.00, 30, 'delayed', '2024-03-01', '2024-09-30'),
('Drainage System Installation', 'Flood-prone Areas', 7100000.00, 60, 'active', '2024-01-01', '2024-08-31'),
('Park Avenue Reconstruction', 'Central District', 4500000.00, 100, 'completed', '2023-09-01', '2024-01-15'),
('Sidewalk Improvement Project', 'Suburban Areas', 2300000.00, 100, 'completed', '2023-10-01', '2024-02-28');

-- Insert sample publications
INSERT IGNORE INTO publications (title, content, publish_date, author, category) VALUES
('Q1 Infrastructure Report', 'Comprehensive report on infrastructure projects completed in Q1 2024', '2024-04-15', 'Infrastructure Department', 'Reports'),
('Budget Allocation Update', 'Updated budget allocation for remaining fiscal year 2024', '2024-05-01', 'Finance Department', 'Budget'),
('Public Transparency Initiative', 'New initiatives for improving government transparency', '2024-05-10', 'LGU Office', 'Policy'),
('Road Maintenance Schedule', 'Updated schedule for road maintenance activities', '2024-05-20', 'Road Maintenance Dept', 'Schedules');

-- Verify data was inserted
SELECT 'documents' as table_name, COUNT(*) as record_count FROM documents
UNION ALL
SELECT 'document_views' as table_name, COUNT(*) as record_count FROM document_views
UNION ALL
SELECT 'document_downloads' as table_name, COUNT(*) as record_count FROM document_downloads
UNION ALL
SELECT 'budget_allocation' as table_name, COUNT(*) as record_count FROM budget_allocation
UNION ALL
SELECT 'infrastructure_projects' as table_name, COUNT(*) as record_count FROM infrastructure_projects
UNION ALL
SELECT 'publications' as table_name, COUNT(*) as record_count FROM publications;
