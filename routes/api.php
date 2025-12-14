<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FileUploadController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;

// Simple test route
Route::get('/ping', function () {
    return response()->json(['message' => 'Connected to Laravel backend!']);
});

Route::post('/upload', [FileUploadController::class, 'upload']);

Route::post('/login', [AuthController::class, 'login']);

Route::get('/users', [UserController::class, 'index']);

