<?php

declare(strict_types=1);

namespace App\Services\CxmlBuilder;

use App\Models\AutoDialerCampaign;

/**
 * CXML Builder for Auto Dialer call responses.
 *
 * Generates CXML responses to route calls to AI destinations.
 */
class AutoDialerCxmlBuilder
{
    /**
     * Build CXML for connecting to an AI Assistant.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign configuration
     * @param  array<string, mixed>  $aiAssistantConfig  AI assistant configuration
     * @return string The CXML response
     */
    public static function connectToAiAssistant(AutoDialerCampaign $campaign, array $aiAssistantConfig): string
    {
        $cxml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><Response/>');

        // Add AMD detection if enabled
        if ($campaign->amd_enabled) {
            $amd = $cxml->addChild('amd');
            $amd->addAttribute('timeout', (string) ($campaign->amd_timeout ?? 30));
            $amd->addAttribute('speechThreshold', (string) ($campaign->amd_speech_threshold ?? 1500));
            $amd->addAttribute('speechEndThreshold', (string) ($campaign->amd_speech_end_threshold ?? 2500));
            $amd->addAttribute('silenceTimeout', (string) ($campaign->amd_silence_timeout ?? 3500));
        }

        // Connect to AI
        $connect = $cxml->addChild('connect');

        // Add stream or dial based on AI protocol
        if (isset($aiAssistantConfig['protocol']) && $aiAssistantConfig['protocol'] === 'websocket') {
            $stream = $connect->addChild('stream');
            $stream->addAttribute('url', $aiAssistantConfig['websocket_url'] ?? '');

            // Add any additional stream attributes
            if (isset($aiAssistantConfig['headers']) && is_array($aiAssistantConfig['headers'])) {
                foreach ($aiAssistantConfig['headers'] as $key => $value) {
                    $header = $stream->addChild('header');
                    $header->addAttribute('name', $key);
                    $header->addAttribute('value', $value);
                }
            }
        } else {
            // SIP connection
            $dial = $connect->addChild('dial');
            $dial->addAttribute('target', $aiAssistantConfig['sip_uri'] ?? '');

            if (isset($aiAssistantConfig['timeout'])) {
                $dial->addAttribute('timeout', (string) $aiAssistantConfig['timeout']);
            }
        }

        return $cxml->asXML();
    }

    /**
     * Build CXML for connecting to an AI Load Balancer.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign configuration
     * @param  array<string, mixed>  $loadBalancerConfig  Load balancer configuration
     * @return string The CXML response
     */
    public static function connectToAiLoadBalancer(AutoDialerCampaign $campaign, array $loadBalancerConfig): string
    {
        $cxml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><Response/>');

        // Add AMD detection if enabled
        if ($campaign->amd_enabled) {
            $amd = $cxml->addChild('amd');
            $amd->addAttribute('timeout', (string) ($campaign->amd_timeout ?? 30));
            $amd->addAttribute('speechThreshold', (string) ($campaign->amd_speech_threshold ?? 1500));
            $amd->addAttribute('speechEndThreshold', (string) ($campaign->amd_speech_end_threshold ?? 2500));
            $amd->addAttribute('silenceTimeout', (string) ($campaign->amd_silence_timeout ?? 3500));
        }

        // Connect to load balancer
        $connect = $cxml->addChild('connect');

        // Load balancer routes to multiple AI assistants
        // For now, we use the primary route, but this could be enhanced
        // to support failover strategies
        if (isset($loadBalancerConfig['primary_route'])) {
            $route = $loadBalancerConfig['primary_route'];

            if (isset($route['protocol']) && $route['protocol'] === 'websocket') {
                $stream = $connect->addChild('stream');
                $stream->addAttribute('url', $route['websocket_url'] ?? '');
            } else {
                $dial = $connect->addChild('dial');
                $dial->addAttribute('target', $route['sip_uri'] ?? '');

                if (isset($route['timeout'])) {
                    $dial->addAttribute('timeout', (string) $route['timeout']);
                }
            }
        }

        return $cxml->asXML();
    }

    /**
     * Build CXML to hang up the call.
     *
     * @return string The CXML response
     */
    public static function hangup(): string
    {
        $cxml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><Response/>');
        $cxml->addChild('hangup');

        return $cxml->asXML();
    }

    /**
     * Build CXML to play a message and then hang up.
     *
     * @param  string  $message  The message to play (TTS)
     * @return string The CXML response
     */
    public static function playMessage(string $message): string
    {
        $cxml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><Response/>');

        $say = $cxml->addChild('say');
        $say->addAttribute('language', 'en-US'); // Default to US English
        $say[0] = $message;

        $cxml->addChild('hangup');

        return $cxml->asXML();
    }
}
