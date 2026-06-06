<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductComponent;
use App\Models\Order\OrderItem;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseOrderItem;
use InvalidArgumentException;

final class InventoryPlanningService
{
    /**
     * @return array{
     *     window_days: int,
     *     low_stock_days: float,
     *     exchange_rate_cny_per_usd: float,
     *     rows: array<int, array<string, mixed>>
     * }
     */
    public function report(
        int $organizationId,
        int $windowDays = 7,
        float $lowStockDays = 21,
        float $exchangeRateCnyPerUsd = 7.2
    ): array {
        if ($windowDays <= 0) {
            throw new InvalidArgumentException('Inventory planning window must be greater than zero.');
        }
        if ($exchangeRateCnyPerUsd <= 0) {
            throw new InvalidArgumentException('CNY per USD exchange rate must be greater than zero.');
        }

        $products = Product::forOrganization($organizationId)
            ->active()
            ->get()
            ->keyBy('id');
        $baseProducts = $products->where('type', 'standard');
        $kitIds = $products->where('type', 'kit')->keys()->all();
        $components = ProductComponent::whereIn('parent_product_id', $kitIds)->get();
        $componentsByParent = $components->groupBy('parent_product_id');
        $kitIdsByBase = $components->groupBy('component_product_id');

        $salesByProduct = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.organization_id', $organizationId)
            ->whereNull('orders.deleted_at')
            ->where('orders.status', '!=', 'cancelled')
            ->whereBetween('orders.order_date', [
                now()->subDays($windowDays - 1)->startOfDay(),
                now()->endOfDay(),
            ])
            ->whereNotNull('order_items.product_id')
            ->selectRaw('order_items.product_id, SUM(order_items.quantity) as quantity_sold')
            ->groupBy('order_items.product_id')
            ->get()
            ->pluck('quantity_sold', 'product_id');

        $baseUnitsSold = [];
        foreach ($salesByProduct as $productId => $quantitySold) {
            $soldProduct = $products->get((int) $productId);
            if (! $soldProduct) {
                continue;
            }

            if ($soldProduct->isKit()) {
                foreach ($componentsByParent->get($soldProduct->id, collect()) as $component) {
                    $baseId = (int) $component->component_product_id;
                    $baseUnitsSold[$baseId] = ($baseUnitsSold[$baseId] ?? 0)
                        + (int) round((float) $component->quantity * (int) $quantitySold);
                }
            } else {
                $baseUnitsSold[$soldProduct->id] = ($baseUnitsSold[$soldProduct->id] ?? 0)
                    + (int) $quantitySold;
            }
        }

        $inboundItems = PurchaseOrderItem::query()
            ->whereHas('purchaseOrder', function ($query) use ($organizationId) {
                $query->where('organization_id', $organizationId)
                    ->whereIn('status', [PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_PARTIAL]);
            })
            ->with('purchaseOrder')
            ->get();

        $inTransitByProduct = [];
        $shipmentsByProduct = [];
        foreach ($inboundItems as $inboundItem) {
            $remaining = max(0, $inboundItem->quantity_ordered - $inboundItem->quantity_received);
            if ($remaining === 0 || ! $inboundItem->product_id) {
                continue;
            }

            $productId = (int) $inboundItem->product_id;
            $inTransitByProduct[$productId] = ($inTransitByProduct[$productId] ?? 0) + $remaining;
            $shipmentsByProduct[$productId][] = [
                'purchase_order_id' => $inboundItem->purchaseOrder->id,
                'po_number' => $inboundItem->purchaseOrder->po_number,
                'shipping_method' => $inboundItem->purchaseOrder->shipping_method,
                'expected_date' => $inboundItem->purchaseOrder->expected_date?->toDateString(),
                'remaining_quantity' => $remaining,
            ];
        }

        $rows = $baseProducts->map(function (Product $baseProduct) use (
            $baseUnitsSold,
            $inTransitByProduct,
            $shipmentsByProduct,
            $kitIdsByBase,
            $componentsByParent,
            $products,
            $windowDays,
            $lowStockDays,
            $exchangeRateCnyPerUsd
        ) {
            $soldUnits = (int) ($baseUnitsSold[$baseProduct->id] ?? 0);
            $averageDailyUnits = $soldUnits > 0 ? $soldUnits / $windowDays : null;
            $warehouseStock = (int) $baseProduct->stock;
            $inTransitQuantity = (int) ($inTransitByProduct[$baseProduct->id] ?? 0);
            $warehouseDays = $averageDailyUnits ? $warehouseStock / $averageDailyUnits : null;
            $inTransitDays = $averageDailyUnits ? $inTransitQuantity / $averageDailyUnits : null;
            $totalDays = $averageDailyUnits ? $warehouseDays + $inTransitDays : null;

            $sellableProducts = collect([$baseProduct]);
            foreach ($kitIdsByBase->get($baseProduct->id, collect()) as $component) {
                $kit = $products->get((int) $component->parent_product_id);
                if ($kit) {
                    $sellableProducts->push($kit);
                }
            }

            $skus = $sellableProducts
                ->unique('id')
                ->map(function (Product $sellableProduct) use (
                    $baseProduct,
                    $componentsByParent,
                    $products,
                    $exchangeRateCnyPerUsd
                ) {
                    $unitsPerBase = 1.0;
                    $componentCost = (float) $sellableProduct->weighted_average_cost_cny;

                    if ($sellableProduct->isKit()) {
                        $componentCost = 0.0;
                        $unitsPerBase = 0.0;
                        foreach ($componentsByParent->get($sellableProduct->id, collect()) as $component) {
                            $componentProduct = $products->get((int) $component->component_product_id);
                            if (! $componentProduct) {
                                continue;
                            }

                            $quantity = (float) $component->quantity;
                            $componentCost += (float) $componentProduct->weighted_average_cost_cny * $quantity;
                            if ($componentProduct->id === $baseProduct->id) {
                                $unitsPerBase = $quantity;
                            }
                        }
                    }

                    $costCny = round($componentCost + (float) $sellableProduct->packaging_cost_cny, 4);
                    $costUsd = round($costCny / $exchangeRateCnyPerUsd, 4);
                    $sellingPriceUsd = (float) ($sellableProduct->selling_price ?? $sellableProduct->price ?? 0);
                    $grossProfitUsd = round($sellingPriceUsd - $costUsd, 4);
                    $grossMarginPercent = $sellingPriceUsd > 0
                        ? round(($grossProfitUsd / $sellingPriceUsd) * 100, 2)
                        : null;

                    return [
                        'product_id' => $sellableProduct->id,
                        'name' => $sellableProduct->name,
                        'sku' => $sellableProduct->sku,
                        'units_per_base' => $unitsPerBase,
                        'selling_price_usd' => $sellingPriceUsd,
                        'cost_cny' => $costCny,
                        'cost_usd' => $costUsd,
                        'gross_profit_usd' => $grossProfitUsd,
                        'gross_margin_percent' => $grossMarginPercent,
                    ];
                })
                ->values()
                ->all();

            return [
                'base_product_id' => $baseProduct->id,
                'name' => $baseProduct->name,
                'sku' => $baseProduct->sku,
                'warehouse_stock' => $warehouseStock,
                'in_transit_quantity' => $inTransitQuantity,
                'base_units_sold' => $soldUnits,
                'average_daily_units' => $averageDailyUnits !== null ? round($averageDailyUnits, 4) : null,
                'warehouse_days' => $warehouseDays !== null ? round($warehouseDays, 2) : null,
                'in_transit_days' => $inTransitDays !== null ? round($inTransitDays, 2) : null,
                'total_days' => $totalDays !== null ? round($totalDays, 2) : null,
                'is_low_stock' => $warehouseDays !== null && $warehouseDays <= $lowStockDays,
                'shipments' => $shipmentsByProduct[$baseProduct->id] ?? [],
                'skus' => $skus,
            ];
        })
            ->sortBy([
                ['is_low_stock', 'desc'],
                ['warehouse_days', 'asc'],
                ['name', 'asc'],
            ])
            ->values()
            ->all();

        return [
            'window_days' => $windowDays,
            'low_stock_days' => $lowStockDays,
            'exchange_rate_cny_per_usd' => $exchangeRateCnyPerUsd,
            'rows' => $rows,
        ];
    }
}
