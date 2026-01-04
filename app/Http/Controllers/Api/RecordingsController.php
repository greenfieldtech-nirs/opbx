<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RecordingsController extends Controller
{
    public function __construct(
        private readonly RecordingUploadService $uploadService,
        private readonly RecordingRemoteService $remoteService,
        private readonly RecordingAccessService $accessService
    ) {
    }

        // Filter by status if specified
        if ($request->has('status') && in_array($request->status, ['active', 'inactive'])) {
            $query->where('status', $request->status);
        }

        // Search by name if specified
        if ($request->has('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        $recordings = $query->paginate(20);

        return response()->json([
            'data' => RecordingResource::collection($recordings),
            'meta' => [
                'current_page' => $recordings->currentPage(),
                'last_page' => $recordings->lastPage(),
                'per_page' => $recordings->perPage(),
                'total' => $recordings->total(),
            ],
        ]);
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
    public function store(StoreRecordingRequest $request): JsonResponse
    {
        $user = $request->user();
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

            $recording = Recording::create([
                'organization_id' => $user->organization_id,
                'name' => $validated['name'],
                'type' => 'upload',
                'file_path' => $filename,
                'original_filename' => $originalName,
                'file_size' => $fileSize,
                'mime_type' => $mimeType,
                'status' => 'active',
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
        } else {
            // Handle remote URL
            $recording = Recording::create([
                'organization_id' => $user->organization_id,
                'name' => $validated['name'],
                'type' => 'remote',
                'remote_url' => $validated['remote_url'],
                'status' => 'active',
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
        }

        return response()->json([
            'message' => 'Recording created successfully',
            'data' => new RecordingResource($recording->load(['creator', 'updater'])),
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Recording $recording): JsonResponse
    {
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
    public function update(UpdateRecordingRequest $request, Recording $recording): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $recording->update(array_merge($validated, [
            'updated_by' => $user->id,
        ]));

        return response()->json([
            'message' => 'Recording updated successfully',
            'data' => new RecordingResource($recording->load(['creator', 'updater'])),
        ]);
    }

    /**
     * Generate a secure download URL for the specified recording file.
     */
    public function download(Request $request, Recording $recording): JsonResponse
    {
        $user = $request->user();

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
            'download_url' => route('recordings.secure-download', ['token' => $token]),
            'filename' => $recording->original_filename ?? $recording->file_path,
            'expires_in' => 1800, // 30 minutes
        ]);
    }

    /**
     * Securely download a recording file using an access token.
     */
    public function secureDownload(Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\JsonResponse
    {
        $token = $request->query('token');
        $user = $request->user();

        if (!$token || !$user) {
            return response()->json(['error' => 'Invalid access token'], 401);
        }

        $recording = $this->accessService->validateAccessToken($token, $user->id);

        if (!$recording) {
            return response()->json(['error' => 'Access denied or token expired'], 403);
        }

        if (!$recording->file_path) {
            return response()->json(['error' => 'File not found'], 404);
        }

        $filePath = storage_path("app/recordings/{$recording->organization_id}/{$recording->file_path}");

        if (!file_exists($filePath)) {
            return response()->json(['error' => 'File not found on disk'], 404);
        }

        // Log the successful download
        $this->accessService->logFileAccess($recording, $user->id, 'downloaded', [
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'file_size' => $recording->file_size,
        ]);

        return response()->download($filePath, $recording->original_filename ?? $recording->file_path);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Recording $recording): JsonResponse
    {
        $user = $request->user();

        // Log the deletion attempt
        $this->accessService->logFileAccess($recording, $user->id, 'deleted', [
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        // Securely delete the file if it's an uploaded recording
        if ($recording->isUploaded() && $recording->file_path) {
            $filePath = storage_path("app/recordings/{$recording->organization_id}/{$recording->file_path}");
            $this->accessService->secureDelete($filePath);
        }

        $recording->delete();

        return response()->json([
            'message' => 'Recording deleted successfully',
        ]);
    }
}
