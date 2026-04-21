<?php

declare(strict_types=1);

namespace App\Services\CloudonixClient;

use App\Models\CloudonixSettings;
use App\Models\Organization;

/**
 * HTTP client for Cloudonix REST API.
 *
 * This class acts as a facade that delegates to specialized API clients:
 * - CloudonixCallsClient: Call management operations
 * - CloudonixDomainsClient: Domain management and voice applications
 * - CloudonixSubscribersClient: Subscriber CRUD operations
 * - CloudonixTrunksClient: Trunk management
 * - CloudonixSessionsClient: Session management
 * - CloudonixRecordingsClient: Recording management
 *
 * @see https://developers.cloudonix.com/Documentation/core-api
 * @see https://developers.cloudonix.com/cloudonixRestOpenAPI
 * @deprecated Use the specialized clients directly for new code.
 *             This facade is maintained for backward compatibility.
 */
class CloudonixClient
{
    private CloudonixBaseClient $baseClient;

    private CloudonixCallsClient $callsClient;

    private CloudonixDomainsClient $domainsClient;

    private CloudonixSubscribersClient $subscribersClient;

    private CloudonixTrunksClient $trunksClient;

    private CloudonixSessionsClient $sessionsClient;

    private CloudonixRecordingsClient $recordingsClient;

    /**
     * Create a new CloudonixClient instance.
     *
     * @param  CloudonixSettings|Organization|null  $settings  Organization settings or Organization model
     * @param  bool  $requireCredentials  Whether to require credentials at instantiation (default: true)
     *
     * @throws \RuntimeException If API token is not configured and credentials are required
     */
    public function __construct(CloudonixSettings|Organization|null $settings = null, bool $requireCredentials = true)
    {
        // Initialize all specialized clients with the same settings
        $this->callsClient = new CloudonixCallsClient($settings, $requireCredentials);
        $this->domainsClient = new CloudonixDomainsClient($settings, $requireCredentials);
        $this->subscribersClient = new CloudonixSubscribersClient($settings, $requireCredentials);
        $this->trunksClient = new CloudonixTrunksClient($settings, $requireCredentials);
        $this->sessionsClient = new CloudonixSessionsClient($settings, $requireCredentials);
        $this->recordingsClient = new CloudonixRecordingsClient($settings, $requireCredentials);

        // Use one client as the base for shared functionality
        $this->baseClient = $this->callsClient;
    }

    // =========================================================================
    // Circuit Breaker Methods
    // =========================================================================

    /**
     * Get circuit breaker status for monitoring.
     *
     * @return array<string, mixed>
     */
    public function getCircuitBreakerStatus(): array
    {
        return $this->baseClient->getCircuitBreakerStatus();
    }

    /**
     * Manually reset the circuit breaker.
     */
    public function resetCircuitBreaker(): void
    {
        $this->baseClient->resetCircuitBreaker();
    }

    // =========================================================================
    // Call Management Methods (Delegated to CloudonixCallsClient)
    // =========================================================================

    /**
     * Get call status by call ID.
     *
     * @param  string  $callId  The Cloudonix call ID
     * @return array<string, mixed>|null
     */
    public function getCallStatus(string $callId): ?array
    {
        return $this->callsClient->getCallStatus($callId);
    }

    /**
     * Get CDR (Call Detail Record) by call ID.
     *
     * @param  string  $callId  The Cloudonix call ID
     * @return array<string, mixed>|null
     */
    public function getCallCdr(string $callId): ?array
    {
        return $this->callsClient->getCallCdr($callId);
    }

    /**
     * Hangup a call.
     *
     * @param  string  $callId  The Cloudonix call ID
     */
    public function hangupCall(string $callId): bool
    {
        return $this->callsClient->hangupCall($callId);
    }

    /**
     * Switch an active session to a new voice application.
     *
     * @param  string  $sessionToken  The Cloudonix session token
     * @param  string  $url  The URL of the new voice application CXML endpoint
     * @return bool True on success, false on failure
     */
    public function switchVoiceApplication(string $sessionToken, string $url): bool
    {
        return $this->callsClient->switchVoiceApplication($sessionToken, $url);
    }

    /**
     * Get list of calls with optional filters.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>|null
     */
    public function listCalls(array $filters = []): ?array
    {
        return $this->callsClient->listCalls($filters);
    }

    /**
     * Initiate an outbound call via Cloudonix API.
     *
     * @param  string  $from  Caller ID (E.164 format)
     * @param  string  $to  Destination number (E.164 format)
     * @param  string  $trunk  Outbound trunk name
     * @param  array<string, mixed>  $options  Optional parameters
     * @return array<string, mixed>|null Call session data or null on failure
     */
    public function initiateCall(string $from, string $to, string $trunk, array $options = []): ?array
    {
        return $this->callsClient->initiateCall($from, $to, $trunk, $options);
    }

