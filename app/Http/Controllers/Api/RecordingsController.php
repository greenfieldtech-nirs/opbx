<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRecordingRequest;
use App\Http\Requests\UpdateRecordingRequest;
use App\Http\Resources\RecordingResource;
use App\Models\Recording;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RecordingsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Recording::query()
            ->with(['creator', 'updater'])
            ->orderBy('created_at', 'desc');

        // Filter by type if specified
        if ($request->has('type') && in_array($request->type, ['upload', 'remote'])) {
            $query->where('type', $request->type);
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

        if ($validated['type'] === 'upload') {
            // Handle file upload
            $file = $request->file('file');
            $originalName = $file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension();
            $fileSize = $file->getSize();
            $mimeType = $file->getMimeType();

            // Generate unique filename
            $filename = Str::uuid() . '_' . Str::slug(pathinfo($originalName, PATHINFO_FILENAME)) . '.' . $extension;

            // Store file in recordings directory under organization
            $path = $file->storeAs(
                "recordings/{$user->organization_id}",
                $filename,
                'local'
            );

            if (!$path) {
                return response()->json(['error' => 'Failed to store file'], 500);
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
     * Remove the specified resource from storage.
     */
    public function destroy(Recording $recording): JsonResponse
    {
        // Delete the file if it's an uploaded recording
        if ($recording->isUploaded() && $recording->file_path) {
            $filePath = "recordings/{$recording->organization_id}/{$recording->file_path}";
            Storage::disk('local')->delete($filePath);
        }

        $recording->delete();

        return response()->json([
            'message' => 'Recording deleted successfully',
        ]);
    }
}
