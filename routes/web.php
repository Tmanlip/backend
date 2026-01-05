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

/*Route::get('/test-azure', function () {
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

Route::get('/test-azure-final', function () {
    try {
        $disk = Storage::disk('azure');
        $filename = 'final-test-' . time() . '.txt';
        
        // 1. Upload
        $disk->put($filename, 'Checking Managed Identity logic');

        // 2. Verify existence without using the url() method
        $exists = $disk->exists($filename);
        
        return response()->json([
            'status' => 'Process Complete',
            'file_was_uploaded' => $exists ? 'YES' : 'NO',
            'filename' => $filename,
            'storage_account' => config('filesystems.disks.azure.account_name'),
            'container' => config('filesystems.disks.azure.container'),
            'tip' => $exists ? 'It works! Check the portal now.' : 'Check your RBAC Roles in Azure.'
        ]);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()]);
    }
});*/

Route::get('/test-azure-mi', function () {
    $disk = Storage::disk('azure');
    $filename = 'mi-test/mi-check-' . now()->timestamp . '.txt';
    $content = 'Managed Identity test at ' . now();

    try {
        // 1. Upload file
        $disk->put($filename, $content);

        // 2. Read file back (BEST verification method for Azure)
        $readBack = $disk->get($filename);

        return response()->json([
            'status' => 'SUCCESS',
            'test' => 'Managed Identity Logic',
            'environment' => app()->environment(),
            'storage_account' => config('filesystems.disks.azure.account_name'),
            'container' => config('filesystems.disks.azure.container'),
            'file_path' => $filename,
            'content_verified' => $readBack,
            'result' => 'Managed Identity + RBAC is working correctly'
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'FAILED',
            'test' => 'Managed Identity Logic',
            'error' => $e->getMessage(),
            'tip' => 'Ensure VM/App Service has Storage Blob Data Contributor role'
        ], 500);
    }
});

Route::get('/test-azure-path', function () {
    $disk = Storage::disk('azure');

    // Simulated document structure
    $folder = 'documents/contracts';
    $filename = $folder . '/doc-' . time() . '.txt';
    $content = 'Document path test at ' . now();

    try {
        // 1. Upload document to virtual folder
        $disk->put($filename, $content);

        // 2. Verify by reading back
        try {
            $readBack = $disk->get($filename);
            $verified = true;
        } catch (\Exception $e) {
            $verified = false;
            $readBack = null;
        }

        return response()->json([
            'status' => 'PROCESS COMPLETE',
            'test' => 'Document Path Resolution',
            'container' => config('filesystems.disks.azure.container'),
            'document_path' => $filename,
            'file_verified' => $verified ? 'YES' : 'NO',
            'content' => $readBack,
            'tip' => $verified
                ? 'Path is correct. Check Azure Portal → Container → documents/contracts/'
                : 'Upload may have succeeded, but read verification failed'
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'FAILED',
            'test' => 'Document Path Resolution',
            'error' => $e->getMessage()
        ], 500);
    }
});
