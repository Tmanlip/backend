<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    // GET /api/users
    public function index()
    {
        return response()->json(
            User::select('id', 'name', 'email', 'role', 'status', 'firmID')->get()
        );
    }

    // POST /api/registerusers
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            'email'          => 'required|email|unique:users,email',
            'username'          => 'required|string|max:50|unique:users,username',
            'password'       => 'required|min:8',
            'role'           => 'required|in:admin,client,lawyer',
            'age'            => 'required|integer|min:1',
            'ICNumber'       => 'required|string',
            'phoneNumber'    => 'required|string',
            'HomeAddress'    => 'required|string',
            'gender'         => 'required|in:Male,Female',
            'maritalStatus'  => 'required|in:Single,Married,Divorce',
        ]);

        $user = User::create($validated);
        // status = 'Active' automatically from migration
        // firmID auto-generated from model booted()

        return response()->json([
            'message' => 'User created successfully',
            'user' => $user,
        ], 201);
    }
}
