# Dialer Destination Metadata in CXML Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Inject per-destination metadata from `auto_dialer_destinations.metadata` into the CXML produced by the auto-dialer, shaped as XML comments (Dummy), SIP headers (SIP AI assistants), or WebSocket stream parameters (WebSocket AI assistants), while preserving metadata across AI Load Balancer follow-through failover.

**Architecture:** Add a small `MetadataHelper` to flatten JSON metadata into string key-value pairs. Extend `CxmlBuilder` with optional metadata/headers/parameters on Dummy, SIP, and WebSocket builders. Wire `AutoDialerCloudonixService` (the live production path used by the Go worker) to read the destination metadata and pass it into the CXML builders. Wire `AlbsFollowThroughController` to recover the same destination metadata from the `AutoDialerCallSession` when Cloudonix calls back for failover, then inject it into the next assistant's CXML.

**Tech Stack:** PHP 8.4, Laravel 12, `CxmlBuilder` (DOMDocument), `AutoDialerCloudonixService`, `AlbsFollowThroughController`, PHPUnit, MySQL.

---

## File Structure

| File | Responsibility |
|------|---------------|
| `app/Services/AutoDialer/MetadataHelper.php` | Flatten nested JSON metadata to dot-notation string key-value pairs; serialize booleans/numbers as strings. |
| `app/Services/CxmlBuilder/CxmlBuilder.php` | Add optional metadata to Dummy, SIP, and WebSocket static/instance builders. |
| `app/Services/AutoDialer/AutoDialerCloudonixService.php` | Read destination metadata and pass it to CXML builders for AI Assistant and AI Load Balancer routing. |
| `app/Http/Controllers/Voice/AlbsFollowThroughController.php` | Look up the call session's destination metadata on failover and pass it to the next assistant's CXML. |
| `tests/Unit/Services/AutoDialer/MetadataHelperTest.php` | Unit tests for flattening and serialization. |
| `tests/Unit/Services/CxmlBuilderTest.php` | Tests for Dummy comments and SIP headers. |
| `tests/Unit/Services/CxmlBuilder/CxmlBuilderWebSocketTest.php` | Tests for WebSocket stream parameters. |
| `tests/Feature/AutoDialer/AutoDialerCxmlMetadataTest.php` | End-to-end tests for CXML generation with metadata. |
| `tests/Feature/Voice/AlbsFollowThroughMetadataTest.php` | Tests metadata preservation across ALB follow-through. |

---

## Background for the Reviewer

- The Go worker (`dialer-worker/internal/api/client.go`) only calls `/api/v1/dialer/worker/calls/initiate`, which uses `AutoDialerCloudonixService`. The `/api/v1/dialer/worker/calls/generate-cxml` endpoint is registered but **not currently used by the worker**; this plan therefore updates only the live initiate path.
- `auto_dialer_destinations.metadata` is a JSON column cast to `array`.
- Metadata must be flattened; nested objects become `parent.child` keys.
- SIP headers are prefixed with `X-` unless the key already starts with `X-`.
- WebSocket parameters use keys as-is.
- If metadata is empty/null, no comments/headers/parameters are emitted.

---

## Task 1: Create MetadataHelper

**Files:**
- Create: `app/Services/AutoDialer/MetadataHelper.php`

- [ ] **Step 1: Create the helper class**

```php
<?php

declare(strict_types=1);

namespace App\Services\AutoDialer;

final class MetadataHelper
{
    public static function flatten(array $metadata): array
    {
        $result = [];
        self::flattenRecursive($metadata, '', $result);

        return $result;
    }

    private static function flattenRecursive(array $data, string $prefix, array &$result): void
    {
        foreach ($data as $key => $value) {
            $fullKey = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                self::flattenRecursive($value, $fullKey, $result);
            } else {
                $result[$fullKey] = self::scalarToString($value);
            }
        }
    }

    private static function scalarToString(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_null($value)) {
            return '';
        }

        return (string) $value;
    }
}
```

- [ ] **Step 2: Add unit test for flattening**

Create `tests/Unit/Services/AutoDialer/MetadataHelperTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AutoDialer;

use App\Services\AutoDialer\MetadataHelper;
use PHPUnit\Framework\TestCase;

class MetadataHelperTest extends TestCase
{
    public function test_flattens_nested_metadata(): void
    {
        $metadata = [
            'key' => 'value',
            'nested' => [
                'child' => 'childValue',
            ],
        ];

        $result = MetadataHelper::flatten($metadata);

        $this->assertSame([
            'key' => 'value',
            'nested.child' => 'childValue',
        ], $result);
    }

    public function test_serializes_booleans_and_numbers(): void
    {
        $result = MetadataHelper::flatten([
            'flag' => true,
            'count' => 42,
            'price' => 19.99,
            'empty' => false,
        ]);

        $this->assertSame([
            'flag' => 'true',
            'count' => '42',
            'price' => '19.99',
            'empty' => 'false',
        ], $result);
    }

    public function test_returns_empty_array_for_empty_input(): void
    {
        $this->assertSame([], MetadataHelper::flatten([]));
        $this->assertSame([], MetadataHelper::flatten(null ?? []));
    }
}
```

- [ ] **Step 3: Run the new tests**

Run: `./run-tests.sh --filter=MetadataHelperTest`
Expected: 3 tests pass.

- [ ] **Step 4: Commit**

```bash
git add app/Services/AutoDialer/MetadataHelper.php tests/Unit/Services/AutoDialer/MetadataHelperTest.php
git commit -m "feat(auto-dialer): add MetadataHelper to flatten destination metadata"
```

---

## Task 2: Extend CxmlBuilder for Dummy Metadata Comments

**Files:**
- Modify: `app/Services/CxmlBuilder/CxmlBuilder.php`

- [ ] **Step 1: Update `dummyAiMessage` and add comment helpers**

Replace the existing `dummyAiMessage()` method with:

