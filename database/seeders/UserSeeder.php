<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\LawCase;
use App\Models\Metadata;
use Illuminate\Support\Facades\Hash;
use App\Http\Controllers\AzureController;
use App\Services\InvoiceProgressService;
use App\Services\UserKeyService;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // ----------------------------
        // Create 7 users with AES + RSA keys
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
                'role' => 'junioradmin',
                'age' => 32,
                'ICNumber' => '900202-02-2222',
                'phoneNumber' => '0122222222',
                'HomeAddress' => 'Admin Street 2',
                'gender' => 'Female',
                'maritalStatus' => 'Married',
            ],
            [
                'name' => 'Admin Staff One',
                'email' => 'adminstaff1@example.com',
                'username' => 'adminstaff1',
                'password' => 'password123',
                'role' => 'adminstaff',
                'age' => 31,
                'ICNumber' => '900212-02-2121',
                'phoneNumber' => '0122121212',
                'HomeAddress' => 'Admin Staff Street 1',
                'gender' => 'Male',
                'maritalStatus' => 'Single',
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
                'email' => 'taimanaliff@gmail.com',
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
                'caseType' => 'Litigation',
                'description' => 'Description of Case A',
                'lawyerID' => $lawyer1->id,
                'clientID' => $client1->id,
                'lawyerFirmID' => $lawyer1->firmID,
                'clientFirmID' => $client1->firmID,
                'oppositionLawyerName' => $lawyer2?->name,
                'oppositionLawyerFirmID' => $lawyer2?->firmID,
                'case_type_fee_json' => [
                    'initial' => [
                        [
                            'practiceArea' => 'Civil',
                            'typeOfWork' => 'Case Merits Review',
                            'selectedFee' => 5000,
                            'estimationFeesRange' => '5000 - 25000'
                        ],
                        [
                            'practiceArea' => 'Civil',
                            'typeOfWork' => 'Initial Evidence Review',
                            'selectedFee' => 7000,
                            'estimationFeesRange' => '3000 - 15000'
                        ],
                        [
                            'practiceArea' => 'Civil',
                            'typeOfWork' => 'Client Strategy Consultation',
                            'selectedFee' => 3000,
                            'estimationFeesRange' => '1000 - 5000'
                        ]
                    ],
                    'first' => [
                        [
                            'practiceArea' => 'Civil',
                            'typeOfWork' => 'Demand Letter and Reply',
                            'selectedFee' => 5000,
                            'estimationFeesRange' => '3000 - 10000'
                        ],
                        [
                            'practiceArea' => 'Civil',
                            'typeOfWork' => 'Pre-Action Negotiation',
                            'selectedFee' => 4000,
                            'estimationFeesRange' => '2000 - 12000'
                        ]
                    ],
                    'second' => [
                        [
                            'practiceArea' => 'Civil',
                            'typeOfWork' => 'Settlement Agreement',
                            'selectedFee' => 15000,
                            'estimationFeesRange' => '15000 - 25000'
                        ]
                    ],
                    'third' => [
                        [
                            'practiceArea' => 'Civil',
                            'typeOfWork' => 'Court Process Planning',
                            'selectedFee' => 3000,
                            'estimationFeesRange' => '3000 - 30000'
                        ],
                        [
                            'practiceArea' => 'Civil',
                            'typeOfWork' => 'Witness Preparation',
                            'selectedFee' => 2000,
                            'estimationFeesRange' => '1000 - 8000'
                        ]
                    ],
                    'final' => [
                        [
                            'practiceArea' => 'Civil',
                            'typeOfWork' => 'Premium Retainer Package',
                            'selectedFee' => 15000,
                            'estimationFeesRange' => '15000 per month'
                        ]
                    ]
                ],
                'expected_initial_payment' => 15000,
                'expected_first_payment' => 9000,
                'expected_second_payment' => 15000,
                'expected_third_payment' => 5000,
                'expected_final_payment' => 15000,
            ],
            [
                'title' => 'Case B',
                'caseType' => 'Corporate',
                'description' => 'Description of Case B',
                'lawyerID' => $lawyer2->id,
                'clientID' => $client2->id,
                'lawyerFirmID' => $lawyer2->firmID,
                'clientFirmID' => $client2->firmID,
                'oppositionLawyerName' => 'External Counsel',
                'oppositionLawyerFirmID' => null,
                'case_type_fee_json' => [
                    'initial' => [
                        [
                            'practiceArea' => 'Corporate',
                            'typeOfWork' => 'Company Incorporation Structuring and Restructuring',
                            'selectedFee' => 5000,
                            'estimationFeesRange' => '5000 - 25000'
                        ]
                    ],
                    'first' => [
                        [
                            'practiceArea' => 'Corporate',
                            'typeOfWork' => 'Acquisition Agreement',
                            'selectedFee' => 15000,
                            'estimationFeesRange' => '15000 - 25000'
                        ]
                    ],
                    'second' => [
                        [
                            'practiceArea' => 'Corporate',
                            'typeOfWork' => 'Due Diligence Exercise',
                            'selectedFee' => 50000,
                            'estimationFeesRange' => '10000 - 250000'
                        ]
                    ],
                    'third' => [
                        [
                            'practiceArea' => 'Corporate',
                            'typeOfWork' => 'Employment Agreement',
                            'selectedFee' => 5500,
                            'estimationFeesRange' => '3000 - 25000'
                        ]
                    ],
                    'final' => [
                        [
                            'practiceArea' => 'Corporate',
                            'typeOfWork' => 'Elite Retainer Package',
                            'selectedFee' => 20000,
                            'estimationFeesRange' => '20000 per month'
                        ]
                    ]
                ],
                'expected_initial_payment' => 5000,
                'expected_first_payment' => 15000,
                'expected_second_payment' => 50000,
                'expected_third_payment' => 5500,
                'expected_final_payment' => 20000,
            ],
        ];

        $azure = new AzureController();
        $invoiceProgressService = new InvoiceProgressService();

        foreach ($cases as $data) {
            $caseData = $data;
            $caseData['case_type_fee_json'] = $data['case_type_fee_json'] ?? null;

            $case = LawCase::updateOrCreate(
                ['title' => $data['title']],
                $caseData
            );

            $invoiceProgressService->syncCaseProgress($case);

            // Store MongoDB metadata
            $metadata = Metadata::storeCase(
                (string) $case->caseId,
                $caseData['lawyerFirmID'],
                $caseData['clientFirmID']
            );

            // Create Azure folder structure
            $caseFolder = "cases/{$case->caseId}/";
            $subFolders = ['documents', 'reports', 'invoices'];

            foreach ($subFolders as $folder) {
                $blobName = $caseFolder . $folder . '/placeholder.txt';
                $content = "This folder: {$folder} for case {$case->caseId}";
                $azure->createBlobFromString($blobName, $content);
            }

            // Create metadata.txt
            $metadataJson = json_encode($metadata->toArray(), JSON_PRETTY_PRINT);
            $azure->createBlobFromString($caseFolder . 'metadata.txt', $metadataJson);
        }

        echo "Seeder completed: users, cases with phase-based fee selections, MongoDB metadata, Azure folders, and synced progress created.\n";
    }
}