<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\LawCase;
use App\Models\Metadata;
use Illuminate\Support\Facades\Hash;
use App\Http\Controllers\AzureController;
use App\Services\UserKeyService;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // ----------------------------
        // Create 6 users with AES + RSA keys
        // ----------------------------
        $users = [
            // Admins
            [
                'name' => 'Admin One',
                'email' => 'admin1@example.com',
                'username' => 'admin1',
                'password' => 'password123',
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
                'password' => 'password123',
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
                'password' => 'password123',
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
                'password' => 'password123',
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
                'password' => 'password123',
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
                'password' => 'password123',
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

            // ----------------------------
            // Generate AES key for document encryption
            // ----------------------------
            $data['key'] = UserKeyService::generateKey();

            // ----------------------------
            // Generate RSA key pair
            // ----------------------------
            $rsaKeys = UserKeyService::generateRsaKeyPair();
            $data['rsa_private_key'] = $rsaKeys['encryptedPrivateKey'];
            $data['rsa_public_key'] = $rsaKeys['publicKey'];

            // Hash password
            $data['password'] = Hash::make($data['password']);

            // Create or update user
            User::updateOrCreate(
                ['email' => $data['email']],
                $data
            );
        }

        // ----------------------------
        // Create 2 cases with MongoDB metadata and Azure folders
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

        $azure = new AzureController();

        foreach ($cases as $data) {
            $case = LawCase::updateOrCreate(
                ['title' => $data['title']],
                $data
            );

            // Store MongoDB metadata
            $metadata = Metadata::storeCase(
                (string) $case->caseId,
                $data['lawyerFirmID'],
                $data['clientFirmID']
            );

            // Create Azure folder structure
            $caseFolder = "cases/{$case->caseId}/";
            $subFolders = ['documents', 'reports', 'cheques'];

            foreach ($subFolders as $folder) {
                $blobName = $caseFolder . $folder . '/placeholder.txt';
                $content = "This folder: {$folder} for case {$case->caseId}";
                $azure->createBlobFromString($blobName, $content);
            }

            // Create metadata.txt
            $metadataJson = json_encode($metadata->toArray(), JSON_PRETTY_PRINT);
            $azure->createBlobFromString($caseFolder . 'metadata.txt', $metadataJson);
        }

        echo "Seeder completed: users with AES+RSA keys, cases, MongoDB metadata, and Azure folders created.\n";
    }
}