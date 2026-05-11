<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            // Relationships
            $table->foreignId('lawyerID')->constrained('users')->cascadeOnDelete();
            $table->foreignId('clientID')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('case_id');

            // Invoice number
            $table->string('invoice_number')->unique();

            // Payment stage (from law_cases)
            $table->enum('payment_stage', [
                'initial',
                'first',
                'second',
                'third',
                'final'
            ]);

            // Dates
            $table->date('issue_date');
            $table->date('due_date')->nullable();

            // Amounts
            $table->decimal('expected_amount', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('balance', 12, 2)->default(0);

            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2);

            // Snapshot for PDF stability
            $table->string('client_name')->nullable();
            $table->string('case_title')->nullable();

            // PDF storage
            $table->string('blob_path')->nullable();

            $table->timestamps();

            $table->foreign('case_id')
                  ->references('caseId')
                  ->on('law_cases')
                  ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};