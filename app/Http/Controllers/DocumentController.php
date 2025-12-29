<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function index()
    {
        $files = Storage::disk('azure')->files();
        return response()->json($files);
    }

    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf|max:10240', // max 10MB
        ]);

        $file = $request->file('file');
        $filename = time() . '_' . $file->getClientOriginalName();

        $path = Storage::disk('azure')->putFileAs('', $file, $filename);

        return response()->json([
            'message' => 'File uploaded successfully',
            'file' => $filename
        ]);
    }
}
