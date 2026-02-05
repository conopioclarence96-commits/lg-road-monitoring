-- Quick fix for inspector_id constraint issue
-- Run this script to allow NULL values for citizen reports

-- Drop the foreign key constraint completely
ALTER TABLE inspections DROP FOREIGN KEY IF EXISTS inspections_ibfk_1;

-- Allow NULL values in inspector_id column
ALTER TABLE inspections MODIFY COLUMN inspector_id INT(11) NULL;

-- Don't re-add the foreign key constraint for now to allow flexibility

SELECT 'inspector_id constraint removed successfully' as message;
