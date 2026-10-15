<?php

declare(strict_types=1);

namespace Tests\Feature\Voice;

use App\Http\Requests\Voice\IvrInputRequest;
use App\Models\DidNumber;
use App\Models\IvrMenu;
use App\Models\Organization;
use App\Services\IvrStateService;
use App\Services\VoiceRouting\Strategies\IvrRoutingStrategy;
use App\Services\VoiceRouting\VoiceRoutingManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/** A caller who presses nothing hears the menu again until Maximum Turns, then goes to the failover destination. */
final class IvrNoInputTest extends TestCase
{
    use RefreshDatabase;

    private const CALL_SID = 'CA-ivr-no-input';

    private IvrMenu $menu;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::del('ivr:call:'.self::CALL_SID);
        $org = Organization::factory()->create();
        $this->menu = IvrMenu::factory()->active()->create([
            'organization_id' => $org->id,
            'max_turns' => 3,
            'tts_text' => 'Press one for sales',
            'failover_destination_type' => 'unavailable',
        ]);
        // The menu is presented once before any input arrives.
        app(IvrRoutingStrategy::class)->route($this->request(null), new DidNumber, ['ivr_menu' => $this->menu]);
    }

    protected function tearDown(): void
    {
        Redis::del('ivr:call:'.self::CALL_SID);
        parent::tearDown();
    }

    private function request(?string $digits): Request
    {
        $data = ['CallSid' => self::CALL_SID, 'From' => '+15550001', 'To' => '+15550002', '_organization_id' => $this->menu->organization_id];
        if ($digits !== null) {
            $data['Digits'] = $digits;
        }

        return Request::create('/voice/ivr-input?menu_id='.$this->menu->id, 'POST', $data);
    }

    private function input(?string $digits): string
    {
        return (string) app(VoiceRoutingManager::class)->handleIvrInput($this->request($digits))->getContent();
    }

    private function assertReplay(string $xml): void
    {
        $this->assertStringContainsString('<Gather', $xml);
        $this->assertStringContainsString('Press one for sales', $xml);
    }

    private function assertFailover(string $xml): void
    {
        $this->assertStringContainsString("Destination doesn't exist", $xml);
        $this->assertStringNotContainsString('<Gather', $xml);
    }

    public function test_digits_are_optional_in_the_ivr_input_request(): void
    {
        $rules = (new IvrInputRequest)->rules();
        $base = ['CallSid' => 'CA1', 'From' => '+15550001', 'To' => '+15550002'];

        $this->assertTrue(Validator::make($base, $rules)->passes());
        $this->assertTrue(Validator::make($base + ['Digits' => null], $rules)->passes());
        $this->assertFalse(Validator::make($base + ['Digits' => 'abc'], $rules)->passes());
    }

    public function test_menu_gather_reports_empty_input_back(): void
    {
        $xml = (string) app(IvrRoutingStrategy::class)->route($this->request(null), new DidNumber, ['ivr_menu' => $this->menu])->getContent();

        $this->assertStringContainsString('actionOnEmptyResult="true"', $xml);
    }

    public function test_no_input_once_replays_the_menu(): void
    {
        $this->assertReplay($this->input(null));
        $this->assertSame(1, app(IvrStateService::class)->getCallState(self::CALL_SID)['turn_count']);
    }

    public function test_no_input_until_max_turns_goes_to_failover(): void
    {
        $this->assertReplay($this->input(null));
        $this->assertReplay($this->input(''));
        $this->assertFailover($this->input(null));
    }

    public function test_invalid_and_no_input_share_the_turn_count(): void
    {
        $this->assertStringContainsString('Invalid menu option', $this->input('9'));
        $this->assertReplay($this->input(null));
        $this->assertFailover($this->input('8'));
    }
}
