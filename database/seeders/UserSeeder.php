<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password123'),
                'role' => 'admin',
            ]
        );

        User::updateOrCreate(
            ['email' => 'client@example.com'],
            [
                'name' => 'Client User',
                'password' => Hash::make('password123'),
                'role' => 'client',
            ]
        );

        User::updateOrCreate(
            ['email' => 'lawyer@example.com'],
            [
                'name' => 'Lawyer User',
                'password' => Hash::make('password123'),
                'role' => 'lawyer',
            ]
        );
    }
}
