<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\Supplier;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseOrderItem;
use App\Models\User;
use App\Services\InboundReceivingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboundReceivingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_allocates_freight_by_base_unit_and_updates_weighted_average_cost(): void
    {
        $organization = Organization::create([
            'name' => 'Inbound Org',
            'email' => 'inbound@example.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);
        $supplier = Supplier::create([
            'organization_id' => $organization->id,
            'name' => 'China Supplier',
            'currency' => 'CNY',
        ]);
        $user = User::create([
            'name' => 'Inbound Operator',
            'email' => 'inbound-operator@example.com',
            'password' => bcrypt('password'),
            'organization_id' => $organization->id,
            'role' => 'admin',
        ]);
        $this->actingAs($user);
        $productA = Product::create([
            'organization_id' => $organization->id,
            'sku' => 'BASE-A',
            'name' => 'Base A',
            'price' => 10,
            'currency' => 'USD',
            'stock' => 100,
            'min_stock' => 0,
            'is_active' => true,
            'weighted_average_cost_cny' => 10,
        ]);
        $productB = Product::create([
            'organization_id' => $organization->id,
            'sku' => 'BASE-B',
            'name' => 'Base B',
            'price' => 20,
            'currency' => 'USD',
            'stock' => 0,
            'min_stock' => 0,
            'is_active' => true,
            'weighted_average_cost_cny' => 0,
        ]);
        $purchaseOrder = PurchaseOrder::create([
            'organization_id' => $organization->id,
            'supplier_id' => $supplier->id,
            'po_number' => 'PO-INBOUND-001',
            'status' => PurchaseOrder::STATUS_SENT,
            'order_date' => now()->toDateString(),
            'expected_date' => now()->addDays(20)->toDateString(),
            'subtotal' => 2500,
            'tax' => 0,
            'shipping' => 0,
            'total' => 2500,
            'currency' => 'CNY',
            'shipping_method' => 'sea',
            'domestic_freight_cny' => 200,
            'first_leg_freight_cny' => 800,
        ]);
        $itemA = PurchaseOrderItem::create([
            'purchase_order_id' => $purchaseOrder->id,
            'product_id' => $productA->id,
            'product_name' => $productA->name,
            'sku' => $productA->sku,
            'quantity_ordered' => 50,
            'quantity_received' => 0,
            'unit_cost' => 20,
            'subtotal' => 1000,
            'tax' => 0,
            'total' => 1000,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $purchaseOrder->id,
            'product_id' => $productB->id,
            'product_name' => $productB->name,
            'sku' => $productB->sku,
            'quantity_ordered' => 50,
            'quantity_received' => 0,
            'unit_cost' => 30,
            'subtotal' => 1500,
            'tax' => 0,
            'total' => 1500,
        ]);

        $adjustment = app(InboundReceivingService::class)->receiveItem($itemA, 50);

        $this->assertNotNull($adjustment);
        $this->assertSame(150, (int) $productA->fresh()->stock);
        $this->assertSame('30.0000', (string) $itemA->fresh()->landed_unit_cost_cny);
        $this->assertSame('16.6667', (string) $productA->fresh()->weighted_average_cost_cny);
        $this->assertSame(PurchaseOrder::STATUS_PARTIAL, $purchaseOrder->fresh()->status);
    }
}
