<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\InvalidOrderItemException;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductComponent;
use App\Services\SalesStockResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesStockResolverTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Resolver Org',
            'email' => 'resolver@example.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);
    }

    public function test_standard_product_resolves_to_itself(): void
    {
        $product = $this->product('STANDARD', 'standard', 20);

        $resolved = app(SalesStockResolver::class)->resolve(
            $this->organization->id,
            [['product_id' => $product->id, 'quantity' => 3]]
        );

        $this->assertCount(1, $resolved['lines']);
        $this->assertSame(3, $resolved['targets']["p{$product->id}"]['quantity']);
        $this->assertSame($product->id, $resolved['targets']["p{$product->id}"]['target']->id);
    }

    public function test_kit_resolves_to_component_quantity(): void
    {
        $base = $this->product('BASE', 'standard', 20);
        $threePack = $this->product('THREE-PACK', 'kit', 0);
        ProductComponent::create([
            'parent_product_id' => $threePack->id,
            'component_product_id' => $base->id,
            'quantity' => 3,
        ]);

        $resolved = app(SalesStockResolver::class)->resolve(
            $this->organization->id,
            [['product_id' => $threePack->id, 'quantity' => 2]]
        );

        $this->assertSame(6, $resolved['targets']["p{$base->id}"]['quantity']);
        $this->assertSame($threePack->id, $resolved['lines'][0]['product']->id);
    }

    public function test_multiple_kits_aggregate_onto_shared_component(): void
    {
        $base = $this->product('BASE', 'standard', 30);
        $twoPack = $this->product('TWO-PACK', 'kit', 0);
        $threePack = $this->product('THREE-PACK', 'kit', 0);
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

        $resolved = app(SalesStockResolver::class)->resolve(
            $this->organization->id,
            [
                ['product_id' => $twoPack->id, 'quantity' => 2],
                ['product_id' => $threePack->id, 'quantity' => 3],
            ]
        );

        $this->assertSame(13, $resolved['targets']["p{$base->id}"]['quantity']);
    }

    public function test_fractional_kit_component_is_rejected(): void
    {
        $base = $this->product('BASE', 'standard', 30);
        $fractionalKit = $this->product('FRACTIONAL', 'kit', 0);
        ProductComponent::create([
            'parent_product_id' => $fractionalKit->id,
            'component_product_id' => $base->id,
            'quantity' => 1.5,
        ]);

        $this->expectException(InvalidOrderItemException::class);
        $this->expectExceptionMessage('fractional component stock');

        app(SalesStockResolver::class)->resolve(
            $this->organization->id,
            [['product_id' => $fractionalKit->id, 'quantity' => 1]]
        );
    }

    private function product(string $sku, string $type, int $stock): Product
    {
        return Product::create([
            'organization_id' => $this->organization->id,
            'type' => $type,
            'sku' => $sku,
            'name' => $sku,
            'price' => 10,
            'currency' => 'USD',
            'stock' => $stock,
            'min_stock' => 0,
            'is_active' => true,
        ]);
    }
}
