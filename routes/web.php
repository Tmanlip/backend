<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/testazure', function () {
    try {
        $result = Storage::disk('azure')->put('demo.txt', 'hello azure');
        return $result ? 'upload success' : 'upload failed';
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
});

Route::get('/debugazure', function () {
    return config('filesystems.disks.azure');
});