-- Add estimation column to road_transportation_reports table
ALTER TABLE road_transportation_reports 
ADD COLUMN estimation DECIMAL(12,2) DEFAULT 0.00 
AFTER assigned_to;

-- Add estimation column to road_maintenance_reports table  
ALTER TABLE road_maintenance_reports
ADD COLUMN estimation DECIMAL(12,2) DEFAULT 0.00
AFTER maintenance_team;

-- Add index for better performance on estimation queries
CREATE INDEX idx_transportation_estimation ON road_transportation_reports(estimation);
CREATE INDEX idx_maintenance_estimation ON road_maintenance_reports(estimation);