```php
    /**
     * Build a dummy AI assistant response with a fixed message and optional metadata comments.
     *
     * @param  array<string, string>  $metadata  Flattened key-value metadata
     */
    public static function dummyAiMessage(array $metadata = []): string
    {
        $builder = new self;

        foreach ($metadata as $key => $value) {
            $builder->addMetadataComment($key, $value);
        }

        $builder->say('Hi There, this is not an AI assistant, this is just a small dummy audio message, that will ensure that your routing setup is functional and working. I know you expected more at this point, but it really doesn\'t get any better than this right now. Thank you for using Cloudonix and O.P.B.X - don\'t forget to visit cloudonix-dot-com for the most up to date information about cloudonix services.')
            ->hangup();

        return $builder->build();
    }

    /**
     * Add a metadata comment to the response.
     */
    private function addMetadataComment(string $key, string $value): void
    {
        $safeKey = $this->escapeComment($key);
        $safeValue = $this->escapeComment($value);

        $comment = $this->document->createComment(
            sprintf(' metadata key="%s" value="%s" ', $safeKey, $safeValue)
        );

        $this->response->appendChild($comment);
    }

    /**
     * Sanitize a string so it can safely live inside an XML comment.
     */
    private function escapeComment(string $value): string
    {
        // XML comments may not contain the sequence "--".
        return str_replace(['--', '>'], ['- -', ' '], $value);
    }
```

- [ ] **Step 2: Add unit test for dummy metadata comments**

Add to `tests/Unit/Services/CxmlBuilderTest.php`:

```php
    public function test_dummy_ai_message_includes_metadata_comments(): void
    {
        $cxml = CxmlBuilder::dummyAiMessage([
            'key' => 'value',
            'key2' => 'value2',
        ]);

        $this->assertStringContainsString('<!-- metadata key="key" value="value" -->', $cxml);
        $this->assertStringContainsString('<!-- metadata key="key2" value="value2" -->', $cxml);
    }

    public function test_dummy_ai_message_omits_comments_when_metadata_empty(): void
    {
        $cxml = CxmlBuilder::dummyAiMessage([]);

        $this->assertStringNotContainsString('<!-- metadata', $cxml);
    }
```

- [ ] **Step 3: Run the CxmlBuilder tests**

Run: `./run-tests.sh --filter=CxmlBuilderTest`
Expected: all tests pass, including the new ones.

- [ ] **Step 4: Commit**

```bash
git add app/Services/CxmlBuilder/CxmlBuilder.php tests/Unit/Services/CxmlBuilderTest.php
git commit -m "feat(cxml): add metadata comments to dummy AI assistant response"
```

---

## Task 3: Extend CxmlBuilder for SIP Headers

**Files:**
- Modify: `app/Services/CxmlBuilder/CxmlBuilder.php`

- [ ] **Step 1: Update `addDialService` to accept headers**

Replace `addDialService` with:

```php
    /**
     * Add Service noun to Dial verb for service provider forwarding.
     *
     * @param  string  $serviceUrl  The service provider URL
     * @param  string|null  $serviceToken  Optional service authentication token
     * @param  array<string, mixed>  $params  Additional service parameters
     * @param  array<string, string>  $headers  SIP headers to include in the Dial verb
     */
    public function addDialService(string $serviceUrl, ?string $serviceToken = null, array $params = [], array $headers = []): self
    {
        $dial = $this->document->createElement('Dial');

        foreach ($headers as $headerName => $headerValue) {
            $header = $this->document->createElement('Header');
            $header->setAttribute('name', (string) $headerName);
            $header->setAttribute('value', (string) $headerValue);
            $dial->appendChild($header);
        }

        $service = $this->document->createElement('Service', htmlspecialchars($serviceUrl, self::XML_ENCODING, 'UTF-8'));

        if ($serviceToken !== null) {
            $service->setAttribute('token', $serviceToken);
        }

        // Add any additional parameters
        foreach ($params as $key => $value) {
            if ($value !== null) {
                $service->setAttribute($key, (string) $value);
            }
        }

        $dial->appendChild($service);
        $this->response->appendChild($dial);

        return $this;
    }
```

- [ ] **Step 2: Update static `dialService` factory**

Replace `dialService` with:

```php
    /**
     * Build service provider dialing response.
     *
     * @param  string  $serviceUrl  The service provider URL
     * @param  string|null  $serviceToken  Optional service authentication token
     * @param  array<string, mixed>  $params  Additional service parameters
     * @param  array<string, string>  $headers  SIP headers to include in the Dial verb
     */
    public static function dialService(string $serviceUrl, ?string $serviceToken = null, array $params = [], array $headers = []): string
    {
        $builder = new self;
        $builder->addDialService($serviceUrl, $serviceToken, $params, $headers);

        return $builder->build();
    }
```

- [ ] **Step 3: Update `dialServiceProvider` for provider + phone headers**

Replace `dialServiceProvider` with:

```php
    /**
     * Build service provider dialing response with provider and phone number.
     *
     * Used for Cloudonix Service providers like Retell, VAPI, etc.
     *
     * @param  string  $provider  The service provider name (e.g., 'retell', 'vapi')
     * @param  string  $phoneNumber  The service provider phone number
     * @param  array<string, string>  $headers  SIP headers to include in the Dial verb
     */
    public static function dialServiceProvider(string $provider, string $phoneNumber, array $headers = []): string
    {
        $builder = new self;
        $dial = $builder->document->createElement('Dial');

        foreach ($headers as $headerName => $headerValue) {
            $header = $builder->document->createElement('Header');
            $header->setAttribute('name', (string) $headerName);
            $header->setAttribute('value', (string) $headerValue);
            $dial->appendChild($header);
        }

        $service = $builder->document->createElement('Service', htmlspecialchars($phoneNumber, self::XML_ENCODING, 'UTF-8'));
        $service->setAttribute('provider', $provider);
        $dial->appendChild($service);
        $builder->response->appendChild($dial);

        return $builder->build();
    }
```

