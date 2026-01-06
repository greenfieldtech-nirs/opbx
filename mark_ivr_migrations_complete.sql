-- Mark IVR migrations as completed in Laravel's migrations table
-- Run this AFTER the tables have been created via the SQL script

INSERT IGNORE INTO `migrations` (`migration`, `batch`) VALUES
('2026_01_06_004127_create_ivr_menus_table', 1),
('2026_01_06_004128_create_ivr_menu_options_table', 1);

-- Verify the migrations are marked as run
SELECT migration, batch FROM migrations WHERE migration LIKE '%ivr%' ORDER BY migration;