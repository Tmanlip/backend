<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => Hash::make('password123'),
            'role' => 'client',
            'status' => 'Active',
            'age' => 21,
            'ICNumber' => '990101-01-1234',
            'phoneNumber' => '0123456789',
            'HomeAddress' => 'Test Address',
            'gender' => 'Male',
            'maritalStatus' => 'Single',
        ];
    }
}
