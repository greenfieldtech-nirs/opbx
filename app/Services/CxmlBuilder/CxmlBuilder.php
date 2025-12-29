<?php

declare(strict_types=1);

namespace App\Services\CxmlBuilder;

use DOMDocument;
use DOMElement;

/**
 * CXML response builder for Cloudonix voice applications.
 *
 * @see https://developers.cloudonix.com/Documentation/voiceApplication
 * @see https://developers.cloudonix.com/Documentation/voiceApplication/Verb/dial
 */
class CxmlBuilder
{
    private DOMDocument $document;
    private DOMElement $response;

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
     * @param string|array<string> $targets Phone number(s) or SIP URI(s)
     * @param int|null $timeout Timeout in seconds
     * @param string|null $action Callback URL after dial completes
     */
    public function dial(string|array $targets, ?int $timeout = null, ?string $action = null): self
    {
        $dial = $this->document->createElement('Dial');

        if ($timeout !== null) {
            $dial->setAttribute('timeout', (string) $timeout);
        }

        if ($action !== null) {
            $dial->setAttribute('action', $action);
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
     * Add Conference verb to connect call to a conference room.
     *
     * @param string $name Conference room name
     * @param bool $startOnEnter Start conference when participant enters
     * @param bool $endOnExit End conference when last participant exits
     * @param int|null $maxParticipants Maximum number of participants
     * @param string|null $waitUrl URL for hold music while waiting
     * @param bool $muteOnEntry Mute participant when they enter
     * @param bool $announceJoinLeave Announce when participants join/leave
     */
    public function conference(
        string $name,
        bool $startOnEnter = true,
        bool $endOnExit = false,
        ?int $maxParticipants = null,
        ?string $waitUrl = null,
        bool $muteOnEntry = false,
        bool $announceJoinLeave = false
    ): self {
        $conference = $this->document->createElement('Conference');

        $conference->setAttribute('name', htmlspecialchars($name, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
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

        $this->response->appendChild($conference);

        return $this;
    }

    /**
     * Build dial action for an extension.
     *
     * @param string $sipUri SIP URI of the extension
     * @param int|null $timeout Timeout in seconds
     */
    public static function dialExtension(string $sipUri, ?int $timeout = null): string
    {
        $builder = new self();
        $builder->dial($sipUri, $timeout ?? config('cloudonix.cxml.default_timeout', 30));

        return $builder->build();
    }

    /**
     * Build dial action for a ring group (multiple extensions).
     *
     * @param array<string> $sipUris Array of SIP URIs
     * @param int|null $timeout Timeout in seconds
     */
    public static function dialRingGroup(array $sipUris, ?int $timeout = null): string
    {
        $builder = new self();
        $builder->dial($sipUris, $timeout ?? config('cloudonix.cxml.default_timeout', 30));

        return $builder->build();
    }

    /**
     * Build a busy response.
     */
    public static function busy(string $message = 'All agents are currently busy. Please try again later.'): string
    {
        $builder = new self();
        $builder->say($message)
            ->hangup();

        return $builder->build();
    }

    /**
     * Build a voicemail response.
     */
    public static function sendToVoicemail(?string $action = null): string
    {
        $builder = new self();
        $builder->voicemail(action: $action);

        return $builder->build();
    }

    /**
     * Build an unavailable response.
     *
     * @param string $message The unavailable message
     */
    public static function unavailable(string $message = 'The extension you are trying to reach is unavailable.'): string
    {
        $builder = new self();
        $builder->say($message . ' Goodbye.')
            ->hangup();

        return $builder->build();
    }

    /**
     * Build a simple dial response.
     *
     * @param string $destination The destination to dial
     * @param string|null $callerId Optional caller ID
     * @param int|null $timeout Optional timeout in seconds
     */
    public static function simpleDial(string $destination, ?string $callerId = null, ?int $timeout = null): string
    {
        $builder = new self();
        $builder->dial($destination, $timeout, null);

        return $builder->build();
    }

    /**
     * Build a say with optional hangup response.
     *
     * @param string $message The message to say
     * @param bool $hangupAfter Whether to hangup after saying
     * @param string|null $voice Voice type
     * @param string|null $language Language code
     */
    public static function sayWithHangup(
        string $message,
        bool $hangupAfter = false,
        ?string $voice = null,
        ?string $language = null
    ): string {
        $builder = new self();
        $builder->say($message, $voice, $language);

        if ($hangupAfter) {
            $builder->hangup();
        }

        return $builder->build();
    }

    /**
     * Build a conference room response.
     *
     * @param string $conferenceName Name of the conference room
     * @param int|null $maxParticipants Maximum participants
     * @param bool $muteOnEntry Whether to mute participants on entry
     * @param bool $announceJoinLeave Whether to announce joins/leaves
     */
    public static function joinConference(
        string $conferenceName,
        ?int $maxParticipants = null,
        bool $muteOnEntry = false,
        bool $announceJoinLeave = false
    ): string {
        $builder = new self();
        $builder->conference(
            $conferenceName,
            true, // startOnEnter
            false, // endOnExit
            $maxParticipants,
            null, // waitUrl
            $muteOnEntry,
            $announceJoinLeave
        );

        return $builder->build();
    }

    /**
     * Build a simple hangup response.
     */
    public static function simpleHangup(): string
    {
        $builder = new self();
        $builder->hangup();

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
     * @param int $status HTTP status code (default: 200)
     * @return \Illuminate\Http\Response
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
