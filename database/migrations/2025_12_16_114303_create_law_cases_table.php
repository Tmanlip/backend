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
            $table->string('caseNumber')->nullable()->unique();

            $table->string('title');
            $table->enum('caseType', ['Litigation', 'Criminal', 'Corporate'])->default('Litigation');
            $table->longText('description');

            $table->enum('status', ['Active', 'Archived'])->default('Active');
            $table->decimal('progress', 5, 2)->default(0);
            $table->decimal('expected_initial_payment', 12, 2)->default(0);
            $table->decimal('expected_first_payment', 12, 2)->default(0);
            $table->decimal('expected_second_payment', 12, 2)->default(0);
            $table->decimal('expected_third_payment', 12, 2)->default(0);
            $table->decimal('expected_final_payment', 12, 2)->default(0);
            $table->decimal('balance_initial_payment', 12, 2)->default(0);
            $table->decimal('balance_first_payment', 12, 2)->default(0);
            $table->decimal('balance_second_payment', 12, 2)->default(0);
            $table->decimal('balance_third_payment', 12, 2)->default(0);
            $table->decimal('balance_final_payment', 12, 2)->default(0);
            $table->decimal('total_balance', 12, 2)->default(0);

            // Numeric IDs -> foreign keys
            $table->foreignId('lawyerID')->constrained('users')->cascadeOnDelete();
            $table->foreignId('clientID')->constrained('users')->cascadeOnDelete();

            // Optional: store firmIDs as reference (no FK)
            $table->string('lawyerFirmID');
            $table->string('clientFirmID');
            $table->string('oppositionLawyerName')->nullable();
            $table->string('oppositionLawyerFirmID')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('law_cases');
    }
};
