<?php

declare(strict_types=1);

namespace App\Services\Recording;

use App\Models\Recording;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RecordingUploadService
{
    /**
     * Allowed MIME types for audio files.
     */
    private const ALLOWED_MIME_TYPES = [
        'audio/mpeg',     // .mp3
        'audio/mp3',      // .mp3 (alternative)
        'audio/wav',      // .wav
        'audio/x-wav',    // .wav (alternative)
        'audio/wave',     // .wav (alternative)
    ];

    /**
     * Maximum file size in bytes (from config).
     */
    private const MAX_FILE_SIZE = 5 * 1024 * 1024; // Will be overridden by config in validateFile()

    /**
     * Upload and process an audio file.
     *
     * @param UploadedFile $file The uploaded file
     * @param string $name The recording name
     * @param User $user The user uploading the file
     * @return Recording The created recording
     * @throws \Exception If validation or upload fails
     */
    public function uploadFile(UploadedFile $file, string $name, User $user): Recording
    {
        // Validate the file
        $this->validateFile($file);

        // Generate secure filename
        $originalName = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();
        $filename = $this->generateSecureFilename($originalName, $extension);

        // Store the file in the recordings disk
        Log::info('RecordingUploadService: Storing file', [
            'organization_id' => $user->organization_id,
            'filename' => $filename,
            'disk' => 'recordings'
        ]);

        try {
            $path = $file->storeAs(
                "{$user->organization_id}",
                $filename,
                'recordings'
            );

            Log::info('RecordingUploadService: File stored successfully', [
                'path' => $path,
                'organization_id' => $user->organization_id,
                'filename' => $filename
            ]);

            if (!$path) {
                throw new \Exception('Failed to store the uploaded file.');
            }
        } catch (\Exception $e) {
            Log::error('RecordingUploadService: File storage failed', [
                'error' => $e->getMessage(),
                'organization_id' => $user->organization_id,
                'filename' => $filename,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }

        // Extract metadata
        $metadata = $this->extractMetadata($file);

        Log::info('RecordingUploadService: Creating recording in database', [
            'organization_id' => $user->organization_id,
            'name' => $name,
            'filename' => $filename,
            'file_size' => $file->getSize(),
        ]);

        // Create the recording
        $recording = Recording::create([
            'organization_id' => $user->organization_id,
            'name' => $name,
            'type' => 'upload',
            'file_path' => $filename,
            'original_filename' => $originalName,
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'duration_seconds' => $metadata['duration_seconds'] ?? null,
            'status' => 'active',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Log::info('Recording file uploaded successfully', [
            'recording_id' => $recording->id,
            'filename' => $filename,
            'file_size' => $file->getSize(),
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
        ]);

        return $recording;
    }

    /**
     * Validate the uploaded file.
     *
     * @param UploadedFile $file
     * @throws \Exception If validation fails
     */
    private function validateFile(UploadedFile $file): void
    {
        $maxSize = config('recordings.max_file_size_kb', 5120) * 1024; // Convert KB to bytes
        $allowedMimeTypes = config('recordings.allowed_mime_types', self::ALLOWED_MIME_TYPES);
        $allowedExtensions = config('recordings.allowed_extensions', ['mp3', 'wav']);

        // Check file size
        if ($file->getSize() > $maxSize) {
            $maxSizeMB = $maxSize / (1024 * 1024);
            throw new \Exception("File size exceeds the maximum allowed size of {$maxSizeMB}MB.");
        }

        // Check MIME type
        $mimeType = $file->getMimeType();
        if (!in_array($mimeType, $allowedMimeTypes, true)) {
            throw new \Exception('Invalid file type. Only MP3 and WAV files are allowed.');
        }

        // Additional security check: ensure the file extension matches allowed extensions
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, $allowedExtensions, true)) {
            throw new \Exception('Invalid file extension. Only .mp3 and .wav files are allowed.');
        }

        // Security: Check for malicious file signatures
        $this->checkFileContent($file);

        // Security: Ensure filename doesn't contain dangerous characters
        $originalName = $file->getClientOriginalName();
        if ($this->containsDangerousCharacters($originalName)) {
            throw new \Exception('Filename contains invalid characters.');
        }
    }

    /**
     * Check file content for basic security issues.
     *
     * @param UploadedFile $file
     * @throws \Exception If suspicious content is detected
     */
    private function checkFileContent(UploadedFile $file): void
    {
        // Read first few bytes to check for common file signatures
        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle) {
            $header = fread($handle, 12);
            fclose($handle);

            // Check for MP3 signature (ID3 or MPEG frame)
            if ($file->getMimeType() === 'audio/mpeg') {
                if (!preg_match('/^(ID3|\xFF[\xFB\xF3\xE3])/', $header)) {
                    throw new \Exception('File does not appear to be a valid MP3 file.');
                }
            }

            // Check for WAV signature
            if ($file->getMimeType() === 'audio/wav' || $file->getMimeType() === 'audio/x-wav') {
                if (substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WAVE') {
                    throw new \Exception('File does not appear to be a valid WAV file.');
                }
            }

            // Check for potentially dangerous content (very basic check)
            if (preg_match('/<\?php|<script|javascript:/i', $header)) {
                throw new \Exception('File contains potentially dangerous content.');
            }
        }
    }

    /**
     * Check if filename contains dangerous characters.
     *
     * @param string $filename
     * @return bool
     */
    private function containsDangerousCharacters(string $filename): bool
    {
        // Check for directory traversal attempts
        if (str_contains($filename, '..') || str_contains($filename, '/')) {
            return true;
        }

        // Check for null bytes or other dangerous characters
        if (str_contains($filename, "\0") || str_contains($filename, "\r") || str_contains($filename, "\n")) {
            return true;
        }

        // Check for Windows reserved names
        $reservedNames = ['CON', 'PRN', 'AUX', 'NUL', 'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9', 'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9'];
        $baseName = pathinfo($filename, PATHINFO_FILENAME);
        if (in_array(strtoupper($baseName), $reservedNames, true)) {
            return true;
        }

        return false;
    }

    /**
     * Generate a secure, unique filename.
     *
     * @param string $originalName
     * @param string $extension
     * @return string
     */
    private function generateSecureFilename(string $originalName, string $extension): string
    {
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);
        $sanitizedName = Str::slug($baseName, '_');
        $uniqueId = Str::random(8);

        return $uniqueId . '_' . $sanitizedName . '.' . strtolower($extension);
    }

    /**
     * Extract metadata from the audio file.
     *
     * Note: Since FFMPEG is not available, we'll use basic PHP functions
     * to extract what we can. This is a simplified implementation.
     *
     * @param UploadedFile $file
     * @return array
     */
    private function extractMetadata(UploadedFile $file): array
    {
        $metadata = [];

        try {
            // Get file path for reading
            $filePath = $file->getRealPath();

            if (!$filePath || !file_exists($filePath)) {
                return $metadata;
            }

            // Basic file information
            $fileSize = filesize($filePath);

            // For WAV files, we can extract some basic metadata
            if ($file->getMimeType() === 'audio/wav') {
                $metadata = $this->extractWavMetadata($filePath);
            }

            // For MP3 files, basic size information
            if (str_starts_with($file->getMimeType(), 'audio/mpeg')) {
                // MP3 metadata extraction would require a library like getID3
                // For now, we'll just store basic info
                $metadata['file_size'] = $fileSize;
            }

        } catch (\Exception $e) {
            Log::warning('Failed to extract audio metadata', [
                'error' => $e->getMessage(),
                'filename' => $file->getClientOriginalName(),
            ]);
        }

        return $metadata;
    }

    /**
     * Extract basic metadata from WAV files.
     *
     * @param string $filePath
     * @return array
     */
    private function extractWavMetadata(string $filePath): array
    {
        $metadata = [];

        try {
            $handle = fopen($filePath, 'rb');
            if (!$handle) {
                return $metadata;
            }

            // Read WAV header (44 bytes)
            $header = fread($handle, 44);
            if (strlen($header) >= 24) {
                // Extract sample rate (bytes 24-27)
                $sampleRate = unpack('V', substr($header, 24, 4))[1] ?? 0;

                // Extract data size (bytes 40-43)
                $dataSize = unpack('V', substr($header, 40, 4))[1] ?? 0;

                if ($sampleRate > 0 && $dataSize > 0) {
                    // Calculate duration (rough estimate for uncompressed WAV)
                    // Duration = data_size / (sample_rate * channels * bytes_per_sample)
                    // Assuming 16-bit stereo (most common)
                    $bytesPerSecond = $sampleRate * 2 * 2; // 2 channels, 2 bytes per sample
                    $duration = $dataSize / $bytesPerSecond;

                    $metadata['duration_seconds'] = (int) round($duration);
                }
            }

            fclose($handle);

        } catch (\Exception $e) {
            Log::warning('Failed to extract WAV metadata', [
                'error' => $e->getMessage(),
                'file_path' => $filePath,
            ]);
        }

        return $metadata;
    }
}