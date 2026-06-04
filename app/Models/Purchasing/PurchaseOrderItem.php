<?php

declare(strict_types=1);

namespace App\Models\Purchasing;

use App\Models\Inventory\Product;
use App\Models\Inventory\StockAdjustment;
use App\Services\InboundReceivingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Represents an item within a purchase order.
 *
 * @property int $id
 * @property int $purchase_order_id
 * @property int|null $product_id
 * @property string $product_name
 * @property string|null $sku
 * @property string|null $supplier_sku
 * @property int $quantity_ordered
 * @property int $quantity_received
 * @property string $unit_cost
 * @property string|null $landed_unit_cost_cny
 * @property string $subtotal
 * @property string $tax
 * @property string $total
 * @property string|null $notes
 * @property array|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int $remaining_quantity
 * @property-read PurchaseOrder $purchaseOrder
 * @property-read Product|null $product
 */
class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'product_id',
        'product_name',
        'sku',
        'supplier_sku',
        'quantity_ordered',
        'quantity_received',
        'unit_cost',
        'landed_unit_cost_cny',
        'subtotal',
        'tax',
        'total',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'quantity_ordered' => 'integer',
        'quantity_received' => 'integer',
        'unit_cost' => 'decimal:2',
        'landed_unit_cost_cny' => 'decimal:4',
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the purchase order this item belongs to.
     *
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * Get the product associated with this item.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Calculate and set totals based on quantity and unit cost.
     */
    public function calculateTotals(): void
    {
        $this->subtotal = $this->quantity_ordered * $this->unit_cost;
        $this->total = $this->subtotal + ($this->tax ?? 0);
    }

    /**
     * Check if item is fully received.
     */
    public function isFullyReceived(): bool
    {
        return $this->quantity_received >= $this->quantity_ordered;
    }

    /**
     * Get remaining quantity to receive.
     */
    public function getRemainingQuantityAttribute(): int
    {
        return max(0, $this->quantity_ordered - $this->quantity_received);
    }

    /**
     * Receive a quantity of this item.
     *
     * Creates a stock adjustment and updates product stock.
     *
     * @param  int  $quantity  The quantity to receive
     */
    public function receive(int $quantity): ?StockAdjustment
    {
        return app(InboundReceivingService::class)->receiveItem($this, $quantity);
    }

    /**
     * Static method to create item from product.
     *
     * @return static
     */
    public static function fromProduct(Product $product, int $quantity, ?float $unitCost = null): self
    {
        // Try to get cost from supplier pivot or product
        if ($unitCost === null) {
            $unitCost = $product->purchase_price ?? $product->price ?? 0;
        }

        $item = new self([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'quantity_ordered' => $quantity,
            'quantity_received' => 0,
            'unit_cost' => $unitCost,
        ]);

        $item->calculateTotals();

        return $item;
    }
}
