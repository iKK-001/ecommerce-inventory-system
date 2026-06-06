<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\Supplier;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderManualEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_purchase_order_persists_inventory_planning_shipment_fields(): void
    {
        SystemSetting::set('installed', true, 'boolean');

        $organization = Organization::create([
            'name' => 'Manual Entry Org',
            'email' => 'manual-entry@example.com',
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
            'sku' => 'MANUAL-BASE',
            'name' => 'Manual Base Unit',
            'price' => 10,
            'purchase_price' => 20,
            'currency' => 'USD',
            'stock' => 0,
            'min_stock' => 0,
            'is_active' => true,
        ]);
        $user = User::create([
            'name' => 'Manual Entry Admin',
            'email' => 'manual-entry-admin@example.com',
            'password' => bcrypt('password'),
            'organization_id' => $organization->id,
            'role' => 'admin',
        ]);
        $role = Role::firstOrCreate(
            ['slug' => 'manual-purchase-order-admin'],
            [
                'name' => 'Manual Purchase Order Admin',
                'is_system' => true,
                'permissions' => ['create_purchase_orders', 'view_purchase_orders'],
            ]
        );
        $user->roles()->syncWithoutDetaching([$role->id]);

        $response = $this->actingAs($user)->post(route('purchase-orders.store'), [
            'supplier_id' => $supplier->id,
            'order_date' => now()->toDateString(),
            'expected_date' => now()->addDays(18)->toDateString(),
            'currency' => 'CNY',
            'shipping_method' => 'sea',
            'domestic_freight_cny' => 150.50,
            'first_leg_freight_cny' => 900.25,
            'shipping' => 0,
            'tax' => 0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 100,
                    'unit_cost' => 20,
                ],
            ],
        ]);

        $purchaseOrder = PurchaseOrder::firstOrFail();
        $response->assertRedirect(route('purchase-orders.show', $purchaseOrder));
        $this->assertSame('CNY', $purchaseOrder->currency);
        $this->assertSame('sea', $purchaseOrder->shipping_method);
        $this->assertSame('150.50', (string) $purchaseOrder->domestic_freight_cny);
        $this->assertSame('900.25', (string) $purchaseOrder->first_leg_freight_cny);
    }
}
