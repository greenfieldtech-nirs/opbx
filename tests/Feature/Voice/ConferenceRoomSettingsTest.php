<?php

declare(strict_types=1);

namespace Tests\Feature\Voice;

use App\Enums\UserStatus;
use App\Models\CloudonixSettings;
use App\Models\ConferenceRoom;
use App\Models\DidNumber;
use App\Models\Organization;
use App\Services\VoiceRouting\Strategies\ConferenceRoutingStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ConferenceRoomSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        CloudonixSettings::factory()->create([
            'organization_id' => $this->organization->id,
            'webhook_base_url' => 'https://example.com',
        ]);
    }

    private function room(array $attrs = []): ConferenceRoom
    {
        return ConferenceRoom::create($attrs + [
            'organization_id' => $this->organization->id,
            'name' => 'Room '.uniqid(),
            'max_participants' => 10,
            'status' => UserStatus::ACTIVE,
            'pin_required' => false,
            'recording_enabled' => false,
            'recording_auto_start' => false,
            'wait_for_host' => false,
            'mute_on_entry' => false,
            'announce_join_leave' => false,
            'music_on_hold' => true,
            'talk_detection_enabled' => false,
        ]);
    }

    private function route(ConferenceRoom $room): string
    {
        $request = Request::create('/voice/route', 'POST', ['_organization_id' => $this->organization->id]);

        return app(ConferenceRoutingStrategy::class)->route($request, new DidNumber, ['conference_room' => $room])->getContent();
    }

    private function pin(ConferenceRoom $room, string $digits, int $attempt = 1): string
    {
        $request = Request::create(
            "/callbacks/voice/conference-pin?room_id={$room->id}&attempt={$attempt}",
            'POST',
            ['_organization_id' => $this->organization->id, 'Digits' => $digits]
        );

        return app(ConferenceRoutingStrategy::class)->handlePinCallback($request)->getContent();
    }

    public function test_open_room_joins_with_documented_attributes(): void
    {
        $xml = $this->route($this->room(['max_participants' => 500]));

        $this->assertStringContainsString('<Conference', $xml);
        $this->assertStringContainsString('startConferenceOnEnter="true"', $xml);
        $this->assertStringContainsString('holdMusic="true"', $xml);
        $this->assertStringContainsString('muted="false"', $xml);
        $this->assertStringContainsString('prompts="false"', $xml);
        $this->assertStringContainsString('maxParticipants="250"', $xml);
        $this->assertStringNotContainsString('<Gather', $xml);
        $this->assertStringNotContainsString('record=', $xml);
    }

    public function test_moh_mute_prompts_and_recording_are_applied(): void
    {
        $xml = $this->route($this->room([
            'music_on_hold' => false,
            'mute_on_entry' => true,
            'announce_join_leave' => true,
            'recording_enabled' => true,
            'recording_auto_start' => true,
        ]));

        $this->assertStringContainsString('holdMusic="false"', $xml);
        $this->assertStringContainsString('muted="true"', $xml);
        $this->assertStringContainsString('prompts="true"', $xml);
        $this->assertStringContainsString('record="record-from-start"', $xml);
        $this->assertStringContainsString('https://example.com/api/webhooks/cloudonix/recording-status', $xml);
    }

    public function test_recording_enabled_records_even_without_auto_start(): void
    {
        $xml = $this->route($this->room(['recording_enabled' => true, 'recording_auto_start' => false]));

        $this->assertStringContainsString('record="record-from-start"', $xml);
    }

    public function test_recording_disabled_does_not_record(): void
    {
        $xml = $this->route($this->room(['recording_enabled' => false, 'recording_auto_start' => true]));

        $this->assertStringNotContainsString('record=', $xml);
    }

    public function test_only_the_host_ends_the_room_on_exit(): void
    {
        $room = $this->room(['pin_required' => true, 'pin' => '1234', 'host_pin' => '5678']);

        $this->assertStringContainsString('endConferenceOnExit="true"', $this->pin($room, '5678'));
        $this->assertStringContainsString('endConferenceOnExit="false"', $this->pin($room, '1234'));
        $this->assertStringContainsString('endConferenceOnExit="false"', $this->route($this->room()));
    }

    public function test_pin_required_prompts_instead_of_joining(): void
    {
        $room = $this->room(['pin_required' => true, 'pin' => '1234']);
        $xml = $this->route($room);

        $this->assertStringContainsString('<Gather', $xml);
        $this->assertStringContainsString("room_id={$room->id}", $xml);
        $this->assertStringNotContainsString('1234', $xml);
        $this->assertStringNotContainsString('<Conference', $xml);
    }

    public function test_correct_pin_joins_wrong_pin_reprompts_then_hangs_up(): void
    {
        $room = $this->room(['pin_required' => true, 'pin' => '1234']);

        $this->assertStringContainsString('<Conference', $this->pin($room, '1234'));

        $wrong = $this->pin($room, '9999', 1);
        $this->assertStringContainsString('<Gather', $wrong);
        $this->assertStringContainsString('attempt=2', $wrong);
        $this->assertStringNotContainsString('<Conference', $wrong);

        $last = $this->pin($room, '9999', 3);
        $this->assertStringContainsString('<Hangup', $last);
        $this->assertStringNotContainsString('<Conference', $last);
    }

    public function test_host_pin_joins_as_host_who_starts_the_room_and_is_not_muted(): void
    {
        $room = $this->room([
            'pin_required' => true, 'pin' => '1234', 'host_pin' => '5678',
            'wait_for_host' => true, 'mute_on_entry' => true,
        ]);

        $participant = $this->pin($room, '1234');
        $this->assertStringContainsString('startConferenceOnEnter="false"', $participant);
        $this->assertStringContainsString('muted="true"', $participant);

        $host = $this->pin($room, '5678');
        $this->assertStringContainsString('startConferenceOnEnter="true"', $host);
        $this->assertStringContainsString('muted="false"', $host);
    }

    public function test_wait_for_host_without_pin_required_offers_an_optional_host_prompt(): void
    {
        $room = $this->room(['wait_for_host' => true, 'host_pin' => '5678']);

        $xml = $this->route($room);
        $this->assertStringContainsString('<Gather', $xml);
        // No input falls through to a waiting participant.
        $this->assertStringContainsString('startConferenceOnEnter="false"', $xml);

        // Anything but the host PIN joins as a waiting participant (no rejection).
        $this->assertStringContainsString('startConferenceOnEnter="false"', $this->pin($room, '0000'));
        $this->assertStringContainsString('startConferenceOnEnter="true"', $this->pin($room, '5678'));
    }

    public function test_wait_for_host_without_host_pin_is_ignored_so_the_room_is_not_stuck(): void
    {
        $xml = $this->route($this->room(['wait_for_host' => true]));

        $this->assertStringContainsString('startConferenceOnEnter="true"', $xml);
    }

    public function test_pin_callback_is_scoped_to_the_organization(): void
    {
        $room = $this->room(['pin_required' => true, 'pin' => '1234']);
        $request = Request::create(
            "/callbacks/voice/conference-pin?room_id={$room->id}&attempt=1",
            'POST',
            ['_organization_id' => Organization::factory()->create()->id, 'Digits' => '1234']
        );

        $xml = app(ConferenceRoutingStrategy::class)->handlePinCallback($request)->getContent();

        $this->assertStringNotContainsString('<Conference', $xml);
    }
}
