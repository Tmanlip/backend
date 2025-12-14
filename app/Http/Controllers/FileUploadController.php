<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FileUploadController extends Controller
{
    // Show upload form
    public function uploadForm()
    {
        return view('upload');
    }

    // Handle file upload
    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:51200', // 50MB max
        ]);

        $file = $request->file('file');
        $filename = time() . '-' . $file->getClientOriginalName();

        // Upload to Azure Blob
        Storage::disk('azure')->put($filename, file_get_contents($file));

        return back()->with('success', 'File uploaded to Azure: ' . $filename);
    }

    // List files inside container
    public function listFiles()
    {
        $files = Storage::disk('azure')->allFiles();

        $fileUrls = [];
        foreach ($files as $file) {
            $fileUrls[] = [
                'name' => $file,
                'endpoint'  => Storage::disk('azure')->endpoint($file),
            ];
        }

        return response()->json($fileUrls);
    }
}
