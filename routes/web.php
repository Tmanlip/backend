<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\Student;
use App\Http\Controllers\StorageController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/storage/upload-test', [StorageController::class, 'uploadTest']);
Route::get('/storage/read-test', [StorageController::class, 'readTest']);