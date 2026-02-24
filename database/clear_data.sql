-- ============================================================
-- LGU Road Monitoring – data cleanup (one database: lg_road_monitoring)
-- Run in phpMyAdmin or: mysql -u root lg_road_monitoring < clear_data.sql
-- ============================================================
USE lg_road_monitoring;

-- ============================================================
-- SECTION 1: Clear published projects (Recent Publications)
-- ============================================================
DELETE FROM published_completed_projects;

-- ============================================================
-- SECTION 2: Clear ALL reports (transport + maintenance)
-- ============================================================
SET FOREIGN_KEY_CHECKS = 0;
DELETE FROM road_transportation_reports;
DELETE FROM road_maintenance_reports;
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- SECTION 3 (optional): Remove only dummy/sample reports
--    Use this INSTEAD of Section 2 if you want to keep real
--    reports and only remove sample data (RPT-202x, MNT-202x,
--    or reports with no attachments). Comment out Section 2
--    above and uncomment the block below.
-- ============================================================
/*
DELETE FROM road_transportation_reports
WHERE report_id LIKE 'RPT-2024-%' OR report_id LIKE 'RPT-2023-%'
   OR attachments IS NULL OR attachments = 'null'
   OR attachments = '[]' OR attachments = '';
DELETE FROM road_maintenance_reports
WHERE report_id LIKE 'MNT-2024-%' OR report_id LIKE 'MNT-2023-%';
*/
