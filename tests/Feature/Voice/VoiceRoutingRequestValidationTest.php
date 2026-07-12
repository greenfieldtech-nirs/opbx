<?php

declare(strict_types=1);

namespace Tests\Feature\Voice;

use App\Http\Requests\Voice\VoiceRoutingRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

final class VoiceRoutingRequestValidationTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function baseData(array $overrides = []): array
    {
        return array_merge([
            'CallSid' => 'd5de2068f2d94fd586b08c2eef2adf74',
            'From' => '60000',
            'To' => '2001',
            'Domain' => 'ama-18112025-i5ixgg.cloudonix.net',
            'Direction' => 'subscriber',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validate(array $data): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($data, (new VoiceRoutingRequest())->rules());
    }

    public function test_normal_extension_destination_passes(): void
    {
        $this->assertTrue($this->validate($this->baseData(['To' => '2001']))->passes());
    }

    public function test_e164_destination_passes(): void
    {
        $this->assertTrue($this->validate($this->baseData(['To' => '+14155551234']))->passes());
    }

    public function test_spy_sentinel_passes(): void
    {
        $this->assertTrue(
            $this->validate($this->baseData(['To' => 'spy_936871ad326b4dcb976ec86e4588eda3']))->passes()
        );
    }

    public function test_barge_sentinel_passes(): void
    {
        $this->assertTrue(
            $this->validate($this->baseData(['To' => 'barge_936871ad326b4dcb976ec86e4588eda3']))->passes()
        );
    }

    public function test_whisper_sentinel_passes(): void
    {
        $this->assertTrue(
            $this->validate($this->baseData(['To' => 'whisper_callee_936871ad326b4dcb976ec86e4588eda3']))->passes()
        );
    }

    public function test_garbage_destination_still_fails(): void
    {
        // Not a phone number and not a valid sentinel (bad party token, non-hex).
        $this->assertTrue(
            $this->validate($this->baseData(['To' => 'spy_NOTHEXXX!!']))->fails()
        );
        $this->assertTrue(
            $this->validate($this->baseData(['To' => 'DROP TABLE users']))->fails()
        );
    }
}
