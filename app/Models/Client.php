<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Client extends Model
{
    protected $connection = 'mongodb';   // important
    protected $collection = 'clients';   // MongoDB collection name

    protected $fillable = [
        'name',
        'email',
        'phone',
    ];
}