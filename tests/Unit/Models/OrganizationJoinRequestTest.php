<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Organization;
use App\Models\OrganizationJoinRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationJoinRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_belongs_to_organization(): void
    {
        $request = OrganizationJoinRequest::factory()->create();

        $this->assertInstanceOf(Organization::class, $request->organization);
    }
}
