<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Set default 0 for quantity on existing monthly_consumption_items table.
     */
    public function up(): void
    {
        if (!Schema::hasTable('monthly_consumption_items')) {
            return;
        }
        DB::statement('ALTER TABLE monthly_consumption_items MODIFY quantity INT NOT NULL DEFAULT 0');
    }

    public function down(): void
    {
        if (!Schema::hasTable('monthly_consumption_items')) {
            return;
        }
        DB::statement('ALTER TABLE monthly_consumption_items MODIFY quantity INT NOT NULL');
    }
};
