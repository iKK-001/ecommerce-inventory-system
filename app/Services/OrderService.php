<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidOrderItemException;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductComponent;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\User;
use App\Support\Money;
use App\Support\SequenceNumberRetry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Single home for sales-order creation.
 *
 * The Inertia, REST, GraphQL, and MCP surfaces each used to hand-roll the same
 * lock-validate-decrement transaction, and they had already drifted (different
 * stock checks, different ledger fidelity, auth() vs payload for the actor).
 * This service owns the invariant so every surface creates orders identically:
 *
 *  - order_number is generated INSIDE the transaction and the whole thing is
 *    wrapped in SequenceNumberRetry, so a unique-constraint collision on a
 *    concurrent insert is retried rather than surfaced.
 *  - every referenced product is batch-locked with SELECT ... FOR UPDATE before
 *    any availability check, closing the read-modify-write race on stock.
 *  - availability is validated across ALL line items first (multiple lines of
 *    the same product accumulate against one running balance).
 *  - a StockAdjustment ledger row is written per line item with faithful
 *    quantity_before / quantity_after threading, and stock is decremented once
 *    per unique product.
 *
 * The caller resolves the warehouse (session/default fallback lives in the web
 * layer) and passes the acting User explicitly — the service never reaches for
 * auth(), so it behaves identically under web, API, queue, and console.
 */
