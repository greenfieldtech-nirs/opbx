## 7. Database Schema Changes

### Table: `users` (modified)

**New Column:**

| Column | Type | Nullable | Default | Index | Description |
|---|---|---|---|---|---|
| `is_platform_manager` | `boolean` | No | `false` | Yes (`idx_users_is_platform_manager`) | Platform super-admin flag |

**Position:** After the `role` column.

**Full Migration Code:**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_manager')
                ->default(false)
                ->after('role')
                ->index('idx_users_is_platform_manager');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_users_is_platform_manager');
            $table->dropColumn('is_platform_manager');
        });
    }
};
```

### Table: `platform_audit_logs` (new)

| Column | Type | Nullable | Default | Index | Description |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` AI | No | — | PK | Primary key |
| `platform_manager_user_id` | `bigint unsigned` FK | No | — | Yes (`idx_pal_manager_user`) | Acting platform manager |
| `target_organization_id` | `bigint unsigned` FK | Yes | `null` | Yes (`idx_pal_target_org`) | Target organization |
| `action` | `varchar(100)` | No | — | Yes (`idx_pal_action`) | Action type string |
| `target_entity_type` | `varchar(100)` | Yes | `null` | Composite (`idx_pal_target_entity`) | Entity class name |
| `target_entity_id` | `bigint unsigned` | Yes | `null` | Composite (`idx_pal_target_entity`) | Entity ID |
| `before_state` | `json` | Yes | `null` | — | State before change |
| `after_state` | `json` | Yes | `null` | — | State after change |
| `reason` | `varchar(500)` | Yes | `null` | — | Optional reason text |
| `ip_address` | `varchar(45)` | Yes | `null` | — | Request IP address |
| `user_agent` | `varchar(500)` | Yes | `null` | — | Request User-Agent |
| `created_at` | `timestamp` | Yes | — | Yes (`idx_pal_created_at`) | Created timestamp |
| `updated_at` | `timestamp` | Yes | — | — | Updated timestamp |

**Foreign Keys:**
- `platform_manager_user_id` → `users.id` ON DELETE CASCADE
- `target_organization_id` → `organizations.id` ON DELETE SET NULL

**Full Migration Code:**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_manager_user_id')
                ->constrained('users')
                ->onDelete('cascade');
            $table->foreignId('target_organization_id')
                ->nullable()
                ->constrained('organizations')
                ->onDelete('set null');
            $table->string('action', 100);
            $table->string('target_entity_type', 100)->nullable();
            $table->unsignedBigInteger('target_entity_id')->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->index('platform_manager_user_id', 'idx_pal_manager_user');
            $table->index('target_organization_id', 'idx_pal_target_org');
            $table->index('action', 'idx_pal_action');
            $table->index('created_at', 'idx_pal_created_at');
            $table->index(
                ['target_entity_type', 'target_entity_id'],
                'idx_pal_target_entity'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_audit_logs');
    }
};
```

---

