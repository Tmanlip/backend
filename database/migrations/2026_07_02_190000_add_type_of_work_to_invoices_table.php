<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('invoices')) {
            return;
        }

        if (!Schema::hasColumn('invoices', 'type_of_work')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->string('type_of_work')->nullable()->after('case_title');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('invoices')) {
            return;
        }

        if (Schema::hasColumn('invoices', 'type_of_work')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropColumn('type_of_work');
            });
        }
    }
};
