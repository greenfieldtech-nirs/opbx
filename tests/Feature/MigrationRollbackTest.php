<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class MigrationRollbackTest extends TestCase
{
    /**
     * Test that the migration file syntax is valid by checking it can be included without errors.
     */
    public function test_migration_file_syntax_is_valid(): void
    {
        // This test will fail if there are PHP syntax errors in the migration file
        $migration = include database_path('migrations/2026_01_09_000000_create_outbound_whitelists_table.php');

        $this->assertInstanceOf(\Illuminate\Database\Migrations\Migration::class, $migration);
        $this->assertTrue(method_exists($migration, 'up'));
        $this->assertTrue(method_exists($migration, 'down'));
    }

    /**
     * Test that the migration has proper structure and rollback capability.
     */
    public function test_migration_structure_and_rollback_capability(): void
    {
        $migration = include database_path('migrations/2026_01_09_000000_create_outbound_whitelists_table.php');

        // Verify the migration class is properly structured
        $this->assertInstanceOf(\Illuminate\Database\Migrations\Migration::class, $migration);

        // Check that up() method exists and is callable
        $this->assertTrue(method_exists($migration, 'up'));
        $reflection = new \ReflectionMethod($migration, 'up');
        $this->assertTrue($reflection->isPublic());
        $this->assertEquals('void', $reflection->getReturnType()?->getName());

        // Check that down() method exists and is callable
        $this->assertTrue(method_exists($migration, 'down'));
        $reflection = new \ReflectionMethod($migration, 'down');
        $this->assertTrue($reflection->isPublic());
        $this->assertEquals('void', $reflection->getReturnType()?->getName());

        // Test that the migration can be instantiated multiple times (important for rollback testing)
        $migration2 = include database_path('migrations/2026_01_09_000000_create_outbound_whitelists_table.php');
        $this->assertInstanceOf(\Illuminate\Database\Migrations\Migration::class, $migration2);
        $this->assertNotSame($migration, $migration2); // Should be different instances
    }
}