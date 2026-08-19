-- Local workflow status for IPMS road projects (pending/approved/etc).
-- Set on insert only; IPMS sync updates must not overwrite this column.
-- Run once against rgmap_lg_road_monitoring (phpMyAdmin or mysql CLI).

ALTER TABLE `ipms_road_projects`
	ADD COLUMN `status` VARCHAR(50) NULL DEFAULT NULL AFTER `created_at`;
