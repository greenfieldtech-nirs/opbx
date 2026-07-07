<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Requests\Webhook\CallInitiatedRequest;
use App\Http\Requests\Webhook\CallStatusRequest;
use App\Http\Requests\Webhook\CdrRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Test webhook payload validation rules.
 */
class WebhookValidationTest extends TestCase
{
    /**
     * Test CallInitiatedRequest validation with valid payload.
     */
    public function test_call_initiated_valid_payload(): void
    {
        $request = new CallInitiatedRequest;
        $rules = $request->rules();

        $data = [
            'call_id' => 'test-call-123',
            'from' => '+12025551234',
            'to' => '+13105559999',
            'did' => '+13105559999',
            'timestamp' => 1700000000,
            'organization_id' => 1,
            'direction' => 'inbound',
            'status' => 'ringing',
        ];

        $validator = Validator::make($data, $rules);

        $this->assertFalse($validator->fails(), 'Valid payload should pass validation');
    }

    /**
     * Test CallInitiatedRequest validation with missing required fields.
     */
    public function test_call_initiated_missing_required_fields(): void
    {
        $request = new CallInitiatedRequest;
        $rules = $request->rules();

        $data = [
            'timestamp' => 1700000000,
        ];

        $validator = Validator::make($data, $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('call_id', $validator->errors()->toArray());
        $this->assertArrayHasKey('from', $validator->errors()->toArray());
        $this->assertArrayHasKey('to', $validator->errors()->toArray());
        $this->assertArrayHasKey('did', $validator->errors()->toArray());
    }

    /**
     * Test CallInitiatedRequest validation with invalid phone numbers.
     */
    public function test_call_initiated_invalid_phone_numbers(): void
    {
        $request = new CallInitiatedRequest;
        $rules = $request->rules();

        $data = [
            'call_id' => 'test-call-123',
            'from' => 'not-a-phone',
            'to' => '0000000000',
            'did' => '+1234567890123456789', // Too long
        ];

        $validator = Validator::make($data, $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('from', $validator->errors()->toArray());
        $this->assertArrayHasKey('to', $validator->errors()->toArray());
        $this->assertArrayHasKey('did', $validator->errors()->toArray());
    }

    /**
     * Test CallInitiatedRequest validation with invalid direction.
     */
    public function test_call_initiated_invalid_direction(): void
    {
        $request = new CallInitiatedRequest;
        $rules = $request->rules();

        $data = [
            'call_id' => 'test-call-123',
            'from' => '+12025551234',
            'to' => '+13105559999',
            'did' => '+13105559999',
            'direction' => 'invalid-direction',
        ];

        $validator = Validator::make($data, $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('direction', $validator->errors()->toArray());
    }

    /**
     * Test CallStatusRequest validation with valid payload.
     */
    public function test_call_status_valid_payload(): void
    {
        $request = new CallStatusRequest;
        $rules = $request->rules();

        $data = [
            'call_id' => 'test-call-123',
            'status' => 'answered',
            'timestamp' => 1700000000,
            'duration' => 120,
            'disconnect_reason' => 'normal',
            'answer_time' => 1700000010,
        ];

        $validator = Validator::make($data, $rules);

        $this->assertFalse($validator->fails(), 'Valid payload should pass validation');
    }

    /**
     * Test CallStatusRequest validation with missing required fields.
     */
    public function test_call_status_missing_required_fields(): void
    {
        $request = new CallStatusRequest;
        $rules = $request->rules();

        $data = [
            'timestamp' => 1700000000,
        ];

        $validator = Validator::make($data, $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('call_id', $validator->errors()->toArray());
        $this->assertArrayHasKey('status', $validator->errors()->toArray());
    }

    /**
     * Test CallStatusRequest validation with invalid status.
     */
    public function test_call_status_invalid_status(): void
    {
        $request = new CallStatusRequest;
        $rules = $request->rules();

        $data = [
            'call_id' => 'test-call-123',
            'status' => 'invalid-status',
        ];

        $validator = Validator::make($data, $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('status', $validator->errors()->toArray());
    }

    /**
     * Test CallStatusRequest validation with invalid duration.
     */
    public function test_call_status_invalid_duration(): void
    {
        $request = new CallStatusRequest;
        $rules = $request->rules();

        $data = [
            'call_id' => 'test-call-123',
            'status' => 'answered',
            'duration' => 100000, // Exceeds max (24 hours)
        ];

        $validator = Validator::make($data, $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('duration', $validator->errors()->toArray());
    }

    /**
     * Test CdrRequest validation with valid payload.
     */
    public function test_cdr_valid_payload(): void
    {
        $request = new CdrRequest;
        $rules = $request->rules();

        $data = [
            'call_id' => 'test-call-123',
            'timestamp' => 1700000120,
            'from' => '+12025551234',
            'to' => '+13105559999',
            'disposition' => 'answered',
            'duration' => 120,
            'billsec' => 110,
            'session' => [
                'callStartTime' => 1700000000000,
                'callEndTime' => 1700000120000,
                'callAnswerTime' => 1700000010000,
            ],
            'recording_url' => 'https://example.com/recordings/test.mp3',
            'rated_cost' => 0.05,
        ];

        $validator = Validator::make($data, $rules);

        $this->assertFalse($validator->fails(), 'Valid payload should pass validation');
    }

    /**
     * Test CdrRequest validation with missing required fields.
     */
    public function test_cdr_missing_required_fields(): void
    {
        $request = new CdrRequest;
        $rules = $request->rules();

        $data = [
            'duration' => 120,
        ];

        $validator = Validator::make($data, $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('call_id', $validator->errors()->toArray());
        $this->assertArrayHasKey('from', $validator->errors()->toArray());
        $this->assertArrayHasKey('to', $validator->errors()->toArray());
        $this->assertArrayHasKey('timestamp', $validator->errors()->toArray());
        $this->assertArrayHasKey('disposition', $validator->errors()->toArray());
        $this->assertArrayHasKey('billsec', $validator->errors()->toArray());
    }

    /**
     * Test CdrRequest validation with invalid disposition.
     *
     * The Cloudonix CDR contract treats disposition as a free-form string
     * (answered, busy, no-answer, failed, congestion, ...), so there is no
     * fixed enum. The enforced constraint is required + max:50; an
     * over-length disposition must be rejected.
     */
    public function test_cdr_invalid_disposition(): void
    {
        $request = new CdrRequest;
        $rules = $request->rules();

        $data = [
            'call_id' => 'test-call-123',
            'timestamp' => 1700000120,
            'from' => '+12025551234',
            'to' => '+13105559999',
            'duration' => 120,
            'billsec' => 110,
            'disposition' => str_repeat('x', 51), // Exceeds max:50
        ];

        $validator = Validator::make($data, $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('disposition', $validator->errors()->toArray());
    }

    /**
     * Test CdrRequest validation with invalid recording URL.
     */
    public function test_cdr_invalid_recording_url(): void
    {
        $request = new CdrRequest;
        $rules = $request->rules();

        $data = [
            'call_id' => 'test-call-123',
            'timestamp' => 1700000120,
            'from' => '+12025551234',
            'to' => '+13105559999',
            'duration' => 120,
            'billsec' => 110,
            'disposition' => 'answered',
            'recording_url' => 'not-a-url',
        ];

        $validator = Validator::make($data, $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('recording_url', $validator->errors()->toArray());
    }

    /**
     * Test CdrRequest validation with negative cost.
     *
     * The Cloudonix CDR contract carries cost under rated_cost / approx_cost /
     * sell_cost (there is no bare "cost" field). Each must reject negatives.
     */
    public function test_cdr_negative_cost(): void
    {
        $request = new CdrRequest;
        $rules = $request->rules();

        $data = [
            'call_id' => 'test-call-123',
            'timestamp' => 1700000120,
            'from' => '+12025551234',
            'to' => '+13105559999',
            'duration' => 120,
            'billsec' => 110,
            'disposition' => 'answered',
            'rated_cost' => -0.05,
        ];

        $validator = Validator::make($data, $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('rated_cost', $validator->errors()->toArray());
    }

    /**
     * Test all webhook FormRequests authorize correctly.
     */
    public function test_webhook_requests_authorize(): void
    {
        $callInitiated = new CallInitiatedRequest;
        $callStatus = new CallStatusRequest;
        $cdr = new CdrRequest;

        $this->assertTrue($callInitiated->authorize());
        $this->assertTrue($callStatus->authorize());
        $this->assertTrue($cdr->authorize());
    }
}
