<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\Role;
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
            ->assertSessionHasErrors('sales.0.product_id');
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

    /**
     * @return array<string, mixed>
     */
    private function payload(Product $product, string $weekStart = '2026-06-01'): array
    {
        return [
            'week_start' => $weekStart,
            'sales' => [[
                'product_id' => $product->id,
                'daily_quantities' => [
                    '2026-06-01' => 2,
                    '2026-06-02' => 0,
                    '2026-06-03' => 0,
                    '2026-06-04' => 0,
                    '2026-06-05' => 0,
                    '2026-06-06' => 0,
                    '2026-06-07' => 0,
                ],
            ]],
        ];
    }
}
