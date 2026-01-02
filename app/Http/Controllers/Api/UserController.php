<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use App\Models\LawCase;

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
            'username'       => 'required|string|max:50|unique:users,username',
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

        return response()->json([
            'message' => 'User created successfully',
            'user' => $user,
        ], 201);
    }

    // ============================
    // CLIENT FULL DATA
    // GET /api/clients/{firmID}
    // ============================
    public function getClientFullData(string $firmID)
    {
        $client = User::where('firmID', $firmID)
            ->where('role', 'client')
            ->first();

        if (!$client) {
            return response()->json(['message' => 'Client not found'], 404);
        }

        $cases = LawCase::where('clientID', $client->id)
            ->with('lawyer:id,name')
            ->get()
            ->map(function ($case) {
                return [
                    'caseId'      => $case->caseId,
                    'title'       => $case->title,
                    'description' => $case->description,
                    'status'      => $case->status,
                    'clientName' => $case->client?->name,
                    'lawyerName'  => $case->lawyer?->name,
                    'created_at'  => $case->created_at,
                ];
            });

        return response()->json([
            'client' => [
                'id'            => $client->id,
                'firmID'        => $client->firmID,
                'name'          => $client->name,
                'email'         => $client->email,
                'username'      => $client->username,
                'age'           => $client->age,
                'ICNumber'      => $client->ICNumber,
                'phoneNumber'   => $client->phoneNumber,
                'HomeAddress'   => $client->HomeAddress,
                'gender'        => $client->gender,
                'maritalStatus' => $client->maritalStatus,
                'status'        => $client->status,
                'created_at'    => $client->created_at,
            ],
            'cases' => $cases
        ]);
    }

    // ============================
    // LAWYER FULL DATA (NEW)
    // GET /api/lawyers/{firmID}
    // ============================
    public function getLawyerFullData(string $firmID)
    {
        $lawyer = User::where('firmID', $firmID)
            ->where('role', 'lawyer')
            ->first();

        if (!$lawyer) {
            return response()->json(['message' => 'Lawyer not found'], 404);
        }

        $cases = LawCase::where('lawyerID', $lawyer->id)
            ->with('client:id,name')
            ->get()
            ->map(function ($case) {
                return [
                    'caseId'     => $case->caseId,
                    'title'      => $case->title,
                    'description'=> $case->description,
                    'status'     => $case->status,
                    'clientName' => $case->client?->name,
                    'lawyerName'  => $case->lawyer?->name,
                    'created_at' => $case->created_at,
                ];
            });

        return response()->json([
            'lawyer' => [
                'id'            => $lawyer->id,
                'firmID'        => $lawyer->firmID,
                'name'          => $lawyer->name,
                'email'         => $lawyer->email,
                'username'      => $lawyer->username,
                'age'           => $lawyer->age,
                'ICNumber'      => $lawyer->ICNumber,
                'phoneNumber'   => $lawyer->phoneNumber,
                'HomeAddress'   => $lawyer->HomeAddress,
                'gender'        => $lawyer->gender,
                'maritalStatus' => $lawyer->maritalStatus,
                'status'        => $lawyer->status,
                'created_at'    => $lawyer->created_at,
            ],
            'cases' => $cases
        ]);
    }

    // ============================
    // ADMIN FULL DATA (NEW)
    // GET /api/admins/{firmID}
    // ============================
    public function getAdminFullData(string $firmID)
    {
        $admin = User::where('firmID', $firmID)
            ->where('role', 'admin')
            ->first();

        if (!$admin) {
            return response()->json(['message' => 'Admin not found'], 404);
        }

        // admins may not have cases, but we keep the structure consistent
        return response()->json([
            'admin' => [
                'id'            => $admin->id,
                'firmID'        => $admin->firmID,
                'name'          => $admin->name,
                'email'         => $admin->email,
                'username'      => $admin->username,
                'age'           => $admin->age,
                'ICNumber'      => $admin->ICNumber,
                'phoneNumber'   => $admin->phoneNumber,
                'HomeAddress'   => $admin->HomeAddress,
                'gender'        => $admin->gender,
                'maritalStatus' => $admin->maritalStatus,
                'status'        => $admin->status,
                'created_at'    => $admin->created_at,
            ],
            'cases' => [], // empty array
        ]);
    }
}