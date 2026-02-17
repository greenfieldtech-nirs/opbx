<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Skip if pivot table already exists (idempotent migration)
        if (Schema::hasTable('inbound_blacklist_did_number')) {
            return;
        }

        // Create pivot table for many-to-many relationship
        Schema::create('inbound_blacklist_did_number', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inbound_blacklist_id')->constrained()->onDelete('cascade');
            $table->foreignId('did_number_id')->constrained('did_numbers')->onDelete('cascade');
            $table->timestamps();

            // Prevent duplicates
            $table->unique(['inbound_blacklist_id', 'did_number_id']);

            // Indexes
            $table->index('inbound_blacklist_id');
            $table->index('did_number_id');
        });

        // Migrate existing single DID relationships to pivot table
        $this->migrateExistingData();

        // Drop the old did_number_id column from inbound_blacklists (if it still exists)
        if (Schema::hasColumn('inbound_blacklists', 'did_number_id')) {
            Schema::table('inbound_blacklists', function (Blueprint $table) {
                $table->dropForeign(['did_number_id']);
                $table->dropColumn('did_number_id');
            });
        }
    }

    /**
     * Migrate existing data from the old column to the pivot table.
     */
    private function migrateExistingData(): void
    {
        $entries = DB::table('inbound_blacklists')
            ->whereNotNull('did_number_id')
            ->get();

        foreach ($entries as $entry) {
            DB::table('inbound_blacklist_did_number')->insert([
                'inbound_blacklist_id' => $entry->id,
                'did_number_id' => $entry->did_number_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Add back the old column
        Schema::table('inbound_blacklists', function (Blueprint $table) {
            $table->foreignId('did_number_id')->nullable()->constrained('did_numbers')->onDelete('cascade')->after('description');
        });

        // Migrate data back (only first DID per entry)
        $entries = DB::table('inbound_blacklist_did_number')
            ->select('inbound_blacklist_id', DB::raw('MIN(did_number_id) as did_number_id'))
            ->groupBy('inbound_blacklist_id')
            ->get();

        foreach ($entries as $entry) {
            DB::table('inbound_blacklists')
                ->where('id', $entry->inbound_blacklist_id)
                ->update(['did_number_id' => $entry->did_number_id]);
        }

        // Drop pivot table
        Schema::dropIfExists('inbound_blacklist_did_number');
    }
};