    // =========================================================================
    // Domain Management Methods (Delegated to CloudonixDomainsClient)
    // =========================================================================

    /**
     * Validate domain credentials by fetching domain details.
     *
     * @param  string  $domainUuid  The domain UUID to validate
     * @param  string  $apiKey  The API key (Bearer token) to authenticate with
     * @return array{valid: bool, profile: array<string, mixed>|null} Validation result with domain profile data
     */
    public function validateDomain(string $domainUuid, string $apiKey): array
    {
        return $this->domainsClient->validateDomain($domainUuid, $apiKey);
    }

    /**
     * Static method to validate domain credentials without requiring client instantiation.
     *
     * @param  string  $domainUuid  The domain UUID to validate
     * @param  string  $apiKey  The API key (Bearer token) to authenticate with
     * @return array{valid: bool, profile: array<string, mixed>|null} Validation result with domain profile data
     */
    public static function validateDomainCredentials(string $domainUuid, string $apiKey): array
    {
        return CloudonixDomainsClient::validateDomainCredentials($domainUuid, $apiKey);
    }

    /**
     * Update domain profile settings in Cloudonix.
     *
     * @param  string  $domainUuid  The domain UUID to update
     * @param  string  $apiKey  The API key (Bearer token) to authenticate with
     * @param  array<string, mixed>  $profileData  Profile settings to update
     * @return array{success: bool, message: string|null, data: array<string, mixed>|null}
     */
    public function updateDomain(string $domainUuid, string $apiKey, array $profileData): array
    {
        return $this->domainsClient->updateDomain($domainUuid, $apiKey, $profileData);
    }

    /**
     * Create a voice application in Cloudonix.
     *
     * @param  string  $domainUuid  The domain UUID
     * @param  string  $apiKey  The API key (Bearer token) to authenticate with
     * @param  array<string, mixed>  $applicationData  Application configuration
     * @return array{success: bool, message: string|null, data: array<string, mixed>|null}
     */
    public function createVoiceApplication(string $domainUuid, string $apiKey, array $applicationData): array
    {
        return $this->domainsClient->createVoiceApplication($domainUuid, $apiKey, $applicationData);
    }

    /**
     * Update the default application for a domain.
     *
     * @param  string  $domainUuid  The domain UUID
     * @param  string  $apiKey  The API key (Bearer token) to authenticate with
     * @param  int  $applicationId  The application ID to set as default
     * @return array{success: bool, message: string|null, data: array<string, mixed>|null}
     */
    public function updateDomainDefaultApplication(string $domainUuid, string $apiKey, int $applicationId): array
    {
        return $this->domainsClient->updateDomainDefaultApplication($domainUuid, $apiKey, $applicationId);
    }

    /**
     * Update a voice application in Cloudonix.
     *
     * @param  string  $domainUuid  The domain UUID
     * @param  string  $apiKey  The API key (Bearer token) to authenticate with
     * @param  int  $applicationId  The application ID to update
     * @param  array<string, mixed>  $applicationData  Application configuration to update
     * @return array{success: bool, message: string|null, data: array<string, mixed>|null}
     */
    public function updateVoiceApplication(string $domainUuid, string $apiKey, int $applicationId, array $applicationData): array
    {
        return $this->domainsClient->updateVoiceApplication($domainUuid, $apiKey, $applicationId, $applicationData);
    }

    // =========================================================================
    // Subscriber Management Methods (Delegated to CloudonixSubscribersClient)
    // =========================================================================

    /**
     * Create a new subscriber in Cloudonix.
     *
     * @param  string  $msisdn  Extension number/phone number
     * @param  string  $sipPassword  SIP authentication password
     * @param  array<string, mixed>|null  $profile  Optional profile data
     * @return array<string, mixed>|null Subscriber data or null on failure
     */
    public function createSubscriber(string $msisdn, string $sipPassword, ?array $profile = null): ?array
    {
        return $this->subscribersClient->createSubscriber($msisdn, $sipPassword, $profile);
    }

    /**
     * Update an existing subscriber in Cloudonix.
     *
     * @param  string  $subscriberId  The Cloudonix subscriber ID
     * @param  array<string, mixed>  $data  Update data
     * @return array<string, mixed>|null Updated subscriber data or null on failure
     */
    public function updateSubscriber(string $subscriberId, array $data): ?array
    {
        return $this->subscribersClient->updateSubscriber($subscriberId, $data);
    }

    /**
     * Delete a subscriber from Cloudonix.
     *
     * @param  string  $subscriberId  The Cloudonix subscriber ID
     * @return bool True on success, false on failure
     */
    public function deleteSubscriber(string $subscriberId): bool
    {
        return $this->subscribersClient->deleteSubscriber($subscriberId);
    }

