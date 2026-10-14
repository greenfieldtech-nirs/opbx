<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Call queues can fall back to another call queue; until now the action existed in the API
 * but had no target column, and the fallback_action ENUM did not know 'call_queue' (saving it
 * failed at the database). Adds the value and fallback_call_queue_id (nullable, FK
 * nullOnDelete like the other fallbacks). Idempotent: each part is only added when missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rewriteEnum(fn (string $type): string => str_contains($type, "'call_queue'")
            ? $type
            : substr($type, 0, -1).",'call_queue')");

        if (! Schema::hasColumn('call_queues', 'fallback_call_queue_id')) {
            Schema::table('call_queues', function (Blueprint $table): void {
                $table->unsignedBigInteger('fallback_call_queue_id')->nullable()->after('fallback_ai_load_balancer_id');
            });
        }

        if (! $this->hasForeign()) {
            Schema::table('call_queues', function (Blueprint $table): void {
                $table->foreign('fallback_call_queue_id')->references('id')->on('call_queues')->nullOnDelete();
            });
        }
    }

    /** Refuses while a queue still uses the call_queue action (no safe value to map it back to). */
    public function down(): void
    {
        if (DB::table('call_queues')->where('fallback_action', 'call_queue')->exists()) {
            throw new RuntimeException('Cannot roll back: call queues still use fallback_action call_queue. Re-point them first.');
        }

        foreach (Schema::getForeignKeys('call_queues') as $foreign) {
            if ($foreign['columns'] === ['fallback_call_queue_id']) {
                Schema::table('call_queues', fn (Blueprint $table) => $table->dropForeign($foreign['name']));
            }
        }

        if (Schema::hasColumn('call_queues', 'fallback_call_queue_id')) {
            Schema::table('call_queues', fn (Blueprint $table) => $table->dropColumn('fallback_call_queue_id'));
        }

        $this->rewriteEnum(fn (string $type): string => str_replace(",'call_queue'", '', $type));
    }

    /** @param  callable(string): string  $newType */
    private function rewriteEnum(callable $newType): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $info = DB::selectOne("SHOW COLUMNS FROM `call_queues` WHERE Field = 'fallback_action'");
        $type = (string) $info->Type;
        if (! str_starts_with(strtolower($type), 'enum(') || $newType($type) === $type) {
            return;
        }

        $nullable = $info->Null === 'YES' ? 'NULL' : 'NOT NULL';
        $default = $info->Default === null
            ? ($info->Null === 'YES' ? ' DEFAULT NULL' : '')
            : ' DEFAULT '.DB::getPdo()->quote((string) $info->Default);

        DB::statement("ALTER TABLE `call_queues` MODIFY COLUMN `fallback_action` {$newType($type)} {$nullable}{$default}");
    }

    private function hasForeign(): bool
    {
        foreach (Schema::getForeignKeys('call_queues') as $foreign) {
            if ($foreign['columns'] === ['fallback_call_queue_id']) {
                return true;
            }
        }

        return false;
    }
};
