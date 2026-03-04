-- ============================================================
-- Migration: Multi-number WhatsApp support
-- Run this once on existing installations
-- ============================================================

-- WhatsApp accounts (one row per phone number)
CREATE TABLE IF NOT EXISTS `wa_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `phone_number_id` varchar(100) NOT NULL,
  `access_token` text NOT NULL,
  `verify_token` varchar(100) NOT NULL,
  `bot_flow` enum('standard','alfonica') NOT NULL DEFAULT 'standard',
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phone_number_id` (`phone_number_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Link conversations to a specific WhatsApp account
ALTER TABLE `conversations`
  ADD COLUMN `wa_account_id` int(11) DEFAULT NULL AFTER `channel`,
  ADD KEY `wa_account_id` (`wa_account_id`),
  ADD CONSTRAINT `fk_conv_wa_account`
    FOREIGN KEY (`wa_account_id`) REFERENCES `wa_accounts` (`id`) ON DELETE SET NULL;

-- Migrate existing single-number settings into wa_accounts (if configured)
INSERT IGNORE INTO `wa_accounts` (`name`, `phone_number_id`, `access_token`, `verify_token`, `bot_flow`, `is_enabled`)
SELECT
  'RCUK',
  (SELECT `value` FROM `settings` WHERE `key` = 'wa_phone_number_id'),
  (SELECT `value` FROM `settings` WHERE `key` = 'wa_access_token'),
  (SELECT `value` FROM `settings` WHERE `key` = 'wa_verify_token'),
  'standard',
  IF((SELECT `value` FROM `settings` WHERE `key` = 'wa_enabled') = '1', 1, 0)
WHERE (SELECT `value` FROM `settings` WHERE `key` = 'wa_phone_number_id') != ''
  AND (SELECT `value` FROM `settings` WHERE `key` = 'wa_phone_number_id') IS NOT NULL;
