-- Fix Database Schema for Citizen Module
-- This script updates the damage_reports table to match the citizen module requirements
-- Run this script in phpMyAdmin if you're getting column errors

-- First, check if we need to add missing columns to the existing damage_reports table
ALTER TABLE damage_reports 
ADD COLUMN IF NOT EXISTS barangay VARCHAR(100) NOT NULL DEFAULT '',
ADD COLUMN IF NOT EXISTS damage_type VARCHAR(50) NOT NULL DEFAULT 'other',
ADD COLUMN IF NOT EXISTS estimated_size VARCHAR(100) NULL,
ADD COLUMN IF NOT EXISTS traffic_impact ENUM('none', 'minor', 'moderate', 'severe', 'blocked') DEFAULT 'moderate',
ADD COLUMN IF NOT EXISTS contact_number VARCHAR(20) NULL,
ADD COLUMN IF NOT EXISTS anonymous_report TINYINT(1) DEFAULT 0,
ADD COLUMN IF NOT EXISTS lgu_notes TEXT NULL;

-- Update the severity enum to include 'urgent' if it doesn't exist
-- Note: MySQL doesn't support ALTER ENUM directly, so we need to modify it
ALTER TABLE damage_reports 
MODIFY COLUMN severity ENUM('low', 'medium', 'high', 'urgent') NOT NULL DEFAULT 'medium';

-- Update the status enum to include all needed values
ALTER TABLE damage_reports 
MODIFY COLUMN status ENUM('pending', 'under_review', 'approved', 'in_progress', 'completed', 'rejected') DEFAULT 'pending';

-- Make sure created_at exists (if the table uses reported_at instead)
ALTER TABLE damage_reports 
ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

-- If reported_at exists but created_at doesn't, copy the data
UPDATE damage_reports SET created_at = reported_at WHERE created_at IS NULL AND reported_at IS NOT NULL;

-- Make sure images column is JSON type
ALTER TABLE damage_reports 
MODIFY COLUMN images JSON;

-- Add indexes for better performance
CREATE INDEX IF NOT EXISTS idx_damage_reports_barangay ON damage_reports(barangay);
CREATE INDEX IF NOT EXISTS idx_damage_reports_damage_type ON damage_reports(damage_type);
CREATE INDEX IF NOT EXISTS idx_damage_reports_traffic_impact ON damage_reports(traffic_impact);

-- Show the final table structure
DESCRIBE damage_reports;
