<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Order\Order;
use App\Models\Role;
use App\Models\Setting;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WeeklySalesControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');
    }

    public function test_user_with_view_orders_can_open_org_scoped_weekly_sales_page(): void
    {
        $organization = $this->organization('Weekly Page Org');
        $otherOrganization = $this->organization('Other Weekly Page Org');
        $user = $this->user($organization, ['view_orders']);
        $ownProduct = $this->product($organization, 'OWN-SELLABLE');
        $this->product($otherOrganization, 'OTHER-SELLABLE');

        $response = $this->actingAs($user)->get('/weekly-sales?week_start=2026-06-01');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('WeeklySales/Index')
            ->where('report.week_start', '2026-06-01')
            ->has('report.rows', 1)
            ->where('report.rows.0.product_id', $ownProduct->id)
        );
    }

    public function test_weekly_sales_page_lists_product_variants_as_separate_entry_rows(): void
    {
        $organization = $this->organization('Weekly Variant Page Org');
        $user = $this->user($organization, ['view_orders']);
        $product = $this->product($organization, 'VARIANT-PARENT', stock: 50);
        $product->updateQuietly(['has_variants' => true]);
        $red = $this->variant($product, 'VARIANT-RED', 'Red');
        $blue = $this->variant($product, 'VARIANT-BLUE', 'Blue');

        $response = $this->actingAs($user)->get('/weekly-sales?week_start=2026-06-01');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('WeeklySales/Index')
            ->has('report.rows', 2)
            ->where('report.rows.0.product_id', $product->id)
            ->where('report.rows.0.variant_id', $blue->id)
            ->where('report.rows.0.entry_key', "v:{$blue->id}")
            ->where('report.rows.1.product_id', $product->id)
            ->where('report.rows.1.variant_id', $red->id)
            ->where('report.rows.1.entry_key', "v:{$red->id}")
        );
    }

    public function test_user_without_view_orders_cannot_open_weekly_sales_page(): void
    {
        $organization = $this->organization('No View Org');
        $user = $this->user($organization);

        $this->actingAs($user)->get('/weekly-sales')->assertStatus(403);
    }

    public function test_user_with_create_orders_can_save_weekly_sales(): void
    {
        $organization = $this->organization('Weekly Save Org');
        $user = $this->user($organization, ['create_orders']);
        $product = $this->product($organization, 'SAVE-SKU', stock: 10);

        $response = $this->actingAs($user)->post('/weekly-sales', $this->payload($product));

        $response->assertRedirect('/weekly-sales?week_start=2026-06-01');
        $response->assertSessionHas('success');
        $this->assertSame(8, (int) $product->fresh()->stock);
        $this->assertSame(1, Order::where('source', 'tiktok_us_manual')->count());
    }

    public function test_user_can_save_sales_for_variants_that_share_parent_stock(): void
    {
        $organization = $this->organization('Weekly Variant Save Org');
        $user = $this->user($organization, ['create_orders']);
        $product = $this->product($organization, 'VARIANT-SAVE', stock: 10);
        $product->updateQuietly(['has_variants' => true]);
        $small = $this->variant($product, 'VARIANT-SMALL', 'Small');
        $large = $this->variant($product, 'VARIANT-LARGE', 'Large');

        $response = $this->actingAs($user)->post('/weekly-sales', [
            'week_start' => '2026-06-01',
            'sales' => [
                $this->payloadRow($product, variant: $small, monday: 2),
                $this->payloadRow($product, variant: $large, monday: 3),
            ],
        ]);

        $response->assertRedirect('/weekly-sales?week_start=2026-06-01');
        $response->assertSessionHas('success');
        $this->assertSame(5, (int) $product->fresh()->stock);
        $this->assertSame(
            ['VARIANT-LARGE' => 3, 'VARIANT-SMALL' => 2],
            Order::where('external_id', 'tiktok-us-sales:2026-06-01')
                ->firstOrFail()
                ->items()
                ->orderBy('sku')
                ->pluck('quantity', 'sku')
                ->map(fn ($quantity): int => (int) $quantity)
                ->all()
        );
    }

    public function test_user_can_save_variant_sales_even_when_parent_variant_flag_is_stale(): void
    {
        $organization = $this->organization('Weekly Stale Variant Save Org');
        $user = $this->user($organization, ['create_orders']);
        $product = $this->product($organization, 'STALE-VARIANT-SAVE', stock: 10);
        $product->updateQuietly(['has_variants' => false]);
        $variant = $this->variant($product, 'STALE-VARIANT-ROW', 'Row');

        $response = $this->actingAs($user)->post('/weekly-sales', [
            'week_start' => '2026-06-01',
            'sales' => [
                $this->payloadRow($product, variant: $variant, monday: 4),
            ],
        ]);

        $response->assertRedirect('/weekly-sales?week_start=2026-06-01');
        $this->assertSame(6, (int) $product->fresh()->stock);
    }

    public function test_user_without_create_orders_cannot_save_weekly_sales(): void
    {
        $organization = $this->organization('No Save Org');
        $user = $this->user($organization, ['view_orders']);
        $product = $this->product($organization, 'NO-SAVE-SKU', stock: 10);

        $this->actingAs($user)
            ->post('/weekly-sales', $this->payload($product))
            ->assertStatus(403);

        $this->assertSame(10, (int) $product->fresh()->stock);
    }

    public function test_store_rejects_non_monday_negative_foreign_and_non_sellable_input(): void
    {
        $organization = $this->organization('Validation Org');
        $otherOrganization = $this->organization('Foreign Validation Org');
        $user = $this->user($organization, ['create_orders']);
        $product = $this->product($organization, 'VALID-SKU');
        $foreign = $this->product($otherOrganization, 'FOREIGN-SKU');
        $nonSellable = $this->product($organization, 'NON-SELLABLE', isSellable: false);
        $variantTracked = $this->product($organization, 'VARIANT-TRACKED');
        $variantTracked->updateQuietly(['has_variants' => true]);
        $validVariant = $this->variant($variantTracked, 'VALID-VARIANT', 'Valid');
        $foreignVariant = $this->variant($foreign, 'FOREIGN-VARIANT', 'Foreign');

        $this->actingAs($user)
            ->post('/weekly-sales', $this->payload($product, weekStart: '2026-06-02'))
            ->assertSessionHasErrors('week_start');

        $negative = $this->payload($product);
        $negative['sales'][0]['daily_quantities']['2026-06-01'] = -1;
        $this->actingAs($user)
            ->post('/weekly-sales', $negative)
            ->assertSessionHasErrors('sales.0.daily_quantities.2026-06-01');

        $this->actingAs($user)
            ->post('/weekly-sales', $this->payload($foreign))
            ->assertSessionHasErrors('sales.0.product_id');

        $this->actingAs($user)
            ->post('/weekly-sales', $this->payload($nonSellable))
            ->assertSessionHasErrors('sales.0.product_id');

        $this->actingAs($user)
            ->post('/weekly-sales', $this->payload($variantTracked))
            ->assertSessionHasErrors('sales.0.product_variant_id');

        $wrongVariant = $this->payload($variantTracked);
        $wrongVariant['sales'][0]['product_variant_id'] = $foreignVariant->id;
        $this->actingAs($user)
            ->post('/weekly-sales', $wrongVariant)
            ->assertSessionHasErrors('sales.0.product_variant_id');

        $duplicateVariant = [
            'week_start' => '2026-06-01',
            'sales' => [
                $this->payloadRow($variantTracked, variant: $validVariant, monday: 1),
                $this->payloadRow($variantTracked, variant: $validVariant, monday: 2),
            ],
        ];
        $this->actingAs($user)
            ->post('/weekly-sales', $duplicateVariant)
            ->assertSessionHasErrors('sales.1.product_variant_id');
    }

    public function test_user_with_edit_products_can_update_weekly_sales_costs_from_usd_inputs(): void
    {
        $organization = $this->organization('Weekly Cost Edit Org');
        Setting::create([
            'organization_id' => $organization->id,
            'key' => 'inventory.exchange_rate_cny_per_usd',
            'value' => '7.2',
        ]);
        $user = $this->user($organization, ['edit_products']);
        $product = $this->product($organization, 'COST-SKU');

        $response = $this->actingAs($user)
            ->put("/weekly-sales/products/{$product->id}/costs", [
                'week_start' => '2026-06-01',
                'selling_price_usd' => 12.34,
                'product_cost_usd' => 0.50,
                'domestic_logistics_cost_usd' => 0.10,
                'us_first_leg_cost_usd' => 0.20,
                'us_last_mile_cost_usd' => 1.25,
                'packing_cost_usd' => 0.30,
            ]);

        $response->assertRedirect('/weekly-sales?week_start=2026-06-01');
        $response->assertSessionHas('success');

        $product->refresh();
        $this->assertSame('12.34', (string) $product->selling_price);
        $this->assertSame('12.34', (string) $product->price);
        $this->assertSame('USD', $product->currency);
        $this->assertSame('1.2500', (string) $product->last_mile_cost_usd);
        $this->assertSame('2.1600', (string) $product->packaging_cost_cny);
        $this->assertSame('0.0000', (string) $product->packing_labor_cost_cny);
        $this->assertSame('5.7600', (string) $product->weighted_average_cost_cny);
        $this->assertSame(3.6, $product->metadata['unit_goods_cost_cny']);
        $this->assertSame(0.72, $product->metadata['domestic_logistics_unit_cny']);
        $this->assertSame(1.44, $product->metadata['first_leg_freight_unit_cny']);
    }

    public function test_user_with_edit_products_can_update_variant_weekly_sales_costs_without_changing_parent_costs(): void
    {
        $organization = $this->organization('Weekly Variant Cost Edit Org');
        Setting::create([
            'organization_id' => $organization->id,
            'key' => 'inventory.exchange_rate_cny_per_usd',
            'value' => '7.2',
        ]);
        $user = $this->user($organization, ['edit_products']);
        $product = $this->product($organization, 'PARENT-COST-SKU');
        $product->updateQuietly([
            'has_variants' => true,
            'selling_price' => 10,
            'price' => 10,
            'weighted_average_cost_cny' => 9,
            'last_mile_cost_usd' => 0.75,
            'packaging_cost_cny' => 1.5,
            'metadata' => [
                'unit_goods_cost_cny' => 7.2,
                'domestic_logistics_unit_cny' => 1,
                'first_leg_freight_unit_cny' => 0.8,
            ],
        ]);
        $variant = $this->variant($product, 'VARIANT-COST-SKU', 'Variant Cost');

        $response = $this->actingAs($user)
            ->put("/weekly-sales/variants/{$variant->id}/costs", [
                'week_start' => '2026-06-01',
                'selling_price_usd' => 12.34,
                'product_cost_usd' => 0.50,
                'domestic_logistics_cost_usd' => 0.10,
                'us_first_leg_cost_usd' => 0.20,
                'us_last_mile_cost_usd' => 1.25,
                'packing_cost_usd' => 0.30,
            ]);

        $response->assertRedirect('/weekly-sales?week_start=2026-06-01');
        $response->assertSessionHas('success');

        $variant->refresh();
        $product->refresh();

        $this->assertSame('12.34', (string) $variant->price);
        $this->assertSame('3.60', (string) $variant->purchase_price);
        $this->assertSame(3.6, $variant->metadata['unit_goods_cost_cny']);
        $this->assertSame(0.72, $variant->metadata['domestic_logistics_unit_cny']);
        $this->assertSame(1.44, $variant->metadata['first_leg_freight_unit_cny']);
        $this->assertSame(1.25, $variant->metadata['last_mile_cost_usd']);
        $this->assertSame(2.16, $variant->metadata['packing_cost_cny']);

        $this->assertSame('10.00', (string) $product->selling_price);
        $this->assertSame('0.7500', (string) $product->last_mile_cost_usd);
        $this->assertSame(7.2, $product->metadata['unit_goods_cost_cny']);
    }

    public function test_user_without_edit_products_cannot_update_weekly_sales_costs(): void
    {
        $organization = $this->organization('No Weekly Cost Edit Org');
        $user = $this->user($organization, ['view_orders']);
        $product = $this->product($organization, 'NO-COST-EDIT-SKU');

        $this->actingAs($user)
            ->put("/weekly-sales/products/{$product->id}/costs", [
                'week_start' => '2026-06-01',
                'selling_price_usd' => 12.34,
                'product_cost_usd' => 0.50,
                'domestic_logistics_cost_usd' => 0.10,
                'us_first_leg_cost_usd' => 0.20,
                'us_last_mile_cost_usd' => 1.25,
                'packing_cost_usd' => 0.30,
            ])
            ->assertStatus(403);

        $this->assertSame('10.00', (string) $product->fresh()->selling_price);
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

    /**
     * @param  array<int, string>  $permissions
     */
    private function user(Organization $organization, array $permissions = []): User
    {
        $user = User::create([
            'name' => 'Weekly Sales User',
            'email' => uniqid('weekly-sales-').'@example.com',
            'password' => bcrypt('password'),
            'organization_id' => $organization->id,
            'role' => 'member',
        ]);

        if ($permissions !== []) {
            $role = Role::create([
                'name' => 'Weekly Sales '.uniqid(),
                'slug' => 'weekly-sales-'.uniqid(),
                'organization_id' => $organization->id,
                'permissions' => $permissions,
                'is_system' => false,
            ]);
            $user->roles()->attach($role);
        }

        return $user;
    }

    private function product(
        Organization $organization,
        string $sku,
        int $stock = 20,
        bool $isSellable = true
    ): Product {
        return Product::create([
            'organization_id' => $organization->id,
            'type' => 'standard',
            'sku' => $sku,
            'name' => $sku,
            'price' => 10,
            'selling_price' => 10,
            'currency' => 'USD',
            'stock' => $stock,
            'min_stock' => 0,
            'is_active' => true,
            'is_sellable' => $isSellable,
        ]);
    }

    private function variant(Product $product, string $sku, string $title): ProductVariant
    {
        return ProductVariant::create([
            'organization_id' => $product->organization_id,
            'product_id' => $product->id,
            'sku' => $sku,
            'title' => $title,
            'option_values' => ['Option' => $title],
            'stock' => 0,
            'min_stock' => 0,
            'is_active' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Product $product, string $weekStart = '2026-06-01'): array
    {
        return [
            'week_start' => $weekStart,
            'sales' => [$this->payloadRow($product)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadRow(Product $product, ?ProductVariant $variant = null, int $monday = 2): array
    {
        return array_filter([
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'daily_quantities' => [
                '2026-06-01' => $monday,
                '2026-06-02' => 0,
                '2026-06-03' => 0,
                '2026-06-04' => 0,
                '2026-06-05' => 0,
                '2026-06-06' => 0,
                '2026-06-07' => 0,
            ],
        ], fn ($value): bool => $value !== null);
    }
}
