<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Public routes for external services (Cloudonix) to access audio files
Route::get('storage/recordings/{path}', [\App\Http\Controllers\Api\RecordingsController::class, 'serveMinioFile'])
    ->name('storage.recordings.serve')
    ->where('path', '[0-9]+/.+');
