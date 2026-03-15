<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FileUploadController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\Api\LawCaseController;
use App\Http\Controllers\AzureController;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

Route::get('/test', function () {
    return 'API WORKS';
});

// Simple test route
Route::get('/ping', function () {
    return response()->json(['message' => 'Connected to Laravel backend!']);
});

//Authentication API /api
Route::post('/login', [AuthController::class, 'login']);
Route::post('/refresh', [AuthController::class, 'refresh']);
Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);
Route::post('/password/send-otp', [AuthController::class, 'sendOtp']);
Route::post('/password/verify-code', [AuthController::class, 'verifyOtp']);
Route::post('/password/reset', [AuthController::class, 'resetPassword']);
Route::post('/forgot-password', [AuthController::class, 'sendResetLink']);
Route::post('/reset-password', [AuthController::class, 'resetNewPassword']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/read/{path}', [AzureController::class, 'preview'])->where('path', '.*');
    Route::get('/download/{path}', [AzureController::class, 'download'])->where('path', '.*');
    Route::post('/upload', [AzureController::class, 'upload']);
});

//Users API /api
Route::get('/users', [UserController::class, 'index']);
Route::get('/lawyers', [UserController::class, 'getAllLawyers']);
Route::get('/clients', [UserController::class, 'getAllClients']);
Route::post('/registerusers', [UserController::class, 'store']);
Route::get('/clients/{firmID}', [UserController::class, 'getClientFullData']);
Route::get('/lawyers/{firmID}', [UserController::class, 'getLawyerFullData']);
Route::get('/admins/{firmID}', [UserController::class, 'getAdminFullData']);
Route::put('/users/{firmID}', [UserController::class, 'update']);

Route::get('/user/{firmID}/public-key', [UserController::class, 'getPublicKey']);

//Cases API /api
Route::post('/registercases', [LawCaseController::class, 'store']);
Route::get('/cases', [LawCaseController::class, 'index']);
Route::put('/cases/{caseId}', [LawCaseController::class, 'update']);

Route::post('/upload', [AzureController::class, 'upload']);      // Upload file
Route::get('/read/{path}', [AzureController::class, 'preview'])->where('path', '.*');;
Route::get('/download/{path}', [AzureController::class, 'download'])->where('path', '.*');;
Route::delete('/delete/{path}', [AzureController::class, 'delete'])->where('path', '.*'); // Delete file
Route::get('/files', [AzureController::class, 'list']);
Route::delete('/files', [AzureController::class, 'deleteByQuery']);

