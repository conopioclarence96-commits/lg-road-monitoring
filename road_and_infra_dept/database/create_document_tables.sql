-- =====================================================
-- CREATE MISSING TABLES: document_views and document_downloads
-- Purpose: Fix missing tables for public_transparency.php
-- =====================================================

USE lg_road_monitoring;

-- =====================================================
-- TABLE: document_views
-- =====================================================
CREATE TABLE IF NOT EXISTS `document_views` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `document_id` INT NOT NULL,
  `user_id` INT NULL,
  `views` INT DEFAULT 1,
  `viewed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `ip_address` VARCHAR(45),
  INDEX `idx_document_id` (`document_id`),
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_viewed_at` (`viewed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE: document_downloads
-- =====================================================
CREATE TABLE IF NOT EXISTS `document_downloads` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `document_id` INT NOT NULL,
  `user_id` INT NULL,
  `downloaded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `ip_address` VARCHAR(45),
  INDEX `idx_document_id` (`document_id`),
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_downloaded_at` (`downloaded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE: documents (main documents table)
-- =====================================================
CREATE TABLE IF NOT EXISTS `documents` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `file_path` VARCHAR(500),
  `category` VARCHAR(100),
  `is_public` BOOLEAN DEFAULT FALSE,
  `is_active` BOOLEAN DEFAULT TRUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_category` (`category`),
  INDEX `idx_is_public` (`is_public`),
  INDEX `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE: public_documents (if also missing)
-- =====================================================
CREATE TABLE IF NOT EXISTS `public_documents` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `file_path` VARCHAR(500),
  `category` VARCHAR(100),
  `is_active` BOOLEAN DEFAULT TRUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_category` (`category`),
  INDEX `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE: budget_allocation
-- =====================================================
CREATE TABLE IF NOT EXISTS `budget_allocation` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `year` INT NOT NULL,
  `annual_budget` DECIMAL(12,2) NOT NULL,
  `allocation_percentage` DECIMAL(5,2) DEFAULT 0,
  `department` VARCHAR(100),
  `allocated_amount` DECIMAL(12,2),
  `spent_amount` DECIMAL(12,2) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_year` (`year`),
  INDEX `idx_department` (`department`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE: infrastructure_projects
-- =====================================================
CREATE TABLE IF NOT EXISTS `infrastructure_projects` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `location` VARCHAR(255),
  `budget` DECIMAL(12,2),
  `progress` INT DEFAULT 0,
  `status` ENUM('active', 'completed', 'delayed', 'pending') DEFAULT 'active',
  `start_date` DATE,
  `end_date` DATE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_status` (`status`),
  INDEX `idx_start_date` (`start_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE: publications
-- =====================================================
CREATE TABLE IF NOT EXISTS `publications` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `content` TEXT,
  `publish_date` DATE,
  `author` VARCHAR(100),
  `category` VARCHAR(100),
  `is_active` BOOLEAN DEFAULT TRUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_publish_date` (`publish_date`),
  INDEX `idx_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verify tables were created
SHOW TABLES LIKE 'document%';
SHOW TABLES LIKE 'budget%';
SHOW TABLES LIKE 'infrastructure%';
SHOW TABLES LIKE 'publication%';
