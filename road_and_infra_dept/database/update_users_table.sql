-- Add new columns to users table
-- Run this SQL to update the users table structure

ALTER TABLE `users`
ADD COLUMN `address` VARCHAR(100) NULL AFTER `department`,
ADD COLUMN `birthday` DATE NULL AFTER `address`,
ADD COLUMN `civil_status` VARCHAR(50) NULL AFTER `birthday`;
