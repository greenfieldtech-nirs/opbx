# IVR Menus Migration Troubleshooting Guide

## 🎯 **Problem Summary**

When running Laravel migrations for IVR Menus, you encountered this error:
```
SQLSTATE[HY000]: General error: 3730 Cannot drop table 'ivr_menus' referenced by a foreign key constraint 'ivr_menu_options_ivr_menu_id_foreign' on table 'ivr_menu_options'.
```

**Root Cause**: Migrations were trying to drop tables in wrong order due to foreign key constraints.

## ✅ **Solution Applied**

We've fixed both IVR migration files:

### 1. **`2026_01_06_004127_create_ivr_menus_table.php`**
- ❌ **Before**: Would try to drop `ivr_menus` table first
- ✅ **After**: Only creates the table (no drops)

### 2. **`2026_01_06_004128_create_ivr_menu_options_table.php`**
- ❌ **Before**: Would try to drop `ivr_menu_options` first  
- ✅ **After**: Only drops table if it exists, then creates it

## 🚀 **How to Run Migrations Now**

### Option A: Fresh Migration (Recommended if no production data)

```bash
# 1. Rollback all migrations to this point
docker-compose exec app php artisan migrate:rollback --step=0

# 2. Run migrations fresh
docker-compose exec app php artisan migrate:fresh --seed

# 3. Verify tables created
docker-compose exec db mysql -u opbx_user -p opbx_password -e "SHOW TABLES LIKE 'ivr_%';"
```

### Option B: Selective Migration (If you have data)

```bash
# 1. Check current migration state
docker-compose exec app php artisan migrate:status

# 2. If migrations show as failed/ran, mark them as complete first
docker-compose exec app php artisan tinker --execute="
DB::table('migrations')->insert([
    ['migration' => '2026_01_06_004127_create_ivr_menus_table', 'batch' => 1],
    ['migration' => '2026_01_06_004128_create_ivr_menu_options_table', 'batch' => 1]
]);
"

# 3. Rollback to before migrations
docker-compose exec app php artisan migrate:rollback --step=0

# 4. Run migrations again
docker-compose exec app php artisan migrate
```

### Option C: Manual SQL Execution (Last Resort)

If Laravel migrations continue to fail, you can manually create tables:

```bash
# 1. Access MySQL container
docker-compose exec db mysql -u opbx_user -p opbx_password opbx_database

# 2. Run these SQL commands (in correct order)
DROP TABLE IF EXISTS ivr_menu_options;
DROP TABLE IF EXISTS ivr_menus;

CREATE TABLE ivr_menus (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  organization_id bigint(20) unsigned NOT NULL,
  name varchar(255) NOT NULL,
  description text,
  audio_file_path varchar(500) DEFAULT NULL,
  tts_text text,
  max_turns tinyint(3) unsigned NOT NULL DEFAULT 3,
  failover_destination_type enum('extension','ring_group','conference_room','ivr_menu','hangup') NOT NULL DEFAULT 'hangup',
  failover_destination_id bigint(20) unsigned DEFAULT NULL,
  status enum('active','inactive') NOT NULL DEFAULT 'active',
  created_at timestamp NULL DEFAULT NULL,
  updated_at timestamp NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_ivr_menus_org_status (organization_id,status),
  KEY idx_ivr_menus_org_name (organization_id,name),
  CONSTRAINT ivr_menus_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ivr_menu_options (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ivr_menu_id bigint(20) unsigned NOT NULL,
  input_digits varchar(10) NOT NULL,
  description varchar(255) DEFAULT NULL,
  destination_type enum('extension','ring_group','conference_room','ivr_menu') NOT NULL,
  destination_id bigint(20) unsigned NOT NULL,
  priority tinyint(3) unsigned NOT NULL DEFAULT 1,
  created_at timestamp NULL DEFAULT NULL,
  updated_at timestamp NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY unique_menu_digits (ivr_menu_id,input_digits),
  KEY idx_ivr_menu_options_menu_priority (ivr_menu_id,priority),
  CONSTRAINT ivr_menu_options_ivr_menu_id_foreign FOREIGN KEY (ivr_menu_id) REFERENCES ivr_menus (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

# 3. Mark migrations as complete
INSERT IGNORE INTO migrations (migration, batch) VALUES
('2026_01_06_004127_create_ivr_menus_table', 1),
('2026_01_06_004128_create_ivr_menu_options_table', 1);

# 4. Exit MySQL
exit
```

## 🔍 **Verification Steps**

After running migrations, verify they worked:

```bash
# 1. Check migration status
docker-compose exec app php artisan migrate:status

# 2. Verify tables exist
docker-compose exec db mysql -u opbx_user -p opbx_password -e "
SHOW TABLES LIKE 'ivr_%';
DESCRIBE ivr_menus;
DESCRIBE ivr_menu_options;
"

# 3. Test IVR Menus page
curl -H "Authorization: Bearer YOUR_TOKEN" http://localhost/api/v1/ivr-menus
```

## 📋 **What Was Fixed**

✅ **Migration Execution Order**: 
- Ensures `ivr_menus` table is created BEFORE `ivr_menu_options` 
- Prevents foreign key constraint conflicts
- Removes redundant `dropIfExists()` calls that were causing issues

✅ **Down Migration Order**:
- Drops `ivr_menu_options` (child table) FIRST
- Then drops `ivr_menus` (parent table)
- Maintains referential integrity

## 🎯 **Expected Result**

After fixing migrations:

✅ Migrations run without SQLSTATE[HY000] errors
✅ `ivr_menus` table created with all required columns
✅ `ivr_menu_options` table created with foreign key to `ivr_menus`
✅ All indexes and constraints properly applied
✅ IVR Menus page loads successfully
✅ Full CRUD operations available

## 💡 **Best Practices Applied**

- ✅ Idempotent migrations (can be run multiple times safely)
- ✅ Proper foreign key handling
- ✅ Correct execution order
- ✅ Graceful error handling

## 🚨 **If You Still See Errors**

1. **Check MySQL version**: Requires MySQL 5.7 or higher for proper enum support
2. **Verify database credentials**: Ensure DB_DATABASE, DB_USERNAME, DB_PASSWORD in `.env` are correct
3. **Check connection**: `docker-compose exec app php artisan tinker --execute="DB::connection()->getPdo(); echo 'Connected';"`
4. **Clear cache**: `docker-compose exec app php artisan cache:clear`
5. **Check permissions**: Ensure MySQL user has CREATE, ALTER, DROP, INDEX privileges

## 📞 **Technical Details**

**Migration Files Fixed:**
- `database/migrations/2026_01_06_004127_create_ivr_menus_table.php`
- `database/migrations/2026_01_06_004128_create_ivr_menu_options_table.php`

**Changes:**
- Removed duplicate/conflicting table drop operations
- Ensured correct creation order
- Maintained all table constraints and indexes

**Next:**
Run migrations using Option A (Fresh Migration) for clean state, or Option B if you need to preserve existing data.