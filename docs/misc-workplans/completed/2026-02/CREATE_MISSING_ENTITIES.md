# Creating Missing Ring Groups and IVR Menus

Extensions 3001 and 3002 are configured to reference ring group and IVR menu IDs that may not exist in your database. This can happen during development or when setting up test data.

## Solution

Two approaches are provided to fix this issue:

### Option 1: Database Seeder (Recommended for Production/Setup)

The `FixMissingRingGroupAndIvrMenuSeeder` will automatically run when you seed your database:

```bash
php artisan db:seed
```

Or run the specific seeder:

```bash
php artisan db:seed --class=FixMissingRingGroupAndIvrMenuSeeder
```

### Option 2: Development Script

For development environments, you can run the standalone script:

```bash
php scripts/create-missing-entities.php
```

## What It Does

The script/seeder will:

1. **For Extension 3001 (Ring Group)**:
   - Create a ring group named "Test Ring Group" with:
     - Status: active
     - Strategy: simultaneous
     - Timeout: 20 seconds
   - Add an existing extension as a member (prefers extension 3000, falls back to any available extension)
   - Update extension 3001's configuration to reference the new ring group

2. **For Extension 3002 (IVR Menu)**:
   - Create an IVR menu named "Test IVR Menu" with:
     - Status: active
     - TTS Text: "Welcome to our IVR system"
     - Max Turns: 3
   - Update extension 3002's configuration to reference the new IVR menu

## Pattern

This follows the exact same pattern as the `scripts/testing/test-voice-routing.php` script, ensuring consistency with your test data setup.