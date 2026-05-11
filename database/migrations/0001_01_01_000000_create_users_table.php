<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('username')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->integer('age');
            $table->string('ICNumber');
            $table->string('key', 64)->nullable();
            $table->string('phoneNumber');
            $table->string('HomeAddress');
            $table->string('firmID')->unique();
            $table->string('otp', 6)->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->text('rsa_public_key')->nullable();
            $table->text('rsa_private_key')->nullable();
            $table->enum('maritalStatus', ['Single', 'Married', 'Divorced'])->default('Single');
            $table->enum('gender', ['Male','Female'])->default('Male');
            $table->enum('role', ['admin', 'junioradmin', 'client', 'lawyer'])->default('client');
            $table->enum('status', ['Active', 'Inactive', 'Archived'])->default('Active');
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};