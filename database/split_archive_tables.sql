-- Split archives so each live table has a matching archive table.
-- Run once against rgmap_lg_road_monitoring (phpMyAdmin or mysql CLI).
-- Safe to re-run (IF NOT EXISTS / INSERT IGNORE).
--
--   cimm_verification_reports  -> cimm_verification_reports_archive
--   ipms_road_projects         -> ipms_road_projects_archive
--   road_transportation_reports -> road_transportation_reports_archive (unchanged)
--
-- Also creates ipms_sync_exclusions so Delete Forever is not undone by IPMS Sync.
--
-- NOTE: A current source dump (e.g. rgmap_lg_road_monitoring (20).sql) already
-- contains these three tables. After importing that dump you do not need this
-- script for schema — re-running it only fills leftover remapped rows (if any)
-- from road_transportation_reports_archive.

-- ---------------------------------------------------------------------------
-- CIMM archive (same columns as live + archive metadata)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cimm_verification_reports_archive` LIKE `cimm_verification_reports`;

ALTER TABLE `cimm_verification_reports_archive`
  ADD COLUMN IF NOT EXISTS `previous_status` VARCHAR(50) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `archived_at` DATETIME NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `archive_status` VARCHAR(50) NULL DEFAULT NULL;

-- Copies may share cimm_req_id with a later archive of the same report.
ALTER TABLE `cimm_verification_reports_archive` DROP INDEX IF EXISTS `uq_cimm_req`;
ALTER TABLE `cimm_verification_reports_archive` ADD INDEX IF NOT EXISTS `idx_cimm_req_arch` (`cimm_req_id`);
ALTER TABLE `cimm_verification_reports_archive` ADD INDEX IF NOT EXISTS `idx_archive_status` (`archive_status`);
ALTER TABLE `cimm_verification_reports_archive` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- IPMS archive (same columns as live + archive metadata)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ipms_road_projects_archive` LIKE `ipms_road_projects`;

ALTER TABLE `ipms_road_projects_archive`
  ADD COLUMN IF NOT EXISTS `previous_status` VARCHAR(50) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `archived_at` DATETIME NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `archive_status` VARCHAR(50) NULL DEFAULT NULL;

ALTER TABLE `ipms_road_projects_archive` ADD INDEX IF NOT EXISTS `idx_ipms_arch_status` (`archive_status`);

-- ---------------------------------------------------------------------------
-- Permanent local skip list for IPMS Sync (Delete Forever only)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ipms_sync_exclusions` (
  `project_id` INT UNSIGNED NOT NULL,
  `excluded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `excluded_by` VARCHAR(180) NULL DEFAULT NULL,
  `reason` VARCHAR(100) NULL DEFAULT NULL,
  PRIMARY KEY (`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Move existing remapped CIMM rows out of the shared archive
-- (column list matches road_transportation_reports_archive in the current dump)
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `cimm_verification_reports_archive` (
  `cimm_req_id`,
  `reference_code`,
  `infrastructure`,
  `location`,
  `issue`,
  `reporter_name`,
  `contact_number`,
  `email`,
  `district`,
  `coord_lat`,
  `coord_lng`,
  `approval_status`,
  `priority`,
  `engineer`,
  `budget`,
  `starting_date`,
  `estimated_end_date`,
  `submitted_at`,
  `portal_url`,
  `verification_status`,
  `created_at`,
  `previous_status`,
  `archived_at`,
  `archive_status`
)
SELECT
  COALESCE(
    `source_pk`,
    CASE
      WHEN `report_id` REGEXP '^[0-9]+$' THEN CAST(`report_id` AS UNSIGNED)
      WHEN `report_id` LIKE 'REQ-%' THEN CAST(SUBSTRING(`report_id`, 5) AS UNSIGNED)
      ELSE `id`
    END
  ),
  COALESCE(NULLIF(`report_id`, ''), CONCAT('CIMM-', `id`)),
  COALESCE(NULLIF(`title`, ''), 'CIMM Report'),
  COALESCE(`location`, ''),
  COALESCE(`description`, ''),
  `reporter_name`,
  `reporter_phone`,
  `reporter_email`,
  COALESCE(`cimm_district`, `district`),
  `latitude`,
  `longitude`,
  COALESCE(`approval_status`, 'Pending'),
  COALESCE(`priority`, 'medium'),
  COALESCE(`cimm_engineer_name`, `engineer`),
  COALESCE(`cimm_budget`, `budget_allocation`),
  `cimm_starting_date`,
  `cimm_estimated_end_date`,
  `created_at`,
  `cimm_report_url`,
  COALESCE(`cimm_status`, `previous_status`, 'Pending Review'),
  COALESCE(`created_at`, NOW()),
  `previous_status`,
  COALESCE(`updated_at`, NOW()),
  COALESCE(`status`, 'rejected')
FROM `road_transportation_reports_archive`
WHERE `archived_from` = 'cimm_verification_reports'
   OR `report_source` = 'external';

DELETE FROM `road_transportation_reports_archive`
WHERE `archived_from` = 'cimm_verification_reports'
   OR `report_source` = 'external';

-- ---------------------------------------------------------------------------
-- Move existing remapped IPMS rows out of the shared archive
-- Uses only columns that exist on the current dump's shared archive.
-- polyline / start_address / end_address are NULL here (those columns are
-- not on road_transportation_reports_archive in the source dump).
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `ipms_road_projects_archive` (
  `project_id`,
  `project_name`,
  `project_status`,
  `progress_percent`,
  `start_date`,
  `end_date`,
  `road_name`,
  `road_type`,
  `road_status`,
  `polyline_json`,
  `start_lat`,
  `start_lng`,
  `budget`,
  `start_address`,
  `end_address`,
  `status`,
  `priority`,
  `created_at`,
  `previous_status`,
  `archived_at`,
  `archive_status`
)
SELECT
  COALESCE(
    `source_pk`,
    CASE
      WHEN `report_id` LIKE 'IPMS-%' THEN CAST(SUBSTRING(`report_id`, 6) AS UNSIGNED)
      ELSE `id`
    END
  ),
  COALESCE(NULLIF(`title`, ''), 'Infrastructure Project'),
  'unknown',
  0,
  `cimm_starting_date`,
  COALESCE(`cimm_estimated_end_date`, `due_date`),
  COALESCE(NULLIF(`location`, ''), COALESCE(NULLIF(`title`, ''), 'Unnamed Road')),
  COALESCE(NULLIF(`report_type`, ''), 'unknown'),
  COALESCE(`description`, ''),
  NULL,
  `latitude`,
  `longitude`,
  COALESCE(`budget_allocation`, `cimm_budget`),
  NULL,
  NULL,
  COALESCE(`status`, 'rejected'),
  COALESCE(`priority`, 'medium'),
  COALESCE(`created_at`, NOW()),
  `previous_status`,
  COALESCE(`updated_at`, NOW()),
  COALESCE(`status`, 'rejected')
FROM `road_transportation_reports_archive`
WHERE `archived_from` = 'ipms_road_projects'
   OR `report_id` LIKE 'IPMS-%';

DELETE FROM `road_transportation_reports_archive`
WHERE `archived_from` = 'ipms_road_projects'
   OR `report_id` LIKE 'IPMS-%';
