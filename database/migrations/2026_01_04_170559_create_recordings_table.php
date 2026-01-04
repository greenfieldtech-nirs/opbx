<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('recordings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->enum('type', ['upload', 'remote']);
            $table->string('file_path')->nullable(); // For uploaded files
            $table->string('remote_url')->nullable(); // For remote URLs
            $table->string('original_filename')->nullable();
            $table->unsignedBigInteger('file_size')->nullable(); // In bytes
            $table->string('mime_type')->nullable();
            $table->integer('duration_seconds')->nullable(); // If we can extract it later
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('updated_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();

            // Indexes for performance
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'type']);
            $table->index('created_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recordings');
    }
};
