<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index()
    {
        // Fetch all users
        $users = User::select('id', 'name', 'email', 'role', 'status')->get();

        return response()->json($users);
    }
}
