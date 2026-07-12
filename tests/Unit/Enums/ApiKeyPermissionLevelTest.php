<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\ApiKeyPermissionLevel;
use Tests\TestCase;

class ApiKeyPermissionLevelTest extends TestCase
{
    public function test_write_permits_all_methods(): void
    {
        foreach (['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->assertTrue(ApiKeyPermissionLevel::WRITE->permitsMethod($method));
        }
    }

    public function test_read_permits_only_get_and_head(): void
    {
        $this->assertTrue(ApiKeyPermissionLevel::READ->permitsMethod('GET'));
        $this->assertTrue(ApiKeyPermissionLevel::READ->permitsMethod('HEAD'));
        $this->assertFalse(ApiKeyPermissionLevel::READ->permitsMethod('POST'));
        $this->assertFalse(ApiKeyPermissionLevel::READ->permitsMethod('PUT'));
        $this->assertFalse(ApiKeyPermissionLevel::READ->permitsMethod('PATCH'));
        $this->assertFalse(ApiKeyPermissionLevel::READ->permitsMethod('DELETE'));
    }

    public function test_method_check_is_case_insensitive(): void
    {
        $this->assertTrue(ApiKeyPermissionLevel::READ->permitsMethod('get'));
        $this->assertTrue(ApiKeyPermissionLevel::WRITE->permitsMethod('post'));
    }
}
