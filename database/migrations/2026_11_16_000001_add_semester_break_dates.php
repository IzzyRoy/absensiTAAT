<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('semesters', function (Blueprint $table) {
            $table->date('holiday_start_date')->nullable()->after('end_date');
            $table->date('holiday_end_date')->nullable()->after('holiday_start_date');
        });
    }

    public function down(): void
    {
        Schema::table('semesters', function (Blueprint $table) {
            $table->dropColumn(['holiday_start_date', 'holiday_end_date']);
        });
    }
};
