<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InvalidOrderItemException;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductComponent;
use App\Models\Inventory\ProductVariant;

/**
 * Resolve sellable order lines to the physical stock records they consume.
 *
 * Standard products consume their own stock, variants consume variant stock,
 * and kits expand to their component products. Callers can then validate or
 * apply the aggregated physical-stock requirements consistently.
 */
final class SalesStockResolver
{
    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{
     *     lines: array<int, array{
     *         item: array<string, mixed>,
     *         product: Product,
     *         variant: ?ProductVariant,
     *         qty: int,
     *         stockTargets: array<int, array{
     *             target: Product|ProductVariant,
     *             key: string,
     *             qty: int,
     *             label: string
     *         }>
     *     }>,
     *     targets: array<string, array{
     *         target: Product|ProductVariant,
     *         quantity: int,
     *         qty: int,
     *         label: string
     *     }>
     * }
     */
    public function resolve(int $organizationId, array $items, bool $lockForUpdate = true): array
    {
        $soldProductIds = array_values(array_unique(array_map(
            fn (array $item): int => (int) $item['product_id'],
            $items
        )));

        $kitComponents = ProductComponent::whereIn('parent_product_id', $soldProductIds)
            ->get()
            ->groupBy('parent_product_id');
        $componentProductIds = $kitComponents
            ->flatten(1)
            ->pluck('component_product_id')
            ->all();
        $productIds = array_values(array_unique([...$soldProductIds, ...$componentProductIds]));
        sort($productIds);

        $productQuery = Product::whereIn('id', $productIds)
            ->where('organization_id', $organizationId);
        if ($lockForUpdate) {
            $productQuery->lockForUpdate();
        }
        $products = $productQuery->get()->keyBy('id');

        $variantIds = array_values(array_unique(array_filter(
            array_map(fn (array $item) => $item['product_variant_id'] ?? null, $items)
        )));
        $variants = collect();
        if ($variantIds !== []) {
            $variantQuery = ProductVariant::whereIn('id', $variantIds)
                ->where('organization_id', $organizationId);
            if ($lockForUpdate) {
                $variantQuery->lockForUpdate();
            }
            $variants = $variantQuery->get()->keyBy('id');
        }

        $lines = [];
        $targets = [];

        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            if (! $products->has($productId)) {
                throw new \Exception("Product not found: {$productId}");
            }

            $product = $products[$productId];
            $variantId = $item['product_variant_id'] ?? null;
            $quantity = (int) $item['quantity'];

            if ($variantId !== null) {
                $variant = $variants->get($variantId);
                if (! $variant || $variant->product_id !== $product->id) {
                    throw new InvalidOrderItemException(
                        "Variant {$variantId} does not belong to product {$product->name}."
                    );
                }

                $stockTargets = [[
                    'target' => $variant,
                    'key' => "v{$variantId}",
                    'qty' => $quantity,
                    'label' => $this->lineLabel($product, $variant),
                ]];
            } else {
                if ($product->has_variants) {
                    throw new InvalidOrderItemException(
                        "{$product->name} is sold by variant; each line item needs a product_variant_id."
                    );
                }

                $variant = null;
                if ($product->isKit()) {
                    $components = $kitComponents->get($product->id, collect());
                    if ($components->isEmpty()) {
                        throw new InvalidOrderItemException(
                            "{$product->name} is a kit without components."
                        );
                    }

                    $stockTargets = $components->map(function (ProductComponent $component) use ($products, $product, $quantity): array {
                        $target = $products->get($component->component_product_id);
                        if (! $target) {
                            throw new InvalidOrderItemException(
                                "{$product->name} references a component outside the organization."
                            );
                        }

                        $required = (float) $component->quantity * $quantity;
                        if (floor($required) !== $required) {
                            throw new InvalidOrderItemException(
                                "{$product->name} requires fractional component stock, which is not supported."
                            );
                        }

                        return [
                            'target' => $target,
                            'key' => "p{$target->id}",
                            'qty' => (int) $required,
                            'label' => "{$product->name} component {$target->name}",
                        ];
                    })->all();
                } else {
                    $stockTargets = [[
                        'target' => $product,
                        'key' => "p{$productId}",
                        'qty' => $quantity,
                        'label' => $product->name,
                    ]];
                }
            }

            foreach ($stockTargets as $stockTarget) {
                $key = $stockTarget['key'];
                $total = ($targets[$key]['quantity'] ?? 0) + $stockTarget['qty'];
                $targets[$key] = [
                    'target' => $stockTarget['target'],
                    'quantity' => $total,
                    'qty' => $total,
                    'label' => $stockTarget['label'],
                ];
            }

            $lines[] = compact('item', 'product', 'variant', 'stockTargets')
                + ['qty' => $quantity];
        }

        return compact('lines', 'targets');
    }

    private function lineLabel(Product $product, ?ProductVariant $variant): string
    {
        if ($variant !== null) {
            $descriptor = $variant->title ?? $variant->sku ?? "variant {$variant->id}";

            return "{$product->name} ({$descriptor})";
        }

        return $product->name;
    }
}
