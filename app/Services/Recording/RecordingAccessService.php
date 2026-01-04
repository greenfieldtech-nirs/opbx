<?php

declare(strict_types=1);

namespace App\Services\Recording;

use App\Models\Recording;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class RecordingAccessService
{
    /**
     * Generate a secure access token for file download.
     *
     * @param Recording $recording
     * @param int $userId
     * @return string
     */
    public function generateAccessToken(Recording $recording, int $userId): string
    {
        $expiryMinutes = config('recordings.access_token_expiry', 30);

        $payload = [
            'recording_id' => $recording->id,
            'organization_id' => $recording->organization_id,
            'user_id' => $userId,
            'expires_at' => now()->addMinutes($expiryMinutes)->timestamp,
        ];

        $token = Crypt::encryptString(json_encode($payload));

        Log::info('Generated recording access token', [
            'recording_id' => $recording->id,
            'user_id' => $userId,
            'expires_at' => $payload['expires_at'],
        ]);

        return $token;
    }

    /**
     * Validate an access token and return the recording if valid.
     *
     * @param string $token
     * @param int $userId
     * @return Recording|null
     */
    public function validateAccessToken(string $token, int $userId): ?Recording
    {
        try {
            $decrypted = Crypt::decryptString($token);
            $payload = json_decode($decrypted, true);

            if (!$payload ||
                !isset($payload['recording_id'], $payload['organization_id'], $payload['user_id'], $payload['expires_at'])) {
                Log::warning('Invalid access token format', [
                    'user_id' => $userId,
                ]);
                return null;
            }

            // Check token expiry
            if (now()->timestamp > $payload['expires_at']) {
                Log::warning('Expired access token', [
                    'user_id' => $userId,
                    'recording_id' => $payload['recording_id'],
                    'expired_at' => Carbon::createFromTimestamp($payload['expires_at']),
                ]);
                return null;
            }

            // Check user access
            if ($payload['user_id'] !== $userId) {
                Log::warning('Access token user mismatch', [
                    'token_user_id' => $payload['user_id'],
                    'request_user_id' => $userId,
                    'recording_id' => $payload['recording_id'],
                ]);
                return null;
            }

            // Get and validate recording
            $recording = Recording::find($payload['recording_id']);

            if (!$recording ||
                $recording->organization_id !== $payload['organization_id'] ||
                !$recording->isActive()) {
                Log::warning('Invalid recording access', [
                    'recording_id' => $payload['recording_id'],
                    'user_id' => $userId,
                ]);
                return null;
            }

            Log::info('Valid access token used', [
                'recording_id' => $recording->id,
                'user_id' => $userId,
            ]);

            return $recording;

        } catch (\Exception $e) {
            Log::warning('Access token validation failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Log a file access event.
     *
     * @param Recording $recording
     * @param int $userId
     * @param string $action
     * @param array $metadata
     */
    public function logFileAccess(Recording $recording, int $userId, string $action, array $metadata = []): void
    {
        Log::info('Recording file access', array_merge([
            'recording_id' => $recording->id,
            'recording_name' => $recording->name,
            'recording_type' => $recording->type,
            'user_id' => $userId,
            'organization_id' => $recording->organization_id,
            'action' => $action,
            'file_size' => $recording->file_size,
        ], $metadata));
    }

    /**
     * Securely delete a file with overwrite.
     *
     * @param string $filePath
     * @return bool
     */
    public function secureDelete(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return true;
        }

        $enableSecureDelete = config('recordings.enable_secure_delete', true);

        if (!$enableSecureDelete) {
            // Simple delete if secure delete is disabled
            return unlink($filePath);
        }

        try {
            // Get file size
            $fileSize = filesize($filePath);

            // Overwrite file content with random data (secure delete)
            $handle = fopen($filePath, 'wb');
            if ($handle && $fileSize > 0) {
                $randomData = random_bytes(min($fileSize, 1024)); // Overwrite at least first 1KB
                $overwriteSize = min($fileSize, strlen($randomData));
                fwrite($handle, $randomData, $overwriteSize);
                fclose($handle);
            }

            // Then delete the file
            return unlink($filePath);

        } catch (\Exception $e) {
            Log::error('Secure file deletion failed', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);

            // Fallback to regular delete
            return @unlink($filePath);
        }
    }
}