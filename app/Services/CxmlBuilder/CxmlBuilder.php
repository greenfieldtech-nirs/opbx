<?php

declare(strict_types=1);

namespace App\Services\CxmlBuilder;

use DOMDocument;
use DOMElement;

/**
 * CXML response builder for Cloudonix voice applications.
 *
 * SECURITY: All user input is encoded using htmlspecialchars with ENT_XML1 to prevent
 * XSS attacks through CXML responses. This ensures that any special characters in
 * user-controlled data (extension numbers, names, URLs) are properly escaped.
 *
 * @see https://developers.cloudonix.com/Documentation/voiceApplication
 * @see https://developers.cloudonix.com/Documentation/voiceApplication/Verb/dial
 */
class CxmlBuilder
{
    private DOMDocument $document;

    private DOMElement $response;

    /**
     * XML encoding constant for security - prevents XSS in CXML responses.
     * Encodes: < > & " '
     */
    private const XML_ENCODING = ENT_XML1 | ENT_QUOTES;

    public function __construct()
    {
        $this->document = new DOMDocument('1.0', 'UTF-8');
        $this->document->formatOutput = true;

        $this->response = $this->document->createElement('Response');
        $this->document->appendChild($this->response);
    }

    /**
     * Add Dial verb to route call to a number or SIP URI.
     *
     * @param  string|array<string>  $targets  Phone number(s) or SIP URI(s)
     * @param  int|null  $timeout  Timeout in seconds
     * @param  string|null  $action  Callback URL after dial completes
     * @param  string|null  $trunks  Trunk identifier(s) to use for dialing
     */
    public function dial(string|array $targets, ?int $timeout = null, ?string $action = null, ?string $trunks = null): self
    {
        $dial = $this->document->createElement('Dial');

        if ($timeout !== null) {
            $dial->setAttribute('timeout', (string) $timeout);
        }

        if ($action !== null) {
            $dial->setAttribute('action', $action);
        }

        if ($trunks !== null) {
            $dial->setAttribute('trunks', $trunks);
        }

        // Handle multiple targets
        $targetArray = is_array($targets) ? $targets : [$targets];

        foreach ($targetArray as $target) {
            if ($this->isSipUri($target)) {
                $sip = $this->document->createElement('Sip', htmlspecialchars($target, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                $dial->appendChild($sip);
            } else {
                $number = $this->document->createElement('Number', htmlspecialchars($target, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                $dial->appendChild($number);
            }
        }

        $this->response->appendChild($dial);

        return $this;
    }

    /**
     * Add Say verb to speak text using TTS.
     */
    public function say(string $text, ?string $voice = null, ?string $language = null): self
    {
        $say = $this->document->createElement('Say', htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8'));

        if ($voice !== null) {
            $say->setAttribute('voice', $voice);
        }

        if ($language !== null) {
            $say->setAttribute('language', $language);
        }

        $this->response->appendChild($say);

        return $this;
    }

    /**
     * Add Play verb to play an audio file.
     */
    public function play(string $url, ?int $loop = null): self
    {
        $play = $this->document->createElement('Play', htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8'));

        if ($loop !== null) {
            $play->setAttribute('loop', (string) $loop);
        }

        $this->response->appendChild($play);

        return $this;
    }

    /**
     * Add Hangup verb to end the call.
     */
    public function hangup(): self
    {
        $hangup = $this->document->createElement('Hangup');
        $this->response->appendChild($hangup);

        return $this;
    }

    /**
     * Add Redirect verb to transfer control to another URL.
     */
    public function redirect(string $url): self
    {
        $redirect = $this->document->createElement('Redirect', htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
        $this->response->appendChild($redirect);

        return $this;
    }

    /**
     * Add Voicemail verb to send call to voicemail.
     */
    public function voicemail(?string $transcribe = null, ?string $action = null): self
    {
        $voicemail = $this->document->createElement('Voicemail');

        if ($transcribe !== null) {
            $voicemail->setAttribute('transcribe', $transcribe);
        }

        if ($action !== null) {
            $voicemail->setAttribute('action', $action);
        }

        $this->response->appendChild($voicemail);

        return $this;
    }

    /**
     * Add Service noun to Dial verb for service provider forwarding.
     *
     * @param  string  $serviceUrl  The service provider URL
     * @param  string|null  $serviceToken  Optional service authentication token
     * @param  array<string, mixed>  $params  Additional service parameters
     */
    public function addDialService(string $serviceUrl, ?string $serviceToken = null, array $params = []): self
    {
        $dial = $this->document->createElement('Dial');
        $service = $this->document->createElement('Service', htmlspecialchars($serviceUrl, ENT_XML1 | ENT_QUOTES, 'UTF-8'));

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

    /**
     * Add Dial verb with nested Conference for conference room access.
     *
     * @param  string  $conferenceIdentifier  Clean conference identifier (letters/digits only)
     * @param  bool  $startOnEnter  Start conference when participant enters
     * @param  bool  $endOnExit  End conference when last participant exits
     * @param  int|null  $maxParticipants  Maximum number of participants
     * @param  string|null  $waitUrl  URL for hold music while waiting
     * @param  bool  $muteOnEntry  Mute participant when they enter
     * @param  bool  $announceJoinLeave  Announce when participants join/leave
     * @param  int|null  $timeout  Dial timeout in seconds
     */
    public function dialConference(
        string $conferenceIdentifier,
        bool $startOnEnter = true,
        bool $endOnExit = false,
        ?int $maxParticipants = null,
        ?string $waitUrl = null,
        bool $muteOnEntry = false,
        bool $announceJoinLeave = false,
        ?int $timeout = null
    ): self {
        $dial = $this->document->createElement('Dial');

        if ($timeout !== null) {
            $dial->setAttribute('timeout', (string) $timeout);
        }

        $conference = $this->document->createElement('Conference');

        $conference->setAttribute('startConferenceOnEnter', $startOnEnter ? 'true' : 'false');
        $conference->setAttribute('endConferenceOnExit', $endOnExit ? 'true' : 'false');

        if ($maxParticipants !== null) {
            $conference->setAttribute('maxParticipants', (string) $maxParticipants);
        }

        if ($waitUrl !== null) {
            $conference->setAttribute('waitUrl', htmlspecialchars($waitUrl, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
        }

        $conference->setAttribute('muteOnEntry', $muteOnEntry ? 'true' : 'false');
        $conference->setAttribute('announceJoinLeave', $announceJoinLeave ? 'true' : 'false');

        // Add conference identifier as text content
        $conference->textContent = $conferenceIdentifier;

        $dial->appendChild($conference);
        $this->response->appendChild($dial);

        return $this;
    }

    /**
     * Build dial action for an extension.
     *
     * @param  string  $sipUri  SIP URI of the extension
     * @param  int|null  $timeout  Timeout in seconds
     */
    public static function dialExtension(string $sipUri, ?int $timeout = null): string
    {
        $builder = new self;
        $builder->dial($sipUri, $timeout ?? config('cloudonix.cxml.default_timeout', 30));

        return $builder->build();
    }

    /**
     * Build dial action for a ring group (multiple extensions).
     *
     * @param  array<string>  $sipUris  Array of SIP URIs
     * @param  int|null  $timeout  Timeout in seconds
     */
    public static function dialRingGroup(array $sipUris, ?int $timeout = null): string
    {
        $builder = new self;
        $builder->dial($sipUris, $timeout ?? config('cloudonix.cxml.default_timeout', 30));

        return $builder->build();
    }

    /**
     * Build a busy response.
     */
    public static function busy(string $message = 'All agents are currently busy. Please try again later.'): string
    {
        $builder = new self;
        $builder->say($message)
            ->hangup();

        return $builder->build();
    }

    /**
     * Build a voicemail response.
     */
    public static function sendToVoicemail(?string $action = null): string
    {
        $builder = new self;
        $builder->voicemail(action: $action);

        return $builder->build();
    }

    /**
     * Build a conference room response with Dial wrapper.
     *
     * @param  string  $conferenceIdentifier  Clean conference identifier (letters/digits only)
     * @param  int|null  $maxParticipants  Maximum participants
     * @param  bool  $muteOnEntry  Whether to mute participants on entry
     * @param  bool  $announceJoinLeave  Whether to announce joins/leaves
     */
    public static function joinConference(
        string $conferenceIdentifier,
        ?int $maxParticipants = null,
        bool $muteOnEntry = false,
        bool $announceJoinLeave = false
    ): string {
        $builder = new self;
        $builder->dialConference(
            $conferenceIdentifier,
            true, // startOnEnter
            false, // endOnExit
            $maxParticipants,
            null, // waitUrl
            $muteOnEntry,
            $announceJoinLeave,
            null // timeout
        );

        return $builder->build();
    }

    /**
     * Build an unavailable response.
     *
     * @param  string  $message  The unavailable message
     */
    public static function unavailable(string $message = 'The extension you are trying to reach is unavailable.'): string
    {
        $builder = new self;
        $builder->say($message.' Goodbye.')
            ->hangup();

        return $builder->build();
    }

    /**
     * Build a simple dial response.
     *
     * @param  string  $destination  The destination to dial
     * @param  string|null  $callerId  Optional caller ID
     * @param  int|null  $timeout  Optional timeout in seconds
     * @param  string|null  $trunks  Trunk identifier(s) to use for dialing
     */
    public static function simpleDial(string $destination, ?string $callerId = null, ?int $timeout = null, ?string $trunks = null): string
    {
        $builder = new self;
        $builder->dial($destination, $timeout, null, $trunks);

        return $builder->build();
    }

    /**
     * Build a say with optional hangup response.
     *
     * @param  string  $message  The message to say
     * @param  bool  $hangupAfter  Whether to hangup after saying
     * @param  string|null  $voice  Voice type
     * @param  string|null  $language  Language code
     */
    public static function sayWithHangup(
        string $message,
        bool $hangupAfter = false,
        ?string $voice = null,
        ?string $language = null
    ): string {
        $builder = new self;
        $builder->say($message, $voice, $language);

        if ($hangupAfter) {
            $builder->hangup();
        }

        return $builder->build();
    }

    /**
     * Build a simple hangup response.
     */
    public static function simpleHangup(): string
    {
        $builder = new self;
        $builder->hangup();

        return $builder->build();
    }

    /**
     * Build service provider dialing response.
     *
     * @param  string  $serviceUrl  The service provider URL
     * @param  string|null  $serviceToken  Optional service authentication token
     * @param  array<string, mixed>  $params  Additional service parameters
     */
    public static function dialService(string $serviceUrl, ?string $serviceToken = null, array $params = []): string
    {
        $builder = new self;
        $builder->addDialService($serviceUrl, $serviceToken, $params);

        return $builder->build();
    }

    /**
     * Build service provider dialing response with provider and phone number.
     *
     * Used for Cloudonix Service providers like Retell, VAPI, etc.
     *
     * @param  string  $provider  The service provider name (e.g., 'retell', 'vapi')
     * @param  string  $phoneNumber  The service provider phone number
     */
    public static function dialServiceProvider(string $provider, string $phoneNumber): string
    {
        $builder = new self;
        $dial = $builder->document->createElement('Dial');
        $service = $builder->document->createElement('Service', htmlspecialchars($phoneNumber, self::XML_ENCODING, 'UTF-8'));
        $service->setAttribute('provider', $provider);
        $dial->appendChild($service);
        $builder->response->appendChild($dial);

        return $builder->build();
    }

    /**
     * Build service provider dialing response with action callback for follow-through.
     *
     * Used when we need Cloudonix to callback after the dial completes
     * (busy, no-answer, failed, etc.) so we can try the next assistant.
     *
     * @param  string  $provider  The service provider name (e.g., 'retell', 'vapi')
     * @param  string  $phoneNumber  The service provider phone number
     * @param  string  $actionUrl  Callback URL when dial completes
     */
    public static function dialServiceProviderWithAction(string $provider, string $phoneNumber, string $actionUrl): string
    {
        $builder = new self;
        $dial = $builder->document->createElement('Dial');

        // Add action attribute for callback
        // DOMDocument handles XML encoding automatically - & becomes &amp;
        $dial->setAttribute('action', $actionUrl);

        $service = $builder->document->createElement('Service', htmlspecialchars($phoneNumber, self::XML_ENCODING, 'UTF-8'));
        $service->setAttribute('provider', $provider);

        $dial->appendChild($service);
        $builder->response->appendChild($dial);

        return $builder->build();
    }

    /**
     * Add Gather verb for DTMF input collection.
     *
     * @param  string  $nestedVerbs  XML string of nested verbs (Say, Play, etc.)
     * @param  string  $action  Callback URL for input processing
     * @param  int  $timeout  Timeout in seconds
     * @param  string  $finishOnKey  Key that ends input collection
     * @param  int  $minDigits  Minimum number of digits to collect
     * @param  int  $maxDigits  Maximum number of digits to collect
     */
    public function addGather(
        string $nestedVerbs,
        string $action,
        int $timeout = 5,
        string $finishOnKey = '#',
        int $minDigits = 1,
        int $maxDigits = 1,
        ?int $maxTimeout = null
    ): self {
        $gather = $this->document->createElement('Gather');

        $gather->setAttribute('action', $action);
        $gather->setAttribute('timeout', (string) $timeout);
        $gather->setAttribute('finishOnKey', $finishOnKey);
        $gather->setAttribute('minDigits', (string) $minDigits);
        $gather->setAttribute('maxDigits', (string) $maxDigits);

        if ($maxTimeout !== null) {
            $gather->setAttribute('maxTimeout', (string) $maxTimeout);
        }

        // Parse and append nested verbs
        $tempDoc = new DOMDocument;
        $tempDoc->loadXML('<root>'.$nestedVerbs.'</root>', LIBXML_NOERROR | LIBXML_NOWARNING);

        if ($tempDoc->documentElement) {
            foreach ($tempDoc->documentElement->childNodes as $node) {
                if ($node->nodeType === XML_ELEMENT_NODE) {
                    $importedNode = $this->document->importNode($node, true);
                    $gather->appendChild($importedNode);
                }
            }
        }

        $this->response->appendChild($gather);

        return $this;
    }

    /**
     * Build a Gather response with nested content.
     *
     * @param  string  $nestedVerbs  XML string of nested verbs
     * @param  string  $action  Callback URL for input processing
     * @param  int  $timeout  Timeout in seconds
     * @param  string  $finishOnKey  Key that ends input collection
     * @param  int  $minDigits  Minimum number of digits to collect
     * @param  int  $maxDigits  Maximum number of digits to collect
     */
    public static function gather(
        string $nestedVerbs,
        string $action,
        int $timeout = 5,
        string $finishOnKey = '#',
        int $minDigits = 1,
        int $maxDigits = 1,
        ?int $maxTimeout = null
    ): string {
        $builder = new self;

        return $builder->addGather($nestedVerbs, $action, $timeout, $finishOnKey, $minDigits, $maxDigits, $maxTimeout)->build();
    }

    /**
     * Get Play verb XML fragment.
     *
     * @param  string  $url  Audio file URL to play
     * @param  int|null  $loop  Number of times to loop (0 = infinite)
     */
    public static function playXml(string $url, ?int $loop = null): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $play = $doc->createElement('Play');

        if ($loop !== null) {
            $play->setAttribute('loop', (string) $loop);
        }

        $play->textContent = htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $doc->appendChild($play);

        return $doc->saveXML($play);
    }

    /**
     * Get Say verb XML fragment.
     *
     * @param  string  $text  Text to speak
     * @param  string|null  $voice  Voice to use
     * @param  string|null  $language  Language code
     */
    public static function sayXml(string $text, ?string $voice = null, ?string $language = null): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $say = $doc->createElement('Say');

        if ($voice !== null) {
            $say->setAttribute('voice', $voice);
        }

        if ($language !== null) {
            $say->setAttribute('language', $language);
        }

        $say->textContent = $text;
        $doc->appendChild($say);

        return $doc->saveXML($say);
    }

    /**
     * Add Connect verb with Stream noun for WebSocket audio streaming.
     *
     * This enables bi-directional audio streaming to WebSocket-based services.
     *
     * @param  string  $websocketUrl  WebSocket URL (must start with wss://)
     *
     * @see https://developers.cloudonix.com/Documentation/voiceApplication/Verb/connect/stream
     */
    public function connectStream(string $websocketUrl): self
    {
        // Validate WebSocket URL format
        if (! str_starts_with($websocketUrl, 'wss://')) {
            throw new \InvalidArgumentException('WebSocket URL must start with wss://');
        }

        $connect = $this->document->createElement('Connect');
        $stream = $this->document->createElement('Stream');
        $stream->setAttribute('url', htmlspecialchars($websocketUrl, self::XML_ENCODING, 'UTF-8'));

        $connect->appendChild($stream);
        $this->response->appendChild($connect);

        return $this;
    }

    /**
     * Add Connect verb with Stream noun for WebSocket audio streaming with action callback.
     *
     * This enables bi-directional audio streaming and notifies us when the connect
     * completes (busy, no-answer, failed, etc.) via the action parameter.
     *
     * @param  string  $websocketUrl  WebSocket URL (must start with wss://)
     * @param  string|null  $actionUrl  Callback URL when connect completes
     *
     * @see https://developers.cloudonix.com/Documentation/voiceApplication/Verb/connect
     */
    public function connectStreamWithAction(string $websocketUrl, ?string $actionUrl = null): self
    {
        // Validate WebSocket URL format
        if (! str_starts_with($websocketUrl, 'wss://')) {
            throw new \InvalidArgumentException('WebSocket URL must start with wss://');
        }

        $connect = $this->document->createElement('Connect');

        // Add action attribute on Connect verb for callback when connect completes
        // DOMDocument handles XML encoding automatically - & becomes &amp;
        if ($actionUrl !== null) {
            $connect->setAttribute('action', $actionUrl);
        }

        $stream = $this->document->createElement('Stream');
        $stream->setAttribute('url', $websocketUrl);

        $connect->appendChild($stream);
        $this->response->appendChild($connect);

        return $this;
    }

    /**
     * Build a Connect Stream response with action callback.
     *
     * Static factory method for creating WebSocket streaming responses with action callback.
     *
     * @param  string  $websocketUrl  WebSocket URL (must start with wss://)
     * @param  string|null  $actionUrl  Callback URL when connect completes
     * @return string CXML response
     */
    public static function streamToWebSocketWithAction(string $websocketUrl, ?string $actionUrl = null): string
    {
        $builder = new self;
        $builder->connectStreamWithAction($websocketUrl, $actionUrl);

        return $builder->build();
    }

    /**
     * Build a Connect Stream response with action callback.
     *
     * Static factory method for creating WebSocket streaming responses with action callback.
     *
     * @param  string  $websocketUrl  WebSocket URL (must start with wss://)
     * @param  string|null  $actionUrl  Callback URL when connect completes
     * @return string CXML response
     *
     * @deprecated Use streamToWebSocketWithAction() instead
     */
    public static function streamToWebSocketWithCallback(string $websocketUrl, ?string $actionUrl = null): string
    {
        return self::streamToWebSocketWithAction($websocketUrl, $actionUrl);
    }

    /**
     * Build a Connect Stream response for WebSocket audio streaming.
     *
     * Static factory method for creating WebSocket streaming responses.
     *
     * @param  string  $websocketUrl  WebSocket URL (must start with wss://)
     * @return string CXML response
     */
    public static function streamToWebSocket(string $websocketUrl): string
    {
        $builder = new self;
        $builder->connectStream($websocketUrl);

        return $builder->build();
    }

    /**
     * Build the CXML response as an XML string.
     */
    public function build(): string
    {
        $xml = $this->document->saveXML();

        if ($xml === false) {
            throw new \RuntimeException('Failed to generate CXML');
        }

        return $xml;
    }

    /**
     * Convert the CXML to a Laravel HTTP Response.
     *
     * @param  int  $status  HTTP status code (default: 200)
     */
    public function toResponse(int $status = 200): \Illuminate\Http\Response
    {
        return response($this->build(), $status)
            ->header('Content-Type', 'application/xml');
    }

    /**
     * Check if a string is a SIP URI.
     */
    private function isSipUri(string $value): bool
    {
        return str_starts_with(strtolower($value), 'sip:');
    }
}
