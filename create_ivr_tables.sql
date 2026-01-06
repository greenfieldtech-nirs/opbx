-- IVR Menu Tables Creation Script
-- Run this in your MySQL database to create the required tables

-- Check if tables exist and drop them if they do (for clean recreation)
DROP TABLE IF EXISTS `ivr_menu_options`;
DROP TABLE IF EXISTS `ivr_menus`;

-- Create ivr_menus table
CREATE TABLE `ivr_menus` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text,
  `audio_file_path` varchar(500) DEFAULT NULL,
  `tts_text` text,
  `max_turns` tinyint(3) unsigned NOT NULL DEFAULT 3,
  `failover_destination_type` enum('extension','ring_group','conference_room','ivr_menu','hangup') NOT NULL DEFAULT 'hangup',
  `failover_destination_id` bigint(20) unsigned DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ivr_menus_org_status` (`organization_id`,`status`),
  KEY `idx_ivr_menus_org_name` (`organization_id`,`name`),
  CONSTRAINT `ivr_menus_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create ivr_menu_options table
CREATE TABLE `ivr_menu_options` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ivr_menu_id` bigint(20) unsigned NOT NULL,
  `input_digits` varchar(10) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `destination_type` enum('extension','ring_group','conference_room','ivr_menu') NOT NULL,
  `destination_id` bigint(20) unsigned NOT NULL,
  `priority` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_menu_digits` (`ivr_menu_id`,`input_digits`),
  KEY `idx_ivr_menu_options_menu_priority` (`ivr_menu_id`,`priority`),
  CONSTRAINT `ivr_menu_options_ivr_menu_id_foreign` FOREIGN KEY (`ivr_menu_id`) REFERENCES `ivr_menus` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verify tables were created
SELECT 'ivr_menus table created successfully' as status
UNION ALL
SELECT 'ivr_menu_options table created successfully' as status;