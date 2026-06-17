<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('mfa_enabled')->default(false)->after('temporary_password_generated_at');
            $table->text('mfa_secret_encrypted')->nullable()->after('mfa_enabled');
            $table->timestamp('mfa_confirmed_at')->nullable()->after('mfa_secret_encrypted');
            $table->json('mfa_recovery_codes')->nullable()->after('mfa_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'mfa_enabled',
                'mfa_secret_encrypted',
                'mfa_confirmed_at',
                'mfa_recovery_codes',
            ]);
        });
    }
};
