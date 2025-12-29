<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('law_cases', function (Blueprint $table) {
            $table->id('caseId');

            $table->string('title');
            $table->longText('description');

            $table->enum('status', ['Active', 'Archived'])->default('Active');

            // Numeric IDs → foreign keys
            $table->foreignId('lawyerID')->constrained('users')->cascadeOnDelete();
            $table->foreignId('clientID')->constrained('users')->cascadeOnDelete();

            // Optional: store firmIDs as reference (no FK)
            $table->string('lawyerFirmID');
            $table->string('clientFirmID');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('law_cases');
    }
};
