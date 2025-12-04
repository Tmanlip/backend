<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\Student;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/test-blob', function () {
    Storage::disk('azure')->put('demo2.txt', 'Hello from Laravel!');
    return "Uploaded test.txt to Azure.";
});
