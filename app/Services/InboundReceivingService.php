<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Inventory\Product;
use App\Models\Inventory\StockAdjustment;
use App\Models\Purchasing\PurchaseOrderItem;
use Illuminate\Support\Facades\DB;

final class InboundReceivingService
{
    public function receiveItem(PurchaseOrderItem $item, int $quantity): ?StockAdjustment
    {
        if ($quantity <= 0) {
            return null;
        }

        return DB::transaction(function () use ($item, $quantity) {
            $lockedItem = PurchaseOrderItem::whereKey($item->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedItem->load('purchaseOrder.items');

            $quantityToReceive = min($quantity, $lockedItem->remaining_quantity);
            if ($quantityToReceive <= 0 || ! $lockedItem->product_id) {
                return null;
            }

            $purchaseOrder = $lockedItem->purchaseOrder;
            $product = Product::whereKey($lockedItem->product_id)
                ->where('organization_id', $purchaseOrder->organization_id)
                ->lockForUpdate()
                ->first();
            if (! $product) {
                return null;
            }

            $itemUpdates = [
                'quantity_received' => $lockedItem->quantity_received + $quantityToReceive,
            ];

            if (strtoupper((string) $purchaseOrder->currency) === 'CNY') {
                $totalOrderedUnits = (int) $purchaseOrder->items->sum('quantity_ordered');
                if ($totalOrderedUnits <= 0) {
                    throw new \DomainException('Inbound landed-cost allocation requires at least one ordered unit.');
                }

                $totalFreight = bcadd(
                    (string) $purchaseOrder->domestic_freight_cny,
                    (string) $purchaseOrder->first_leg_freight_cny,
                    4
                );
                $freightPerUnit = bcdiv($totalFreight, (string) $totalOrderedUnits, 8);
                $landedUnitCost = $this->roundPositive(
                    bcadd((string) $lockedItem->unit_cost, $freightPerUnit, 8),
                    4
                );

                $oldStock = (int) $product->stock;
                $newStock = $oldStock + $quantityToReceive;
                $oldInventoryValue = bcmul((string) $product->weighted_average_cost_cny, (string) $oldStock, 8);
                $receivedInventoryValue = bcmul($landedUnitCost, (string) $quantityToReceive, 8);
                $newWeightedAverage = $this->roundPositive(
                    bcdiv(
                        bcadd($oldInventoryValue, $receivedInventoryValue, 8),
                        (string) $newStock,
                        8
                    ),
                    4
                );

                $itemUpdates['landed_unit_cost_cny'] = $landedUnitCost;
                $product->update(['weighted_average_cost_cny' => $newWeightedAverage]);
            }

            $lockedItem->update($itemUpdates);

            $adjustment = StockAdjustment::adjust(
                product: $product,
                quantity: $quantityToReceive,
                type: 'purchase',
                reason: "PO {$purchaseOrder->po_number} received",
                notes: $lockedItem->notes,
                reference: $purchaseOrder
            );

            $purchaseOrder->refresh()->load('items');
            $purchaseOrder->updateReceivingStatus();

            $item->setRawAttributes($lockedItem->fresh()->getAttributes());
            $item->syncOriginal();

            return $adjustment;
        });
    }

    private function roundPositive(string $value, int $scale): string
    {
        $increment = '0.'.str_repeat('0', $scale).'5';

        return bcadd($value, $increment, $scale);
    }
}
