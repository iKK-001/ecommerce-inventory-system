<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductComponent;
use App\Models\Inventory\Supplier;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseOrderItem;
use App\Services\InventoryPlanningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryPlanningServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_converts_pack_sales_to_base_units_and_calculates_coverage_and_margin(): void
    {
        $organization = Organization::create([
            'name' => 'Planning Org',
            'email' => 'planning-service@example.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);
        $supplier = Supplier::create([
            'organization_id' => $organization->id,
            'name' => 'China Supplier',
            'currency' => 'CNY',
        ]);

        $base = $this->createProduct($organization, [
            'type' => 'standard',
            'sku' => 'TOY-BASE',
            'name' => 'Toy Base Unit',
            'price' => 5,
            'stock' => 30,
            'weighted_average_cost_cny' => 14.4,
        ]);
        $twoPack = $this->createProduct($organization, [
            'type' => 'kit',
            'sku' => 'TOY-2PK',
            'name' => 'Toy 2 Pack',
            'price' => 10,
            'stock' => 0,
            'packaging_cost_cny' => 1.6,
        ]);
        $threePack = $this->createProduct($organization, [
            'type' => 'kit',
            'sku' => 'TOY-3PK',
            'name' => 'Toy 3 Pack',
            'price' => 14,
            'stock' => 0,
            'packaging_cost_cny' => 2,
        ]);
        ProductComponent::create([
            'parent_product_id' => $twoPack->id,
            'component_product_id' => $base->id,
            'quantity' => 2,
        ]);
        ProductComponent::create([
            'parent_product_id' => $threePack->id,
            'component_product_id' => $base->id,
            'quantity' => 3,
        ]);

        $this->createHistoricalSale($organization, $twoPack, 7, now()->subDays(2));
        $this->createHistoricalSale($organization, $threePack, 7, now()->subDay());
        $this->createHistoricalSale($organization, $twoPack, 100, now()->subDays(10));

        $this->createInboundPurchaseOrder($organization, $supplier, $base, PurchaseOrder::STATUS_SENT, 20, 0);
        $this->createInboundPurchaseOrder($organization, $supplier, $base, PurchaseOrder::STATUS_PARTIAL, 20, 10);
        $this->createInboundPurchaseOrder($organization, $supplier, $base, PurchaseOrder::STATUS_DRAFT, 100, 0);

        $report = app(InventoryPlanningService::class)->report(
            organizationId: $organization->id,
            windowDays: 7,
            lowStockDays: 7,
            exchangeRateCnyPerUsd: 7.6,
        );

        $row = collect($report['rows'])->firstWhere('base_product_id', $base->id);
        $this->assertNotNull($row);
        $this->assertSame(35, $row['base_units_sold']);
        $this->assertSame(30, $row['warehouse_stock']);
        $this->assertSame(30, $row['in_transit_quantity']);
        $this->assertEqualsWithDelta(5.0, $row['average_daily_units'], 0.0001);
        $this->assertEqualsWithDelta(6.0, $row['warehouse_days'], 0.0001);
        $this->assertEqualsWithDelta(6.0, $row['in_transit_days'], 0.0001);
        $this->assertEqualsWithDelta(12.0, $row['total_days'], 0.0001);
        $this->assertTrue($row['is_low_stock']);

        $twoPackEconomics = collect($row['skus'])->firstWhere('product_id', $twoPack->id);
        $this->assertNotNull($twoPackEconomics);
        $this->assertEqualsWithDelta(30.4, $twoPackEconomics['cost_cny'], 0.0001);
        $this->assertEqualsWithDelta(4.0, $twoPackEconomics['cost_usd'], 0.0001);
        $this->assertEqualsWithDelta(6.0, $twoPackEconomics['gross_profit_usd'], 0.0001);
        $this->assertEqualsWithDelta(60.0, $twoPackEconomics['gross_margin_percent'], 0.0001);
    }

    public function test_report_leaves_coverage_empty_when_there_are_no_recent_sales(): void
    {
        $organization = Organization::create([
            'name' => 'No Sales Org',
            'email' => 'no-sales@example.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);
        $base = $this->createProduct($organization, [
            'type' => 'standard',
            'sku' => 'NO-SALES',
            'name' => 'No Sales Product',
            'stock' => 5,
        ]);

        $report = app(InventoryPlanningService::class)->report($organization->id, 7, 21, 7.2);
        $row = collect($report['rows'])->firstWhere('base_product_id', $base->id);

        $this->assertNotNull($row);
        $this->assertNull($row['warehouse_days']);
        $this->assertNull($row['in_transit_days']);
        $this->assertNull($row['total_days']);
        $this->assertFalse($row['is_low_stock']);
    }

    private function createProduct(Organization $organization, array $attributes): Product
    {
        return Product::create(array_merge([
            'organization_id' => $organization->id,
            'type' => 'standard',
            'sku' => 'SKU-'.uniqid(),
            'name' => 'Product',
            'price' => 10,
            'currency' => 'USD',
            'stock' => 0,
            'min_stock' => 0,
            'is_active' => true,
        ], $attributes));
    }

    private function createHistoricalSale(Organization $organization, Product $product, int $quantity, $date): void
    {
        $order = Order::create([
            'organization_id' => $organization->id,
            'order_number' => 'ORD-'.uniqid(),
            'source' => 'manual',
            'status' => 'delivered',
            'approval_status' => 'approved',
            'subtotal' => $product->price * $quantity,
            'tax' => 0,
            'shipping' => 0,
            'total' => $product->price * $quantity,
            'currency' => 'USD',
            'order_date' => $date,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'quantity' => $quantity,
            'unit_price' => $product->price,
            'subtotal' => $product->price * $quantity,
            'tax' => 0,
            'total' => $product->price * $quantity,
        ]);
    }

    private function createInboundPurchaseOrder(
        Organization $organization,
        Supplier $supplier,
        Product $product,
        string $status,
        int $ordered,
        int $received
    ): void {
        $purchaseOrder = PurchaseOrder::create([
            'organization_id' => $organization->id,
            'supplier_id' => $supplier->id,
            'po_number' => 'PO-'.uniqid(),
            'status' => $status,
            'order_date' => now()->toDateString(),
            'expected_date' => now()->addDays(14)->toDateString(),
            'subtotal' => $ordered * 10,
            'tax' => 0,
            'shipping' => 0,
            'total' => $ordered * 10,
            'currency' => 'CNY',
            'shipping_method' => 'sea',
            'domestic_freight_cny' => 0,
            'first_leg_freight_cny' => 0,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $purchaseOrder->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'quantity_ordered' => $ordered,
            'quantity_received' => $received,
            'unit_cost' => 10,
            'subtotal' => $ordered * 10,
            'tax' => 0,
            'total' => $ordered * 10,
        ]);
    }
}
