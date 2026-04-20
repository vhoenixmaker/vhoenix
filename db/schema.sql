-- =====================================================
-- VHOENIX Database Schema — FULL
-- Import this file via phpMyAdmin: Database → Import
-- =====================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================
-- users table
-- =====================================================
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(255) DEFAULT NULL,
  `password_hash` VARCHAR(255) DEFAULT NULL,
  `wallet_address` VARCHAR(44) DEFAULT NULL,
  `display_name` VARCHAR(100) DEFAULT NULL,
  `tier` ENUM('scout','apex') NOT NULL DEFAULT 'scout',
  `analyses_today` INT UNSIGNED NOT NULL DEFAULT 0,
  `analyses_reset_at` DATE DEFAULT NULL,
  `email_verified_at` TIMESTAMP NULL DEFAULT NULL,
  `last_login_at` TIMESTAMP NULL DEFAULT NULL,
  `last_login_ip` VARCHAR(45) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_email` (`email`),
  UNIQUE KEY `uk_wallet` (`wallet_address`),
  INDEX `idx_tier` (`tier`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- sessions table
-- =====================================================
CREATE TABLE IF NOT EXISTS `sessions` (
  `id` VARCHAR(64) PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(500) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` TIMESTAMP NOT NULL,
  `last_activity_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_expires` (`expires_at`),
  INDEX `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- wallet_nonces table
-- =====================================================
CREATE TABLE IF NOT EXISTS `wallet_nonces` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `wallet_address` VARCHAR(44) NOT NULL,
  `nonce` VARCHAR(64) NOT NULL,
  `used` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` TIMESTAMP NOT NULL,
  INDEX `idx_wallet_nonce` (`wallet_address`, `nonce`),
  INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- rate_limits table
-- =====================================================
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `identifier` VARCHAR(100) NOT NULL,
  `action` VARCHAR(50) NOT NULL,
  `count` INT UNSIGNED NOT NULL DEFAULT 1,
  `window_start` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_identifier_action` (`identifier`, `action`),
  INDEX `idx_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- analyses table — stores completed AI analyses
-- =====================================================
CREATE TABLE IF NOT EXISTS `analyses` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `token_ca` VARCHAR(44) NOT NULL,
  `token_symbol` VARCHAR(32) DEFAULT NULL,
  `token_name` VARCHAR(128) DEFAULT NULL,
  `image_url` VARCHAR(500) DEFAULT NULL,
  `market_cap` DECIMAL(20,2) DEFAULT NULL,
  `liquidity` DECIMAL(20,2) DEFAULT NULL,
  `volume_24h` DECIMAL(20,2) DEFAULT NULL,
  `price` DECIMAL(30,18) DEFAULT NULL,
  `price_change_1h` DECIMAL(10,2) DEFAULT NULL,
  `price_change_24h` DECIMAL(10,2) DEFAULT NULL,
  `txns_24h` INT UNSIGNED DEFAULT NULL,
  `liquidity_locked` TINYINT(1) DEFAULT NULL,
  `verdict` ENUM('ape','wait','avoid') NOT NULL DEFAULT 'wait',
  `risk_level` ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  `summary` TEXT DEFAULT NULL,
  `risk_flags_json` TEXT DEFAULT NULL,
  `raw_data_json` MEDIUMTEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_user_created` (`user_id`, `created_at` DESC),
  INDEX `idx_token_created` (`token_ca`, `created_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- watchlists table
-- =====================================================
CREATE TABLE IF NOT EXISTS `watchlists` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `token_ca` VARCHAR(44) NOT NULL,
  `token_symbol` VARCHAR(32) DEFAULT NULL,
  `added_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `uk_user_token` (`user_id`, `token_ca`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- chat_messages table — for follow-up chat context
-- =====================================================
CREATE TABLE IF NOT EXISTS `chat_messages` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `token_ca` VARCHAR(44) NOT NULL,
  `role` ENUM('user','assistant') NOT NULL,
  `content` TEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_user_token` (`user_id`, `token_ca`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- email_otps table (for email verification with OTP)
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

SET FOREIGN_KEY_CHECKS = 1;
