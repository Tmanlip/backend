<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\Student;
use App\Http\Controllers\FileUploadController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/test-blob', function () {
    Storage::disk('azure')->put('demo2.txt', 'Hello from Laravel!');
    return "Uploaded test.txt to Azure.";
});

Route::get('/test-azure', function () {
    $filename = 'test-file-' . now()->timestamp . '.txt';
    $content = 'Testing Azure Storage at ' . now();

    try {
        // 1. Attempt to write
        Storage::disk('azure')->put($filename, $content);

        // 2. Attempt to read back
        $output = Storage::disk('azure')->get($filename);

        return response()->json([
            'status' => 'Success!',
            'environment' => app()->environment(),
            'file_created' => $filename,
            'content_verified' => $output
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'Failed',
            'error' => $e->getMessage(),
            'tip' => 'Check if your Managed Identity has "Storage Blob Data Contributor" role.'
        ], 500);
    }
});