final class OrderService
{
    /**
     * Create an order with its line items and stock movements.
     *
     * @param  array<string, mixed>  $data  Validated order payload. Must contain
     *                                      an `items` array of
     *                                      {product_id, quantity, unit_price};
     *                                      may contain customer_*, status,
     *                                      order_date, warehouse_id, tax,
     *                                      shipping, notes, approval_status.
     * @param  User  $creator  The acting user; sets organization_id, created_by,
     *                         and the ledger actor.
     * @param  string  $source  Order source channel (manual, ebay, …).
     *
     * @throws \Exception When a product is missing or stock is insufficient.
     * @throws QueryException On unrecoverable DB errors.
     */
    public function create(array $data, User $creator, string $source = 'manual'): Order
    {
        $data['organization_id'] = $creator->organization_id;
        $data['created_by'] = $creator->id;
        $data['source'] = $source;
        $data['approval_status'] ??= 'pending';

        $order = SequenceNumberRetry::create(fn () => DB::transaction(function () use ($data, $creator) {
            $orgId = $data['organization_id'];
            $data['order_number'] = Order::generateOrderNumber($orgId);

            // Batch-lock every referenced product (and variant) in single
            // SELECT ... FOR UPDATE queries so concurrent orders can't race the
            // read-modify-write on stock.
            $soldProductIds = array_unique(array_column($data['items'], 'product_id'));
            $kitComponents = ProductComponent::whereIn('parent_product_id', $soldProductIds)
                ->get()
                ->groupBy('parent_product_id');
            $componentProductIds = $kitComponents
                ->flatten(1)
                ->pluck('component_product_id')
                ->all();
            $productIds = array_values(array_unique([...$soldProductIds, ...$componentProductIds]));
            sort($productIds);

            $products = Product::whereIn('id', $productIds)
                ->where('organization_id', $orgId)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $variantIds = array_values(array_unique(array_filter(
                array_map(fn ($item) => $item['product_variant_id'] ?? null, $data['items'])
            )));
            $variants = $variantIds === []
                ? collect()
                : ProductVariant::whereIn('id', $variantIds)
                    ->where('organization_id', $orgId)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

            // Resolve the stock targets behind each sellable line. Standard
            // products and variants have one target; kits expand to their
            // component products so pack sizes share the same physical stock.
            $lines = [];
            foreach ($data['items'] as $item) {
                $pid = $item['product_id'];
                if (! $products->has($pid)) {
                    throw new \Exception("Product not found: {$pid}");
                }
                $product = $products[$pid];
                $variantId = $item['product_variant_id'] ?? null;

                if ($variantId !== null) {
                    $variant = $variants->get($variantId);
                    if (! $variant || $variant->product_id !== $product->id) {
                        throw new InvalidOrderItemException(
                            "Variant {$variantId} does not belong to product {$product->name}."
                        );
                    }
                    $target = $variant;
                    $key = "v{$variantId}";
                    $stockTargets = [[
                        'target' => $target,
                        'key' => $key,
                        'qty' => (int) $item['quantity'],
                        'label' => $this->lineLabel(compact('product', 'variant')),
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

                        $stockTargets = $components->map(function (ProductComponent $component) use ($products, $product, $item) {
                            $target = $products->get($component->component_product_id);
                            if (! $target) {
                                throw new InvalidOrderItemException(
                                    "{$product->name} references a component outside the organization."
                                );
                            }

                            $required = (float) $component->quantity * (int) $item['quantity'];
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
                            'key' => "p{$pid}",
                            'qty' => (int) $item['quantity'],
                            'label' => $product->name,
                        ]];
                    }
                }

                $lines[] = compact('item', 'product', 'variant', 'stockTargets')
                    + ['qty' => (int) $item['quantity']];
            }

            // Validate availability across ALL lines first. Lines sharing a
            // target (including different kit SKUs backed by the same base
            // product) accumulate against one running balance.
            $running = [];
            foreach ($lines as $line) {
                foreach ($line['stockTargets'] as $stockTarget) {
                    $key = $stockTarget['key'];
                    $target = $stockTarget['target'];
                    $running[$key] = ($running[$key] ?? (int) $target->stock) - $stockTarget['qty'];
                    if ($running[$key] < 0) {
                        throw new InsufficientStockException(
                            "Insufficient stock for {$stockTarget['label']}."
                            ." Available: {$target->stock}, Requested: {$stockTarget['qty']}"
                        );
                    }
                }
            }

            // Build order-item rows + stock-adjustment rows. quantity_before and
            // quantity_after thread the running stock so the ledger is faithful
            // when the order touches the same target twice.
            $subtotal = '0';
            $itemTaxTotal = '0';
            $orderItemRows = [];
            $now = now();
            $adjustmentRows = [];
            $perTargetQty = [];
            $targets = [];
            $threadStock = [];

            foreach ($lines as $line) {
                $item = $line['item'];
                $product = $line['product'];
                $variant = $line['variant'];
                $qty = $line['qty'];

                // unit_price is optional: callers may omit it and fall back to
                // the variant's own price (when a variant is chosen) or the
                // product's selling/list price.
                $unitPrice = $item['unit_price']
                    ?? $variant?->price
                    ?? $product->selling_price
                    ?? $product->price
                    ?? 0;
                $itemTax = $item['tax'] ?? 0;

                $itemSubtotal = Money::multiply($unitPrice, $qty);
                $subtotal = Money::add($subtotal, $itemSubtotal);
                $itemTaxTotal = Money::add($itemTaxTotal, $itemTax);

                $orderItemRows[] = [
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'product_name' => $product->name,
                    'sku' => $variant?->sku ?? $product->sku,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'subtotal' => $itemSubtotal,
                    'tax' => $itemTax,
                    'total' => Money::add($itemSubtotal, $itemTax),
                ];

                foreach ($line['stockTargets'] as $stockTarget) {
                    $key = $stockTarget['key'];
                    $target = $stockTarget['target'];
                    $stockQty = $stockTarget['qty'];
                    $targets[$key] = $target;
                    $perTargetQty[$key] = ($perTargetQty[$key] ?? 0) + $stockQty;
                    $beforeForEntry = $threadStock[$key] ?? (int) $target->stock;
                    $afterForEntry = $beforeForEntry - $stockQty;
                    $threadStock[$key] = $afterForEntry;

                    $adjustmentRows[] = [
                        'organization_id' => $orgId,
                        'product_id' => $target instanceof ProductVariant ? $target->product_id : $target->id,
                        'product_variant_id' => $target instanceof ProductVariant ? $target->id : null,
                        'user_id' => $creator->id,
                        'type' => 'order_fulfillment',
                        'quantity_before' => $beforeForEntry,
                        'quantity_after' => $afterForEntry,
                        'adjustment_quantity' => -$stockQty,
                        'reason' => null,  // set after order_number known
                        'notes' => null,
                        'reference_type' => Order::class,
                        'reference_id' => null,  // set after $order is created
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            // Order tax = any order-level tax (web) plus the sum of per-line
            // taxes (API). Exactly one side is non-zero per surface today, so
            // this preserves both: web keeps its order-level tax with zero line
            // tax; API keeps its summed line tax with no order-level tax.
            $data['subtotal'] = $subtotal;
            $data['tax'] = Money::add($data['tax'] ?? 0, $itemTaxTotal);
            $data['shipping'] = Money::of($data['shipping'] ?? 0);
            $data['total'] = Money::add($subtotal, $data['tax'], $data['shipping']);

            $order = Order::create($data);

            // Fill in the order_id-dependent fields and bulk-insert.
            foreach ($orderItemRows as &$row) {
                $row['order_id'] = $order->id;
                $row['created_at'] = $now;
                $row['updated_at'] = $now;
            }
            unset($row);
            OrderItem::insert($orderItemRows);

            foreach ($adjustmentRows as &$adj) {
                $adj['reason'] = "Order {$order->order_number} fulfilled";
                $adj['reference_id'] = $order->id;
            }
            unset($adj);
            StockAdjustment::insert($adjustmentRows);

            // Decrement stock once per unique target — the variant when one was
            // chosen, otherwise the product. This is the fix for variant counts
            // drifting: a line sold as a variant no longer decrements the parent.
            foreach ($perTargetQty as $key => $totalQty) {
                $targets[$key]->decrement('stock', $totalQty);
            }

            return $order;
        }));

        // Fire the action hook once the order and its line items are committed,
        // from the single service every surface (web, REST, GraphQL, MCP) uses —
        // so plugins and webhooks observe order creation consistently, with the
        // full aggregate present (firing on the model `created` event would see
        // an itemless order, since items are inserted after Order::create).
        do_action('order_created', $order, $creator);

        return $order;
    }

    /**
     * Human-readable label for an insufficient-stock message.
     *
     * @param  array{product: Product, variant: ?ProductVariant}  $line
     */
    private function lineLabel(array $line): string
    {
        if ($line['variant'] !== null) {
            $variant = $line['variant'];
            $descriptor = $variant->title ?? $variant->sku ?? "variant {$variant->id}";

            return "{$line['product']->name} ({$descriptor})";
        }

        return $line['product']->name;
    }
}
