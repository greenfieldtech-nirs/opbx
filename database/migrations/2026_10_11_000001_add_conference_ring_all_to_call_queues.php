<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-queue opt-in for conference-mediated ring-all offers: each agent is
     * originated in its own Cloudonix session and the winner joins the caller
     * in a conference room (instead of a multi-target <Dial>).
     */
    public function up(): void
    {
        Schema::table('call_queues', function (Blueprint $table) {
            $table->boolean('conference_ring_all')->default(false)->after('immediate_connect');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('call_queues', function (Blueprint $table) {
            $table->dropColumn('conference_ring_all');
        });
    }
};
