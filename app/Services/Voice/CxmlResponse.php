<?php

declare(strict_types=1);

namespace App\Services\Voice;

use DOMDocument;
use DOMElement;

/**
 * CXML Response Builder
 *
 * Fluent API for building CXML (Cloudonix XML) documents for voice call routing.
 * Supports common verbs: Say, Dial, Hangup, Gather, Play, Pause, and Redirect.
 *
 * Example usage:
 * ```
 * $cxml = CxmlResponse::create()
 *     ->say('Hello, welcome to our company')
 *     ->gather(
 *         action: 'https://example.com/ivr-input',
 *         numDigits: 1,
 *         timeout: 5
 *     )
 *     ->say('Press 1 for sales, 2 for support')
 *     ->endGather()
 *     ->build();
 * ```
 */
class CxmlResponse
{
    private DOMDocument $doc;
    private DOMElement $response;
    private ?DOMElement $currentParent = null;

    private function __construct()
    {
        $this->doc = new DOMDocument('1.0', 'UTF-8');
        $this->doc->formatOutput = true;
        $this->response = $this->doc->createElement('Response');
        $this->doc->appendChild($this->response);
        $this->currentParent = $this->response;
    }

    /**
     * Create a new CXML response builder
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Add a Say verb (text-to-speech)
     *
     * @param string $text Text to speak
     * @param string $voice Voice to use (alice, man, woman)
     * @param string $language Language code (e.g., en-US, es-ES)
     * @param int|null $loop Number of times to repeat
     * @return self
     */
    public function say(
        string $text,
        string $voice = 'alice',
        string $language = 'en-US',
        ?int $loop = null
    ): self {
        $say = $this->doc->createElement('Say', htmlspecialchars($text, ENT_XML1, 'UTF-8'));
        $say->setAttribute('voice', $voice);
        $say->setAttribute('language', $language);

        if ($loop !== null) {
            $say->setAttribute('loop', (string) $loop);
        }

        $this->currentParent->appendChild($say);
        return $this;
    }

    /**
     * Add a Dial verb (dial a number or SIP endpoint)
     *
     * @param string $number Phone number or SIP address to dial
     * @param int $timeout Timeout in seconds
     * @param string|null $callerId Caller ID to present
     * @param string|null $action Callback URL after dial completes
     * @param string|null $method HTTP method for action callback (POST or GET)
     * @param int|null $timeLimit Maximum call duration in seconds
     * @return self
     */
    public function dial(
        string $number,
        int $timeout = 30,
        ?string $callerId = null,
        ?string $action = null,
        ?string $method = 'POST',
        ?int $timeLimit = null
    ): self {
        $dial = $this->doc->createElement('Dial');
        $dial->setAttribute('timeout', (string) $timeout);

        if ($callerId !== null) {
            $dial->setAttribute('callerId', $callerId);
        }

        if ($action !== null) {
            $dial->setAttribute('action', $action);
            $dial->setAttribute('method', $method ?? 'POST');
        }

        if ($timeLimit !== null) {
            $dial->setAttribute('timeLimit', (string) $timeLimit);
        }

        // Add the number as a Number child element
        $numberElement = $this->doc->createElement('Number', htmlspecialchars($number, ENT_XML1, 'UTF-8'));
        $dial->appendChild($numberElement);

        $this->currentParent->appendChild($dial);
        return $this;
    }

    /**
     * Add a Hangup verb (end the call)
     *
     * @return self
     */
    public function hangup(): self
    {
        $hangup = $this->doc->createElement('Hangup');
        $this->currentParent->appendChild($hangup);
        return $this;
    }

    /**
     * Start a Gather verb (collect DTMF input)
     *
     * Call endGather() when done adding nested verbs.
     *
     * @param string $action Callback URL to send digits
     * @param string $method HTTP method (POST or GET)
     * @param int $numDigits Number of digits to collect
     * @param int $timeout Timeout in seconds to wait for digit
     * @param string $finishOnKey Digit that ends input (# by default)
     * @return self
     */
    public function gather(
        string $action,
        string $method = 'POST',
        int $numDigits = 1,
        int $timeout = 5,
        string $finishOnKey = '#'
    ): self {
        $gather = $this->doc->createElement('Gather');
        $gather->setAttribute('action', $action);
        $gather->setAttribute('method', $method);
        $gather->setAttribute('numDigits', (string) $numDigits);
        $gather->setAttribute('timeout', (string) $timeout);
        $gather->setAttribute('finishOnKey', $finishOnKey);

        $this->currentParent->appendChild($gather);
        $this->currentParent = $gather;
        return $this;
    }

    /**
     * End the current Gather verb
     *
     * @return self
     */
    public function endGather(): self
    {
        if ($this->currentParent->nodeName === 'Gather') {
            $this->currentParent = $this->response;
        }
        return $this;
    }

    /**
     * Add a Play verb (play audio file)
     *
     * @param string $url URL of audio file to play
     * @param int|null $loop Number of times to repeat
     * @return self
     */
    public function play(string $url, ?int $loop = null): self
    {
        $play = $this->doc->createElement('Play', htmlspecialchars($url, ENT_XML1, 'UTF-8'));

        if ($loop !== null) {
            $play->setAttribute('loop', (string) $loop);
        }

        $this->currentParent->appendChild($play);
        return $this;
    }

    /**
     * Add a Pause verb (silence)
     *
     * @param int $length Duration in seconds
     * @return self
     */
    public function pause(int $length = 1): self
    {
        $pause = $this->doc->createElement('Pause');
        $pause->setAttribute('length', (string) $length);
        $this->currentParent->appendChild($pause);
        return $this;
    }

    /**
     * Add a Redirect verb (redirect to another CXML URL)
     *
     * @param string $url URL to fetch new CXML from
     * @param string $method HTTP method (POST or GET)
     * @return self
     */
    public function redirect(string $url, string $method = 'POST'): self
    {
        $redirect = $this->doc->createElement('Redirect', htmlspecialchars($url, ENT_XML1, 'UTF-8'));
        $redirect->setAttribute('method', $method);
        $this->currentParent->appendChild($redirect);
        return $this;
    }

    /**
     * Add a Reject verb (reject the call)
     *
     * @param string $reason Rejection reason (busy or rejected)
     * @return self
     */
    public function reject(string $reason = 'busy'): self
    {
        $reject = $this->doc->createElement('Reject');
        $reject->setAttribute('reason', $reason);
        $this->currentParent->appendChild($reject);
        return $this;
    }

    /**
     * Build and return the final CXML XML string
     *
     * @return string CXML XML document
     */
    public function build(): string
    {
        return $this->doc->saveXML();
    }

    /**
     * Build a simple error response
     *
     * @param string $message Error message to speak
     * @return string CXML XML document
     */
    public static function error(string $message = 'An error occurred. Please try again later.'): string
    {
        return self::create()
            ->say($message)
            ->hangup()
            ->build();
    }

    /**
     * Build a simple busy response
     *
     * @return string CXML XML document
     */
    public static function busy(): string
    {
        return self::create()
            ->reject('busy')
            ->build();
    }
}
