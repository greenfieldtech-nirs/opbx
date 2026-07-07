<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\OrganizationJoinRequestStatus;
use Tests\TestCase;

class OrganizationJoinRequestStatusTest extends TestCase
{
    public function test_status_values(): void
    {
        $this->assertSame('pending', OrganizationJoinRequestStatus::PENDING->value);
        $this->assertSame('approved', OrganizationJoinRequestStatus::APPROVED->value);
        $this->assertSame('rejected', OrganizationJoinRequestStatus::REJECTED->value);
    }
}
