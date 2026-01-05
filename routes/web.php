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
        // Upload
        $disk->put($filename, $content);

        // Read back (authoritative Azure check)
        $readBack = (string) $disk->get($filename);

        return response()->json([
            'status' => 'SUCCESS',
            'test' => 'Managed Identity Logic',
            'environment' => app()->environment(),

            // Read directly from env to avoid config cache nulls
            'storage_account' => env('AZURE_STORAGE_NAME'),
            'container' => env('AZURE_STORAGE_CONTAINER'),
            'disk_url' => env('AZURE_STORAGE_NAME')
                ? 'https://' . env('AZURE_STORAGE_NAME') . '.blob.core.windows.net/' . env('AZURE_STORAGE_CONTAINER')
                : null,

            'file_path' => $filename,
            'content_verified' => $readBack,
            'result' => 'Upload & read succeeded using configured auth method'
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'FAILED',
            'test' => 'Managed Identity Logic',
            'error' => $e->getMessage(),
            'tip' => 'Check Managed Identity or Storage Account Key permissions'
        ], 500);
    }
}); 

Route::get('/test-azure-path', function () {
    $disk = Storage::disk('azure');

    $folder = 'documents/contracts';
    $filename = $folder . '/doc-' . time() . '.txt';
    $content = 'Document path test at ' . now();

    try {
        // Upload to virtual folder
        $disk->put($filename, $content);

        // Verify by read
        try {
            $readBack = (string) $disk->get($filename);
            $verified = true;
        } catch (\Exception $e) {
            $verified = false;
            $readBack = null;
        }

        return response()->json([
            'status' => 'PROCESS COMPLETE',
            'test' => 'Document Path Resolution',

            // Pulled from env to match your disk config
            'container' => env('AZURE_STORAGE_CONTAINER'),
            'document_path' => $filename,
            'full_blob_url' => env('AZURE_STORAGE_NAME')
                ? 'https://' . env('AZURE_STORAGE_NAME') . '.blob.core.windows.net/'
                    . env('AZURE_STORAGE_CONTAINER') . '/' . $filename
                : null,

            'file_verified' => $verified ? 'YES' : 'NO',
            'content' => $readBack,
            'tip' => $verified
                ? 'Path is correct. Check Azure Portal → Container → documents/contracts/'
                : 'Upload worked but verification failed'
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'FAILED',
            'test' => 'Document Path Resolution',
            'error' => $e->getMessage()
        ], 500);
    }
});

Route::get('/debug-azure-target', function () {
    return response()->json([
        'env_account' => env('AZURE_STORAGE_NAME'),
        'env_container' => env('AZURE_STORAGE_CONTAINER'),
        'env_key_set' => env('AZURE_STORAGE_KEY') ? 'YES' : 'NO',

        'config_account' => config('filesystems.disks.azure.account_name'),
        'config_container' => config('filesystems.disks.azure.container'),

        'app_env' => app()->environment(),
    ]);
});
