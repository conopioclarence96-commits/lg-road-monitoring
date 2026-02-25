-- Check if estimation column exists in road_transportation_reports
DESCRIBE road_transportation_reports;

-- If estimation column doesn't exist, add it
ALTER TABLE road_transportation_reports 
ADD COLUMN IF NOT EXISTS estimation DECIMAL(10,2) DEFAULT 0.00 
AFTER resolution_notes;

-- Also add to road_maintenance_reports for consistency
ALTER TABLE road_maintenance_reports 
ADD COLUMN IF NOT EXISTS estimation DECIMAL(10,2) DEFAULT 0.00 
AFTER updated_at;

-- Show the updated structure
DESCRIBE road_transportation_reports;
