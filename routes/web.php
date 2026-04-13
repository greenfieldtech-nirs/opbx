<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Test route for custom error pages - accessible via /error?code=404 (or 403, 405, 500)
Route::get('/error', function () {
    $code = request()->query('code', 404);
    $validCodes = [403, 404, 405, 500];

    if (! in_array((int) $code, $validCodes, true)) {
        $code = 404;
    }

    abort((int) $code);
});
