-- Add id_file_path column to users table for storing uploaded ID file paths
-- Run this SQL to update the users table structure

ALTER TABLE `users`
ADD COLUMN `id_file_path` VARCHAR(255) NULL AFTER `civil_status`;