- [ ] **Step 4: Update `dialServiceProviderWithAction` for headers with action callback**

Replace `dialServiceProviderWithAction` with:

```php
    /**
     * Build service provider dialing response with action callback for follow-through.
     *
     * Used when we need Cloudonix to callback after the dial completes
     * (busy, no-answer, failed, etc.) so we can try the next assistant.
     *
     * @param  string  $provider  The service provider name (e.g., 'retell', 'vapi')
     * @param  string  $phoneNumber  The service provider phone number
     * @param  string  $actionUrl  Callback URL when dial completes
     * @param  array<string, string>  $headers  SIP headers to include in the Dial verb
     */
    public static function dialServiceProviderWithAction(string $provider, string $phoneNumber, string $actionUrl, array $headers = []): string
    {
        $builder = new self;
        $dial = $builder->document->createElement('Dial');

        // Add action attribute for callback
        $dial->setAttribute('action', $actionUrl);

        foreach ($headers as $headerName => $headerValue) {
            $header = $builder->document->createElement('Header');
            $header->setAttribute('name', (string) $headerName);
            $header->setAttribute('value', (string) $headerValue);
            $dial->appendChild($header);
        }

        $service = $builder->document->createElement('Service', htmlspecialchars($phoneNumber, self::XML_ENCODING, 'UTF-8'));
        $service->setAttribute('provider', $provider);
        $dial->appendChild($service);
        $builder->response->appendChild($dial);

        return $builder->build();
    }
```

- [ ] **Step 5: Add unit tests for SIP headers**

Add to `tests/Unit/Services/CxmlBuilderTest.php`:

```php
    public function test_dial_service_provider_includes_sip_headers(): void
    {
        $cxml = CxmlBuilder::dialServiceProvider('retell', '+12127773456', [
            'X-key' => 'value',
            'X-key2' => 'value2',
        ]);

        $this->assertStringContainsString('<Header name="X-key" value="value" />', $cxml);
        $this->assertStringContainsString('<Header name="X-key2" value="value2" />', $cxml);
        $this->assertStringContainsString('<Service provider="retell">+12127773456</Service>', $cxml);
    }

    public function test_dial_service_provider_with_action_includes_sip_headers(): void
    {
        $cxml = CxmlBuilder::dialServiceProviderWithAction('retell', '+12127773456', 'https://example.com/callback', [
            'X-key' => 'value',
        ]);

        $this->assertStringContainsString('action="https://example.com/callback"', $cxml);
        $this->assertStringContainsString('<Header name="X-key" value="value" />', $cxml);
    }

    public function test_dial_service_includes_sip_headers(): void
    {
        $cxml = CxmlBuilder::dialService('https://example.com/ai', 'token', [], ['X-key' => 'value']);

        $this->assertStringContainsString('<Header name="X-key" value="value" />', $cxml);
        $this->assertStringContainsString('<Service token="token">https://example.com/ai</Service>', $cxml);
    }

    public function test_sip_headers_omitted_when_empty(): void
    {
        $cxml = CxmlBuilder::dialServiceProvider('retell', '+12127773456', []);

        $this->assertStringNotContainsString('<Header', $cxml);
    }
```

- [ ] **Step 6: Run the CxmlBuilder tests**

Run: `./run-tests.sh --filter=CxmlBuilderTest`
Expected: all tests pass.

- [ ] **Step 7: Commit**

```bash
git add app/Services/CxmlBuilder/CxmlBuilder.php tests/Unit/Services/CxmlBuilderTest.php
git commit -m "feat(cxml): add SIP Header support to Dial builders"
```

---

## Task 4: Extend CxmlBuilder for WebSocket Parameters

**Files:**
- Modify: `app/Services/CxmlBuilder/CxmlBuilder.php`

- [ ] **Step 1: Update `connectStream` and `connectStreamWithAction` to accept parameters**

Replace `connectStream` with:

```php
    /**
     * Add Connect verb with Stream noun for WebSocket audio streaming.
     *
     * This enables bi-directional audio streaming to WebSocket-based services.
     *
     * @param  string  $websocketUrl  WebSocket URL (must start with wss://)
     * @param  array<string, string>  $parameters  Parameters to send on the Stream
     *
     * @see https://developers.cloudonix.com/Documentation/voiceApplication/Verb/connect/stream
     */
    public function connectStream(string $websocketUrl, array $parameters = []): self
    {
        if (! str_starts_with($websocketUrl, 'wss://')) {
            throw new \InvalidArgumentException('WebSocket URL must start with wss://');
        }

        $connect = $this->document->createElement('Connect');
        $stream = $this->document->createElement('Stream');
        $stream->setAttribute('url', $websocketUrl);

        foreach ($parameters as $paramName => $paramValue) {
            $param = $this->document->createElement('Parameter');
            $param->setAttribute('name', (string) $paramName);
            $param->setAttribute('value', (string) $paramValue);
            $stream->appendChild($param);
        }

        $connect->appendChild($stream);
        $this->response->appendChild($connect);

        return $this;
    }
```

Replace `connectStreamWithAction` with:

```php
    /**
     * Add Connect verb with Stream noun for WebSocket audio streaming with action callback.
     *
     * @param  string  $websocketUrl  WebSocket URL (must start with wss://)
     * @param  string|null  $actionUrl  Callback URL when connect completes
     * @param  array<string, string>  $parameters  Parameters to send on the Stream
     *
     * @see https://developers.cloudonix.com/Documentation/voiceApplication/Verb/connect
     */
    public function connectStreamWithAction(string $websocketUrl, ?string $actionUrl = null, array $parameters = []): self
    {
        if (! str_starts_with($websocketUrl, 'wss://')) {
            throw new \InvalidArgumentException('WebSocket URL must start with wss://');
        }

        $connect = $this->document->createElement('Connect');

        if ($actionUrl !== null) {
            $connect->setAttribute('action', $actionUrl);
        }

        $stream = $this->document->createElement('Stream');
        $stream->setAttribute('url', $websocketUrl);

        foreach ($parameters as $paramName => $paramValue) {
            $param = $this->document->createElement('Parameter');
            $param->setAttribute('name', (string) $paramName);
            $param->setAttribute('value', (string) $paramValue);
            $stream->appendChild($param);
        }

        $connect->appendChild($stream);
        $this->response->appendChild($connect);

        return $this;
    }
```

