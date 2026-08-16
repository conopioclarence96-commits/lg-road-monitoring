-- Start/end street addresses for IPMS road projects (TomTom reverse geocode).
-- Filled on first insert from start_lat/start_lng and end_lat/end_lng.
-- Run once against rgmap_lg_road_monitoring (phpMyAdmin or mysql CLI).

ALTER TABLE `ipms_road_projects`
	ADD COLUMN `start_address` VARCHAR(100) NULL DEFAULT NULL AFTER `status`,
	ADD COLUMN `end_address` VARCHAR(100) NULL DEFAULT NULL AFTER `start_address`;
