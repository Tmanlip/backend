<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\LawCase;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // ----------------------------
        // Create 6 users
        // ----------------------------
        $users = [
            // Admins
            [
                'name' => 'Admin One',
                'email' => 'admin1@example.com',
                'username' => 'admin1',
                'password' => Hash::make('password123'),
                'role' => 'admin',
                'age' => 30,
                'ICNumber' => '900101-01-1111',
                'phoneNumber' => '0121111111',
                'HomeAddress' => 'Admin Street 1',
                'gender' => 'Male',
                'maritalStatus' => 'Single',
            ],
            [
                'name' => 'Admin Two',
                'email' => 'admin2@example.com',
                'username' => 'admin2',
                'password' => Hash::make('password123'),
                'role' => 'admin',
                'age' => 32,
                'ICNumber' => '900202-02-2222',
                'phoneNumber' => '0122222222',
                'HomeAddress' => 'Admin Street 2',
                'gender' => 'Female',
                'maritalStatus' => 'Married',
            ],

            // Lawyers
            [
                'name' => 'Lawyer One',
                'email' => 'lawyer1@example.com',
                'username' => 'lawyer1',
                'password' => Hash::make('password123'),
                'role' => 'lawyer',
                'age' => 35,
                'ICNumber' => '880303-03-3333',
                'phoneNumber' => '0173333333',
                'HomeAddress' => 'Lawyer Street 1',
                'gender' => 'Male',
                'maritalStatus' => 'Single',
            ],
            [
                'name' => 'Lawyer Two',
                'email' => 'lawyer2@example.com',
                'username' => 'lawyer2',
                'password' => Hash::make('password123'),
                'role' => 'lawyer',
                'age' => 38,
                'ICNumber' => '880404-04-4444',
                'phoneNumber' => '0174444444',
                'HomeAddress' => 'Lawyer Street 2',
                'gender' => 'Female',
                'maritalStatus' => 'Married',
            ],

            // Clients
            [
                'name' => 'Client One',
                'email' => 'client1@example.com',
                'username' => 'client1',
                'password' => Hash::make('password123'),
                'role' => 'client',
                'age' => 28,
                'ICNumber' => '920505-05-5555',
                'phoneNumber' => '0195555555',
                'HomeAddress' => 'Client Street 1',
                'gender' => 'Male',
                'maritalStatus' => 'Single',
            ],
            [
                'name' => 'Client Two',
                'email' => 'client2@example.com',
                'username' => 'client2',
                'password' => Hash::make('password123'),
                'role' => 'client',
                'age' => 29,
                'ICNumber' => '920606-06-6666',
                'phoneNumber' => '0196666666',
                'HomeAddress' => 'Client Street 2',
                'gender' => 'Female',
                'maritalStatus' => 'Married',
            ],
        ];

        foreach ($users as $data) {
            User::updateOrCreate(
                ['email' => $data['email']],
                $data
            );
        }

        // ----------------------------
        // Create 2 cases
        // ----------------------------
        $lawyer1 = User::where('role', 'lawyer')->first();
        $client1 = User::where('role', 'client')->first();

        $lawyer2 = User::where('role', 'lawyer')->skip(1)->first();
        $client2 = User::where('role', 'client')->skip(1)->first();

        $cases = [
            [
                'title' => 'Case A',
                'description' => 'Description of Case A',
                'lawyerID' => $lawyer1->id,
                'clientID' => $client1->id,
                'lawyerFirmID' => $lawyer1->firmID,
                'clientFirmID' => $client1->firmID,
            ],
            [
                'title' => 'Case B',
                'description' => 'Description of Case B',
                'lawyerID' => $lawyer2->id,
                'clientID' => $client2->id,
                'lawyerFirmID' => $lawyer2->firmID,
                'clientFirmID' => $client2->firmID,
            ],
        ];

        foreach ($cases as $data) {
            LawCase::updateOrCreate(
                ['title' => $data['title']],
                $data
            );
        }
    }
}