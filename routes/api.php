<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Simple test route
Route::get('/ping', function () {
    return response()->json(['message' => 'Connected to Laravel backend!']);
});
