<?php

declare(strict_types=1);

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
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->string('correlation_id')->index()->comment('Unique ID for request tracing');
            $table->string('provider')->comment('Email provider used (mailgun, mailjet, etc.)');
            $table->string('driver')->comment('Driver class name');
            $table->string('from_email')->comment('Sender email address');
            $table->string('to_email')->comment('Primary recipient email address');
            $table->string('subject')->comment('Email subject');
            $table->enum('status', ['queued', 'sent', 'delivered', 'bounced', 'failed'])
                ->default('queued')
                ->comment('Delivery status');
            $table->string('provider_message_id')->nullable()->comment('Provider tracking ID');
            $table->text('error_message')->nullable()->comment('Error details if failed');
            $table->json('metadata')->nullable()->comment('Request/response data');
            $table->timestamp('sent_at')->nullable()->comment('When the email was sent');
            $table->timestamps();

            // Composite index for common queries
            $table->index(['provider', 'status', 'created_at'], 'email_logs_lookup_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
