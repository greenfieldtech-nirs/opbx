<?php

declare(strict_types=1);

namespace Tests\Feature\Supervisor;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MigrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_supervisor_user_assignments_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('supervisor_user_assignments'));
    }

    public function test_supervisor_ring_group_assignments_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('supervisor_ring_group_assignments'));
    }

    public function test_users_role_enum_includes_supervisor(): void
    {
        $column = DB::selectOne("SHOW COLUMNS FROM users WHERE Field = 'role'");
        $this->assertStringContainsString('supervisor', $column->Type);
    }
}
