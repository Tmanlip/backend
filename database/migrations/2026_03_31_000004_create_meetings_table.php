<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('law_cases', 'caseId')->cascadeOnDelete();
            $table->foreignId('organizer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('lawyerID')->constrained('users')->cascadeOnDelete();
            $table->foreignId('clientID')->constrained('users')->cascadeOnDelete();
            $table->string('meeting_method', 32)->default('Online');
            $table->text('agenda')->nullable();
            $table->string('timezone', 64)->default('Asia/Kuala_Lumpur');
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->string('google_event_id')->nullable()->unique();
            $table->timestamps();

            $table->index(['lawyerID', 'start_at']);
            $table->index(['clientID', 'start_at']);
            $table->index(['case_id', 'start_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meetings');
    }
};
