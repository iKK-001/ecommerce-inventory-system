<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidOrderItemException;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductComponent;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use App\Models\User;
use App\Services\WeeklySalesService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class WeeklySalesServiceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    private CarbonImmutable $weekStart;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'TikTok US Org',
            'email' => 'tiktok-us@example.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->user = User::create([
            'name' => 'Weekly Sales Operator',
            'email' => 'weekly-sales@example.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);

        $this->weekStart = CarbonImmutable::parse('2026-06-01');
    }

    public function test_new_weekly_sales_create_daily_aggregate_orders_and_decrement_stock(): void
    {
        $product = $this->product('STANDARD', 50);

        $this->save([
            $this->row($product, ['2026-06-01' => 3, '2026-06-02' => 4]),
        ]);

        $this->assertSame(43, (int) $product->fresh()->stock);
        $this->assertSame(2, Order::where('source', 'tiktok_us_manual')->count());

        $monday = Order::where('external_id', 'tiktok-us-sales:2026-06-01')->firstOrFail();
        $this->assertSame('delivered', $monday->status->value);
        $this->assertSame('TikTok US Daily Aggregate', $monday->customer_name);
        $this->assertSame(3, (int) $monday->items()->firstOrFail()->quantity);

        $tuesday = Order::where('external_id', 'tiktok-us-sales:2026-06-02')->firstOrFail();
        $this->assertSame(4, (int) $tuesday->items()->firstOrFail()->quantity);
        $this->assertSame(2, StockAdjustment::where('reference_type', Order::class)->count());
    }

    public function test_overwrite_increase_and_decrease_only_apply_the_difference(): void
    {
        $product = $this->product('OVERWRITE', 50);

        $this->save([$this->row($product, ['2026-06-01' => 3])]);
        $this->assertSame(47, (int) $product->fresh()->stock);

        $this->save([$this->row($product, ['2026-06-01' => 5])]);
        $this->assertSame(45, (int) $product->fresh()->stock);

        $this->save([$this->row($product, ['2026-06-01' => 1])]);
        $this->assertSame(49, (int) $product->fresh()->stock);

        $this->assertSame(
            [-3, -2, 4],
            StockAdjustment::query()
                ->orderBy('id')
                ->pluck('adjustment_quantity')
                ->map(fn ($quantity): int => (int) $quantity)
                ->all()
        );

        $order = Order::where('external_id', 'tiktok-us-sales:2026-06-01')->firstOrFail();
        $this->assertSame(1, (int) $order->items()->firstOrFail()->quantity);
    }

    public function test_clearing_a_date_restores_stock_and_soft_deletes_the_order(): void
    {
        $product = $this->product('CLEAR', 50);

        $this->save([$this->row($product, ['2026-06-01' => 3])]);
        $this->save([$this->row($product)]);

        $this->assertSame(50, (int) $product->fresh()->stock);
        $this->assertSame(0, Order::where('source', 'tiktok_us_manual')->count());
        $this->assertSame(1, Order::withTrashed()->where('source', 'tiktok_us_manual')->count());
        $this->assertNotNull(
            Order::withTrashed()
                ->where('external_id', 'tiktok-us-sales:2026-06-01')
                ->firstOrFail()
                ->deleted_at
        );
    }

    public function test_kit_overwrite_adjusts_shared_component_stock_by_the_difference(): void
    {
        $base = $this->product('BASE', 20);
        $threePack = $this->product('THREE-PACK', 0, 'kit');
        ProductComponent::create([
            'parent_product_id' => $threePack->id,
            'component_product_id' => $base->id,
            'quantity' => 3,
        ]);

        $this->save([$this->row($threePack, ['2026-06-01' => 1])]);
        $this->assertSame(17, (int) $base->fresh()->stock);

        $this->save([$this->row($threePack, ['2026-06-01' => 3])]);
        $this->assertSame(11, (int) $base->fresh()->stock);
        $this->assertSame(0, (int) $threePack->fresh()->stock);
    }

    public function test_restorations_are_applied_before_new_sales_using_the_same_physical_stock(): void
    {
        $base = $this->product('SHARED-BASE', 3);
        $threePack = $this->product('SHARED-THREE-PACK', 0, 'kit');
        ProductComponent::create([
            'parent_product_id' => $threePack->id,
            'component_product_id' => $base->id,
            'quantity' => 3,
        ]);

        $this->save([$this->row($threePack, ['2026-06-02' => 1])]);
        $this->assertSame(0, (int) $base->fresh()->stock);

        $this->save([
            $this->row($base, ['2026-06-01' => 3]),
            $this->row($threePack),
        ]);

        $this->assertSame(0, (int) $base->fresh()->stock);
        $this->assertSame(1, Order::where('source', 'tiktok_us_manual')->count());
    }

    public function test_insufficient_stock_rolls_back_every_date_and_sku_in_the_week(): void
    {
        $first = $this->product('FIRST', 5);
        $insufficient = $this->product('INSUFFICIENT', 2);

        $this->expectException(InsufficientStockException::class);
        $this->expectExceptionMessage('2026-06-02');

        try {
            $this->save([
                $this->row($first, ['2026-06-01' => 3]),
                $this->row($insufficient, ['2026-06-02' => 3]),
            ]);
        } finally {
            $this->assertSame(5, (int) $first->fresh()->stock);
            $this->assertSame(2, (int) $insufficient->fresh()->stock);
            $this->assertSame(0, Order::withTrashed()->where('source', 'tiktok_us_manual')->count());
            $this->assertSame(0, StockAdjustment::count());
        }
    }

    public function test_repeating_an_identical_save_is_idempotent(): void
    {
        $product = $this->product('IDEMPOTENT', 50);
        $sales = [$this->row($product, ['2026-06-01' => 3, '2026-06-02' => 4])];

        $this->save($sales);
        $this->save($sales);

        $this->assertSame(43, (int) $product->fresh()->stock);
        $this->assertSame(2, Order::where('source', 'tiktok_us_manual')->count());
        $this->assertSame(2, StockAdjustment::count());
    }

    public function test_reconciliation_does_not_emit_intermediate_product_update_events(): void
    {
        $first = $this->product('EVENT-FIRST', 5);
        $insufficient = $this->product('EVENT-INSUFFICIENT', 0);
        Event::fake();

        try {
            $this->save([
                $this->row($first, ['2026-06-01' => 5]),
                $this->row($insufficient, ['2026-06-02' => 1]),
            ]);
            $this->fail('Expected insufficient stock to roll back the weekly reconciliation.');
        } catch (InsufficientStockException) {
            // Expected.
        }

        Event::assertNotDispatched('eloquent.updated: '.Product::class);
        $this->assertSame(5, (int) $first->fresh()->stock);
    }

    public function test_reconciliation_preserves_existing_sales_for_unsubmitted_hidden_skus(): void
    {
        $hidden = $this->product('HIDDEN', 10);
        $visible = $this->product('VISIBLE', 10);

        $this->save([
            $this->row($hidden, ['2026-06-01' => 1]),
            $this->row($visible, ['2026-06-01' => 2]),
        ]);
        $hidden->updateQuietly(['is_sellable' => false]);

        $this->save([
            $this->row($visible, ['2026-06-01' => 3]),
        ]);

        $this->assertSame(9, (int) $hidden->fresh()->stock);
        $this->assertSame(7, (int) $visible->fresh()->stock);

        $order = Order::where('external_id', 'tiktok-us-sales:2026-06-01')->firstOrFail();
        $this->assertSame(
            ['HIDDEN' => 1, 'VISIBLE' => 3],
            $order->items()
                ->orderBy('sku')
                ->pluck('quantity', 'sku')
                ->map(fn ($quantity): int => (int) $quantity)
                ->all()
        );
    }

    public function test_variant_sales_are_entered_separately_but_decrement_shared_parent_stock(): void
    {
        $product = $this->product('SHARED-VARIANTS', 20);
        $product->updateQuietly(['has_variants' => true]);
        $red = $this->variant($product, 'SHARED-RED', 'Red');
        $blue = $this->variant($product, 'SHARED-BLUE', 'Blue');

        $this->save([
            $this->variantRow($red, ['2026-06-01' => 2]),
            $this->variantRow($blue, ['2026-06-01' => 3]),
        ]);

        $this->assertSame(15, (int) $product->fresh()->stock);
        $this->assertSame(0, (int) $red->fresh()->stock);
        $this->assertSame(0, (int) $blue->fresh()->stock);

        $order = Order::where('external_id', 'tiktok-us-sales:2026-06-01')->firstOrFail();
        $this->assertSame(
            ['SHARED-BLUE' => 3, 'SHARED-RED' => 2],
            $order->items()
                ->orderBy('sku')
                ->pluck('quantity', 'sku')
                ->map(fn ($quantity): int => (int) $quantity)
                ->all()
        );
        $this->assertSame(
            [$blue->id, $red->id],
            $order->items()
                ->orderBy('sku')
                ->pluck('product_variant_id')
                ->map(fn ($variantId): int => (int) $variantId)
                ->all()
        );

        $this->save([
            $this->variantRow($red, ['2026-06-01' => 1]),
            $this->variantRow($blue, ['2026-06-01' => 3]),
        ]);

        $this->assertSame(16, (int) $product->fresh()->stock);
        $this->assertSame(
            [-2, -3, 1],
            StockAdjustment::query()
                ->orderBy('id')
                ->pluck('adjustment_quantity')
                ->map(fn ($quantity): int => (int) $quantity)
                ->all()
        );
    }

    public function test_variant_tracked_product_rows_still_require_a_variant_id(): void
    {
        $variantTracked = $this->product('VARIANT-TRACKED', 10);
        $variantTracked->updateQuietly(['has_variants' => true]);

        $this->expectException(InvalidOrderItemException::class);

        $this->save([$this->row($variantTracked)]);
    }

    /**
     * @param  array<int, array{product_id: int, daily_quantities: array<string, int>}>  $sales
     */
    private function save(array $sales): void
    {
        app(WeeklySalesService::class)->save($this->user, $this->weekStart, $sales);
    }

    /**
     * @param  array<string, int>  $quantities
     * @return array{product_id: int, daily_quantities: array<string, int>}
     */
    private function row(Product $product, array $quantities = []): array
    {
        $dailyQuantities = [];
        for ($day = 0; $day < 7; $day++) {
            $date = $this->weekStart->addDays($day)->toDateString();
            $dailyQuantities[$date] = $quantities[$date] ?? 0;
        }

        return [
            'product_id' => $product->id,
            'daily_quantities' => $dailyQuantities,
        ];
    }

    /**
     * @param  array<string, int>  $quantities
     * @return array{product_id: int, product_variant_id: int, daily_quantities: array<string, int>}
     */
    private function variantRow(ProductVariant $variant, array $quantities = []): array
    {
        $row = $this->row($variant->product, $quantities);
        $row['product_variant_id'] = $variant->id;

        return $row;
    }

    private function product(string $sku, int $stock, string $type = 'standard'): Product
    {
        return Product::create([
            'organization_id' => $this->organization->id,
            'type' => $type,
            'sku' => $sku,
            'name' => $sku,
            'price' => 10,
            'selling_price' => 12,
            'currency' => 'USD',
            'stock' => $stock,
            'min_stock' => 0,
            'is_active' => true,
            'is_sellable' => true,
        ]);
    }

    private function variant(Product $product, string $sku, string $title): ProductVariant
    {
        return ProductVariant::create([
            'organization_id' => $this->organization->id,
            'product_id' => $product->id,
            'sku' => $sku,
            'title' => $title,
            'option_values' => ['Color' => $title],
            'stock' => 0,
            'min_stock' => 0,
            'is_active' => true,
        ]);
    }
}
