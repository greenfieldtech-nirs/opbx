# Caller ID Pooling - Database Schema

## Overview
This document describes the database changes required to support multiple Caller IDs per auto-dialer campaign.

---

## 1. New Tables

### 1.1 `auto_dialer_campaign_caller_ids` (Pivot Table)

Stores the Caller ID pool for each campaign.

```sql
CREATE TABLE auto_dialer_campaign_caller_ids (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT UNSIGNED NOT NULL,
    did_number_id BIGINT UNSIGNED NOT NULL,
    weight INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (campaign_id) REFERENCES auto_dialer_campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (did_number_id) REFERENCES did_numbers(id) ON DELETE CASCADE,
    
    UNIQUE KEY unique_campaign_did (campaign_id, did_number_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED | Primary key |
| `campaign_id` | BIGINT UNSIGNED | FK to `auto_dialer_campaigns` |
| `did_number_id` | BIGINT UNSIGNED | FK to `did_numbers` |
| `weight` | INT UNSIGNED | For weighted distribution (default 1) |
| `created_at` | TIMESTAMP | Record creation time |
| `updated_at` | TIMESTAMP | Last update time |

**Indexes:**
- Primary: `id`
- Unique: `campaign_id + did_number_id` (prevents duplicate assignments)
- Foreign keys automatically indexed

---

### 1.2 `auto_dialer_caller_id_stats` (Statistics Table)

Tracks usage statistics per Caller ID per campaign.

```sql
CREATE TABLE auto_dialer_caller_id_stats (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT UNSIGNED NOT NULL,
    did_number_id BIGINT UNSIGNED NOT NULL,
    total_calls INT UNSIGNED NOT NULL DEFAULT 0,
    completed_calls INT UNSIGNED NOT NULL DEFAULT 0,
    failed_calls INT UNSIGNED NOT NULL DEFAULT 0,
    last_used_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (campaign_id) REFERENCES auto_dialer_campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (did_number_id) REFERENCES did_numbers(id) ON DELETE CASCADE,
    
    UNIQUE KEY unique_campaign_did_stats (campaign_id, did_number_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED | Primary key |
| `campaign_id` | BIGINT UNSIGNED | FK to `auto_dialer_campaigns` |
| `did_number_id` | BIGINT UNSIGNED | FK to `did_numbers` |
| `total_calls` | INT UNSIGNED | Total calls attempted |
| `completed_calls` | INT UNSIGNED | Successfully completed calls |
| `failed_calls` | INT UNSIGNED | Failed calls |
| `last_used_at` | TIMESTAMP | When this Caller ID was last used |
| `created_at` | TIMESTAMP | Record creation time |
| `updated_at` | TIMESTAMP | Last update time |

---

## 2. Modified Tables

### 2.1 `auto_dialer_campaigns`

Add columns to support Caller ID pooling.

```sql
ALTER TABLE auto_dialer_campaigns
    ADD COLUMN caller_id_strategy VARCHAR(20) NOT NULL DEFAULT 'round_robin' 
        COMMENT 'round_robin, random, least_recently_used' 
        AFTER caller_id,
    ADD COLUMN caller_id_pool_enabled BOOLEAN NOT NULL DEFAULT FALSE 
        AFTER caller_id_strategy;
```

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `caller_id_strategy` | VARCHAR(20) | 'round_robin' | Distribution strategy |
| `caller_id_pool_enabled` | BOOLEAN | FALSE | Whether pool mode is enabled |

**Note:** The existing `caller_id` column is retained for backward compatibility during migration.

---

### 2.2 `auto_dialer_call_sessions`

Add column to track which Caller ID was used.

```sql
ALTER TABLE auto_dialer_call_sessions
    ADD COLUMN caller_did_id BIGINT UNSIGNED NULL 
        AFTER caller_id,
    ADD FOREIGN KEY (caller_did_id) REFERENCES did_numbers(id) ON DELETE SET NULL;
```

| Column | Type | Description |
|--------|------|-------------|
| `caller_did_id` | BIGINT UNSIGNED | FK to `did_numbers` - the actual Caller ID used |

---

## 3. Migration Strategy

### 3.1 Migration Steps

1. **Create new tables** (`auto_dialer_campaign_caller_ids`, `auto_dialer_caller_id_stats`)
2. **Add columns** to `auto_dialer_campaigns` and `auto_dialer_call_sessions`
3. **Migrate existing data**:
   ```sql
   -- For each campaign with a caller_id, create a pool entry
   INSERT INTO auto_dialer_campaign_caller_ids (campaign_id, did_number_id, weight)
   SELECT 
       adc.id as campaign_id,
       dn.id as did_number_id,
       1 as weight
   FROM auto_dialer_campaigns adc
   JOIN did_numbers dn ON adc.caller_id = dn.phone_number
   WHERE adc.caller_id IS NOT NULL;
   
   -- Enable pool mode for migrated campaigns
   UPDATE auto_dialer_campaigns 
   SET caller_id_pool_enabled = TRUE 
   WHERE caller_id IS NOT NULL;
   ```
4. **Create initial stats records** for migrated Caller IDs

### 3.2 Rollback Plan

```sql
-- Rollback migration
ALTER TABLE auto_dialer_campaigns 
    DROP COLUMN caller_id_strategy,
    DROP COLUMN caller_id_pool_enabled;

ALTER TABLE auto_dialer_call_sessions 
    DROP FOREIGN KEY auto_dialer_call_sessions_caller_did_id_foreign,
    DROP COLUMN caller_did_id;

DROP TABLE IF EXISTS auto_dialer_caller_id_stats;
DROP TABLE IF EXISTS auto_dialer_campaign_caller_ids;
```

---

## 4. PHP Enum

### 4.1 `CallerIdStrategy` Enum

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum CallerIdStrategy: string
{
    case ROUND_ROBIN = 'round_robin';
    case RANDOM = 'random';
    case LEAST_RECENTLY_USED = 'least_recently_used';
    
    public function label(): string
    {
        return match($this) {
            self::ROUND_ROBIN => 'Round Robin',
            self::RANDOM => 'Random',
            self::LEAST_RECENTLY_USED => 'Least Recently Used',
        };
    }
    
    public function description(): string
    {
        return match($this) {
            self::ROUND_ROBIN => 'Cycle through Caller IDs sequentially',
            self::RANDOM => 'Select Caller IDs randomly',
            self::LEAST_RECENTLY_USED => 'Select the least recently used Caller ID',
        };
    }
}
```

---

## 5. Eloquent Models

### 5.1 `AutoDialerCampaignCallerId` Model

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutoDialerCampaignCallerId extends Pivot
{
    protected $table = 'auto_dialer_campaign_caller_ids';
    
    protected $fillable = [
        'campaign_id',
        'did_number_id',
        'weight',
    ];
    
    protected $casts = [
        'weight' => 'integer',
    ];
    
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AutoDialerCampaign::class, 'campaign_id');
    }
    
    public function didNumber(): BelongsTo
    {
        return $this->belongsTo(DidNumber::class, 'did_number_id');
    }
}
```

### 5.2 Model Relationships

Add to `AutoDialerCampaign` model:

```php
public function callerIds(): BelongsToMany
{
    return $this->belongsToMany(
        DidNumber::class,
        'auto_dialer_campaign_caller_ids',
        'campaign_id',
        'did_number_id'
    )
    ->withPivot('weight')
    ->withTimestamps();
}

public function callerIdStats(): HasMany
{
    return $this->hasMany(AutoDialerCallerIdStat::class, 'campaign_id');
}
```

---

## 6. Constraints & Validation

### 6.1 Database Constraints

1. **Maximum 100 Caller IDs per campaign** (enforced at application layer)
2. **Only active DIDs can be assigned** (validated before insert)
3. **DIDs must belong to same organization** (enforced via validation)

### 6.2 Validation Rules

```php
// StoreCampaignCallerIdsRequest
public function rules(): array
{
    return [
        'caller_ids' => 'required|array|min:1|max:100',
        'caller_ids.*.did_id' => 'required|exists:did_numbers,id',
        'caller_ids.*.weight' => 'integer|min:1|max:100',
        'caller_id_strategy' => 'required|in:round_robin,random,least_recently_used',
    ];
}
```
