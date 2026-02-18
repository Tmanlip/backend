<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class FileMetadata extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'file_metadata';

    protected $fillable = [
        'case_id',
        'file_name',
        'blob_path',
        'encrypted_key',
        'iv',
    ];

    public $timestamps = true;

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /* ================= FILE STORAGE ================= */

    public static function store(
        string $caseId,
        string $fileName,
        string $blobPath,
        string $encryptedKey,
        string $iv
    ): self {

        return self::create([
            'case_id'       => $caseId,
            'file_name'     => $fileName,
            'blob_path'     => $blobPath,
            'encrypted_key' => $encryptedKey,
            'iv'            => $iv,
        ]);
    }
}