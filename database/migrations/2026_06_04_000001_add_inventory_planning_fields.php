<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('weighted_average_cost_cny', 14, 4)->default(0)->after('purchase_price');
            $table->decimal('packaging_cost_cny', 14, 4)->default(0)->after('weighted_average_cost_cny');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('shipping_method', 10)->nullable()->after('expected_date');
            $table->decimal('domestic_freight_cny', 14, 2)->default(0)->after('shipping');
            $table->decimal('first_leg_freight_cny', 14, 2)->default(0)->after('domestic_freight_cny');
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->decimal('landed_unit_cost_cny', 14, 4)->nullable()->after('unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('landed_unit_cost_cny');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['shipping_method', 'domestic_freight_cny', 'first_leg_freight_cny']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['weighted_average_cost_cny', 'packaging_cost_cny']);
        });
    }
};
