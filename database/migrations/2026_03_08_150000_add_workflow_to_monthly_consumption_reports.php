<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add approval workflow columns to monthly_consumption_reports.
     * Flow: draft → submitted → reviewed → approved | rejected
     */
    public function up(): void
    {
        Schema::table('monthly_consumption_reports', function (Blueprint $table) {
            $table->string('status', 50)->default('draft')->after('month_year');
            $table->timestamp('submitted_at')->nullable()->after('status');
            $table->unsignedBigInteger('submitted_by')->nullable()->after('submitted_at');
            $table->timestamp('reviewed_at')->nullable()->after('submitted_by');
            $table->unsignedBigInteger('reviewed_by')->nullable()->after('reviewed_at');
            $table->timestamp('approved_at')->nullable()->after('reviewed_by');
            $table->unsignedBigInteger('approved_by')->nullable()->after('approved_at');
            $table->timestamp('rejected_at')->nullable()->after('approved_by');
            $table->unsignedBigInteger('rejected_by')->nullable()->after('rejected_at');
            $table->text('rejection_reason')->nullable()->after('rejected_by');
        });
    }

    public function down(): void
    {
        Schema::table('monthly_consumption_reports', function (Blueprint $table) {
            $table->dropColumn([
                'status',
                'submitted_at',
                'submitted_by',
                'reviewed_at',
                'reviewed_by',
                'approved_at',
                'approved_by',
                'rejected_at',
                'rejected_by',
                'rejection_reason',
            ]);
        });
    }
};
