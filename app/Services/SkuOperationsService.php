<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductComponent;
use App\Models\Inventory\ProductVariant;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseOrderItem;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Build the read model for TikTok US SKU operations and weekly sales entry.
 */
final class SkuOperationsService
{
    public const DEFAULT_EXCHANGE_RATE_CNY_PER_USD = 7.2;

    public const DEFAULT_LOW_STOCK_DAYS = 21.0;

    public const COVERAGE_WINDOW_DAYS = 7;

    /**
     * @return array<string, mixed>
     */
    public function report(int $organizationId, CarbonImmutable $weekStart): array
    {
        $weekStart = $weekStart->startOfDay();
        if (! $weekStart->isMonday()) {
            throw new InvalidArgumentException('SKU operations week_start must be a Monday.');
        }

        [$exchangeRate, $lowStockDays] = $this->settings($organizationId);
        $days = $this->days($weekStart);
        $weekEnd = $weekStart->addDays(6)->endOfDay();

        $sellableProducts = Product::forOrganization($organizationId)
            ->active()
            ->where('is_sellable', true)
            ->get()
            ->keyBy('id');
        $variantsByProduct = ProductVariant::forOrganization($organizationId)
            ->active()
            ->whereIn('product_id', $sellableProducts->keys()->all())
            ->get()
            ->groupBy('product_id');
        $kitIds = $sellableProducts->where('type', 'kit')->keys()->all();
        $componentsByKit = ProductComponent::whereIn('parent_product_id', $kitIds)
            ->get()
            ->groupBy('parent_product_id');
        $inventoryProductIds = array_values(array_unique([
            ...$sellableProducts->keys()->all(),
            ...$componentsByKit->flatten(1)->pluck('component_product_id')->all(),
        ]));
        $inventoryProducts = Product::forOrganization($organizationId)
            ->whereIn('id', $inventoryProductIds)
            ->get()
            ->keyBy('id');

        $inTransitByProduct = $this->inTransitByProduct($organizationId, $inventoryProductIds);
        $dailySalesByEntry = $this->selectedWeekSales(
            $organizationId,
            $weekStart,
            $weekEnd,
            $sellableProducts->keys()->all()
        );
        $recentSalesByEntry = $this->recentSales(
            $organizationId,
            $sellableProducts->keys()->all()
        );

        $rows = $sellableProducts
            ->flatMap(function (Product $product) use (
                $componentsByKit,
                $inventoryProducts,
                $inTransitByProduct,
                $dailySalesByEntry,
                $recentSalesByEntry,
                $variantsByProduct,
                $days,
                $exchangeRate,
                $lowStockDays
            ) {
                $components = $componentsByKit->get($product->id, collect());
                $productCostCny = $this->productCostCny($product, $components, $inventoryProducts);
                $productCostUsd = round($productCostCny / $exchangeRate, 4);
                $domesticLogisticsCostUsd = round(
                    $this->domesticLogisticsCostCny($product, $components, $inventoryProducts) / $exchangeRate,
                    4
                );
                $usFirstLegCostUsd = round(
                    $this->usFirstLegCostCny($product, $components, $inventoryProducts) / $exchangeRate,
                    4
                );
                $packingCostUsd = round(
                    ((float) $product->packaging_cost_cny + (float) $product->packing_labor_cost_cny)
                    / $exchangeRate,
                    4
                );
                $usLastMileCostUsd = round((float) $product->last_mile_cost_usd, 4);
                $unitTotalCostUsd = round(
                    $productCostUsd
                    + $domesticLogisticsCostUsd
                    + $usFirstLegCostUsd
                    + $usLastMileCostUsd
                    + $packingCostUsd,
                    4
                );

                $warehouseStock = $product->isKit()
                    ? $this->completeKitQuantity(
                        $components,
                        $inventoryProducts,
                        fn (Product $component): int => (int) $component->stock
                    )
                    : (int) $product->stock;
                $inTransitQuantity = $product->isKit()
                    ? $this->completeKitQuantity(
                        $components,
                        $inventoryProducts,
                        fn (Product $component): int => (int) ($inTransitByProduct[$component->id] ?? 0)
                    )
                    : (int) ($inTransitByProduct[$product->id] ?? 0);

                $variants = $product->has_variants
                    ? $variantsByProduct->get($product->id, collect())
                    : collect();
                $entries = $variants->isNotEmpty() ? $variants : collect([null]);

                return $entries->map(function (?ProductVariant $variant) use (
                    $product,
                    $days,
                    $dailySalesByEntry,
                    $recentSalesByEntry,
                    $lowStockDays,
                    $warehouseStock,
                    $inTransitQuantity,
                    $productCostUsd,
                    $domesticLogisticsCostUsd,
                    $usFirstLegCostUsd,
                    $usLastMileCostUsd,
                    $packingCostUsd,
                    $unitTotalCostUsd,
                    $components,
                    $inventoryProducts,
                    $inTransitByProduct
                ): array {
                    $entryKey = $this->entryKey($product, $variant);
                    $sellingPriceUsd = round((float) ($variant?->price ?? $product->selling_price ?? $product->price ?? 0), 4);
                    $grossProfitUsd = round($sellingPriceUsd - $unitTotalCostUsd, 4);
                    $grossMarginPercent = $sellingPriceUsd > 0
                        ? round(($grossProfitUsd / $sellingPriceUsd) * 100, 2)
                        : null;

                    $dailyQuantities = [];
                    foreach ($days as $day) {
                        $dailyQuantities[$day['date']] = (int) ($dailySalesByEntry[$entryKey][$day['date']] ?? 0);
                    }
                    $weeklySalesTotal = array_sum($dailyQuantities);
                    $recentUnits = (int) ($recentSalesByEntry[$entryKey] ?? 0);
                    $averageDailyUnits = $recentUnits > 0 ? $recentUnits / self::COVERAGE_WINDOW_DAYS : null;
                    $sellableDays = $averageDailyUnits !== null
                        ? round($warehouseStock / $averageDailyUnits, 2)
                        : null;
                    $isLowStock = $sellableDays !== null && $sellableDays <= $lowStockDays;

                    return [
                        'entry_key' => $entryKey,
                        'product_id' => $product->id,
                        'variant_id' => $variant?->id,
                        'sku' => $variant?->sku ?? $product->sku,
                        'parent_sku' => $product->sku,
                        'name' => $variant === null ? $product->name : $this->variantDisplayName($product, $variant),
                        'parent_name' => $product->name,
                        'variant_title' => $variant?->title,
                        'type' => $product->type,
                        'is_entry_supported' => ! $product->has_variants || $variant !== null,
                        'selling_price_usd' => $sellingPriceUsd,
                        'product_cost_usd' => $productCostUsd,
                        'domestic_logistics_cost_usd' => $domesticLogisticsCostUsd,
                        'us_first_leg_cost_usd' => $usFirstLegCostUsd,
                        'us_last_mile_cost_usd' => $usLastMileCostUsd,
                        'last_mile_cost_usd' => $usLastMileCostUsd,
                        'packing_cost_usd' => $packingCostUsd,
                        'unit_total_cost_usd' => $unitTotalCostUsd,
                        'gross_profit_usd' => $grossProfitUsd,
                        'gross_margin_percent' => $grossMarginPercent,
                        'warehouse_stock' => $warehouseStock,
                        'in_transit_quantity' => $inTransitQuantity,
                        'recent_sales_units' => $recentUnits,
                        'sellable_days' => $sellableDays,
                        'is_low_stock' => $isLowStock,
                        'daily_quantities' => $dailyQuantities,
                        'weekly_sales_total' => $weeklySalesTotal,
                        'component_impact' => $this->componentImpact(
                            $components,
                            $inventoryProducts,
                            $inTransitByProduct
                        ),
                    ];
                });
            })
            ->sort(function (array $left, array $right): int {
                if ($left['is_low_stock'] !== $right['is_low_stock']) {
                    return $left['is_low_stock'] ? -1 : 1;
                }

                return strcasecmp((string) ($left['sku'] ?? $left['name']), (string) ($right['sku'] ?? $right['name']));
            })
            ->values()
            ->all();

        $summary = [
            'units_sold' => array_sum(array_column($rows, 'weekly_sales_total')),
            'estimated_revenue_usd' => round(array_sum(array_map(
                fn (array $row): float => $row['weekly_sales_total'] * $row['selling_price_usd'],
                $rows
            )), 2),
            'estimated_gross_profit_usd' => round(array_sum(array_map(
                fn (array $row): float => $row['weekly_sales_total'] * $row['gross_profit_usd'],
                $rows
            )), 2),
            'replenishment_sku_count' => count(array_filter(
                $rows,
                fn (array $row): bool => $row['is_low_stock']
            )),
        ];

        return [
            'store' => 'TikTok US',
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'days' => $days,
            'settings' => [
                'exchange_rate_cny_per_usd' => $exchangeRate,
                'low_stock_days' => $lowStockDays,
            ],
            'summary' => $summary,
            'rows' => $rows,
        ];
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function settings(int $organizationId): array
    {
        $settings = Setting::forOrganization($organizationId)
            ->whereIn('key', [
                'inventory.exchange_rate_cny_per_usd',
                'inventory.low_stock_days',
            ])
            ->pluck('value', 'key');

        $exchangeRate = (float) $settings->get(
            'inventory.exchange_rate_cny_per_usd',
            self::DEFAULT_EXCHANGE_RATE_CNY_PER_USD
        );
        $lowStockDays = (float) $settings->get(
            'inventory.low_stock_days',
            self::DEFAULT_LOW_STOCK_DAYS
        );

        if ($exchangeRate <= 0) {
            $exchangeRate = self::DEFAULT_EXCHANGE_RATE_CNY_PER_USD;
        }
        if ($lowStockDays < 0) {
            $lowStockDays = self::DEFAULT_LOW_STOCK_DAYS;
        }

        return [$exchangeRate, $lowStockDays];
    }

    /**
     * @return array<int, array{date: string, label: string}>
     */
    private function days(CarbonImmutable $weekStart): array
    {
        $days = [];
        for ($day = 0; $day < 7; $day++) {
            $date = $weekStart->addDays($day);
            $days[] = [
                'date' => $date->toDateString(),
                'label' => $date->format('D'),
            ];
        }

        return $days;
    }

    /**
     * @param  array<int, int>  $productIds
     * @return array<int, int>
     */
    private function inTransitByProduct(int $organizationId, array $productIds): array
    {
        $inTransit = [];
        $items = PurchaseOrderItem::query()
            ->whereIn('product_id', $productIds)
            ->whereHas('purchaseOrder', function ($query) use ($organizationId): void {
                $query->where('organization_id', $organizationId)
                    ->whereIn('status', [PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_PARTIAL]);
            })
            ->get();

        foreach ($items as $item) {
            $remaining = max(0, (int) $item->quantity_ordered - (int) $item->quantity_received);
            if ($remaining > 0 && $item->product_id !== null) {
                $inTransit[$item->product_id] = ($inTransit[$item->product_id] ?? 0) + $remaining;
            }
        }

        return $inTransit;
    }

    /**
     * @param  array<int, int>  $productIds
     * @return array<string, array<string, int>>
     */
    private function selectedWeekSales(
        int $organizationId,
        CarbonImmutable $weekStart,
        CarbonImmutable $weekEnd,
        array $productIds
    ): array {
        $sales = [];
        $orders = Order::with('items')
            ->where('organization_id', $organizationId)
            ->where('source', WeeklySalesService::SOURCE)
            ->where('status', '!=', 'cancelled')
            ->whereBetween('order_date', [$weekStart, $weekEnd])
            ->get();

        foreach ($orders as $order) {
            $date = $order->order_date->toDateString();
            foreach ($order->items as $item) {
                if ($item->product_id !== null && in_array($item->product_id, $productIds, true)) {
                    $entryKey = $this->orderItemEntryKey($item);
                    $sales[$entryKey][$date] = ($sales[$entryKey][$date] ?? 0)
                        + (int) $item->quantity;
                }
            }
        }

        return $sales;
    }

    /**
     * @param  array<int, int>  $productIds
     * @return array<string, int>
     */
    private function recentSales(int $organizationId, array $productIds): array
    {
        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.organization_id', $organizationId)
            ->whereNull('orders.deleted_at')
            ->where('orders.status', '!=', 'cancelled')
            ->whereIn('order_items.product_id', $productIds)
            ->whereBetween('orders.order_date', [
                now()->subDays(self::COVERAGE_WINDOW_DAYS - 1)->startOfDay(),
                now()->endOfDay(),
            ])
            ->selectRaw('order_items.product_id, order_items.product_variant_id, SUM(order_items.quantity) as quantity_sold')
            ->groupBy('order_items.product_id', 'order_items.product_variant_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [$this->orderItemEntryKey($row) => (int) $row->quantity_sold])
            ->all();
    }

    private function entryKey(Product $product, ?ProductVariant $variant = null): string
    {
        return $variant !== null ? "v:{$variant->id}" : "p:{$product->id}";
    }

    private function orderItemEntryKey($item): string
    {
        return $item->product_variant_id !== null
            ? "v:{$item->product_variant_id}"
            : "p:{$item->product_id}";
    }

    private function variantDisplayName(Product $product, ProductVariant $variant): string
    {
        $descriptor = $variant->title ?? $variant->sku ?? "Variant {$variant->id}";

        return "{$product->name} - {$descriptor}";
    }

    /**
     * @param  Collection<int, ProductComponent>  $components
     * @param  Collection<int, Product>  $products
     */
    private function productCostCny(Product $product, Collection $components, Collection $products): float
    {
        return $this->componentAwareCostCny(
            $product,
            $components,
            $products,
            fn (Product $costProduct): float => $this->metadataUnitCostCny(
                $costProduct,
                ['unit_goods_cost_cny', 'goods_unit_cost_cny'],
                (float) $costProduct->weighted_average_cost_cny
            )
        );
    }

    /**
     * @param  Collection<int, ProductComponent>  $components
     * @param  Collection<int, Product>  $products
     */
    private function domesticLogisticsCostCny(Product $product, Collection $components, Collection $products): float
    {
        return $this->componentAwareCostCny(
            $product,
            $components,
            $products,
            fn (Product $costProduct): float => $this->metadataUnitCostCny(
                $costProduct,
                ['domestic_logistics_unit_cny', 'domestic_freight_unit_cny']
            )
        );
    }

    /**
     * @param  Collection<int, ProductComponent>  $components
     * @param  Collection<int, Product>  $products
     */
    private function usFirstLegCostCny(Product $product, Collection $components, Collection $products): float
    {
        return $this->componentAwareCostCny(
            $product,
            $components,
            $products,
            fn (Product $costProduct): float => $this->metadataUnitCostCny(
                $costProduct,
                [
                    'first_leg_freight_unit_cny',
                    'first_leg_unit_cost_cny',
                    'first_leg_unit_cny',
                ]
            )
        );
    }

    /**
     * @param  Collection<int, ProductComponent>  $components
     * @param  Collection<int, Product>  $products
     */
    private function componentAwareCostCny(
        Product $product,
        Collection $components,
        Collection $products,
        callable $unitCost
    ): float {
        if (! $product->isKit()) {
            return round($unitCost($product), 4);
        }

        return round($components->sum(function (ProductComponent $component) use ($products, $unitCost): float {
            $componentProduct = $products->get($component->component_product_id);

            return $componentProduct
                ? $unitCost($componentProduct) * (float) $component->quantity
                : 0.0;
        }), 4);
    }

    private function metadataUnitCostCny(Product $product, array $keys, float $fallback = 0.0): float
    {
        $metadata = $product->metadata ?? [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $metadata) && is_numeric($metadata[$key])) {
                return (float) $metadata[$key];
            }
        }

        return $fallback;
    }

