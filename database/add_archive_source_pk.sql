-- Original live PK used on restore so report_updates stay attached.
-- Run once against rgmap_lg_road_monitoring (phpMyAdmin or mysql CLI).
-- Safe to re-run.

ALTER TABLE road_transportation_reports_archive
  ADD COLUMN IF NOT EXISTS source_pk INT NULL DEFAULT NULL;