- [ ] **Step 2: Update static WebSocket factory methods**

Replace `streamToWebSocket` and `streamToWebSocketWithAction` with:

```php
    /**
     * Build a Connect Stream response for WebSocket audio streaming.
     *
     * @param  string  $websocketUrl  WebSocket URL (must start with wss://)
     * @param  array<string, string>  $parameters  Parameters to send on the Stream
     * @return string CXML response
     */
    public static function streamToWebSocket(string $websocketUrl, array $parameters = []): string
    {
        $builder = new self;
        $builder->connectStream($websocketUrl, $parameters);

        return $builder->build();
    }

    /**
     * Build a Connect Stream response with action callback.
     *
     * @param  string  $websocketUrl  WebSocket URL (must start with wss://)
     * @param  string|null  $actionUrl  Callback URL when connect completes
     * @param  array<string, string>  $parameters  Parameters to send on the Stream
     * @return string CXML response
     */
    public static function streamToWebSocketWithAction(string $websocketUrl, ?string $actionUrl = null, array $parameters = []): string
    {
        $builder = new self;
        $builder->connectStreamWithAction($websocketUrl, $actionUrl, $parameters);

        return $builder->build();
    }
```

- [ ] **Step 3: Add WebSocket parameter tests**

Add to `tests/Unit/Services/CxmlBuilder/CxmlBuilderWebSocketTest.php`:

```php
    public function test_stream_to_websocket_includes_parameters(): void
    {
        $cxml = CxmlBuilder::streamToWebSocket('wss://example.com/stream', [
            'key' => 'value',
            'key2' => 'value2',
        ]);

        $this->assertStringContainsString('<Parameter name="key" value="value" />', $cxml);
        $this->assertStringContainsString('<Parameter name="key2" value="value2" />', $cxml);
    }

    public function test_stream_to_websocket_with_action_includes_parameters(): void
    {
        $cxml = CxmlBuilder::streamToWebSocketWithAction(
            'wss://example.com/stream',
            'https://example.com/callback',
            ['key' => 'value']
        );

        $this->assertStringContainsString('action="https://example.com/callback"', $cxml);
        $this->assertStringContainsString('<Parameter name="key" value="value" />', $cxml);
    }

    public function test_stream_parameters_omitted_when_empty(): void
    {
        $cxml = CxmlBuilder::streamToWebSocket('wss://example.com/stream', []);

        $this->assertStringNotContainsString('<Parameter', $cxml);
    }
```

- [ ] **Step 4: Run the WebSocket tests**

Run: `./run-tests.sh --filter=CxmlBuilderWebSocketTest`
Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add app/Services/CxmlBuilder/CxmlBuilder.php tests/Unit/Services/CxmlBuilder/CxmlBuilderWebSocketTest.php
git commit -m "feat(cxml): add Stream Parameter support for WebSocket AI assistants"
```

---

## Task 5: Wire Metadata into AutoDialerCloudonixService

**Files:**
- Modify: `app/Services/AutoDialer/AutoDialerCloudonixService.php`

- [ ] **Step 1: Add the MetadataHelper import**

Add to the existing imports:

```php
use App\Services\AutoDialer\MetadataHelper;
```

- [ ] **Step 2: Pass destination to AI Assistant CXML generation**

In `generateCxmlForCampaign`, change the two case blocks:

```php
            switch ($routingType) {
                case 'ai_assistant':
                    $innerCxml = $this->generateAiAssistantCxml($campaign, $destination, $cloudonixParams);
                    break;

                case 'ai_load_balancer':
                    $innerCxml = $this->generateAiLoadBalancerCxml($campaign, $destination, $cloudonixParams);
                    break;
```

- [ ] **Step 3: Update `generateAiAssistantCxml` signature and metadata wiring**

Replace the method with:

```php
    private function generateAiAssistantCxml(AutoDialerCampaign $campaign, AutoDialerDestination $destination, array $cloudonixParams): string
    {
        $aiAssistant = AiAssistant::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $campaign->routing_destination_id)
            ->where('organization_id', $campaign->organization_id)
            ->first();

        if (! $aiAssistant) {
            Log::error('AutoDialer: AI Assistant not found for campaign', [
                'campaign_id' => $campaign->id,
                'ai_assistant_id' => $campaign->routing_destination_id,
            ]);

            return $this->buildHangupCxml();
        }

        $config = $aiAssistant->configuration ?? [];
        $protocol = $aiAssistant->protocol;
        $provider = $aiAssistant->provider;
        $metadata = MetadataHelper::flatten($destination->metadata ?? []);

        if ($protocol === 'websocket') {
            return $this->generateWebSocketCxml($aiAssistant, $config, $provider, $cloudonixParams, $metadata);
        }

        if ($protocol === 'dummy') {
            return $this->generateDummyCxml($aiAssistant, $metadata);
        }

        return $this->generateSipCxml($aiAssistant, $config, $provider, $metadata);
    }
```

- [ ] **Step 4: Update `generateWebSocketCxml` to accept metadata**

Replace the method signature and the return statement:

```php
    private function generateWebSocketCxml(
        AiAssistant $aiAssistant,
        array $config,
        ?string $provider,
        array $cloudonixParams,
        array $metadata = []
    ): string {
        ...
        // Generate CXML with Connect>Stream verb
        return CxmlBuilder::streamToWebSocket($websocketUrl, $metadata);
    }
