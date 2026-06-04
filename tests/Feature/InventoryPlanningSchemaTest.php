<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\Supplier;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryPlanningSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_planning_cost_and_shipping_fields_are_persisted(): void
    {
        $organization = Organization::create([
            'name' => 'Planning Org',
            'email' => 'planning@example.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $supplier = Supplier::create([
            'organization_id' => $organization->id,
            'name' => 'China Supplier',
            'currency' => 'CNY',
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'sku' => 'BASE-001',
            'name' => 'Base Toy',
            'price' => 12.00,
            'currency' => 'USD',
            'stock' => 20,
            'min_stock' => 0,
            'is_active' => true,
            'weighted_average_cost_cny' => 18.1250,
            'packaging_cost_cny' => 1.7500,
        ]);

        $purchaseOrder = PurchaseOrder::create([
            'organization_id' => $organization->id,
            'supplier_id' => $supplier->id,
            'po_number' => 'PO-PLANNING-001',
            'status' => PurchaseOrder::STATUS_SENT,
            'order_date' => now()->toDateString(),
            'expected_date' => now()->addDays(14)->toDateString(),
            'subtotal' => 100,
            'tax' => 0,
            'shipping' => 0,
            'total' => 100,
            'currency' => 'CNY',
            'shipping_method' => 'sea',
            'domestic_freight_cny' => 120.50,
            'first_leg_freight_cny' => 800.25,
        ]);

        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $purchaseOrder->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'quantity_ordered' => 10,
            'quantity_received' => 0,
            'unit_cost' => 18,
            'subtotal' => 180,
            'tax' => 0,
            'total' => 180,
            'landed_unit_cost_cny' => 20.2500,
        ]);

        $this->assertSame('18.1250', (string) $product->fresh()->weighted_average_cost_cny);
        $this->assertSame('1.7500', (string) $product->fresh()->packaging_cost_cny);
        $this->assertSame('sea', $purchaseOrder->fresh()->shipping_method);
        $this->assertSame('120.50', (string) $purchaseOrder->fresh()->domestic_freight_cny);
        $this->assertSame('800.25', (string) $purchaseOrder->fresh()->first_leg_freight_cny);
        $this->assertSame('20.2500', (string) $item->fresh()->landed_unit_cost_cny);
    }
}
