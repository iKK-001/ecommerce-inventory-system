<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Role;
use App\Models\Setting;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryPlanningReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_planning_report_uses_org_settings_and_hides_other_org_products(): void
    {
        SystemSetting::set('installed', true, 'boolean');

        $organization = Organization::create([
            'name' => 'Report Org',
            'email' => 'report@example.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);
        $otherOrganization = Organization::create([
            'name' => 'Other Org',
            'email' => 'other-report@example.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);
        $user = User::create([
            'name' => 'Report Admin',
            'email' => 'report-admin@example.com',
            'password' => bcrypt('password'),
            'organization_id' => $organization->id,
            'role' => 'admin',
        ]);
        $role = Role::firstOrCreate(
            ['slug' => 'inventory-planning-report-admin'],
            [
                'name' => 'Inventory Planning Report Admin',
                'is_system' => true,
                'permissions' => ['view_reports'],
            ]
        );
        $user->roles()->syncWithoutDetaching([$role->id]);

        $this->createProduct($organization, 'OWN-BASE');
        $this->createProduct($otherOrganization, 'OTHER-BASE');
        Setting::create([
            'organization_id' => $organization->id,
            'key' => 'inventory.exchange_rate_cny_per_usd',
            'value' => '7.35',
        ]);
        Setting::create([
            'organization_id' => $organization->id,
            'key' => 'inventory.low_stock_days',
            'value' => '18',
        ]);

        $response = $this->actingAs($user)->get(route('reports.inventory-planning', ['window' => 14]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Reports/InventoryPlanning')
            ->where('report.window_days', 14)
            ->where('report.exchange_rate_cny_per_usd', 7.35)
            ->where('report.low_stock_days', 18)
            ->has('report.rows', 1)
            ->where('report.rows.0.sku', 'OWN-BASE')
        );
    }

    private function createProduct(Organization $organization, string $sku): Product
    {
        return Product::create([
            'organization_id' => $organization->id,
            'type' => 'standard',
            'sku' => $sku,
            'name' => $sku,
            'price' => 10,
            'currency' => 'USD',
            'stock' => 20,
            'min_stock' => 0,
            'is_active' => true,
        ]);
    }
}
