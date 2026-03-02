-- ============================================================
-- WhatsApp Bot Migration — run on existing installs
-- ============================================================

ALTER TABLE `conversations`
  ADD COLUMN IF NOT EXISTS `bot_state` varchar(50) DEFAULT NULL AFTER `unread_visitor`,
  ADD COLUMN IF NOT EXISTS `bot_data`  text         DEFAULT NULL AFTER `bot_state`;

INSERT IGNORE INTO `settings` (`key`, `value`) VALUES ('wa_bot_enabled', '1');
