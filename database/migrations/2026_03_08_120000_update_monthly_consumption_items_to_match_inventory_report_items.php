<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Aligns monthly_consumption_items with inventory_report_items structure
     * (excluding warehouse_id, unit_cost, total_cost).
     */
    public function up(): void
    {
        Schema::table('monthly_consumption_items', function (Blueprint $table) {
            // Add new columns after product_id (same order as inventory_report_items, minus warehouse_id and costs)
            $table->string('uom', 255)->nullable()->after('product_id');
            $table->string('batch_number', 255)->nullable()->after('uom');
            $table->date('expiry_date')->nullable()->after('batch_number');
            $table->integer('beginning_balance')->default(0)->after('expiry_date');
            $table->integer('received_quantity')->default(0)->after('beginning_balance');
            $table->integer('issued_quantity')->default(0)->after('received_quantity');
            $table->integer('other_quantity_out')->default(0)->after('issued_quantity');
            $table->integer('positive_adjustment')->default(0)->after('other_quantity_out');
            $table->integer('negative_adjustment')->default(0)->after('positive_adjustment');
            $table->integer('closing_balance')->default(0)->after('negative_adjustment');
            $table->integer('total_closing_balance')->default(0)->after('closing_balance');
            $table->integer('average_monthly_consumption')->default(0)->after('total_closing_balance');
            $table->string('months_of_stock', 255)->nullable()->after('average_monthly_consumption');
            $table->unsignedInteger('stockout_days')->default(0)->after('months_of_stock');
            $table->integer('quantity_in_pipeline')->default(0)->after('stockout_days');
        });

        // Preserve existing quantity data into issued_quantity before dropping
        if (Schema::hasColumn('monthly_consumption_items', 'quantity')) {
            DB::table('monthly_consumption_items')->whereNotNull('quantity')->update([
                'issued_quantity' => DB::raw('COALESCE(quantity, 0)'),
            ]);
            Schema::table('monthly_consumption_items', function (Blueprint $table) {
                $table->dropColumn('quantity');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('monthly_consumption_items', function (Blueprint $table) {
            $table->integer('quantity')->default(0)->after('product_id');
        });

        DB::table('monthly_consumption_items')->update([
            'quantity' => DB::raw('issued_quantity'),
        ]);

        Schema::table('monthly_consumption_items', function (Blueprint $table) {
            $table->dropColumn([
                'uom',
                'batch_number',
                'expiry_date',
                'beginning_balance',
                'received_quantity',
                'issued_quantity',
                'other_quantity_out',
                'positive_adjustment',
                'negative_adjustment',
                'closing_balance',
                'total_closing_balance',
                'average_monthly_consumption',
                'months_of_stock',
                'stockout_days',
                'quantity_in_pipeline',
            ]);
        });
    }
};
