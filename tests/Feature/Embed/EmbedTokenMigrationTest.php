<?php

declare(strict_types=1);

namespace Tests\Feature\Embed;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class EmbedTokenMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('user_embed_tokens'));
        $this->assertTrue(Schema::hasColumns('user_embed_tokens', [
            'id', 'user_id', 'organization_id', 'token',
            'icon_position', 'icon_background_color',
            'last_used_at', 'created_at', 'updated_at',
        ]));

        // Allowlist moved to the organization level; must not live per-token.
        $this->assertFalse(Schema::hasColumn('user_embed_tokens', 'allowed_domains'));
    }

    public function test_allowlist_lives_on_cloudonix_settings(): void
    {
        $this->assertTrue(Schema::hasColumn('cloudonix_settings', 'embed_allowed_domains'));
    }
}