```

- [ ] **Step 5: Update `generateDummyCxml` to accept metadata**

Replace with:

```php
    private function generateDummyCxml(AiAssistant $aiAssistant, array $metadata = []): string
    {
        Log::debug('AutoDialer: Using dummy AI provider', [
            'ai_assistant_id' => $aiAssistant->id,
        ]);

        return CxmlBuilder::dummyAiMessage($metadata);
    }
```

- [ ] **Step 6: Update `generateSipCxml` to accept metadata and add headers**

Replace with:

```php
    private function generateSipCxml(
        AiAssistant $aiAssistant,
        array $config,
        ?string $provider,
        array $metadata = []
    ): string {
        $headers = $this->buildSipHeaders($metadata);

        // Check if AI Assistant has service URL (preferred for generic service URLs)
        $extension = Extension::withoutGlobalScope(OrganizationScope::class)
            ->where('ai_assistant_id', $aiAssistant->id)
            ->where('organization_id', $aiAssistant->organization_id)
            ->first();

        if ($extension && $extension->service_url) {
            Log::debug('AutoDialer: Using extension service URL for SIP routing', [
                'ai_assistant_id' => $aiAssistant->id,
                'extension_id' => $extension->id,
                'service_url' => $extension->service_url,
            ]);

            return CxmlBuilder::dialService(
                $extension->service_url,
                $extension->service_token,
                $extension->service_params ?? [],
                $headers
            );
        }

        // Fall back to legacy provider + phone number format
        $phoneNumber = $config['phone_number'] ?? null;

        if (! $provider || ! $phoneNumber) {
            Log::error('AutoDialer: SIP AI Assistant missing provider or phone number', [
                'ai_assistant_id' => $aiAssistant->id,
                'provider' => $provider,
                'has_phone_number' => ! empty($phoneNumber),
            ]);

            return $this->buildHangupCxml();
        }

        Log::debug('AutoDialer: Using provider format for SIP routing', [
            'ai_assistant_id' => $aiAssistant->id,
            'provider' => $provider,
            'phone_number' => $phoneNumber,
        ]);

        return CxmlBuilder::dialServiceProvider($provider, $phoneNumber, $headers);
    }
```

- [ ] **Step 7: Add the SIP header builder helper**

Add a private method to `AutoDialerCloudonixService`:

```php
    /**
     * Build SIP headers from flattened metadata, prefixing with X- when needed.
     *
     * @param  array<string, string>  $metadata
     * @return array<string, string>
     */
    private function buildSipHeaders(array $metadata): array
    {
        $headers = [];

        foreach ($metadata as $key => $value) {
            $headerName = str_starts_with($key, 'X-') ? $key : 'X-'.$key;
            $headers[$headerName] = $value;
        }

        return $headers;
    }
```

- [ ] **Step 8: Update `generateAiLoadBalancerCxml` to pass metadata**

Replace the method signature and the routing calls:

```php
    private function generateAiLoadBalancerCxml(
        AutoDialerCampaign $campaign,
        AutoDialerDestination $destination,
        array $cloudonixParams
    ): string {
        ...
        $metadata = MetadataHelper::flatten($destination->metadata ?? []);
        ...
        if ($protocol === 'websocket') {
            return $this->generateWebSocketCxmlWithAction($aiAssistant, $config, $provider, $cloudonixParams, $callbackUrl, $metadata);
        }

        if ($protocol === 'dummy') {
            return $this->generateDummyCxml($aiAssistant, $metadata);
        }

        return $this->generateSipCxmlWithAction($aiAssistant, $config, $provider, $callbackUrl, $metadata);
    }
```

- [ ] **Step 9: Update `generateWebSocketCxmlWithAction` and `generateSipCxmlWithAction` to accept metadata**

Replace signatures and CXML builder calls:

```php
    private function generateWebSocketCxmlWithAction(
        AiAssistant $aiAssistant,
        array $config,
        ?string $provider,
        array $cloudonixParams,
        string $callbackUrl,
        array $metadata = []
    ): string {
        ...
        return CxmlBuilder::streamToWebSocketWithAction($websocketUrl, $callbackUrl, $metadata);
    }
```

```php
    private function generateSipCxmlWithAction(
        AiAssistant $aiAssistant,
        array $config,
        ?string $provider,
        string $callbackUrl,
        array $metadata = []
    ): string {
        ...
        $headers = $this->buildSipHeaders($metadata);
        return CxmlBuilder::dialServiceProviderWithAction($provider, $phoneNumber, $callbackUrl, $headers);
    }
