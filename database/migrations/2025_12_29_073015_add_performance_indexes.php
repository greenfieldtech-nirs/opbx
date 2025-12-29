<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add performance indexes for critical queries.
 *
 * This migration adds missing composite indexes that will significantly improve
 * query performance for frequently accessed data patterns.
 *
 * Reference: Code Review Report 2025-12-28, Finding #1.7 - Missing Database Indexes
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        /*
         * Existing Indexes (already present, no action needed):
         *
         * extensions table:
         * - UNIQUE(organization_id, extension_number) ✓ covers lookups by org+extension
         * - INDEX(organization_id, status) ✓
         * - INDEX(organization_id, type) ✓
         * - INDEX(user_id) ✓
         *
         * call_logs table:
         * - INDEX(organization_id, created_at) ✓
         * - INDEX(call_id) ✓ unique lookups
         * - INDEX(from_number, created_at) ✓
         * - INDEX(to_number, created_at) ✓
         * - INDEX(status) ✓
         *
         * personal_access_tokens table:
         * - INDEX(tokenable_type, tokenable_id) ✓ created by morphs()
         * - INDEX(expires_at) ✓
         * - UNIQUE(token) ✓
         */

        // Add composite index for did_numbers query pattern
        // Used in: VoiceRoutingController::classifyCall() line 267
        // Query: WHERE organization_id = ? AND phone_number = ? AND status = 'active'
        Schema::table('did_numbers', function (Blueprint $table) {
            // This composite index covers the common lookup pattern:
            // 1. Filter by organization (tenant isolation)
            // 2. Find specific phone number
            // 3. Check if active
            // The existing separate indexes don't optimize this query pattern
            $table->index(
                ['organization_id', 'phone_number', 'status'],
                'idx_did_org_phone_status'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('did_numbers', function (Blueprint $table) {
            $table->dropIndex('idx_did_org_phone_status');
        });
    }
};
