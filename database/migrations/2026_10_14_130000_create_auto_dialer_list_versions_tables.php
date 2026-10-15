<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Previous versions of a distribution list: re-uploading a file keeps the replaced entries,
 * as they were at that moment, until expires_at (config retention.auto_dialer_list_versions_days).
 * Also records who uploaded a list's current file and when, so the version can show it later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_dialer_lists', function (Blueprint $table): void {
            $table->foreignId('uploaded_by')->nullable()->after('original_filename')->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at')->nullable()->after('uploaded_by');
        });

        Schema::create('auto_dialer_list_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('list_id')->constrained('auto_dialer_lists')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('original_filename')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('replaced_at');
            $table->timestamp('expires_at')->nullable()->index();
            $table->unsignedInteger('entries_count')->default(0);
            $table->unsignedInteger('total_rows')->nullable();
            $table->unsignedInteger('valid_rows')->nullable();
            $table->unsignedInteger('invalid_rows')->nullable();
            $table->timestamps();

            $table->unique(['list_id', 'version_number']);
        });

        Schema::create('auto_dialer_list_version_destinations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('version_id')->constrained('auto_dialer_list_versions')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('phone_number', 50);
            $table->string('name', 255)->nullable();
            $table->string('batch_identifier', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->string('status', 20);
            $table->unsignedInteger('dial_attempts')->default(0);
            $table->timestamp('last_dialed_at')->nullable();
            $table->string('last_disposition', 50)->nullable();
            $table->unsignedInteger('duration')->default(0);
            $table->timestamps();

            $table->index(['version_id', 'phone_number'], 'adlvd_version_phone_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_dialer_list_version_destinations');
        Schema::dropIfExists('auto_dialer_list_versions');

        Schema::table('auto_dialer_lists', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('uploaded_by');
            $table->dropColumn('uploaded_at');
        });
    }
};
