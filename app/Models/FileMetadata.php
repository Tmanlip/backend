<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class FileMetadata extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'file_metadata';

    protected $fillable = [
        'type',
        'case_id',
        'category',
        'uploader_user_id',
        'file_name',
        'mime_type',
        'size_bytes',
        'blob_path',
        'content_hash_sha256',
        'cipher',
        'nonce',
        'tag',
        'server_encrypted_dek',
        'dek_version',
        'status',
        'recipients',
        'encrypted_key',
        'iv',
        'invoice_stage',
        'type_of_work',
        'expected_amount',
        'paid_amount',
    ];

    public $timestamps = true;

    protected $casts = [
        'case_id' => 'integer',
        'uploader_user_id' => 'integer',
        'size_bytes' => 'integer',
        'dek_version' => 'integer',
        'expected_amount' => 'float',
        'paid_amount' => 'float',
        'recipients' => 'array',
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
            'type'          => 'legacy_file',
            'case_id'       => $caseId,
            'file_name'     => $fileName,
            'blob_path'     => $blobPath,
            'encrypted_key' => $encryptedKey,
            'iv'            => $iv,
            'status'        => 'active',
        ]);
    }
}