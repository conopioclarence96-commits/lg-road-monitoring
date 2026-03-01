-- Add phone number column to users table for 2FA functionality
-- Run this SQL to update the users table structure

ALTER TABLE `users`
ADD COLUMN `phone_number` VARCHAR(20) NULL AFTER `civil_status`;