```

- [ ] **Step 10: Run the AutoDialerCloudonixService tests**

Run: `./run-tests.sh --filter=AutoDialerCloudonixServiceTest`
Expected: all existing tests still pass.

- [ ] **Step 11: Commit**

```bash
git add app/Services/AutoDialer/AutoDialerCloudonixService.php
git commit -m "feat(auto-dialer): inject destination metadata into AI assistant CXML"
```

---

## Task 6: Add Feature Tests for AutoDialer CXML Metadata

**Files:**
- Create: `tests/Feature/AutoDialer/AutoDialerCxmlMetadataTest.php`

- [ ] **Step 1: Create the feature test file**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\AutoDialer;

use App\Enums\RoutingDestinationType;
use App\Models\AiAssistant;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\CloudonixSettings;
use App\Models\Organization;
use App\Models\OutboundWhitelist;
use App\Services\AutoDialer\AutoDialerCloudonixService;
use App\Services\PhoneNumberService;
use App\Services\VoiceRouting\OutboundRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AutoDialerCxmlMetadataTest extends TestCase
{
    use RefreshDatabase;

    private AutoDialerCloudonixService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AutoDialerCloudonixService(
            Mockery::mock(OutboundRoutingService::class),
            Mockery::mock(PhoneNumberService::class)
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_sip_ai_assistant_cxml_includes_metadata_headers(): void
    {
        $organization = Organization::factory()->create();
        $aiAssistant = AiAssistant::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'retell',
            'protocol' => 'sip',
            'configuration' => ['phone_number' => '+12127773456'],
        ]);
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $organization->id,
            'routing_destination_type' => RoutingDestinationType::AI_ASSISTANT,
            'routing_destination_id' => $aiAssistant->id,
        ]);
        $destination = AutoDialerDestination::factory()->create([
            'organization_id' => $organization->id,
            'metadata' => ['key' => 'value', 'key2' => 'value2'],
        ]);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('generateCxmlForCampaign');
        $method->setAccessible(true);

        $cxml = $method->invoke($this->service, $campaign, $destination);

        $this->assertStringContainsString('<Header name="X-key" value="value" />', $cxml);
        $this->assertStringContainsString('<Header name="X-key2" value="value2" />', $cxml);
        $this->assertStringContainsString('<Service provider="retell">+12127773456</Service>', $cxml);
    }

    public function test_websocket_ai_assistant_cxml_includes_metadata_parameters(): void
    {
        $organization = Organization::factory()->create();
        $aiAssistant = AiAssistant::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'deepdub',
            'protocol' => 'websocket',
            'configuration' => [
                'bot_id' => 'bot123',
                'auth_token' => 'token456',
            ],
        ]);
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $organization->id,
            'routing_destination_type' => RoutingDestinationType::AI_ASSISTANT,
            'routing_destination_id' => $aiAssistant->id,
        ]);
        $destination = AutoDialerDestination::factory()->create([
            'organization_id' => $organization->id,
            'metadata' => ['key' => 'value', 'key2' => 'value2'],
        ]);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('generateCxmlForCampaign');
        $method->setAccessible(true);

        $cxml = $method->invoke($this->service, $campaign, $destination);

        $this->assertStringContainsString('<Parameter name="key" value="value" />', $cxml);
        $this->assertStringContainsString('<Parameter name="key2" value="value2" />', $cxml);
    }

    public function test_dummy_ai_assistant_cxml_includes_metadata_comments(): void
    {
        $organization = Organization::factory()->create();
        $aiAssistant = AiAssistant::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'dummy_ai',
            'protocol' => 'dummy',
            'configuration' => [],
        ]);
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $organization->id,
            'routing_destination_type' => RoutingDestinationType::AI_ASSISTANT,
            'routing_destination_id' => $aiAssistant->id,
        ]);
        $destination = AutoDialerDestination::factory()->create([
            'organization_id' => $organization->id,
            'metadata' => ['key' => 'value', 'key2' => 'value2'],
        ]);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('generateCxmlForCampaign');
        $method->setAccessible(true);

        $cxml = $method->invoke($this->service, $campaign, $destination);

        $this->assertStringContainsString('<!-- metadata key="key" value="value" -->', $cxml);
        $this->assertStringContainsString('<!-- metadata key="key2" value="value2" -->', $cxml);
    }

    public function test_ai_load_balancer_cxml_includes_metadata_for_selected_assistant(): void
    {
        $organization = Organization::factory()->create();
        $aiAssistant = AiAssistant::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'retell',
            'protocol' => 'sip',
            'configuration' => ['phone_number' => '+12127773456'],
        ]);
        $loadBalancer = \App\Models\AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $organization->id,
            'strategy' => 'priority',
            'follow_through' => false,
        ]);
        \App\Models\AiAssistantLoadBalancerMember::factory()->create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant->id,
            'priority' => 1,
            'status' => 'active',
        ]);
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $organization->id,
            'routing_destination_type' => RoutingDestinationType::AI_LOAD_BALANCER,
            'routing_destination_id' => $loadBalancer->id,
        ]);
        $destination = AutoDialerDestination::factory()->create([
            'organization_id' => $organization->id,
            'metadata' => ['key' => 'value'],
        ]);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('generateCxmlForCampaign');
        $method->setAccessible(true);

        $cxml = $method->invoke($this->service, $campaign, $destination);

        $this->assertStringContainsString('<Header name="X-key" value="value" />', $cxml);
    }

    public function test_empty_metadata_omits_comments_headers_parameters(): void
    {
        $organization = Organization::factory()->create();
        $aiAssistant = AiAssistant::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'retell',
            'protocol' => 'sip',
            'configuration' => ['phone_number' => '+12127773456'],
        ]);
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $organization->id,
            'routing_destination_type' => RoutingDestinationType::AI_ASSISTANT,
            'routing_destination_id' => $aiAssistant->id,
        ]);
        $destination = AutoDialerDestination::factory()->create([
            'organization_id' => $organization->id,
            'metadata' => [],
        ]);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('generateCxmlForCampaign');
        $method->setAccessible(true);

        $cxml = $method->invoke($this->service, $campaign, $destination);

        $this->assertStringNotContainsString('<Header', $cxml);
    }
}
```

- [ ] **Step 2: Run the new feature tests**

Run: `./run-tests.sh --filter=AutoDialerCxmlMetadataTest`
Expected: 5 tests pass.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/AutoDialer/AutoDialerCxmlMetadataTest.php
git commit -m "test(auto-dialer): add CXML metadata feature tests"
```

---

## Task 7: Wire Metadata into ALB Follow-Through Controller

**Files:**
- Modify: `app/Http/Controllers/Voice/AlbsFollowThroughController.php`

- [ ] **Step 1: Add imports**

Add to the imports:

```php
use App\Models\AutoDialerCallSession;
use App\Services\AutoDialer\MetadataHelper;
```

- [ ] **Step 2: Load metadata in `handle` and pass it down**

After parsing `$callSid`, add:

```php
        $metadata = $this->getMetadataFromCallSid($callSid);

        Log::info('ALBS Follow Through: Loaded destination metadata', [
            'request_id' => $requestId,
            'call_sid' => $callSid,
            'metadata' => $metadata,
        ]);
```

Then change the route and fallback calls:

```php
        return $this->routeToAssistant($nextAssistant, $albs, $request, $metadata);
```

and

```php
            return $this->executeFallback($albs, $request, $metadata);
