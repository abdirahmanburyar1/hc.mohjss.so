<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Merge columns from monthly_consumption_reports/items into facility_monthly_reports/items.
     * LMIS report uses facility_monthly_reports and facility_monthly_report_items; these columns align with that structure.
     */
    public function up(): void
    {
        Schema::table('facility_monthly_reports', function (Blueprint $table) {
            if (!Schema::hasColumn('facility_monthly_reports', 'generated_by')) {
                $table->unsignedBigInteger('generated_by')->nullable()->after('report_period');
            }
            if (!Schema::hasColumn('facility_monthly_reports', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('rejected_by');
            }
        });

        Schema::table('facility_monthly_report_items', function (Blueprint $table) {
            if (!Schema::hasColumn('facility_monthly_report_items', 'uom')) {
                $table->string('uom', 255)->nullable()->after('product_id');
            }
            if (!Schema::hasColumn('facility_monthly_report_items', 'batch_number')) {
                $table->string('batch_number', 255)->nullable()->after('uom');
            }
            if (!Schema::hasColumn('facility_monthly_report_items', 'expiry_date')) {
                $table->date('expiry_date')->nullable()->after('batch_number');
            }
            if (!Schema::hasColumn('facility_monthly_report_items', 'other_quantity_out')) {
                $table->decimal('other_quantity_out', 15, 2)->default(0)->after('stock_issued');
            }
            if (!Schema::hasColumn('facility_monthly_report_items', 'total_closing_balance')) {
                $table->decimal('total_closing_balance', 15, 2)->default(0)->after('closing_balance');
            }
            if (!Schema::hasColumn('facility_monthly_report_items', 'average_monthly_consumption')) {
                $table->decimal('average_monthly_consumption', 15, 2)->default(0)->after('total_closing_balance');
            }
            if (!Schema::hasColumn('facility_monthly_report_items', 'months_of_stock')) {
                $table->string('months_of_stock', 255)->nullable()->after('average_monthly_consumption');
            }
            if (!Schema::hasColumn('facility_monthly_report_items', 'quantity_in_pipeline')) {
                $table->decimal('quantity_in_pipeline', 15, 2)->default(0)->after('stockout_days');
            }
        });
    }

    public function down(): void
    {
        Schema::table('facility_monthly_reports', function (Blueprint $table) {
            $columns = ['generated_by', 'rejection_reason'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('facility_monthly_reports', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('facility_monthly_report_items', function (Blueprint $table) {
            $columns = [
                'uom', 'batch_number', 'expiry_date', 'other_quantity_out',
                'total_closing_balance', 'average_monthly_consumption', 'months_of_stock', 'quantity_in_pipeline',
            ];
            foreach ($columns as $col) {
                if (Schema::hasColumn('facility_monthly_report_items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
