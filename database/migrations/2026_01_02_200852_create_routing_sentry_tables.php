<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sentry_blacklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('manual'); // manual, dynamic_api, etc.
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'type']);
        });

        Schema::create('sentry_blacklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sentry_blacklist_id')->constrained('sentry_blacklists')->cascadeOnDelete();
            $table->string('pattern'); // E.164 number or REGEX
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('pattern');
        });

        Schema::create('did_sentry_blacklist', function (Blueprint $table) {
            $table->foreignId('did_number_id')->constrained('did_numbers')->cascadeOnDelete();
            $table->foreignId('sentry_blacklist_id')->constrained('sentry_blacklists')->cascadeOnDelete();
            $table->integer('priority')->default(0);

            $table->primary(['did_number_id', 'sentry_blacklist_id']);
            $table->index(['did_number_id', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('did_sentry_blacklist');
        Schema::dropIfExists('sentry_blacklist_items');
        Schema::dropIfExists('sentry_blacklists');
    }
};
