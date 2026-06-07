<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FileUploadController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\InteractionLogController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\Api\LawCaseController;
use App\Http\Controllers\Api\MeetingController;
use App\Http\Controllers\Api\EncryptedDocumentController;
use App\Http\Controllers\Api\DocumentGeneratorController;
use App\Http\Controllers\Api\ChatbotController;
use App\Http\Controllers\AzureController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\RealtimeController;
use App\Http\Middleware\CheckArchivedUser;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

Route::get('/test', function () {
    return 'API WORKS';
});

// Simple test route
Route::get('/ping', function () {
    return response()->json(['message' => 'Connected to Laravel backend!']);
});

// Public helper: generate invoice number (kept public for document-generator preview)
Route::post('/invoices/generate-number', [InvoiceController::class, 'generateInvoiceNumber']);

Route::middleware('throttle:api')->group(function () {
    Route::post('/ask', [ChatbotController::class, 'ask']);
    Route::get('/chats', [ChatbotController::class, 'chats']);
    Route::get('/db-health', [ChatbotController::class, 'dbHealth']);

    Route::prefix('/document-generator')->group(function () {
        Route::get('/health', [DocumentGeneratorController::class, 'health']);
        Route::get('/templates/visibility', [DocumentGeneratorController::class, 'getTemplateVisibility']);
        Route::post('/generate', [DocumentGeneratorController::class, 'ask']);
        Route::post('/generate-lod-docx', [DocumentGeneratorController::class, 'generateLodDocx']);
        Route::post('/generate-lod-pdf', [DocumentGeneratorController::class, 'generateLodPdf']);
        Route::post('/generate-lod-data-xlsx', [DocumentGeneratorController::class, 'generateLodDataXlsx']);
        Route::post('/generate-writ-docx', [DocumentGeneratorController::class, 'generateWritDocx']);
        Route::post('/generate-invoice-docx', [DocumentGeneratorController::class, 'generateInvoiceDocx']);
        Route::post('/generate-invoice-pdf', [DocumentGeneratorController::class, 'generateInvoicePdf']);
        Route::post('/generate-writ-data-xlsx', [DocumentGeneratorController::class, 'generateWritDataXlsx']);
    });

    // Public reference data for case registration dropdowns
    Route::get('/case-type-work-options', [LawCaseController::class, 'typeOfWorkOptions']);
});

//Authentication API /api
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('throttle:auth-login');
Route::post('/password/send-otp', [AuthController::class, 'sendOtp'])->middleware('throttle:auth-otp');
Route::post('/password/verify-code', [AuthController::class, 'verifyOtp'])->middleware('throttle:auth-otp');
Route::post('/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:auth-reset');
Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])->middleware('throttle:auth-reset');
Route::post('/reset-password', [AuthController::class, 'resetNewPassword'])->middleware('throttle:auth-reset');

// Protected routes - archived users blocked from these
Route::middleware(['auth:sanctum', 'throttle:api', CheckArchivedUser::class])->group(function () {
    // Logout (allowed for archived users)
    Route::post('/logout', [AuthController::class, 'logout']);
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
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/mark-read', [NotificationController::class, 'markRead']);
    Route::post('/realtime/negotiate', [RealtimeController::class, 'negotiate']);

    // Cases API /api
    Route::post('/registercases', [LawCaseController::class, 'store']);
    Route::get('/cases', [LawCaseController::class, 'index']);
    Route::get('/cases/{caseId}', [LawCaseController::class, 'show']);
    Route::put('/cases/{caseId}', [LawCaseController::class, 'update']);

    // Meetings API /api
    Route::get('/meetings', [MeetingController::class, 'index']);
    Route::post('/meetings', [MeetingController::class, 'store']);

    // Azure file operations
    Route::post('/upload', [AzureController::class, 'upload']);
    Route::get('/read/{path}', [AzureController::class, 'preview'])->where('path', '.*');
    Route::get('/download/{path}', [AzureController::class, 'download'])->where('path', '.*');
    Route::delete('/delete/{path}', [AzureController::class, 'delete'])->where('path', '.*');
    Route::get('/files', [AzureController::class, 'list']);
    Route::delete('/files', [AzureController::class, 'deleteByQuery']);
    Route::apiResource('invoices', InvoiceController::class);

    Route::put('/document-generator/templates/visibility', [DocumentGeneratorController::class, 'updateTemplateVisibility']);

    // Encrypted document APIs
    Route::post('/encrypted-documents/upload', [EncryptedDocumentController::class, 'upload']);
    Route::get('/encrypted-documents/{documentId}/payload', [EncryptedDocumentController::class, 'getPayload']);
    Route::get('/encrypted-documents/{documentId}/preview', [EncryptedDocumentController::class, 'preview']);
    Route::get('/encrypted-documents/{documentId}/download', [EncryptedDocumentController::class, 'download']);
    Route::post('/encrypted-documents/{documentId}/share', [EncryptedDocumentController::class, 'share']);
    Route::post('/encrypted-documents/{documentId}/revoke', [EncryptedDocumentController::class, 'revoke']);
    Route::post('/encrypted-documents/{documentId}/review', [EncryptedDocumentController::class, 'review']);
    Route::delete('/encrypted-documents/{documentId}', [EncryptedDocumentController::class, 'destroy']);
    Route::get('/encrypted-documents/{documentId}/invoice', [EncryptedDocumentController::class, 'invoiceForDocument']);
    Route::put('/encrypted-documents/{documentId}/invoice', [EncryptedDocumentController::class, 'updateInvoiceForDocument']);
});

