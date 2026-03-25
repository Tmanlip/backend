<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class UserPicture extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'user_picture';

    protected $fillable = [
        'user_id',
        'firm_id',
        'blob_path',
        'photo_url',
        'mime_type',
        'size_bytes',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'size_bytes' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
