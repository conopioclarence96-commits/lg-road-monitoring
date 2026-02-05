-- Simple SQL to check for reports with images
-- Run this in your database management tool (phpMyAdmin, etc.)

SELECT 
    report_id,
    location,
    damage_type,
    severity,
    status,
    images,
    created_at,
    reported_at
FROM damage_reports 
WHERE images IS NOT NULL 
   AND images != 'null' 
   AND images != '' 
   AND images != '[]'
ORDER BY created_at DESC;

-- Also check total reports
SELECT 
    COUNT(*) as total_reports,
    COUNT(CASE WHEN images IS NOT NULL AND images != 'null' AND images != '' AND images != '[]' THEN 1 END) as reports_with_images
FROM damage_reports;
