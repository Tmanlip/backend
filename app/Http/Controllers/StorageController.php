<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;

class StorageController extends Controller
{
    public function uploadTest()
    {
        $disk = Storage::disk('azure');

        $disk->put('test.txt', 'Hello Azure');

        return response()->json([
            'status' => 'success',
            'config' => config('filesystems.disks.azure'),
        ]);
    }

    public function readTest()
    {
        // Make sure this path matches what you uploaded
        $path = 'debug/test.txt';

        if (!Storage::disk('azure')->exists($path)) {
            return response()->json([
                'status' => 'error',
                'message' => 'File not found in Azure Blob Storage',
                'path' => $path,
            ], 404);
        }

        $content = Storage::disk('azure')->get($path);

        return response()->json([
            'status' => 'success',
            'content' => $content,
        ]);
    }
}