```

- [ ] **Step 3: Add metadata lookup helper**

Add a private method:

```php
    /**
     * Load flattened metadata from the original auto-dialer destination.
     */
    private function getMetadataFromCallSid(?string $callSid): array
    {
        if (! $callSid) {
            return [];
        }

        $session = AutoDialerCallSession::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('call_id', $callSid)
            ->orWhere('session_token', $callSid)
            ->with('destination')
            ->first();

        if (! $session || ! $session->destination) {
            return [];
        }

        return MetadataHelper::flatten($session->destination->metadata ?? []);
    }
```

- [ ] **Step 4: Update `routeToAssistant` and its callees**

Change signature:

```php
    private function routeToAssistant(AiAssistant $aiAssistant, AiAssistantLoadBalancer $albs, Request $request, array $metadata = []): Response
```

Change the three routing calls to pass `$metadata`:

```php
        if ($protocol === 'websocket') {
            return $this->routeWebSocket($aiAssistant, $config, $provider, $callbackUrl, $request, $metadata);
        }

        if ($protocol === 'dummy') {
            return $this->routeDummy($aiAssistant, $metadata);
        }

        return $this->routeSip($aiAssistant, $config, $provider, $callbackUrl, $request, $metadata);
```

- [ ] **Step 5: Update `routeWebSocket`, `routeDummy`, and `routeSip`**

`routeWebSocket`:

```php
    private function routeWebSocket(AiAssistant $aiAssistant, array $config, ?string $provider, string $callbackUrl, Request $request, array $metadata = []): Response
    {
        ...
        $builder = CxmlBuilder::streamToWebSocketWithAction($websocketUrl, $callbackUrl, $metadata);
        ...
    }
```

`routeDummy`:

```php
    private function routeDummy(AiAssistant $aiAssistant, array $metadata = []): Response
    {
        ...
        return response(CxmlBuilder::dummyAiMessage($metadata), ...);
    }
```

`routeSip`:

```php
    private function routeSip(AiAssistant $aiAssistant, array $config, ?string $provider, string $callbackUrl, Request $request, array $metadata = []): Response
    {
        ...
        $headers = $this->buildSipHeaders($metadata);
        $builder = CxmlBuilder::dialServiceProviderWithAction($provider, $phoneNumber, $callbackUrl, $headers);
        ...
    }
```

- [ ] **Step 6: Add the SIP header builder helper**

```php
    /**
     * Build SIP headers from flattened metadata, prefixing with X- when needed.
     *
     * @param  array<string, string>  $metadata
     * @return array<string, string>
     */
    private function buildSipHeaders(array $metadata): array
    {
        $headers = [];

        foreach ($metadata as $key => $value) {
            $headerName = str_starts_with($key, 'X-') ? $key : 'X-'.$key;
            $headers[$headerName] = $value;
        }

        return $headers;
    }
```

- [ ] **Step 7: Update `executeFallback` and `routeToAiAssistant` to pass metadata**

```php
    private function executeFallback(AiAssistantLoadBalancer $albs, Request $request, array $metadata = []): Response
    {
        ...
        return match ($fallbackAction) {
            ...
            RingGroupFallbackAction::AI_ASSISTANT => $this->routeToAiAssistant($albs, $request, $metadata),
            ...
        };
    }
```

```php
    private function routeToAiAssistant(AiAssistantLoadBalancer $albs, Request $request, array $metadata = []): Response
    {
        ...
        return $this->routeToAssistantDirect($aiAssistant, $request, $metadata);
    }
```

- [ ] **Step 8: Update `routeToAssistantDirect` for fallback AI assistant**

```php
    private function routeToAssistantDirect(AiAssistant $aiAssistant, Request $request, array $metadata = []): Response
    {
        ...
        if ($protocol === 'websocket') {
            ...
            return response(CxmlBuilder::streamToWebSocket($websocketUrl, $metadata), ...);
        }

        if ($protocol === 'dummy') {
            return $this->routeDummy($aiAssistant, $metadata);
        }

        ...
        $headers = $this->buildSipHeaders($metadata);
        return response(CxmlBuilder::dialServiceProvider($provider, $phoneNumber, $headers), ...);
    }
```

- [ ] **Step 9: Run any existing ALB tests**

Run: `./run-tests.sh --filter=Albs`
Expected: any existing ALB tests still pass.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/Voice/AlbsFollowThroughController.php
git commit -m "feat(albs): preserve destination metadata across follow-through failover"
```

---

## Task 8: Add Follow-Through Metadata Preservation Tests

**Files:**
- Create: `tests/Feature/Voice/AlbsFollowThroughMetadataTest.php`

- [ ] **Step 1: Create the test file**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Voice;

