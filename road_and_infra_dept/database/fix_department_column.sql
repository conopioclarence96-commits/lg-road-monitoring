-- =====================================================
-- FIX DEPARTMENT COLUMN FOR ROAD_TRANSPORTATION_REPORTS
-- =====================================================

-- 1. Modify department column to VARCHAR(255) to accept any text
ALTER TABLE `road_transportation_reports` 
MODIFY COLUMN `department` VARCHAR(255) NOT NULL;

-- 2. Update existing records with default department if needed
UPDATE `road_transportation_reports` 
SET `department` = 'Road and Transportation' 
WHERE `department` NOT IN ('engineering', 'planning', 'maintenance', 'finance');

-- =====================================================
-- VERIFICATION QUERIES (run these to verify the fix)
-- =====================================================

-- Check table structure
DESCRIBE `road_transportation_reports`;

-- Check if update worked
SELECT COUNT(*) as updated_count 
FROM `road_transportation_reports` 
WHERE `department` = 'Road and Transportation';

-- =====================================================
-- NOTES
-- =====================================================
-- This script will:
-- 1. Change department from ENUM to VARCHAR(255)
-- 2. Update any existing records to use 'Road and Transportation'
-- 3. Allow any department value to be inserted without truncation
-- 4. Fix the "Data truncated for column 'department'" error
