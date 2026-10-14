<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ring_groups.fallback_ai_assistant_id held an AI-assistant *extension* id; it now references
 * ai_assistants (on delete set null), like call_queues and ai_assistant_load_balancers.
 *
 * Each value becomes that extension's AI assistant (extensions.ai_assistant_id, else
 * configuration.ai_assistant_id) in the same organization, or null when there is none.
 * Idempotent: once the foreign key points at ai_assistants nothing is done again.
 */
return new class extends Migration
{
    public function up(): void
    {
        $referenced = $this->referencedTable();
        if ($referenced === 'ai_assistants') {
            return;
        }

        if ($referenced !== null) {
            $this->dropForeign();
            $this->convertExtensionIdsToAiAssistantIds();
        }
        // shortcut: no foreign key at all means an earlier run stopped after converting; values are only checked for orphans.
        $this->nullOrphans('ai_assistants');

        Schema::table('ring_groups', fn (Blueprint $table) => $table->foreign('fallback_ai_assistant_id')->references('id')->on('ai_assistants')->nullOnDelete());
    }

    public function down(): void
    {
        if ($this->referencedTable() !== 'ai_assistants') {
            return;
        }

        $this->dropForeign();
        DB::statement(<<<'SQL'
            UPDATE ring_groups rg
            SET rg.fallback_ai_assistant_id = (
                SELECT MIN(e.id) FROM extensions e
                WHERE e.organization_id = rg.organization_id AND e.type = 'ai_assistant' AND e.ai_assistant_id = rg.fallback_ai_assistant_id
            )
            WHERE rg.fallback_ai_assistant_id IS NOT NULL
            SQL);
        $this->nullOrphans('extensions');

        Schema::table('ring_groups', fn (Blueprint $table) => $table->foreign('fallback_ai_assistant_id')->references('id')->on('extensions')->nullOnDelete());
    }

    /** Public so a test can run the data step on rows written with foreign key checks off. */
    public function convertExtensionIdsToAiAssistantIds(): void
    {
        DB::statement(<<<'SQL'
            UPDATE ring_groups rg
            LEFT JOIN extensions e ON e.id = rg.fallback_ai_assistant_id AND e.organization_id = rg.organization_id
            LEFT JOIN ai_assistants a ON a.organization_id = rg.organization_id
                AND a.id = COALESCE(e.ai_assistant_id, CAST(JSON_UNQUOTE(JSON_EXTRACT(e.configuration, '$.ai_assistant_id')) AS UNSIGNED))
            SET rg.fallback_ai_assistant_id = a.id
            WHERE rg.fallback_ai_assistant_id IS NOT NULL
            SQL);
    }

    private function nullOrphans(string $referenced): void
    {
        DB::table('ring_groups')
            ->whereNotNull('fallback_ai_assistant_id')
            ->whereNotIn('fallback_ai_assistant_id', DB::table($referenced)->select('id'))
            ->update(['fallback_ai_assistant_id' => null]);
    }

    private function referencedTable(): ?string
    {
        foreach (Schema::getForeignKeys('ring_groups') as $foreign) {
            if ($foreign['columns'] === ['fallback_ai_assistant_id']) {
                return $foreign['foreign_table'];
            }
        }

        return null;
    }

    private function dropForeign(): void
    {
        foreach (Schema::getForeignKeys('ring_groups') as $foreign) {
            if ($foreign['columns'] === ['fallback_ai_assistant_id']) {
                Schema::table('ring_groups', fn (Blueprint $table) => $table->dropForeign($foreign['name']));
            }
        }
    }
};
