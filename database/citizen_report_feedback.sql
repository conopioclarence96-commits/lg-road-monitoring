-- =====================================================
-- citizen_report_feedback — completed-project ratings
-- =====================================================
-- Purpose: 1–5 star ratings + optional comment on
--          published_completed_projects (transparency cards).
-- Renamed from: citizen_feedback
--
-- If you already have data in citizen_feedback, run this first:
--   RENAME TABLE `citizen_feedback` TO `citizen_report_feedback`;
-- Then skip CREATE if the rename succeeded.
-- =====================================================

CREATE TABLE IF NOT EXISTS `citizen_report_feedback` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT(11) UNSIGNED NOT NULL,
  `rating` TINYINT NOT NULL,
  `comment` VARCHAR(500) NULL DEFAULT NULL,
  `voter_token` CHAR(64) NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `user_agent` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_project_voter` (`project_id`, `voter_token`),
  KEY `idx_project_ip` (`project_id`, `ip_address`),
  CONSTRAINT `fk_report_feedback_project`
    FOREIGN KEY (`project_id`) REFERENCES `published_completed_projects` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
