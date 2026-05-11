<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('law_cases', function (Blueprint $table) {
            // Stores JSON payload grouped by case type (Civil/Corporate/Criminal)
            $table->json('case_type_fee_json')->nullable();
        });

        Schema::table('invoices', function (Blueprint $table) {
            // Snapshot JSON payload grouped by case type (Civil/Corporate/Criminal)
            $table->json('case_type_fee_json')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('law_cases', function (Blueprint $table) {
            $table->dropColumn('case_type_fee_json');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('case_type_fee_json');
        });
    }
};
