<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('monthly_consumption_reports')) {
            return;
        }
        Schema::create('monthly_consumption_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained('facilities')->onDelete('cascade');
            $table->string('month_year');
            $table->foreignId('generated_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the foreign key constraint first

        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        Schema::dropIfExists('monthly_consumption_reports');
        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    }
};
