-- Fix inspector_id constraint to allow NULL values for citizen reports
-- This script modifies the inspections table to allow NULL inspector_id

-- First, drop the foreign key constraint
ALTER TABLE inspections DROP FOREIGN KEY inspections_ibfk_1;

-- Then, modify the column to allow NULL values
ALTER TABLE inspections MODIFY COLUMN inspector_id INT(11) NULL;

-- Re-add the foreign key constraint with ON DELETE SET NULL for citizen reports
ALTER TABLE inspections ADD CONSTRAINT inspections_ibfk_1 
    FOREIGN KEY (inspector_id) REFERENCES users(id) ON DELETE SET NULL;

-- Add a special user record for citizen reports if it doesn't exist
INSERT IGNORE INTO users (id, name, email, role, created_at) 
VALUES (999999, 'Citizen Reports', 'citizen@system.local', 'citizen', NOW());

-- Update any existing citizen reports to use the special user ID
UPDATE inspections SET inspector_id = 999999 WHERE inspector_id IS NULL;

SELECT 'inspector_id constraint fixed successfully' as message;
