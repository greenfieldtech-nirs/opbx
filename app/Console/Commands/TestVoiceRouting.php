<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\Voice\VoiceRoutingController;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Test voice routing logic by simulating webhook requests.
 */
class TestVoiceRouting extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'voice:test:routing
                            {--direction=inbound : Call direction (inbound, outbound, subscriber, application)}
                            {--to= : Destination phone number or extension}
                            {--from= : Caller phone number or extension}
                            {--organization-id= : Organization ID for the request}
                            {--call-sid= : Call SID (auto-generated if not provided)}
                            {--debug : Enable debug logging}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test voice routing logic by simulating webhook requests';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $direction = $this->option('direction');
        $to = $this->option('to');
        $from = $this->option('from');
        $orgId = (int) $this->option('organization-id');
        $callSid = $this->option('call-sid') ?: 'CA' . md5(uniqid('test-', true));
        $debug = $this->option('debug');

        // Validate required parameters
        if (!$to || !$from) {
            $this->error('Both --to and --from parameters are required');
            return 1;
        }

        if (!$orgId) {
            $this->error('--organization-id parameter is required');
            return 1;
        }

        $this->info('🧪 Testing Voice Routing Logic');
        $this->line('Direction: ' . $direction);
        $this->line('To: ' . $to);
        $this->line('From: ' . $from);
        $this->line('Organization ID: ' . $orgId);
        $this->line('Call SID: ' . $callSid);
        $this->line('');

        // Create request object
        $request = new Request();
        $request->merge([
            'Direction' => $direction,
            'To' => $to,
            'From' => $from,
            'CallSid' => $callSid,
            '_organization_id' => $orgId,
        ]);

        // Set request headers to simulate webhook
        $request->headers->set('Authorization', 'Bearer test-api-key');
        $request->headers->set('Accept', 'application/xml');

        if ($debug) {
            $this->info('🔧 Debug mode enabled');
        }

        try {
            // Get the controller and call handleInbound
            $controller = app(VoiceRoutingController::class);
            $response = $controller->handleInbound($request);

            $this->info('📞 Response Status: ' . $response->status());
            $this->line('Content-Type: ' . $response->headers->get('Content-Type'));
            $this->line('');

            $content = $response->getContent();



            if ($response->headers->get('Content-Type') === 'text/xml') {
                $this->info('📋 CXML Response:');
                $this->line('');
                // Pretty print XML
                $dom = new \DOMDocument('1.0');
                $dom->preserveWhiteSpace = false;
                $dom->formatOutput = true;
                $dom->loadXML($content);
                $this->line($dom->saveXML());
            } else {
                $this->info('📋 Response Content:');
                $this->line($content);
            }

            // Additional analysis
            $this->analyzeResponse($content, $response->status(), $debug);

            return 0;

        } catch (\Exception $e) {
            $this->error('❌ Exception occurred: ' . $e->getMessage());
            $this->line('File: ' . $e->getFile() . ':' . $e->getLine());
            $this->line('Trace: ' . $e->getTraceAsString());

            return 1;
        }
    }

    /**
     * Analyze the response and provide insights.
     */
    private function analyzeResponse(string $content, int $status, bool $debug = false): void
    {
        $this->line('');
        $this->info('🔍 Response Analysis:');

        if ($status !== 200) {
            $this->warn('⚠️  Non-200 status code: ' . $status);
        }



        // Check for common patterns
        if (preg_match('/<Say[^>]*>.*<\/Say>/s', $content) && str_contains($content, '<Hangup')) {
            $this->warn('❌ Error response detected - call will be rejected');

            // Extract error message
            if (preg_match('/<Say[^>]*>(.*?)<\/Say>/s', $content, $matches)) {
                $this->line('Error message: "' . trim($matches[1]) . '"');
            }
        } elseif (str_contains($content, '<Dial')) {
            $this->info('✅ Call routing response - call will be connected');

            // Extract dial targets
            if (preg_match_all('/<Number[^>]*>([^<]+)<\/Number>/', $content, $matches)) {
                $this->line('Dialing numbers: ' . implode(', ', $matches[1]));
            }

            if (preg_match_all('/<Sip[^>]*>([^<]+)<\/Sip>/', $content, $matches)) {
                $this->line('Dialing SIP URIs: ' . implode(', ', $matches[1]));
            }

            if (preg_match_all('/<Service[^>]*>([^<]+)<\/Service>/', $content, $matches)) {
                $this->line('Dialing services: ' . implode(', ', $matches[1]));
            }
        } elseif (str_contains($content, '<Gather>')) {
            $this->info('✅ IVR response - waiting for user input');
        } elseif ($status === 204) {
            $this->info('✅ No Content response - continuing with default routing');
        } else {
            $this->warn('⚠️  Unknown response type');
        }

        // Check for timeout
        if (preg_match('/timeout="(\d+)"/', $content, $matches)) {
            $this->line('Timeout: ' . $matches[1] . ' seconds');
        }
    }
}