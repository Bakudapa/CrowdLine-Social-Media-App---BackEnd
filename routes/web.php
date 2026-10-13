<?php

use Illuminate\Support\Facades\Route;

// API-only application; see routes/api.php.
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'api' => url('/api/v1'),
]));
