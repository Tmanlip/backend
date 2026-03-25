<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FileUploadController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\InteractionLogController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\Api\LawCaseController;
use App\Http\Controllers\Api\EncryptedDocumentController;
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
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('throttle:auth-login');
Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);
Route::post('/password/send-otp', [AuthController::class, 'sendOtp'])->middleware('throttle:auth-otp');
Route::post('/password/verify-code', [AuthController::class, 'verifyOtp'])->middleware('throttle:auth-otp');
Route::post('/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:auth-reset');
Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])->middleware('throttle:auth-reset');
Route::post('/reset-password', [AuthController::class, 'resetNewPassword'])->middleware('throttle:auth-reset');

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    // Users API /api
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/lawyers', [UserController::class, 'getAllLawyers']);
    Route::get('/clients', [UserController::class, 'getAllClients']);
    Route::post('/registerusers', [UserController::class, 'store']);
    Route::get('/clients/{firmID}', [UserController::class, 'getClientFullData']);
    Route::get('/lawyers/{firmID}', [UserController::class, 'getLawyerFullData']);
    Route::get('/admins/{firmID}', [UserController::class, 'getAdminFullData']);
    Route::put('/users/{firmID}', [UserController::class, 'update']);
    Route::get('/user/{firmID}/public-key', [UserController::class, 'getPublicKey']);
    Route::get('/logs/interactions', [InteractionLogController::class, 'index']);

    // Cases API /api
    Route::post('/registercases', [LawCaseController::class, 'store']);
    Route::get('/cases', [LawCaseController::class, 'index']);
    Route::put('/cases/{caseId}', [LawCaseController::class, 'update']);

    // Azure file operations
    Route::post('/upload', [AzureController::class, 'upload']);
    Route::get('/read/{path}', [AzureController::class, 'preview'])->where('path', '.*');
    Route::get('/download/{path}', [AzureController::class, 'download'])->where('path', '.*');
    Route::delete('/delete/{path}', [AzureController::class, 'delete'])->where('path', '.*');
    Route::get('/files', [AzureController::class, 'list']);
    Route::delete('/files', [AzureController::class, 'deleteByQuery']);

    // Encrypted document APIs
    Route::post('/encrypted-documents/upload', [EncryptedDocumentController::class, 'upload']);
    Route::get('/encrypted-documents/{documentId}/payload', [EncryptedDocumentController::class, 'getPayload']);
    Route::get('/encrypted-documents/{documentId}/preview', [EncryptedDocumentController::class, 'preview']);
    Route::get('/encrypted-documents/{documentId}/download', [EncryptedDocumentController::class, 'download']);
    Route::post('/encrypted-documents/{documentId}/share', [EncryptedDocumentController::class, 'share']);
    Route::post('/encrypted-documents/{documentId}/revoke', [EncryptedDocumentController::class, 'revoke']);
    Route::delete('/encrypted-documents/{documentId}', [EncryptedDocumentController::class, 'destroy']);
});