    /**
     * @param  Collection<int, ProductComponent>  $components
     * @param  Collection<int, Product>  $products
     */
    private function completeKitQuantity(Collection $components, Collection $products, callable $available): int
    {
        if ($components->isEmpty()) {
            return 0;
        }

        $limits = [];
        foreach ($components as $component) {
            $componentProduct = $products->get($component->component_product_id);
            $quantityPerKit = (float) $component->quantity;
            if (! $componentProduct || $quantityPerKit <= 0) {
                return 0;
            }
            $limits[] = (int) floor($available($componentProduct) / $quantityPerKit);
        }

        return min($limits);
    }

    /**
     * @param  Collection<int, ProductComponent>  $components
     * @param  Collection<int, Product>  $products
     * @param  array<int, int>  $inTransitByProduct
     * @return array<int, array<string, mixed>>
     */
    private function componentImpact(
        Collection $components,
        Collection $products,
        array $inTransitByProduct
    ): array {
        return $components
            ->map(function (ProductComponent $component) use ($products, $inTransitByProduct): ?array {
                $product = $products->get($component->component_product_id);
                if (! $product) {
                    return null;
                }

                return [
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'quantity_per_sale' => (float) $component->quantity,
                    'warehouse_stock' => (int) $product->stock,
                    'in_transit_quantity' => (int) ($inTransitByProduct[$product->id] ?? 0),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
