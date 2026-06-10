<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Role;
use App\Models\Setting;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\SkuOperationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiInventoryOperationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');
        config()->set('services.minimax.api_key', 'test-minimax-key');
        config()->set('services.minimax.base_url', 'https://api.minimax.io/v1');
        config()->set('services.minimax.model', 'MiniMax-M2.7');
    }

    public function test_user_without_ai_write_permissions_cannot_generate_or_execute_draft(): void
    {
        $organization = $this->organization('No AI Permission Org');
        $user = $this->user($organization, ['view_orders']);

        $this->actingAs($user)
            ->postJson('/ai-operations/draft', ['instruction' => '把NTS库存改成500'])
            ->assertStatus(403);

        $this->actingAs($user)
            ->postJson('/ai-operations/execute', ['draft_id' => 1])
            ->assertStatus(403);
    }

    public function test_generating_draft_uses_minimax_and_does_not_modify_database(): void
    {
        $organization = $this->organization('AI Draft Org');
        $user = $this->user($organization, ['edit_products', 'manage_stock', 'manage_purchase_orders']);
        $product = $this->product($organization, 'NTS', '水晶娜塔莎', stock: 20);

        Http::fake([
            'api.minimax.io/v1/chat/completions' => Http::response($this->minimaxToolResponse([
                [
                    'type' => 'cost_update',
                    'product_ref' => 'NTS',
                    'fields' => [
                        'selling_price_usd' => 9.68,
                        'product_cost_usd' => 0.50,
                    ],
                ],
                [
                    'type' => 'stock_set',
                    'product_ref' => 'NTS',
                    'actual_stock' => 500,
                ],
            ])),
        ]);

        $response = $this->actingAs($user)
            ->postJson('/ai-operations/draft', [
                'instruction' => '把娜塔莎售价改成9.68，商品成本0.5，实际库存500',
            ]);

        $response->assertCreated()
            ->assertJsonPath('draft.status', 'draft')
            ->assertJsonCount(2, 'draft.operations')
            ->assertJsonPath('draft.operations.0.product_id', $product->id)
            ->assertJsonPath('draft.operations.0.changes.0.field', 'selling_price_usd')
            ->assertJsonPath('draft.operations.0.changes.0.old_value', 10.0)
            ->assertJsonPath('draft.operations.0.changes.0.new_value', 9.68)
            ->assertJsonPath('draft.operations.1.changes.0.field', 'actual_stock')
            ->assertJsonPath('draft.operations.1.changes.0.old_value', 20)
            ->assertJsonPath('draft.operations.1.changes.0.new_value', 500);

        $product->refresh();
        $this->assertSame(20, $product->stock);
        $this->assertSame('10.00', (string) $product->selling_price);
        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertDatabaseCount('stock_adjustments', 0);
    }

    public function test_confirming_ai_draft_updates_cost_stock_and_in_transit_purchase_order(): void
    {
        $organization = $this->organization('AI Execute Org');
        Setting::create([
            'organization_id' => $organization->id,
            'key' => 'inventory.exchange_rate_cny_per_usd',
            'value' => '7.2',
        ]);

        $user = $this->user($organization, ['edit_products', 'manage_stock', 'manage_purchase_orders']);
        $product = $this->product($organization, 'NTS', '水晶娜塔莎', stock: 20);

        Http::fake([
            'api.minimax.io/v1/chat/completions' => Http::response($this->minimaxToolResponse([
                [
                    'type' => 'cost_update',
                    'product_ref' => 'NTS',
                    'fields' => [
                        'selling_price_usd' => 9.68,
                        'product_cost_usd' => 0.50,
                        'domestic_logistics_cost_usd' => 0.10,
                        'us_first_leg_cost_usd' => 0.20,
                        'us_last_mile_cost_usd' => 1.25,
                        'packing_cost_usd' => 0.30,
                    ],
                ],
                [
                    'type' => 'stock_set',
                    'product_ref' => 'NTS',
                    'actual_stock' => 500,
                ],
                [
                    'type' => 'in_transit_set',
                    'product_ref' => 'NTS',
                    'in_transit_quantity' => 300,
                ],
            ])),
        ]);

        $draftResponse = $this->actingAs($user)
            ->postJson('/ai-operations/draft', [
                'instruction' => '把娜塔莎售价改成9.68，商品成本0.5，国内物流0.1，美国头程0.2，美国尾程1.25，打包成本0.3，实际库存500，在途300',
            ])
            ->assertCreated();

        $draftId = $draftResponse->json('draft.id');

        $this->actingAs($user)
            ->postJson('/ai-operations/execute', ['draft_id' => $draftId])
            ->assertOk()
            ->assertJsonPath('draft.status', 'executed');

        $product->refresh();
        $this->assertSame('9.68', (string) $product->selling_price);
        $this->assertSame('9.68', (string) $product->price);
        $this->assertSame('USD', $product->currency);
        $this->assertSame('3.60', (string) $product->purchase_price);
        $this->assertSame('5.7600', (string) $product->weighted_average_cost_cny);
        $this->assertSame('1.2500', (string) $product->last_mile_cost_usd);
        $this->assertSame('2.1600', (string) $product->packaging_cost_cny);
        $this->assertSame('0.0000', (string) $product->packing_labor_cost_cny);
        $this->assertSame(3.6, $product->metadata['unit_goods_cost_cny']);
        $this->assertSame(0.72, $product->metadata['domestic_logistics_unit_cny']);
        $this->assertSame(1.44, $product->metadata['first_leg_freight_unit_cny']);

        $this->assertSame(500, $product->stock);
        $this->assertDatabaseHas('stock_adjustments', [
            'product_id' => $product->id,
            'quantity_before' => 20,
            'quantity_after' => 500,
            'adjustment_quantity' => 480,
            'type' => 'count',
        ]);

        $purchaseOrder = PurchaseOrder::with('items')->firstOrFail();
        $this->assertSame(PurchaseOrder::STATUS_SENT, $purchaseOrder->status);
        $this->assertSame('AI-IN-TRANSIT', $purchaseOrder->supplier->code);
        $this->assertSame(300, $purchaseOrder->items->first()->quantity_ordered);
        $this->assertSame(0, $purchaseOrder->items->first()->quantity_received);

        $report = app(SkuOperationsService::class)->report((int) $organization->id, now()->startOfWeek()->toImmutable());
        $row = collect($report['rows'])->firstWhere('product_id', $product->id);
        $this->assertSame(300, $row['in_transit_quantity']);
    }

    public function test_invalid_minimax_response_returns_error_without_writes(): void
    {
        $organization = $this->organization('AI Invalid Org');
        $user = $this->user($organization, ['edit_products', 'manage_stock', 'manage_purchase_orders']);
        $product = $this->product($organization, 'NTS', '水晶娜塔莎', stock: 20);

        Http::fake([
            'api.minimax.io/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'not valid operations',
                    ],
                ]],
            ]),
        ]);

        $this->actingAs($user)
            ->postJson('/ai-operations/draft', ['instruction' => '把NTS库存改成500'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('instruction');

        $this->assertSame(20, $product->fresh()->stock);
        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertDatabaseCount('stock_adjustments', 0);
    }

    public function test_minimax_client_uses_saved_organization_ai_settings_before_env_config(): void
    {
        $organization = $this->organization('AI Settings Org');
        $user = $this->user($organization, ['edit_products', 'manage_stock', 'manage_purchase_orders']);
        $product = $this->product($organization, 'NTS', '水晶娜塔莎', stock: 20);

        config()->set('services.minimax.api_key', 'env-key');
        config()->set('services.minimax.base_url', 'https://env.minimax.test/v1');
        config()->set('services.minimax.model', 'EnvModel');

        $this->actingAs($user);
        SettingsService::set('ai.minimax.api_key', 'saved-key', true);
        SettingsService::set('ai.minimax.base_url', 'https://saved.minimax.test/v1');
        SettingsService::set('ai.minimax.model', 'SavedModel');

        Http::fake([
            'saved.minimax.test/v1/chat/completions' => Http::response($this->minimaxToolResponse([
                [
                    'type' => 'cost_update',
                    'product_ref' => 'NTS',
                    'fields' => [
                        'selling_price_usd' => 9.68,
                    ],
                ],
            ])),
        ]);

        $this->postJson('/ai-operations/draft', [
            'instruction' => '把娜塔莎售价改成9.68',
        ])->assertCreated();

        Http::assertSent(function ($request) use ($product): bool {
            return $request->url() === 'https://saved.minimax.test/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer saved-key')
                && $request['model'] === 'SavedModel'
                && str_contains((string) data_get($request->data(), 'messages.1.content'), $product->sku);
        });
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
            'name' => 'AI User',
            'email' => uniqid('ai-user-').'@example.com',
            'password' => bcrypt('password'),
            'organization_id' => $organization->id,
            'role' => 'member',
        ]);

        if ($permissions !== []) {
            $role = Role::create([
                'name' => 'AI Role '.uniqid(),
                'slug' => 'ai-role-'.uniqid(),
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
        string $name,
        int $stock
    ): Product {
        return Product::create([
            'organization_id' => $organization->id,
            'type' => 'standard',
            'sku' => $sku,
            'name' => $name,
            'price' => 10,
            'selling_price' => 10,
            'currency' => 'USD',
            'stock' => $stock,
            'min_stock' => 0,
            'is_active' => true,
            'is_sellable' => true,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $operations
     * @return array<string, mixed>
     */
    private function minimaxToolResponse(array $operations): array
    {
        return [
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => [
                            'name' => 'draft_inventory_operations',
                            'arguments' => json_encode(['operations' => $operations], JSON_THROW_ON_ERROR),
                        ],
                    ]],
                ],
            ]],
        ];
    }
}
