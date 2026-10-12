<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DATA-15: stop user deletion from cascading to recordings, and give the queue
 * fallback / agent columns real foreign keys (nullOnDelete).
 *
 * Safe on existing data: orphaned values are NULLed first (a dangling fallback id
 * already routes nowhere), each step is skipped if already applied, and nothing
 * is dropped except the recordings FK that is immediately re-created.
 *
 * Not changed: ring_groups.fallback_ai_assistant_id references extensions on purpose
 * (RingGroup::fallbackAiAssistant() and its request validate against Extension).
 */
return new class extends Migration
{
    /** @var array<string, array<string, string>> table => column => referenced table */
    private const NEW_FOREIGN_KEYS = [
        'call_queues' => [
            'fallback_extension_id' => 'extensions',
            'fallback_ring_group_id' => 'ring_groups',
            'fallback_ivr_menu_id' => 'ivr_menus',
            'fallback_ai_assistant_id' => 'ai_assistants',
            'fallback_ai_load_balancer_id' => 'ai_assistant_load_balancers',
        ],
        'ring_groups' => ['fallback_call_queue_id' => 'call_queues'],
        'queue_calls' => ['agent_user_id' => 'users'],
    ];

    public function up(): void
    {
        foreach (['created_by', 'updated_by'] as $column) {
            $this->dropForeignOn('recordings', $column);
        }
        Schema::table('recordings', function (Blueprint $table): void {
            $table->unsignedBigInteger('created_by')->nullable()->change();
            $table->unsignedBigInteger('updated_by')->nullable()->change();
        });
        Schema::table('recordings', function (Blueprint $table): void {
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        foreach (self::NEW_FOREIGN_KEYS as $table => $columns) {
            foreach ($columns as $column => $referenced) {
                if ($this->hasForeignOn($table, $column)) {
                    continue;
                }

                DB::table($table)
                    ->whereNotNull($column)
                    ->whereNotIn($column, DB::table($referenced)->select('id'))
                    ->update([$column => null]);

                Schema::table($table, function (Blueprint $blueprint) use ($column, $referenced): void {
                    $blueprint->foreign($column)->references('id')->on($referenced)->nullOnDelete();
                });
            }
        }
    }

    /**
     * Recordings go back to cascade deletes but stay nullable: rows whose creator was
     * deleted since the up() would otherwise block restoring NOT NULL.
     */
    public function down(): void
    {
        foreach (self::NEW_FOREIGN_KEYS as $table => $columns) {
            foreach (array_keys($columns) as $column) {
                $this->dropForeignOn($table, $column);
            }
        }

        foreach (['created_by', 'updated_by'] as $column) {
            $this->dropForeignOn('recordings', $column);
        }
        Schema::table('recordings', function (Blueprint $table): void {
            $table->foreign('created_by')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    private function hasForeignOn(string $table, string $column): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreign) {
            if ($foreign['columns'] === [$column]) {
                return true;
            }
        }

        return false;
    }

    private function dropForeignOn(string $table, string $column): void
    {
        foreach (Schema::getForeignKeys($table) as $foreign) {
            if ($foreign['columns'] === [$column]) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($foreign['name']));
            }
        }
    }
};
