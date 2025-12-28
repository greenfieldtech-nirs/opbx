<?php

declare(strict_types=1);

namespace App\Services\Cxml;

use Illuminate\Http\Response;

/**
 * CXML Builder Service
 *
 * Generates well-formed Cloudonix XML (CXML) responses for voice routing.
 * Handles XML declaration, proper escaping, and response formatting.
 *
 * @see https://developers.cloudonix.com/Documentation/voiceApplication
 */
class CxmlBuilder
{
    /**
     * XML version and encoding declaration
     */
    private const XML_DECLARATION = '<?xml version="1.0" encoding="UTF-8"?>';

    /**
     * Default voice for Say verb
     */
    private const DEFAULT_VOICE = 'woman';

    /**
     * Default language for Say verb
     */
    private const DEFAULT_LANGUAGE = 'en-US';

    /**
     * Build a Dial CXML response
     *
     * Routes the call to the specified destination with caller ID.
     *
     * @param string $destination The destination to dial (extension or E.164 number)
     * @param string|null $callerId The caller ID to display
     * @param int $timeout Timeout in seconds (default: 30)
     * @return Response HTTP response with CXML content
     */
    public function dial(string $destination, ?string $callerId = null, int $timeout = 30): Response
    {
        $dialAttributes = ['timeout' => $timeout];

        if ($callerId) {
            $dialAttributes['callerId'] = $callerId;
        }

        $cxml = $this->buildXmlDocument([
            $this->buildDialElement($destination, $dialAttributes),
        ]);

        return $this->createResponse($cxml);
    }

    /**
     * Build a Say CXML response
     *
     * Plays a text-to-speech message to the caller.
     *
     * @param string $message The message to speak
     * @param string|null $voice Voice type (default: 'woman')
     * @param string|null $language Language code (default: 'en-US')
     * @param bool $hangupAfter Whether to hangup after speaking (default: false)
     * @return Response HTTP response with CXML content
     */
    public function say(
        string $message,
        ?string $voice = null,
        ?string $language = null,
        bool $hangupAfter = false
    ): Response {
        $elements = [
            $this->buildSayElement($message, $voice, $language),
        ];

        if ($hangupAfter) {
            $elements[] = $this->buildHangupElement();
        }

        $cxml = $this->buildXmlDocument($elements);

        return $this->createResponse($cxml);
    }

    /**
     * Build a Hangup CXML response
     *
     * Immediately terminates the call.
     *
     * @return Response HTTP response with CXML content
     */
    public function hangup(): Response
    {
        $cxml = $this->buildXmlDocument([
            $this->buildHangupElement(),
        ]);

        return $this->createResponse($cxml);
    }

    /**
     * Build an unavailable response
     *
     * Plays a message indicating the destination is unavailable, then hangs up.
     *
     * @param string $message The unavailable message
     * @return Response HTTP response with CXML content
     */
    public function unavailable(string $message = 'The extension you are trying to reach is unavailable.'): Response
    {
        $fullMessage = $message . ' Goodbye.';

        return $this->say($fullMessage, hangupAfter: true);
    }

    /**
     * Build a custom CXML response with multiple elements
     *
     * @param array<string> $elements Array of CXML element strings
     * @return Response HTTP response with CXML content
     */
    public function buildResponse(array $elements): Response
    {
        $cxml = $this->buildXmlDocument($elements);

        return $this->createResponse($cxml);
    }

    /**
     * Build a complete XML document with Response wrapper
     *
     * @param array<string> $elements Array of CXML element strings
     * @return string Complete CXML document
     */
    private function buildXmlDocument(array $elements): string
    {
        $content = implode("\n", $elements);

        return self::XML_DECLARATION . "\n" .
            '<Response>' . "\n" .
            $content . "\n" .
            '</Response>';
    }

    /**
     * Build a Dial element
     *
     * @param string $destination The destination to dial
     * @param array<string, mixed> $attributes Dial attributes (timeout, callerId, etc.)
     * @return string Dial element XML
     */
    private function buildDialElement(string $destination, array $attributes = []): string
    {
        $attributeString = $this->buildAttributes($attributes);

        return sprintf(
            '  <Dial%s>%s</Dial>',
            $attributeString,
            $this->escapeXml($destination)
        );
    }

    /**
     * Build a Say element
     *
     * @param string $message The message to speak
     * @param string|null $voice Voice type
     * @param string|null $language Language code
     * @return string Say element XML
     */
    private function buildSayElement(string $message, ?string $voice = null, ?string $language = null): string
    {
        $attributes = [
            'voice' => $voice ?? self::DEFAULT_VOICE,
            'language' => $language ?? self::DEFAULT_LANGUAGE,
        ];

        $attributeString = $this->buildAttributes($attributes);

        return sprintf(
            '  <Say%s>' . "\n" . '    %s' . "\n" . '  </Say>',
            $attributeString,
            $this->escapeXml($message)
        );
    }

    /**
     * Build a Hangup element
     *
     * @return string Hangup element XML
     */
    private function buildHangupElement(): string
    {
        return '  <Hangup/>';
    }

    /**
     * Build XML attributes string from array
     *
     * @param array<string, mixed> $attributes Attribute key-value pairs
     * @return string Formatted attributes string (with leading space)
     */
    private function buildAttributes(array $attributes): string
    {
        if (empty($attributes)) {
            return '';
        }

        $parts = [];
        foreach ($attributes as $key => $value) {
            $parts[] = sprintf('%s="%s"', $key, $this->escapeXml((string) $value));
        }

        return ' ' . implode(' ', $parts);
    }

    /**
     * Escape string for use in XML
     *
     * @param string $value String to escape
     * @return string Escaped string
     */
    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    /**
     * Create HTTP response with CXML content
     *
     * @param string $cxml CXML content
     * @param int $status HTTP status code (default: 200)
     * @return Response HTTP response
     */
    private function createResponse(string $cxml, int $status = 200): Response
    {
        return response($cxml, $status)
            ->header('Content-Type', 'application/xml');
    }
}
