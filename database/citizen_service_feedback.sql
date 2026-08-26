-- =====================================================
-- citizen_service_feedback — overall service ratings
-- =====================================================
-- Purpose: 1–5 star + optional comment from the floating
--          Rate FAB / modal (overall roads & transportation
--          service feedback — not tied to a project card).
-- Limits (enforced in API):
--   - one rating per voter_token (browser cookie)
--   - max 3 ratings per ip_address
-- =====================================================

CREATE TABLE IF NOT EXISTS `citizen_service_feedback` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `rating` TINYINT NOT NULL,
  `comment` VARCHAR(500) NULL DEFAULT NULL,
  `voter_token` CHAR(64) NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `user_agent` VARCHAR(255) NULL DEFAULT NULL,
  `page_url` VARCHAR(500) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_voter_token` (`voter_token`),
  KEY `idx_ip_address` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
