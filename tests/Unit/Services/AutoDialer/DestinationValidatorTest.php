<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AutoDialer;

use App\Enums\AutoDialer\DestinationStatus;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\Organization;
use App\Models\User;
use App\Services\AutoDialer\DestinationValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestinationValidatorTest extends TestCase
{
    use RefreshDatabase;

    private DestinationValidator $validator;

    private Organization $organization;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new DestinationValidator;
        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_is_valid_returns_true_for_eligible_destination(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'max_retry_attempts' => 3,
        ]);

        $destination = AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::PENDING->value,
            'retry_count' => 0,
            'valid' => true,
            'whitelist_status' => 'allowed',
        ]);

        $result = $this->validator->isValid($destination, $campaign);

        $this->assertTrue($result);
    }

    public function test_is_valid_returns_false_for_invalid_destination(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
        ]);

        $destination = AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::PENDING->value,
            'valid' => false,
            'validation_error' => 'Invalid phone number format',
        ]);

        $result = $this->validator->isValid($destination, $campaign);

        $this->assertFalse($result);
    }

    public function test_is_valid_returns_false_for_blocked_whitelist(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
        ]);

        $destination = AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::PENDING->value,
            'valid' => true,
            'whitelist_status' => 'blocked',
        ]);

        $result = $this->validator->isValid($destination, $campaign);

        $this->assertFalse($result);
    }

    public function test_is_valid_returns_false_for_exceeded_retry_count(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'max_retry_attempts' => 3,
        ]);

        $destination = AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::PENDING->value,
            'valid' => true,
            'whitelist_status' => 'allowed',
            'retry_count' => 3,
        ]);

        $result = $this->validator->isValid($destination, $campaign);

        $this->assertFalse($result);
    }

    public function test_is_valid_returns_false_for_non_pending_status(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
        ]);

        $destination = AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::COMPLETED->value,
            'valid' => true,
            'whitelist_status' => 'allowed',
        ]);

        $result = $this->validator->isValid($destination, $campaign);

        $this->assertFalse($result);
    }

    public function test_is_valid_allows_retry_count_less_than_max(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'max_retry_attempts' => 5,
        ]);

        $destination = AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::PENDING->value,
            'valid' => true,
            'whitelist_status' => 'allowed',
            'retry_count' => 2,
        ]);

        $result = $this->validator->isValid($destination, $campaign);

        $this->assertTrue($result);
    }

    public function test_can_retry_returns_true_when_below_max_retries(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'max_retry_attempts' => 3,
        ]);

        $destination = AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'retry_count' => 1,
        ]);

        $result = $this->validator->canRetry($destination, $campaign);

        $this->assertTrue($result);
    }

    public function test_can_retry_returns_false_when_at_max_retries(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'max_retry_attempts' => 3,
        ]);

        $destination = AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'retry_count' => 3,
        ]);

        $result = $this->validator->canRetry($destination, $campaign);

        $this->assertFalse($result);
    }

    public function test_can_retry_returns_false_when_exceeds_max_retries(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'max_retry_attempts' => 3,
        ]);

        $destination = AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'retry_count' => 5,
        ]);

        $result = $this->validator->canRetry($destination, $campaign);

        $this->assertFalse($result);
    }

    public function test_can_retry_returns_true_when_zero_max_retries(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'max_retry_attempts' => 0,
        ]);

        $destination = AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'retry_count' => 0,
        ]);

        $result = $this->validator->canRetry($destination, $campaign);

        $this->assertFalse($result);
    }

    public function test_get_validation_reason_returns_null_for_valid_destination(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'max_retry_attempts' => 3,
        ]);

        $destination = AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::PENDING->value,
            'valid' => true,
            'whitelist_status' => 'allowed',
            'retry_count' => 0,
        ]);

        $result = $this->validator->getValidationReason($destination, $campaign);

        $this->assertNull($result);
    }

    public function test_get_validation_reason_returns_error_for_invalid(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
        ]);

        $destination = AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'valid' => false,
            'validation_error' => 'Phone number too short',
        ]);

        $result = $this->validator->getValidationReason($destination, $campaign);

        $this->assertStringContainsString('Invalid destination', $result);
        $this->assertStringContainsString('Phone number too short', $result);
    }

    public function test_get_validation_reason_returns_error_for_blocked(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
        ]);

        $destination = AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'valid' => true,
            'whitelist_status' => 'blocked',
        ]);

        $result = $this->validator->getValidationReason($destination, $campaign);

        $this->assertStringContainsString('blocked by outbound whitelist', $result);
    }

    public function test_get_validation_reason_returns_error_for_max_retries(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'max_retry_attempts' => 2,
        ]);

        $destination = AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'valid' => true,
            'whitelist_status' => 'allowed',
            'retry_count' => 2,
        ]);

        $result = $this->validator->getValidationReason($destination, $campaign);

        $this->assertStringContainsString('Maximum retry attempts', $result);
    }

    public function test_get_validation_reason_returns_error_for_wrong_status(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
        ]);

        $destination = AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::IN_PROGRESS->value,
            'valid' => true,
            'whitelist_status' => 'allowed',
        ]);

        $result = $this->validator->getValidationReason($destination, $campaign);

        $this->assertStringContainsString('not in pending status', $result);
    }
}
