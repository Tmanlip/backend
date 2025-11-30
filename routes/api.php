<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FileUploadController;

// Simple test route
Route::get('/ping', function () {
    return response()->json(['message' => 'Connected to Laravel backend!']);
});

Route::post('/upload', [FileUploadController::class, 'upload']);
