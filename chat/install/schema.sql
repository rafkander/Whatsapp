-- ============================================================
-- Full Chat System Schema
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- Agents
CREATE TABLE IF NOT EXISTS `agents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('super_admin','admin','supervisor','senior_agent','agent') NOT NULL DEFAULT 'agent',
  `status` enum('online','away','offline') NOT NULL DEFAULT 'offline',
  `avatar` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Departments
CREATE TABLE IF NOT EXISTS `departments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `color` varchar(7) NOT NULL DEFAULT '#2563eb',
  `description` text DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Contacts (visitors)
CREATE TABLE IF NOT EXISTS `contacts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` varchar(64) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `country` varchar(60) DEFAULT NULL,
  `browser` varchar(100) DEFAULT NULL,
  `os` varchar(100) DEFAULT NULL,
  `whatsapp_number` varchar(30) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uid` (`uid`),
  UNIQUE KEY `whatsapp_number` (`whatsapp_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- WhatsApp Accounts (multi-number support)
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

-- Conversations
CREATE TABLE IF NOT EXISTS `conversations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `contact_id` int(11) NOT NULL,
  `channel` enum('widget','whatsapp') NOT NULL DEFAULT 'widget',
  `wa_account_id` int(11) DEFAULT NULL,
  `dept_id` int(11) DEFAULT NULL,
  `assigned_agent_id` int(11) DEFAULT NULL,
  `status` enum('open','closed','pending') NOT NULL DEFAULT 'open',
  `tags` varchar(500) DEFAULT NULL,
  `rating` tinyint(1) DEFAULT NULL,
  `rating_comment` text DEFAULT NULL,
  `page_url` varchar(500) DEFAULT NULL,
  `unread_agent` int(11) NOT NULL DEFAULT 0,
  `unread_visitor` int(11) NOT NULL DEFAULT 0,
  `bot_state` varchar(50) DEFAULT NULL,
  `bot_data` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `contact_id` (`contact_id`),
  KEY `wa_account_id` (`wa_account_id`),
  KEY `dept_id` (`dept_id`),
  KEY `assigned_agent_id` (`assigned_agent_id`),
  KEY `status` (`status`),
  KEY `channel` (`channel`),
  CONSTRAINT `fk_conv_contact` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_conv_wa_account` FOREIGN KEY (`wa_account_id`) REFERENCES `wa_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_conv_dept` FOREIGN KEY (`dept_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_conv_agent` FOREIGN KEY (`assigned_agent_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Messages
CREATE TABLE IF NOT EXISTS `messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `conversation_id` int(11) NOT NULL,
  `sender_type` enum('visitor','agent','bot','system') NOT NULL,
  `sender_id` int(11) DEFAULT NULL,
  `content` text DEFAULT NULL,
  `type` enum('text','file','image','audio','video','system','note') NOT NULL DEFAULT 'text',
  `file_url` varchar(500) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `wa_message_id` varchar(100) DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `conversation_id` (`conversation_id`),
  KEY `sender_type` (`sender_type`),
  KEY `wa_message_id` (`wa_message_id`),
  CONSTRAINT `fk_msg_conv` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Canned Responses
CREATE TABLE IF NOT EXISTS `canned_responses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `shortcut` varchar(50) NOT NULL,
  `title` varchar(200) NOT NULL,
  `content` text NOT NULL,
  `dept_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_shortcut` (`shortcut`),
  KEY `shortcut` (`shortcut`),
  CONSTRAINT `fk_canned_dept` FOREIGN KEY (`dept_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_canned_agent` FOREIGN KEY (`created_by`) REFERENCES `agents` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Settings (key/value)
CREATE TABLE IF NOT EXISTS `settings` (
  `key` varchar(100) NOT NULL,
  `value` text DEFAULT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Triggers / Automation
CREATE TABLE IF NOT EXISTS `triggers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `type` enum('time','offline','hours','page') NOT NULL DEFAULT 'time',
  `condition_value` varchar(255) DEFAULT NULL,
  `message` text NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Typing Status
CREATE TABLE IF NOT EXISTS `typing_status` (
  `conversation_id` int(11) NOT NULL,
  `sender_type` enum('visitor','agent') NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`conversation_id`,`sender_type`),
  CONSTRAINT `fk_typing_conv` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Agent Department memberships
CREATE TABLE IF NOT EXISTS `agent_departments` (
  `agent_id` int(11) NOT NULL,
  `dept_id`  int(11) NOT NULL,
  PRIMARY KEY (`agent_id`,`dept_id`),
  KEY `dept_id` (`dept_id`),
  CONSTRAINT `fk_ad_agent` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ad_dept`  FOREIGN KEY (`dept_id`)  REFERENCES `departments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Agent Sessions (JWT blocklist / logout tracking)
CREATE TABLE IF NOT EXISTS `agent_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `agent_id` int(11) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `agent_id` (`agent_id`),
  KEY `token_hash` (`token_hash`),
  CONSTRAINT `fk_sess_agent` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- SMS Integration (Alfonica SMS API)
-- ============================================================

-- One shared Alfonica API token; sms_accounts are just sender IDs under that account
CREATE TABLE IF NOT EXISTS `sms_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `sender_id` varchar(20) NOT NULL COMMENT 'Phone number or alphanumeric sender ID (max 11 chars for alpha)',
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `contacts`
  ADD COLUMN `sms_number` varchar(30) DEFAULT NULL AFTER `whatsapp_number`;

ALTER TABLE `conversations`
  MODIFY `channel` enum('widget','whatsapp','sms') NOT NULL DEFAULT 'widget',
  ADD COLUMN `sms_account_id` int(11) DEFAULT NULL AFTER `wa_account_id`,
  ADD CONSTRAINT `fk_conv_sms_account` FOREIGN KEY (`sms_account_id`) REFERENCES `sms_accounts` (`id`) ON DELETE SET NULL;

INSERT IGNORE INTO `settings` (`key`, `value`) VALUES ('sms_enabled', '0'), ('alfonica_sms_token', '');

-- ============================================================
-- Default Settings
-- ============================================================
INSERT IGNORE INTO `settings` (`key`, `value`) VALUES
('widget_color', '#2563eb'),
('widget_greeting', 'Hi! How can we help you today?'),
('widget_position', 'bottom-right'),
('widget_name', 'Support Chat'),
('widget_avatar', ''),
('offline_message', 'We are currently offline. Please leave a message and we will get back to you soon.'),
('business_hours_enabled', '0'),
('business_hours', '{"mon":{"open":"09:00","close":"17:00","enabled":true},"tue":{"open":"09:00","close":"17:00","enabled":true},"wed":{"open":"09:00","close":"17:00","enabled":true},"thu":{"open":"09:00","close":"17:00","enabled":true},"fri":{"open":"09:00","close":"17:00","enabled":true},"sat":{"open":"09:00","close":"17:00","enabled":false},"sun":{"open":"09:00","close":"17:00","enabled":false}}'),
('wa_phone_number_id', ''),
('wa_access_token', ''),
('wa_verify_token', ''),
('wa_enabled', '0'),
('wa_bot_enabled', '1'),
('smtp_host', ''),
('smtp_port', '587'),
('smtp_user', ''),
('smtp_pass', ''),
('smtp_from', ''),
('smtp_from_name', 'Chat Support'),
('email_notify_new_chat', '0'),
('email_notify_addresses', ''),
('welcome_trigger_enabled', '1'),
('welcome_trigger_delay', '5'),
('welcome_trigger_message', 'Hi there! 👋 Is there anything we can help you with today?'),
('widget_allowed_origins', '*');

-- Default super admin user
INSERT IGNORE INTO `agents` (`name`, `email`, `password_hash`, `role`, `status`) VALUES
('Rafael Kander', 'rafael.kander@rcuk.com', '$2y$12$98hOqT2uUoKzJ8m5qbLb8.K49ygGOmkIr6bZtFDCj4ALUAEWwvgGm', 'super_admin', 'online');

-- NOTE: Default department, canned response, and trigger data is seeded
-- by the installer (install/index.php) on first run only.

-- ============================================================
-- Bitrix24 CRM Integration (added 2026-03)
-- ============================================================

ALTER TABLE `contacts`
  ADD COLUMN `bitrix24_data`      JSON        DEFAULT NULL AFTER `whatsapp_number`,
  ADD COLUMN `bitrix24_id`        VARCHAR(50) DEFAULT NULL AFTER `bitrix24_data`,
  ADD COLUMN `bitrix24_synced_at` DATETIME    DEFAULT NULL AFTER `bitrix24_id`;

CREATE TABLE IF NOT EXISTS `bitrix24_field_config` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `field_key`  VARCHAR(100) NOT NULL,
  `label`      VARCHAR(150) NOT NULL,
  `field_type` VARCHAR(50)  DEFAULT 'string',
  `is_enabled` TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order` INT(11)      NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_field_key` (`field_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `settings` (`key`, `value`) VALUES
  ('bitrix24_enabled',     '0'),
  ('bitrix24_webhook_url', ''),
  ('bitrix24_cache_ttl',   '3600');

-- ============================================================
-- Roles & Permissions
-- ============================================================

CREATE TABLE IF NOT EXISTS `roles` (
  `id`          int(11)      NOT NULL AUTO_INCREMENT,
  `name`        varchar(100) NOT NULL,
  `description` text         DEFAULT NULL,
  `color`       varchar(7)   NOT NULL DEFAULT '#2563eb',
  `permissions` text         DEFAULT NULL COMMENT 'JSON array of permission keys',
  `is_system`   tinyint(1)   NOT NULL DEFAULT 0,
  `sort_order`  int(11)      NOT NULL DEFAULT 0,
  `created_at`  datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `roles` (name, description, color, permissions, is_system, sort_order) VALUES
  ('Super Admin', 'Unrestricted access to all features', '#7c3aed',
   '["view_all_conversations","reply_conversations","take_conversations","assign_conversations","close_conversations","view_analytics","manage_canned","manage_agents","manage_departments","manage_roles","manage_settings"]', 1, 1),
  ('Admin', 'Full access except super-admin features', '#c0392b',
   '["view_all_conversations","reply_conversations","take_conversations","assign_conversations","close_conversations","view_analytics","manage_canned","manage_agents","manage_departments","manage_roles","manage_settings"]', 1, 2),
  ('Supervisor', 'Can view analytics and manage conversations', '#ea580c',
   '["view_all_conversations","reply_conversations","take_conversations","assign_conversations","close_conversations","view_analytics","manage_canned"]', 1, 3),
  ('Senior Agent', 'Can view all conversations and use canned responses', '#2563eb',
   '["view_all_conversations","reply_conversations","take_conversations","close_conversations","manage_canned"]', 1, 4),
  ('Agent', 'Can view and reply to assigned conversations', '#059669',
   '["reply_conversations","take_conversations","close_conversations"]', 1, 5);
