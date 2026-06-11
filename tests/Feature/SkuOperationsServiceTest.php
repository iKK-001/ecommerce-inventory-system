<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductComponent;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\Supplier;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseOrderItem;
use App\Models\Setting;
use App\Services\SkuOperationsService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SkuOperationsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_report_filters_sellable_products_and_calculates_operating_economics(): void
    {
        $organization = $this->organization('Operations Org');
        $otherOrganization = $this->organization('Other Org');
        Setting::create([
            'organization_id' => $organization->id,
            'key' => 'inventory.exchange_rate_cny_per_usd',
            'value' => '7.2',
        ]);

        $sellable = $this->product($organization, [
            'sku' => 'SELLABLE',
            'name' => 'Sellable',
            'selling_price' => 20,
            'weighted_average_cost_cny' => 36,
            'packaging_cost_cny' => 3.6,
            'packing_labor_cost_cny' => 3.6,
            'last_mile_cost_usd' => 2,
        ]);
        $this->product($organization, ['sku' => 'INACTIVE', 'is_active' => false]);
        $this->product($organization, ['sku' => 'NOT-SELLABLE', 'is_sellable' => false]);
        $this->product($otherOrganization, ['sku' => 'OTHER-ORG']);

        $report = app(SkuOperationsService::class)->report(
            $organization->id,
            CarbonImmutable::parse('2026-06-01')
        );

        $this->assertSame([$sellable->id], collect($report['rows'])->pluck('product_id')->all());
        $row = $report['rows'][0];
        $this->assertEqualsWithDelta(5.0, $row['product_cost_usd'], 0.0001);
        $this->assertEqualsWithDelta(1.0, $row['packing_cost_usd'], 0.0001);
        $this->assertEqualsWithDelta(2.0, $row['last_mile_cost_usd'], 0.0001);
        $this->assertEqualsWithDelta(8.0, $row['unit_total_cost_usd'], 0.0001);
        $this->assertEqualsWithDelta(12.0, $row['gross_profit_usd'], 0.0001);
        $this->assertEqualsWithDelta(60.0, $row['gross_margin_percent'], 0.0001);
    }

    public function test_report_expands_active_variants_into_separate_sales_entry_rows_with_shared_stock(): void
    {
        Carbon::setTestNow('2026-06-08 12:00:00');
        $organization = $this->organization('Variant Operations Org');
        $product = $this->product($organization, [
            'sku' => 'PUZZLE',
            'name' => 'Puzzle',
            'stock' => 25,
            'has_variants' => true,
            'selling_price' => 10,
        ]);
        $small = $this->variant($product, 'PUZZLE-S', 'Small', price: 11);
        $large = $this->variant($product, 'PUZZLE-L', 'Large', price: 12);
        $this->variant($product, 'PUZZLE-HIDDEN', 'Hidden', active: false);
        $this->sale($organization, $product, 2, '2026-06-01', 'tiktok_us_manual', variant: $small);
        $this->sale($organization, $product, 3, '2026-06-02', 'tiktok_us_manual', variant: $large);

        $report = app(SkuOperationsService::class)->report(
            $organization->id,
            CarbonImmutable::parse('2026-06-01')
        );

        $this->assertSame(
            ["v:{$large->id}", "v:{$small->id}"],
            collect($report['rows'])->pluck('entry_key')->all()
        );

        $largeRow = collect($report['rows'])->firstWhere('variant_id', $large->id);
        $smallRow = collect($report['rows'])->firstWhere('variant_id', $small->id);

        $this->assertSame($product->id, $largeRow['product_id']);
        $this->assertSame('PUZZLE-L', $largeRow['sku']);
        $this->assertSame('Puzzle - Large', $largeRow['name']);
        $this->assertTrue($largeRow['is_entry_supported']);
        $this->assertSame(25, $largeRow['warehouse_stock']);
        $this->assertEqualsWithDelta(12.0, $largeRow['selling_price_usd'], 0.0001);
        $this->assertSame(3, $largeRow['daily_quantities']['2026-06-02']);

        $this->assertSame(2, $smallRow['daily_quantities']['2026-06-01']);
        $this->assertSame(5, $report['summary']['units_sold']);
        $this->assertEqualsWithDelta(58.0, $report['summary']['estimated_revenue_usd'], 0.0001);
    }

    public function test_report_splits_domestic_first_leg_last_mile_and_packing_costs(): void
    {
        $organization = $this->organization('Cost Split Org');
        Setting::create([
            'organization_id' => $organization->id,
            'key' => 'inventory.exchange_rate_cny_per_usd',
            'value' => '7.2',
        ]);

        $product = $this->product($organization, [
            'sku' => 'SPLIT',
            'name' => 'Cost Split Product',
            'selling_price' => 20,
            'weighted_average_cost_cny' => 57.6,
            'packaging_cost_cny' => 3.6,
            'packing_labor_cost_cny' => 3.6,
            'last_mile_cost_usd' => 2,
            'metadata' => [
                'unit_goods_cost_cny' => 36,
                'domestic_logistics_unit_cny' => 7.2,
                'first_leg_freight_unit_cny' => 14.4,
            ],
        ]);

        $report = app(SkuOperationsService::class)->report(
            $organization->id,
            CarbonImmutable::parse('2026-06-01')
        );
        $row = collect($report['rows'])->firstWhere('product_id', $product->id);

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(5.0, $row['product_cost_usd'], 0.0001);
        $this->assertEqualsWithDelta(1.0, $row['domestic_logistics_cost_usd'], 0.0001);
        $this->assertEqualsWithDelta(2.0, $row['us_first_leg_cost_usd'], 0.0001);
        $this->assertEqualsWithDelta(2.0, $row['us_last_mile_cost_usd'], 0.0001);
        $this->assertEqualsWithDelta(1.0, $row['packing_cost_usd'], 0.0001);
        $this->assertEqualsWithDelta(11.0, $row['unit_total_cost_usd'], 0.0001);
        $this->assertEqualsWithDelta(9.0, $row['gross_profit_usd'], 0.0001);
        $this->assertEqualsWithDelta(45.0, $row['gross_margin_percent'], 0.0001);
    }

    public function test_kit_cost_stock_and_in_transit_use_all_components_and_the_limiting_quantity(): void
    {
        $organization = $this->organization('Kit Operations Org');
        $supplier = $this->supplier($organization);
        $componentA = $this->product($organization, [
            'sku' => 'COMP-A',
            'stock' => 10,
            'weighted_average_cost_cny' => 14.4,
            'is_sellable' => false,
        ]);
        $componentB = $this->product($organization, [
            'sku' => 'COMP-B',
            'stock' => 3,
            'weighted_average_cost_cny' => 7.2,
            'is_sellable' => false,
        ]);
        $kit = $this->product($organization, [
            'type' => 'kit',
            'sku' => 'KIT',
            'stock' => 999,
            'selling_price' => 12,
        ]);
        ProductComponent::create([
            'parent_product_id' => $kit->id,
            'component_product_id' => $componentA->id,
            'quantity' => 2,
        ]);
        ProductComponent::create([
            'parent_product_id' => $kit->id,
            'component_product_id' => $componentB->id,
            'quantity' => 1,
        ]);

        $this->inbound($organization, $supplier, $componentA, 10, 2);
        $this->inbound($organization, $supplier, $componentB, 12, 2);

        $report = app(SkuOperationsService::class)->report(
            $organization->id,
            CarbonImmutable::parse('2026-06-01')
        );
        $row = collect($report['rows'])->firstWhere('product_id', $kit->id);

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(5.0, $row['product_cost_usd'], 0.0001);
        $this->assertSame(3, $row['warehouse_stock']);
        $this->assertSame(4, $row['in_transit_quantity']);
    }

    public function test_selected_week_sales_and_rolling_coverage_use_their_respective_windows(): void
    {
        Carbon::setTestNow('2026-06-08 12:00:00');
        $organization = $this->organization('Coverage Org');
        Setting::create([
            'organization_id' => $organization->id,
            'key' => 'inventory.low_stock_days',
            'value' => '21',
        ]);
        $product = $this->product($organization, [
            'sku' => 'COVERAGE',
            'selling_price' => 10,
            'stock' => 14,
        ]);

        $this->sale($organization, $product, 2, '2026-06-01', 'tiktok_us_manual');
        $this->sale($organization, $product, 3, '2026-06-02', 'tiktok_us_manual');
        $this->sale($organization, $product, 4, '2026-06-03', 'manual');
        $this->sale($organization, $product, 100, '2026-06-04', 'manual', 'cancelled');
        $this->sale($organization, $product, 100, '2026-05-20', 'manual');

        $report = app(SkuOperationsService::class)->report(
            $organization->id,
            CarbonImmutable::parse('2026-06-01')
        );
        $row = collect($report['rows'])->firstWhere('product_id', $product->id);

        $this->assertSame(2, $row['daily_quantities']['2026-06-01']);
        $this->assertSame(3, $row['daily_quantities']['2026-06-02']);
        $this->assertSame(0, $row['daily_quantities']['2026-06-03']);
        $this->assertSame(5, $row['weekly_sales_total']);
        $this->assertEqualsWithDelta(14.0, $row['sellable_days'], 0.0001);
        $this->assertTrue($row['is_low_stock']);
        $this->assertSame(5, $report['summary']['units_sold']);
        $this->assertEqualsWithDelta(50.0, $report['summary']['estimated_revenue_usd'], 0.0001);
    }

    private function organization(string $name): Organization
    {
        return Organization::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '-', $name)).'@example.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);
    }

    private function supplier(Organization $organization): Supplier
    {
        return Supplier::create([
            'organization_id' => $organization->id,
            'name' => 'China Supplier',
            'currency' => 'CNY',
        ]);
    }

    private function product(Organization $organization, array $attributes): Product
    {
        return Product::create(array_merge([
            'organization_id' => $organization->id,
            'type' => 'standard',
            'sku' => 'SKU-'.uniqid(),
            'name' => 'Product',
            'price' => 10,
            'selling_price' => 10,
            'currency' => 'USD',
            'stock' => 0,
            'min_stock' => 0,
            'weighted_average_cost_cny' => 0,
            'packaging_cost_cny' => 0,
            'packing_labor_cost_cny' => 0,
            'last_mile_cost_usd' => 0,
            'is_active' => true,
            'is_sellable' => true,
        ], $attributes));
    }

    private function variant(
        Product $product,
        string $sku,
        string $title,
        ?int $price = null,
        bool $active = true
    ): ProductVariant {
        return ProductVariant::create([
            'organization_id' => $product->organization_id,
            'product_id' => $product->id,
            'sku' => $sku,
            'title' => $title,
            'option_values' => ['Size' => $title],
            'price' => $price,
            'stock' => 0,
            'min_stock' => 0,
            'is_active' => $active,
        ]);
    }

    private function inbound(
        Organization $organization,
        Supplier $supplier,
        Product $product,
        int $ordered,
        int $received
    ): void {
        $purchaseOrder = PurchaseOrder::create([
            'organization_id' => $organization->id,
            'supplier_id' => $supplier->id,
            'po_number' => 'PO-'.uniqid(),
            'status' => $received > 0 ? PurchaseOrder::STATUS_PARTIAL : PurchaseOrder::STATUS_SENT,
            'order_date' => now()->toDateString(),
            'expected_date' => now()->addDays(14)->toDateString(),
            'subtotal' => $ordered * 10,
            'tax' => 0,
            'shipping' => 0,
            'total' => $ordered * 10,
            'currency' => 'CNY',
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

    private function sale(
        Organization $organization,
        Product $product,
        int $quantity,
        string $date,
        string $source,
        string $status = 'delivered',
        ?ProductVariant $variant = null
    ): void {
        $order = Order::create([
            'organization_id' => $organization->id,
            'order_number' => 'ORD-'.uniqid(),
            'source' => $source,
            'external_id' => $source === 'tiktok_us_manual' ? "tiktok-us-sales:{$date}" : null,
            'status' => $status,
            'approval_status' => 'approved',
            'subtotal' => $product->selling_price * $quantity,
            'tax' => 0,
            'shipping' => 0,
            'total' => $product->selling_price * $quantity,
            'currency' => 'USD',
            'order_date' => $date,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'product_name' => $product->name,
            'sku' => $variant?->sku ?? $product->sku,
            'quantity' => $quantity,
            'unit_price' => $variant?->price ?? $product->selling_price,
            'subtotal' => ($variant?->price ?? $product->selling_price) * $quantity,
            'tax' => 0,
            'total' => ($variant?->price ?? $product->selling_price) * $quantity,
        ]);
    }
}
