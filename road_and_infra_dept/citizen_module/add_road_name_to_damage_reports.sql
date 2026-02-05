-- Add road_name column to damage_reports table
ALTER TABLE damage_reports
ADD COLUMN road_name VARCHAR(255) NOT NULL AFTER id;
