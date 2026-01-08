<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;

use App\Http\Controllers\Traits\ApiRequestHandler;
use App\Http\Requests\StoreRecordingRequest;
use App\Http\Requests\UpdateRecordingRequest;
use App\Http\Resources\RecordingResource;
use App\Jobs\ProcessRecordingUpload;
use App\Jobs\ValidateRemoteUrl;
use App\Models\Recording;
use App\Services\Recording\RecordingAccessService;
use App\Services\Recording\RecordingRemoteService;
use App\Services\Recording\RecordingUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RecordingsController extends Controller
{
    use ApiRequestHandler;
    public function __construct(
        private readonly RecordingUploadService $uploadService,
        private readonly RecordingRemoteService $remoteService,
        private readonly RecordingAccessService $accessService
    ) {
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $this->getAuthenticatedUser();

        // Handle unauthenticated response
        if ($user instanceof \Illuminate\Http\JsonResponse) {
            // This shouldn't happen for authenticated endpoints, but return error if it does
            abort(401, 'Authentication required');
        }

        // Set the current user ID for resource generation
        RecordingResource::setCurrentUserId($user->id);

        $query = Recording::query();

        // Filter by status if specified
        if ($request->has('status') && in_array($request->status, ['active', 'inactive'])) {
            $query->where('status', $request->status);
        }

        // Search by name if specified
        if ($request->has('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        $recordings = $query->paginate(20);

        return RecordingResource::collection($recordings);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreRecordingRequest $request): \Illuminate\Http\JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        // Handle unauthenticated response
        if ($user instanceof \Illuminate\Http\JsonResponse) {
            return $user;
        }
        $validated = $request->validated();

        try {
            if ($validated['type'] === 'upload') {
                // Handle file upload using the service
                $file = $request->file('file');
                $recording = $this->uploadService->uploadFile($file, $validated['name'], $user);

        // Log the creation
        $this->accessService->logFileAccess($recording, $user->id, 'created', [
            'type' => 'upload',
            'file_size' => $recording->file_size,
            'mime_type' => $recording->mime_type,
        ]);

        // Dispatch job for post-processing (metadata extraction)
        if (config('recordings.queue_processing_enabled', true)) {
            ProcessRecordingUpload::dispatch($recording->id);
        }

                RecordingResource::setCurrentUserId($user->id);
                return response()->json([
                    'message' => 'Recording created successfully',
                    'data' => new RecordingResource($recording->load(['creator', 'updater'])),
                ], 201);

            } else {
                // Handle remote URL
                $validationResult = $this->remoteService->validateUrl($validated['remote_url']);

                if (!$validationResult['success']) {
                    return response()->json([
                        'error' => 'Invalid remote URL',
                        'message' => $validationResult['message'] ?? 'URL validation failed',
                    ], 422);
                }

                $recording = Recording::create([
                    'organization_id' => $user->organization_id,
                    'name' => $validated['name'],
                    'type' => 'remote',
                    'remote_url' => $validated['remote_url'],
                    'status' => 'active',
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]);

                // Log the creation
                $this->accessService->logFileAccess($recording, $user->id, 'created', [
                    'type' => 'remote',
                    'remote_url' => $validated['remote_url'],
                ]);

                // Dispatch job for URL validation and info extraction
                if (config('recordings.queue_processing_enabled', true)) {
                    ValidateRemoteUrl::dispatch($recording->id);
                }
            }

            RecordingResource::setCurrentUserId($user->id);
            return response()->json([
                'message' => 'Recording created successfully',
                'data' => new RecordingResource($recording->load(['creator', 'updater'])),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to create recording',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Recording $recording): \Illuminate\Http\JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        // Handle unauthenticated response
        if ($user instanceof \Illuminate\Http\JsonResponse) {
            return $user;
        }

        RecordingResource::setCurrentUserId($user->id);
        return response()->json([
            'data' => new RecordingResource($recording->load(['creator', 'updater'])),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Recording $recording)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateRecordingRequest $request, Recording $recording): \Illuminate\Http\JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        // Handle unauthenticated response
        if ($user instanceof \Illuminate\Http\JsonResponse) {
            return $user;
        }
        $validated = $request->validated();

        $recording->update(array_merge($validated, [
            'updated_by' => $user->id,
        ]));

        RecordingResource::setCurrentUserId($user->id);
        return response()->json([
            'message' => 'Recording updated successfully',
            'data' => new RecordingResource($recording->load(['creator', 'updater'])),
        ]);
    }

    /**
     * Generate a secure download URL for the specified recording file.
     */
    public function download(Request $request, Recording $recording): \Illuminate\Http\JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        // Handle unauthenticated response
        if ($user instanceof \Illuminate\Http\JsonResponse) {
            return $user;
        }

        if (!$recording->isUploaded()) {
            return response()->json(['error' => 'Only uploaded recordings can be downloaded'], 400);
        }

        // Generate secure access token
        $token = $this->accessService->generateAccessToken($recording, $user->id);

        // Log the access attempt
        $this->accessService->logFileAccess($recording, $user->id, 'download_requested', [
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'download_url' => '/api/v1/recordings/download?token=' . urlencode($token),
            'filename' => $recording->original_filename ?? $recording->file_path,
            'expires_in' => 1800, // 30 minutes
        ]);
    }



    /**
     * Securely serve a recording file using an access token (handles both streaming and downloading).
     */
    public function secureDownload(Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse|\Symfony\Component\HttpFoundation\StreamedResponse|\Illuminate\Http\JsonResponse
    {
        $token = $request->query('token');

        if (!$token) {
            return response()->json(['error' => 'Invalid access token'], 401);
        }

        // For token-based access, we allow access with just the token
        // The token contains user information and is cryptographically validated
        $user = $this->getAuthenticatedUser(); // May be null for token-only access (like audio playback)

        // Handle unauthenticated response
        if ($user instanceof \Illuminate\Http\JsonResponse) {
            // For token-only access, we allow anonymous access with just the token
            $recording = $this->accessService->validateAccessToken($token, null);
        } else {
            $recording = $this->accessService->validateAccessToken($token, $user->id);
        }

        if (!$recording) {
            return response()->json(['error' => 'Access denied or token expired'], 403);
        }

        if (!$recording->file_path) {
            return response()->json(['error' => 'File not found'], 404);
        }

        // Check if file exists in MinIO storage
        $filePath = "{$recording->organization_id}/{$recording->file_path}";
        if (!Storage::disk('recordings')->exists($filePath)) {
            return response()->json(['error' => 'File not found in storage'], 404);
        }

        // Get file content from MinIO
        $fileContent = Storage::disk('recordings')->get($filePath);

        // Extract user ID from token payload for logging (since $user may be null)
        try {
            $decrypted = \Illuminate\Support\Facades\Crypt::decryptString($token);
            $payload = json_decode($decrypted, true);
            $userId = $payload['user_id'] ?? ($user ? $user->id : null);
        } catch (\Exception $e) {
            $userId = $user ? $user->id : null;
        }

        // Determine if this is for streaming (audio playback) or downloading
        // Check Accept header or Sec-Fetch-Dest header
        $isStreaming = $request->header('Sec-Fetch-Dest') === 'audio' ||
                      $request->header('Accept') === 'audio/*' ||
                      str_contains($request->header('Accept', ''), 'audio');

        $accessType = $isStreaming ? 'streamed' : 'downloaded';

        // Log the successful access
        $this->accessService->logFileAccess($recording, $userId, $accessType, [
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'file_size' => strlen($fileContent),
            'access_type' => $user ? 'authenticated' : 'token_only',
            'request_type' => $isStreaming ? 'streaming' : 'download',
        ]);

        $headers = [
            'Content-Type' => $recording->mime_type ?? 'application/octet-stream',
            'Content-Length' => strlen($fileContent),
            'Cache-Control' => 'no-cache',
        ];

        if ($isStreaming) {
            // For streaming (audio playback), don't set Content-Disposition
            $headers['Accept-Ranges'] = 'bytes';
        } else {
            // For downloading, set attachment disposition
            $headers['Content-Disposition'] = 'attachment; filename="' . ($recording->original_filename ?? $recording->file_path) . '"';
        }

        return response()->stream(function () use ($fileContent) {
            echo $fileContent;
        }, 200, $headers);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Recording $recording): \Illuminate\Http\JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        // Handle unauthenticated response
        if ($user instanceof \Illuminate\Http\JsonResponse) {
            return $user;
        }
        // Check if user has permission to delete recordings
        if (!$user->hasRole(UserRole::OWNER) && !$user->hasRole(UserRole::PBX_ADMIN)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Log the deletion attempt

        // Log the deletion attempt
        $this->accessService->logFileAccess($recording, $user->id, 'deleted', [
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        // Delete the file from MinIO storage if it's an uploaded recording
        if ($recording->isUploaded() && $recording->file_path) {
            $filePath = "{$recording->organization_id}/{$recording->file_path}";
            try {
                Storage::disk('recordings')->delete($filePath);
            } catch (\Exception $e) {
                // Log the error but don't fail the deletion - the database record will still be deleted
                Log::warning('Failed to delete file from MinIO storage', [
                    'recording_id' => $recording->id,
                    'file_path' => $filePath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $recording->delete();

        return response()->json([
            'message' => 'Recording deleted successfully',
        ]);
    }

    /**
     * Serve MinIO files for external access (used by Cloudonix for IVR audio files).
     * This is a public endpoint that proxies MinIO files without authentication.
     */
    public function serveMinioFile(Request $request, string $path): \Illuminate\Http\Response
    {
        try {
            // Parse the path to extract organization_id and filename
            $pathParts = explode('/', $path, 2);
            if (count($pathParts) !== 2) {
                return response()->json(['error' => 'Invalid path format'], 400);
            }

            $organization_id = $pathParts[0];
            $filename = $pathParts[1];

            // Convert organization_id to int for proper type handling
            $orgId = (int) $organization_id;

            // Construct the MinIO path
            $filePath = "{$orgId}/{$filename}";

            Log::info('MinIO file request', [
                'path' => $path,
                'organization_id' => $orgId,
                'filename' => $filename,
                'file_path' => $filePath,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

        // Check if file exists in MinIO storage first
        $exists = Storage::disk('recordings')->exists($filePath);
        Log::info('MinIO file existence check', [
            'file_path' => $filePath,
            'exists' => $exists,
        ]);

        $fileContent = null;
        $source = 'minio';

        if ($exists) {
            // Get file content from MinIO
            $fileContent = Storage::disk('recordings')->get($filePath);
        } else {
            // Fallback: check local storage (for legacy IVR files)
            $localPath = "ivr/{$filename}";
            $localExists = Storage::disk('public')->exists($localPath);
            Log::info('Local file fallback check', [
                'local_path' => $localPath,
                'exists' => $localExists,
            ]);

            if ($localExists) {
                $fileContent = Storage::disk('public')->get($localPath);
                $source = 'local';
                Log::info('Serving file from local storage', [
                    'file_path' => $filePath,
                    'local_path' => $localPath,
                ]);
            }
        }

        if (!$fileContent) {
            Log::warning('Audio file not found in any storage', [
                'path' => $path,
                'file_path' => $filePath,
                'local_path' => "ivr/{$filename}",
                'organization_id' => $orgId,
                'filename' => $filename,
            ]);
            return response()->json(['error' => 'File not found'], 404);
        }

        // Get MIME type from file extension or default to audio
        $mimeType = $this->getMimeTypeFromFilename($filename);

        // Log external access for monitoring
        Log::info('External audio file access', [
            'path' => $path,
            'organization_id' => $orgId,
            'filename' => $filename,
            'file_path' => $filePath,
            'source' => $source,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'mime_type' => $mimeType,
        ]);

        return response($fileContent, 200, [
            'Content-Type' => $mimeType,
            'Content-Length' => strlen($fileContent),
            'Cache-Control' => 'public, max-age=300', // Cache for 5 minutes
            'Access-Control-Allow-Origin' => '*', // Allow cross-origin for Cloudonix
        ]);
        } catch (\Exception $e) {
            Log::error('Error serving MinIO file', [
                'path' => $path,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Internal server error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get MIME type from filename extension.
     */
    private function getMimeTypeFromFilename(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($extension) {
            'wav' => 'audio/wav',
            'mp3' => 'audio/mpeg',
            'ogg' => 'audio/ogg',
            'm4a' => 'audio/m4a',
            'aac' => 'audio/aac',
            'flac' => 'audio/flac',
            default => 'audio/wav', // Default fallback
        };
    }
}
