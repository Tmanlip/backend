<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FileUploadController extends Controller
{
    public function upload(Request $request)
    {
        $path = Storage::disk('azure')->put(
            'uploads',
            $request->file('file')
        );

        return "Uploaded to: " . $path;
    }
}
