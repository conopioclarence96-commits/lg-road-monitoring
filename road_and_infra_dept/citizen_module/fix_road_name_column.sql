-- Fix road_name column to allow NULL values
-- This script fixes the "Field 'road_name' doesn't have a default value" error

ALTER TABLE damage_reports 
MODIFY COLUMN road_name VARCHAR(255) NULL AFTER id;
