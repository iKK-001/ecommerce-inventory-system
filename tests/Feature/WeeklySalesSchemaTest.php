<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WeeklySalesSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_weekly_sales_product_fields_have_defaults_and_decimal_casts(): void
    {
        $organization = Organization::create([
            'name' => 'Weekly Sales Org',
            'email' => 'weekly-sales@example.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $defaultProduct = Product::create([
            'organization_id' => $organization->id,
            'sku' => 'DEFAULT-SELLABLE',
            'name' => 'Default Sellable Product',
            'price' => 20,
            'stock' => 10,
            'min_stock' => 0,
            'is_active' => true,
        ])->fresh();

        $costedProduct = Product::create([
            'organization_id' => $organization->id,
            'sku' => 'COSTED-SELLABLE',
            'name' => 'Costed Sellable Product',
            'price' => 20,
            'stock' => 10,
            'min_stock' => 0,
            'is_active' => true,
            'is_sellable' => false,
            'last_mile_cost_usd' => 2.1500,
            'packing_labor_cost_cny' => 1.7500,
        ])->fresh();

        $this->assertTrue($defaultProduct->is_sellable);
        $this->assertSame('0.0000', (string) $defaultProduct->last_mile_cost_usd);
        $this->assertSame('0.0000', (string) $defaultProduct->packing_labor_cost_cny);
        $this->assertFalse($costedProduct->is_sellable);
        $this->assertSame('2.1500', (string) $costedProduct->last_mile_cost_usd);
        $this->assertSame('1.7500', (string) $costedProduct->packing_labor_cost_cny);
    }
}
