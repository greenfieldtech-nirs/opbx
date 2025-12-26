<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add contact and address fields to users table for enhanced profile management.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 20)->nullable()->after('email');
            $table->text('street_address')->nullable()->after('phone');
            $table->string('city', 100)->nullable()->after('street_address');
            $table->string('state_province', 100)->nullable()->after('city');
            $table->string('postal_code', 20)->nullable()->after('state_province');
            $table->string('country', 100)->nullable()->after('postal_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'phone',
                'street_address',
                'city',
                'state_province',
                'postal_code',
                'country',
            ]);
        });
    }
};