use App\Enums\AiAssistantStatus;
use App\Enums\AlbsStatus;
use App\Models\AiAssistant;
use App\Models\AiAssistantLoadBalancer;
use App\Models\AiAssistantLoadBalancerMember;
use App\Models\AutoDialerCallSession;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\CloudonixSettings;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AlbsFollowThroughMetadataTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private CloudonixSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->organization = Organization::factory()->create();
        $this->settings = CloudonixSettings::factory()->create([
            'organization_id' => $this->organization->id,
            'webhook_base_url' => 'https://example.com',
        ]);
    }

    public function test_follow_through_includes_metadata_for_next_assistant(): void
    {
        $failedAssistant = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'retell',
            'protocol' => 'sip',
            'configuration' => ['phone_number' => '+12127773456'],
            'status' => AiAssistantStatus::ACTIVE,
        ]);
        $nextAssistant = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'vapi',
            'protocol' => 'sip',
            'configuration' => ['phone_number' => '+12127773457'],
            'status' => AiAssistantStatus::ACTIVE,
        ]);
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'strategy' => 'priority',
            'follow_through' => true,
        ]);
        AiAssistantLoadBalancerMember::factory()->create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $failedAssistant->id,
            'priority' => 1,
            'status' => 'active',
        ]);
        AiAssistantLoadBalancerMember::factory()->create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $nextAssistant->id,
            'priority' => 2,
            'status' => 'active',
        ]);

        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        $destination = AutoDialerDestination::factory()->create([
            'organization_id' => $this->organization->id,
            'metadata' => ['key' => 'value'],
        ]);
        $session = AutoDialerCallSession::factory()->create([
            'organization_id' => $this->organization->id,
            'campaign_id' => $campaign->id,
            'destination_id' => $destination->id,
            'call_id' => 'CA123',
        ]);

        $response = $this->postJson(route('voice.albs-follow-through', [
            'albs_id' => $loadBalancer->id,
            'current_assistant_id' => $failedAssistant->id,
        ]), [
            'CallSid' => 'CA123',
            'DialCallStatus' => 'busy',
        ], [
            'X-Cx-Session' => 'CA123',
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml');
        $cxml = $response->getContent();
        $this->assertStringContainsString('<Header name="X-key" value="value" />', $cxml);
    }

    public function test_follow_through_omits_metadata_when_session_missing(): void
    {
        $aiAssistant = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'retell',
            'protocol' => 'sip',
            'configuration' => ['phone_number' => '+12127773456'],
            'status' => AiAssistantStatus::ACTIVE,
        ]);
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'strategy' => 'priority',
            'follow_through' => true,
        ]);
        AiAssistantLoadBalancerMember::factory()->create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant->id,
            'priority' => 1,
            'status' => 'active',
        ]);

        $response = $this->postJson(route('voice.albs-follow-through', [
            'albs_id' => $loadBalancer->id,
            'current_assistant_id' => $aiAssistant->id,
        ]), [
            'CallSid' => 'UNKNOWN',
            'DialCallStatus' => 'busy',
        ], [
            'X-Cx-Session' => 'UNKNOWN',
        ]);

        $response->assertStatus(200);
        $cxml = $response->getContent();
        $this->assertStringNotContainsString('<Header name="X-', $cxml);
    }
}
```

- [ ] **Step 2: Run the new ALB follow-through tests**

Run: `./run-tests.sh --filter=AlbsFollowThroughMetadataTest`
Expected: 2 tests pass.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Voice/AlbsFollowThroughMetadataTest.php
git commit -m "test(albs): add follow-through metadata preservation tests"
```

---

## Task 9: Full Regression Test

**Files:**
- (none)

- [ ] **Step 1: Run the full PHPUnit suite**

Run: `./run-tests.sh`
Expected: all tests pass, no new failures or errors.

- [ ] **Step 2: Run frontend type-check (if any CXML-related UI changed)**

Run: `cd frontend && npm run type-check`
Expected: passes (no frontend files are changed in this plan, but verify).

- [ ] **Step 3: Commit any final fixes**

If any tests failed, fix them and commit:

```bash
git add ...
git commit -m "fix(auto-dialer): resolve test failures from metadata injection"
```

---

## Task 10: Update Memory Documentation

**Files:**
- Modify: `.my_agent/memory/auto-dialer-campaigns.md`
- Modify: `.my_agent/memory/ai-load-balancers.md`

- [ ] **Step 1: Add CXML metadata notes to auto-dialer memory**

Append a new section to `.my_agent/memory/auto-dialer-campaigns.md`:

```markdown
## Destination Metadata in CXML (2026-07-05)

- `auto_dialer_destinations.metadata` is flattened and injected into outbound CXML.
- Dummy assistants: metadata appears as XML comments (`<!-- metadata key="..." value="..." -->`).
- SIP assistants: metadata appears as `<Header name="X-key" value="..." />` inside `<Dial>`.
- WebSocket assistants: metadata appears as `<Parameter name="key" value="..." />` inside `<Stream>`.
- Metadata is preserved across AI Load Balancer follow-through failover by looking up the `AutoDialerCallSession` from the `CallSid`.
- Empty metadata is omitted entirely.
- Implementation files: `AutoDialerCloudonixService`, `AlbsFollowThroughController`, `CxmlBuilder`, `MetadataHelper`.
```

- [ ] **Step 2: Add follow-through note to AI load balancer memory**

Append a note to `.my_agent/memory/ai-load-balancers.md`:

```markdown
## Follow-Through Metadata (2026-07-05)

When `AlbsFollowThroughController` routes a failed call to the next member, it loads the original `AutoDialerCallSession` by `call_id`/`session_token` and includes the destination's flattened metadata in the next assistant's CXML using the same Dummy/SIP/WebSocket rules as the initial dialer CXML.
```

- [ ] **Step 3: Commit**

```bash
git add .my_agent/memory/auto-dialer-campaigns.md .my_agent/memory/ai-load-balancers.md
git commit -m "docs(memory): document dialer CXML metadata injection"
```

---

## Self-Review Checklist

- [ ] Spec coverage: Dummy, SIP, WebSocket metadata shapes are each implemented in `CxmlBuilder` and wired in `AutoDialerCloudonixService`.
- [ ] Follow-through preservation: `AlbsFollowThroughController` looks up the session and passes metadata to the next assistant.
- [ ] Empty metadata: all builders omit comments/headers/parameters when the array is empty.
- [ ] SIP `X-` prefix: `buildSipHeaders` prefixes only when missing.
- [ ] WebSocket keys: passed as-is.
- [ ] Flattening: nested JSON becomes dot-notation keys; booleans/numbers are strings.
- [ ] Unused path: `CxmlGenerationService` is left unchanged because the Go worker does not use `generate-cxml`.
- [ ] No placeholders: every task contains exact file paths, code, and test commands.
- [ ] Type consistency: `metadata` is always `array<string, string>` after `MetadataHelper::flatten`.

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-07-05-dialer-metadata-in-cxml.md`.

Two execution options:

1. **Subagent-Driven (recommended)** — I dispatch a fresh subagent per task, review between tasks, fast iteration.
2. **Inline Execution** — I execute tasks in this session using the executing-plans skill, batch execution with checkpoints.

Please reply with the option you prefer, or just say "start" and I'll proceed with subagent-driven execution.
