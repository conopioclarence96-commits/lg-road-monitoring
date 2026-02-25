-- Add specific issue type support to road transportation reports
-- This script adds the necessary columns to handle specific issue types

-- Add specific_type column to road_transportation_reports table
ALTER TABLE road_transportation_reports 
ADD COLUMN IF NOT EXISTS specific_type VARCHAR(100) DEFAULT NULL 
AFTER report_type;

-- Add specific_type column to road_maintenance_reports table (for future use)
ALTER TABLE road_maintenance_reports 
ADD COLUMN IF NOT EXISTS specific_type VARCHAR(100) DEFAULT NULL 
AFTER report_type;

-- Update existing records to migrate data from report_type to specific_type where applicable
UPDATE road_transportation_reports 
SET specific_type = report_type 
WHERE report_type IN (
    'traffic_jam', 'accident', 'road_closure', 'traffic_light_outage', 
    'congestion', 'parking_violation', 'public_transport_issue',
    'potholes', 'road_damage', 'cracks', 'erosion', 'flooding', 
    'debris', 'shoulder_damage', 'marking_fade'
);

-- Create index for better performance
CREATE INDEX IF NOT EXISTS idx_specific_type ON road_transportation_reports(specific_type);

-- Update general report_type for existing specific types to maintain categorization
UPDATE road_transportation_reports 
SET report_type = CASE 
    WHEN report_type IN ('traffic_jam', 'accident', 'road_closure', 'traffic_light_outage', 'congestion', 'parking_violation', 'public_transport_issue') 
        THEN 'traffic'
    WHEN report_type IN ('potholes', 'road_damage', 'cracks', 'erosion', 'flooding', 'debris', 'shoulder_damage', 'marking_fade') 
        THEN 'road_damage'
    ELSE report_type
END
WHERE report_type IN (
    'traffic_jam', 'accident', 'road_closure', 'traffic_light_outage', 
    'congestion', 'parking_violation', 'public_transport_issue',
    'potholes', 'road_damage', 'cracks', 'erosion', 'flooding', 
    'debris', 'shoulder_damage', 'marking_fade'
);

-- Show the updated structure
DESCRIBE road_transportation_reports;