    /**
     * Get a subscriber from Cloudonix.
     *
     * @param  string  $subscriberId  The Cloudonix subscriber ID
     * @return array<string, mixed>|null Subscriber data or null on failure
     */
    public function getSubscriber(string $subscriberId): ?array
    {
        return $this->subscribersClient->getSubscriber($subscriberId);
    }

    /**
     * List all subscribers in the domain.
     *
     * @return array<int, array<string, mixed>>|null Array of subscribers or null on failure
     */
    public function listSubscribers(): ?array
    {
        return $this->subscribersClient->listSubscribers();
    }

    /**
     * Get available voices for the domain.
     *
     * @throws \RuntimeException
     */
    public function getVoices(string $domainUuid): array
    {
        return $this->subscribersClient->getVoices($domainUuid);
    }

    // =========================================================================
    // Trunk Management Methods (Delegated to CloudonixTrunksClient)
    // =========================================================================

    /**
     * List outbound trunks for the domain.
     *
     * @return array<array<string, mixed>>|null Array of outbound trunk objects or null on failure
     */
    public function listOutboundTrunks(): ?array
    {
        return $this->trunksClient->listOutboundTrunks();
    }

    // =========================================================================
    // Session Management Methods (Delegated to CloudonixSessionsClient)
    // =========================================================================

    /**
     * Get session details from Cloudonix.
     *
     * @param  int|string  $sessionId  The Cloudonix session ID
     * @return array<string, mixed>|null Session details or null on failure
     */
    public function getSession(int|string $sessionId): ?array
    {
        return $this->sessionsClient->getSession($sessionId);
    }

    /**
     * Disconnect a session by session ID.
     *
     * @param  int|string  $sessionId  The Cloudonix session ID
     * @return bool True on success, false on failure
     */
    public function disconnectSession(int|string $sessionId): bool
    {
        return $this->sessionsClient->disconnectSession($sessionId);
    }

    /**
     * Update a session's profile data.
     *
     * @param  int|string  $sessionId  The Cloudonix session token
     * @param  array<string, mixed>  $profile  Profile data to update
     * @return bool True on success, false on failure
     */
    public function updateSessionProfile(int|string $sessionId, array $profile): bool
    {
        return $this->sessionsClient->updateSessionProfile($sessionId, $profile);
    }

    // =========================================================================
    // Recording Management Methods (Delegated to CloudonixRecordingsClient)
    // =========================================================================

    /**
     * Get recording details by recording ID.
     *
     * @param  string  $recordingId  The Cloudonix recording ID
     * @return array<string, mixed>|null Recording data or null on failure
     */
    public function getRecording(string $recordingId): ?array
    {
        return $this->recordingsClient->getRecording($recordingId);
    }

    /**
     * Get recording download URL.
     *
     * @param  string  $recordingId  The Cloudonix recording ID
     * @return string|null Download URL or null on failure
     */
    public function getRecordingDownloadUrl(string $recordingId): ?string
    {
        return $this->recordingsClient->getRecordingDownloadUrl($recordingId);
    }

    /**
     * Delete a recording.
     *
     * @param  string  $recordingId  The Cloudonix recording ID
     * @return bool True on success, false on failure
     */
    public function deleteRecording(string $recordingId): bool
    {
        return $this->recordingsClient->deleteRecording($recordingId);
    }

    /**
     * List recordings with optional filters.
     *
     * @param  array<string, mixed>  $filters  Optional filters
     * @return array<string, mixed>|null Array of recordings or null on failure
     */
    public function listRecordings(array $filters = []): ?array
    {
        return $this->recordingsClient->listRecordings($filters);
    }

    // =========================================================================
    // Access to Specialized Clients (For New Code)
    // =========================================================================

    /**
     * Get the calls client for direct access.
     */
    public function calls(): CloudonixCallsClient
    {
        return $this->callsClient;
    }

    /**
     * Get the domains client for direct access.
     */
    public function domains(): CloudonixDomainsClient
    {
        return $this->domainsClient;
    }

    /**
     * Get the subscribers client for direct access.
     */
    public function subscribers(): CloudonixSubscribersClient
    {
        return $this->subscribersClient;
    }

    /**
     * Get the trunks client for direct access.
     */
    public function trunks(): CloudonixTrunksClient
    {
        return $this->trunksClient;
    }

    /**
     * Get the sessions client for direct access.
     */
    public function sessions(): CloudonixSessionsClient
    {
        return $this->sessionsClient;
    }

    /**
     * Get the recordings client for direct access.
     */
    public function recordings(): CloudonixRecordingsClient
    {
        return $this->recordingsClient;
    }
}
