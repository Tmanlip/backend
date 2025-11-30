<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FileUploadController extends Controller
{
    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:10240', // 10 MB
        ]);

        $file = $request->file('file');
        $path = 'uploads/' . time() . '_' . $file->getClientOriginalName();

        // Upload to Azure Blob
        Storage::disk('azure')->put($path, file_get_contents($file));

        // Generate public URL
        $url = "https://" . env('AZURE_STORAGE_NAME') . ".blob.core.windows.net/" .
               env('AZURE_STORAGE_CONTAINER') . "/" . $path;

        return response()->json([
            'success' => true,
            'path' => $path,
            'url' => $url
        ]);
    }
}