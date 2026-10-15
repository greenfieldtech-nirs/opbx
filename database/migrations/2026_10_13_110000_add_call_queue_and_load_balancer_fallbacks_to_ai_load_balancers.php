<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI load balancers can fall back to a call queue or another AI load balancer; until now the
 * actions existed but had no target column. Nullable, FK nullOnDelete (like the other fallbacks).
 * The fallback_action ENUM only knew extension/ring_group/ivr_menu/ai_assistant/hangup (+unavailable), so
 * choosing call_queue or ai_load_balancer failed at the database; those two values are appended.
 * Idempotent: each column / key / enum value is only added when missing.
 */
return new class extends Migration
{
    /** @var array<string, string> column => referenced table */
    private const COLUMNS = [
        'fallback_call_queue_id' => 'call_queues',
        'fallback_ai_load_balancer_id' => 'ai_assistant_load_balancers',
    ];

    /** @var list<string> */
    private const ENUM_VALUES = ['ai_load_balancer', 'call_queue'];

    public function up(): void
    {
        $this->rewriteEnum(function (string $type): string {
            foreach (self::ENUM_VALUES as $value) {
                if (! str_contains($type, "'{$value}'")) {
                    $type = substr($type, 0, -1).",'{$value}')";
                }
            }

            return $type;
        });

        foreach (self::COLUMNS as $column => $referenced) {
            if (! Schema::hasColumn('ai_assistant_load_balancers', $column)) {
                Schema::table('ai_assistant_load_balancers', function (Blueprint $table) use ($column): void {
                    $table->unsignedBigInteger($column)->nullable()->after('fallback_ai_assistant_id');
                });
            }

            if (! $this->hasForeignOn($column)) {
                Schema::table('ai_assistant_load_balancers', function (Blueprint $table) use ($column, $referenced): void {
                    $table->foreign($column)->references('id')->on($referenced)->nullOnDelete();
                });
            }
        }
    }

    /** Refuses while a row still uses one of the new actions (no safe value to map it back to). */
    public function down(): void
    {
        if (Schema::hasColumn('ai_assistant_load_balancers', 'fallback_action')
            && DB::table('ai_assistant_load_balancers')->whereIn('fallback_action', self::ENUM_VALUES)->exists()) {
            throw new RuntimeException('Cannot roll back: load balancers still use fallback_action call_queue / ai_load_balancer. Re-point them first.');
        }

        foreach (array_keys(self::COLUMNS) as $column) {
            foreach (Schema::getForeignKeys('ai_assistant_load_balancers') as $foreign) {
                if ($foreign['columns'] === [$column]) {
                    Schema::table('ai_assistant_load_balancers', fn (Blueprint $table) => $table->dropForeign($foreign['name']));
                }
            }

            if (Schema::hasColumn('ai_assistant_load_balancers', $column)) {
                Schema::table('ai_assistant_load_balancers', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }

        $this->rewriteEnum(function (string $type): string {
            foreach (self::ENUM_VALUES as $value) {
                $type = str_replace(",'{$value}'", '', $type);
            }

            return $type;
        });
    }

    /** @param  callable(string): string  $newType */
    private function rewriteEnum(callable $newType): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasColumn('ai_assistant_load_balancers', 'fallback_action')) {
            return;
        }

        $info = DB::selectOne("SHOW COLUMNS FROM `ai_assistant_load_balancers` WHERE Field = 'fallback_action'");
        $type = (string) $info->Type;
        if (! str_starts_with(strtolower($type), 'enum(') || $newType($type) === $type) {
            return;
        }

        $nullable = $info->Null === 'YES' ? 'NULL' : 'NOT NULL';
        $default = $info->Default === null
            ? ($info->Null === 'YES' ? ' DEFAULT NULL' : '')
            : ' DEFAULT '.DB::getPdo()->quote((string) $info->Default);

        DB::statement("ALTER TABLE `ai_assistant_load_balancers` MODIFY COLUMN `fallback_action` {$newType($type)} {$nullable}{$default}");
    }

    private function hasForeignOn(string $column): bool
    {
        foreach (Schema::getForeignKeys('ai_assistant_load_balancers') as $foreign) {
            if ($foreign['columns'] === [$column]) {
                return true;
            }
        }

        return false;
    }
};
