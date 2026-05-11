<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
// Removed references to missing classes to avoid bootstrap errors during local development.
// If you need a storage test route, ensure the controller and model exist before re-enabling.

Route::get('/', function () {
    return view('welcome');
});

// Storage test routes disabled — re-enable after adding StorageController.

use App\Models\Client;

Route::get('/test-mongo', function () {

    Client::create([
        'name' => 'Aiman',
        'email' => 'aiman@test.com',
        'phone' => '0123456789'
    ]);

    return "Inserted into MongoDB!";
});
