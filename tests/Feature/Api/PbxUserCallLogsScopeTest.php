<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\CallDetailRecord;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A PBX User only sees call records from or to their own extension(s), enforced by the server on every
 * call-record endpoint (not just the Call Logs page's default filter).
 */
final class PbxUserCallLogsScopeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $pbxUser;

    private CallDetailRecord $outbound;

    private CallDetailRecord $inbound;

    private CallDetailRecord $plusForm;

    private CallDetailRecord $secondExtension;

    private CallDetailRecord $others;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        $this->pbxUser = User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::PBX_USER]);
        foreach (['1005', '1006'] as $number) {
            Extension::factory()->create([
                'organization_id' => $this->org->id,
                'user_id' => $this->pbxUser->id,
                'type' => 'user',
                'extension_number' => $number,
            ]);
        }

        $this->outbound = $this->cdr('1005', '+15551234567', 60);
        $this->inbound = $this->cdr('+15559876543', '1005', 30);
        $this->plusForm = $this->cdr('+1005', '2001', 10);
        $this->secondExtension = $this->cdr('2002', '1006', 5);
        $this->others = $this->cdr('2001', '2002', 500);
    }

    private function cdr(string $from, string $to, int $duration): CallDetailRecord
    {
        return CallDetailRecord::factory()->create([
            'organization_id' => $this->org->id,
            'from' => $from,
            'to' => $to,
            'duration' => $duration,
            'session_timestamp' => now(),
        ]);
    }

    /** @return list<int> */
    private function ids(string $query = ''): array
    {
        $ids = $this->actingAs($this->pbxUser)->getJson('/api/v1/call-detail-records?per_page=100'.$query)
            ->assertOk()->json('data.*.id');
        sort($ids);

        return $ids;
    }

    /** @return list<int> */
    private function own(): array
    {
        $ids = [$this->outbound->id, $this->inbound->id, $this->plusForm->id, $this->secondExtension->id];
        sort($ids);

        return $ids;
    }

    public function test_index_without_filters_lists_only_own_calls(): void
    {
        $this->assertSame($this->own(), $this->ids());
    }

    public function test_index_filters_cannot_widen_the_scope(): void
    {
        $this->assertSame([], $this->ids('&from=2001&to=2002'));
        $this->assertSame([$this->outbound->id], $this->ids('&from=1005&to=1555&from_to_match=all'));

        $any = $this->ids('&from=1005&to=1005&from_to_match=any');
        $expected = [$this->outbound->id, $this->inbound->id, $this->plusForm->id];
        sort($expected);
        $this->assertSame($expected, $any);
    }

    public function test_show_of_another_users_call_is_forbidden(): void
    {
        $this->actingAs($this->pbxUser)->getJson("/api/v1/call-detail-records/{$this->others->id}")->assertForbidden();
        $this->actingAs($this->pbxUser)->getJson("/api/v1/call-detail-records/{$this->inbound->id}")
            ->assertOk()->assertJsonPath('data.id', $this->inbound->id);
    }

    public function test_recording_of_another_users_call_is_forbidden(): void
    {
        $this->actingAs($this->pbxUser)->get("/api/v1/call-detail-records/{$this->others->id}/recording")->assertForbidden();
    }

    public function test_export_contains_only_own_calls(): void
    {
        $response = $this->actingAs($this->pbxUser)->get('/api/v1/call-detail-records/export')->assertOk();
        ob_start();
        $response->baseResponse->sendContent();
        $lines = explode("\n", trim((string) ob_get_clean()));

        $this->assertCount(5, $lines); // header + 4 own calls
        $this->assertStringNotContainsString('2001,2002', implode("\n", $lines));
    }

    public function test_statistics_count_only_own_calls(): void
    {
        $this->actingAs($this->pbxUser)->getJson('/api/v1/call-detail-records/statistics')
            ->assertOk()
            ->assertJson(['total_calls' => 4, 'total_duration' => 105]);
    }

    public function test_pbx_user_without_extension_sees_nothing(): void
    {
        $lonely = User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::PBX_USER]);

        $this->actingAs($lonely)->getJson('/api/v1/call-detail-records')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($lonely)->getJson('/api/v1/call-detail-records/statistics')->assertJsonPath('total_calls', 0);
        $this->actingAs($lonely)->getJson("/api/v1/call-detail-records/{$this->outbound->id}")->assertForbidden();
    }

    /** @return array<string, array{UserRole}> */
    public static function unrestrictedRoles(): array
    {
        return [
            'owner' => [UserRole::OWNER],
            'admin' => [UserRole::PBX_ADMIN],
            'reporter' => [UserRole::REPORTER],
        ];
    }

    #[DataProvider('unrestrictedRoles')]
    public function test_other_roles_still_see_every_call(UserRole $role): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id, 'role' => $role]);

        $this->actingAs($user)->getJson('/api/v1/call-detail-records?per_page=100')->assertOk()->assertJsonCount(5, 'data');
        $this->actingAs($user)->getJson("/api/v1/call-detail-records/{$this->others->id}")->assertOk();
        $this->actingAs($user)->getJson('/api/v1/call-detail-records/statistics')->assertJsonPath('total_calls', 5);
    }
}
