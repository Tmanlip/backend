<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/testazure', function () {
    try {
        Storage::disk('azure')->put('demo.txt', 'Hello from Laravel! This is Tengku Aiman');
        return 'Azure Blob Storage connection is working.';
    } catch (\Exception $e) {
        return 'Error: ' . $e->getMessage();
    }
});