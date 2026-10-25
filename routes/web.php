<?php

declare(strict_types=1);

use App\Http\Controllers\EmbedDialerController;
use Illuminate\Support\Facades\Route;

// nginx serves the site root from the frontend; this only answers direct hits on the PHP app.
Route::redirect('/', '/ui');

// Embedded dialer: iframe document (per-token frame-ancestors CSP) + host loader.
Route::get('/embed/dialer', [EmbedDialerController::class, 'dialer'])->name('embed.dialer');
Route::get('/embed/loader.js', [EmbedDialerController::class, 'loader'])->name('embed.loader');

// Test route for custom error pages - accessible via /error?code=404 (or 403, 405, 500)
Route::get('/error', function () {
    $code = request()->query('code', 404);
    $validCodes = [403, 404, 405, 500];

    if (! in_array((int) $code, $validCodes, true)) {
        $code = 404;
    }

    abort((int) $code);
});
