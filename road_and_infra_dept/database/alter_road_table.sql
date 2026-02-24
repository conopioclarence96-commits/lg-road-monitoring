-- =====================================================
-- ALTER TABLE: road_transportation_reports
-- Purpose: Add image_path column for uploaded report photos
-- =====================================================

USE lg_road_monitoring;

-- Add image_path column to store the file path of uploaded photos
ALTER TABLE road_transportation_reports 
ADD COLUMN IF NOT EXISTS image_path VARCHAR(500) NULL COMMENT 'File path to uploaded report image';

-- Verify the column was added
DESCRIBE road_transportation_reports;
