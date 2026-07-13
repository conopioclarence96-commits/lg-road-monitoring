-- =====================================================
-- MIGRATION: Add before_photo column to published_completed_projects
-- This enables the Before & After project comparison feature
-- =====================================================

-- Add before_photo column (after the existing photo column)
ALTER TABLE `published_completed_projects` 
ADD COLUMN `before_photo` VARCHAR(500) DEFAULT NULL AFTER `photo`;

-- Verify the change
DESCRIBE `published_completed_projects`;
