<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FileUploadController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\Api\LawCaseController;

// Simple test route
Route::get('/ping', function () {
    return response()->json(['message' => 'Connected to Laravel backend!']);
});

Route::post('/login', [AuthController::class, 'login']);

Route::get('/users', [UserController::class, 'index']);
Route::post('/registerusers', [UserController::class, 'store']);
Route::get('/clients/{firmID}', [UserController::class, 'getClientFullData']);
Route::get('/lawyers/{firmID}', [UserController::class, 'getLawyerFullData']);
Route::get('/admins/{firmID}', [UserController::class, 'getAdminFullData']);

Route::get('/documents', [DocumentController::class, 'index']);
Route::post('/documents/upload', [DocumentController::class, 'upload']);


Route::post('/registercases', [LawCaseController::class, 'store']);
Route::get('/cases', [LawCaseController::class, 'index']);