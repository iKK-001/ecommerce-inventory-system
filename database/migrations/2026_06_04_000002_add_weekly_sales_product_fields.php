<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_sellable')->default(true)->after('is_active');
            $table->decimal('last_mile_cost_usd', 14, 4)->default(0)->after('packaging_cost_cny');
            $table->decimal('packing_labor_cost_cny', 14, 4)->default(0)->after('last_mile_cost_usd');

            $table->index(['organization_id', 'is_active', 'is_sellable'], 'products_org_active_sellable_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_org_active_sellable_index');
            $table->dropColumn(['is_sellable', 'last_mile_cost_usd', 'packing_labor_cost_cny']);
        });
    }
};
