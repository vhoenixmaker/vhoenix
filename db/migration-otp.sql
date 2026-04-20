-- =====================================================
-- VHOENIX Migration: Add email OTP verification table
-- 
-- Run this in phpMyAdmin → SQL tab (NOT Import)
-- Safe to run even if you already have other tables.
-- =====================================================

CREATE TABLE IF NOT EXISTS `email_otps` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(255) NOT NULL,
  `otp_code` VARCHAR(10) NOT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `used` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` TIMESTAMP NOT NULL,
  INDEX `idx_email_otp` (`email`, `otp_code`),
  INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verify it worked:
-- SELECT * FROM email_otps;
