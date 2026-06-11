<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidOrderItemException;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use App\Models\User;
use App\Support\Money;
use App\Support\SequenceNumberRetry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reconcile one week of manually entered TikTok US sales.
 *
 * Every represented date maps to one delivered aggregate order. Overwrites
 * adjust physical inventory by the difference from the saved order items,
 * preserving ordinary product and kit/component stock behavior.
 */
final class WeeklySalesService
{
    public const SOURCE = 'tiktok_us_manual';

    public const EXTERNAL_ID_PREFIX = 'tiktok-us-sales:';

    public const CUSTOMER_NAME = 'TikTok US Daily Aggregate';

    public function __construct(private readonly SalesStockResolver $salesStockResolver) {}

    /**
     * @param  array<int, array{product_id: int, product_variant_id?: int|null, daily_quantities: array<string, int>}>  $sales
     */
    public function save(User $user, CarbonImmutable $weekStart, array $sales): void
    {
        $weekStart = $weekStart->startOfDay();
        if (! $weekStart->isMonday()) {
            throw new InvalidArgumentException('Weekly sales week_start must be a Monday.');
        }
        if ($user->organization_id === null) {
            throw new InvalidArgumentException('Weekly sales require an organization user.');
        }

        SequenceNumberRetry::create(fn () => DB::transaction(function () use ($user, $weekStart, $sales): void {
            $organizationId = (int) $user->organization_id;
            Organization::whereKey($organizationId)->lockForUpdate()->firstOrFail();

            [$dates, $newByDate, $submittedEntries] = $this->normalizeSales(
                $organizationId,
                $weekStart,
                $sales
            );
            $existingOrders = $this->loadExistingOrders($organizationId, $dates);
            $submittedEntryKeys = $submittedEntries->keys()->all();
            $changes = $this->calculateChanges(
                $dates,
                $newByDate,
                $existingOrders,
                $submittedEntries
            );
            $preservedByDate = $this->preservedOrderItemRows(
                $dates,
                $existingOrders,
                $submittedEntryKeys
            );

            if ($changes !== []) {
                $resolved = $this->salesStockResolver->resolve(
                    $organizationId,
                    array_map(
                        fn (array $change): array => [
                            'product_id' => $change['product_id'],
                            'product_variant_id' => $change['product_variant_id'],
                            'quantity' => abs($change['difference']),
                        ],
                        $changes
                    ),
                    true,
                    true
                );

                foreach ($changes as $index => &$change) {
                    $change['line'] = $resolved['lines'][$index];
                }
                unset($change);
            }

            $ordersByDate = $this->ensureDailyOrders(
                $user,
                $dates,
                $newByDate,
                $existingOrders
            );

            $this->applyInventoryChanges($user, $changes, $ordersByDate);
            $this->replaceDailyOrderItems(
                $dates,
                $newByDate,
                $submittedEntries,
                $ordersByDate,
                $preservedByDate
            );
        }));
    }

    /**
     * @param  array<int, array{product_id: int, product_variant_id?: int|null, daily_quantities: array<string, int>}>  $sales
     * @return array{
     *     0: array<int, string>,
     *     1: array<string, array<string, int>>,
     *     2: Collection<string, array{product: Product, variant: ?ProductVariant}>
     * }
     */
    private function normalizeSales(int $organizationId, CarbonImmutable $weekStart, array $sales): array
    {
        $dates = [];
        $newByDate = [];
        for ($day = 0; $day < 7; $day++) {
            $date = $weekStart->addDays($day)->toDateString();
            $dates[] = $date;
            $newByDate[$date] = [];
        }
        $allowedDates = array_fill_keys($dates, true);

        $entryKeys = [];
        $productIds = [];
        $variantIds = [];
        foreach ($sales as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            $variantId = array_key_exists('product_variant_id', $row) && $row['product_variant_id'] !== null
                ? (int) $row['product_variant_id']
                : null;
            $entryKey = $this->entryKey($productId, $variantId);

            if ($productId <= 0 || isset($entryKeys[$entryKey])) {
                throw new InvalidArgumentException('Weekly sales rows require unique product or variant IDs.');
            }
            $entryKeys[$entryKey] = true;
            $productIds[$productId] = true;
            if ($variantId !== null) {
                $variantIds[$variantId] = true;
            }

            foreach (($row['daily_quantities'] ?? []) as $date => $quantity) {
                if (! isset($allowedDates[$date])) {
                    throw new InvalidArgumentException("Weekly sales date {$date} is outside the selected week.");
                }
                if (filter_var($quantity, FILTER_VALIDATE_INT) === false || (int) $quantity < 0) {
                    throw new InvalidArgumentException(
                        "Weekly sales quantity for {$entryKey} on {$date} must be a non-negative integer."
                    );
                }

                if ((int) $quantity > 0) {
                    $newByDate[$date][$entryKey] = (int) $quantity;
                }
            }
        }

        $products = Product::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->where('is_sellable', true)
            ->whereIn('id', array_keys($productIds))
            ->get()
            ->keyBy('id');
        $variants = ProductVariant::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereIn('id', array_keys($variantIds))
            ->get()
            ->keyBy('id');
        $productIdsWithActiveVariants = ProductVariant::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereIn('product_id', array_keys($productIds))
            ->pluck('product_id')
            ->map(fn ($productId): int => (int) $productId)
            ->flip();

        $submittedEntries = collect();
        foreach ($sales as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            $variantId = array_key_exists('product_variant_id', $row) && $row['product_variant_id'] !== null
                ? (int) $row['product_variant_id']
                : null;
            $product = $products->get($productId);

            if (! $product) {
                throw new InvalidOrderItemException(
                    "Product {$productId} is not an active supported sellable SKU in this organization."
                );
            }

            $usesVariants = $product->has_variants || $productIdsWithActiveVariants->has($product->id);

            if ($usesVariants) {
                $variant = $variantId !== null ? $variants->get($variantId) : null;
                if (! $variant || $variant->product_id !== $product->id) {
                    throw new InvalidOrderItemException(
                        "Product {$product->name} requires an active variant for weekly sales."
                    );
                }
            } else {
                if ($variantId !== null) {
                    throw new InvalidOrderItemException(
                        "Product {$product->name} does not support variant weekly sales."
                    );
                }
                $variant = null;
            }

            $submittedEntries->put($this->entryKey($product->id, $variant?->id), [
                'product' => $product,
                'variant' => $variant,
            ]);
        }

        return [$dates, $newByDate, $submittedEntries];
    }

    /**
     * @param  array<int, string>  $dates
     * @return Collection<string, Order>
     */
    private function loadExistingOrders(int $organizationId, array $dates): Collection
    {
        $externalIds = array_map(
            fn (string $date): string => self::EXTERNAL_ID_PREFIX.$date,
            $dates
        );

        return Order::withTrashed()
            ->with('items')
            ->where('organization_id', $organizationId)
            ->where('source', self::SOURCE)
            ->whereIn('external_id', $externalIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('external_id');
    }

    /**
     * @param  array<int, string>  $dates
     * @param  array<string, array<string, int>>  $newByDate
     * @param  Collection<string, Order>  $existingOrders
     * @param  Collection<string, array{product: Product, variant: ?ProductVariant}>  $submittedEntries
     * @return array<int, array{date: string, entry_key: string, product_id: int, product_variant_id: ?int, difference: int}>
     */
    private function calculateChanges(
        array $dates,
        array $newByDate,
        Collection $existingOrders,
        Collection $submittedEntries
    ): array {
        $changes = [];
        $submittedEntryKeys = $submittedEntries->keys()->all();
        sort($submittedEntryKeys);

        foreach ($dates as $date) {
            $order = $existingOrders->get(self::EXTERNAL_ID_PREFIX.$date);
            $old = [];
            if ($order !== null && ! $order->trashed()) {
                foreach ($order->items as $item) {
                    $entryKey = $this->orderItemEntryKey($item);
                    if ($entryKey !== null) {
                        $old[$entryKey] = ($old[$entryKey] ?? 0) + (int) $item->quantity;
                    }
                }
            }

            foreach ($submittedEntryKeys as $entryKey) {
                $difference = ($newByDate[$date][$entryKey] ?? 0) - ($old[$entryKey] ?? 0);
                if ($difference !== 0) {
                    $entry = $submittedEntries->get($entryKey);
                    $changes[] = [
                        'date' => $date,
                        'entry_key' => $entryKey,
                        'product_id' => $entry['product']->id,
                        'product_variant_id' => $entry['variant']?->id,
                        'difference' => $difference,
                    ];
                }
            }
        }

        return $changes;
    }

    /**
     * Preserve active aggregate-order lines that are not represented by the
     * current page submission, such as a SKU hidden after becoming non-sellable.
     *
     * @param  array<int, string>  $dates
     * @param  Collection<string, Order>  $existingOrders
     * @param  array<int, string>  $submittedEntryKeys
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function preservedOrderItemRows(
        array $dates,
        Collection $existingOrders,
        array $submittedEntryKeys
    ): array {
        $submitted = array_fill_keys($submittedEntryKeys, true);
        $preservedByDate = [];

        foreach ($dates as $date) {
            $order = $existingOrders->get(self::EXTERNAL_ID_PREFIX.$date);
            $preservedByDate[$date] = [];
            if ($order === null || $order->trashed()) {
                continue;
            }

            foreach ($order->items as $item) {
                $entryKey = $this->orderItemEntryKey($item);
                if ($entryKey !== null && isset($submitted[$entryKey])) {
                    continue;
                }

                $preservedByDate[$date][] = [
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'product_name' => $item->product_name,
                    'sku' => $item->sku,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => $item->unit_price,
                    'subtotal' => $item->subtotal,
                    'tax' => $item->tax,
                    'total' => $item->total,
                    'metadata' => $item->metadata,
                ];
            }
        }

        return $preservedByDate;
    }

    /**
     * @param  array<int, string>  $dates
     * @param  array<string, array<string, int>>  $newByDate
     * @param  Collection<string, Order>  $existingOrders
     * @return array<string, Order>
     */
    private function ensureDailyOrders(
        User $user,
        array $dates,
        array $newByDate,
        Collection $existingOrders
    ): array {
        $ordersByDate = [];
        $organizationId = (int) $user->organization_id;

        foreach ($dates as $date) {
            $externalId = self::EXTERNAL_ID_PREFIX.$date;
            $order = $existingOrders->get($externalId);
            $hasSales = $newByDate[$date] !== [];

            if (! $hasSales && ($order === null || $order->trashed())) {
                continue;
            }

            if ($order === null) {
                $order = new Order([
                    'organization_id' => $organizationId,
                    'created_by' => $user->id,
                    'order_number' => Order::generateOrderNumber($organizationId),
                    'source' => self::SOURCE,
                    'external_id' => $externalId,
                ]);
            } elseif ($order->trashed()) {
                $order->restoreQuietly();
            }

            $representedAt = CarbonImmutable::parse($date, 'UTC');
            $order->fill([
                'created_by' => $user->id,
                'customer_name' => self::CUSTOMER_NAME,
                'status' => 'delivered',
                'approval_status' => 'approved',
                'approved_by' => $user->id,
                'approved_at' => now(),
                'subtotal' => 0,
                'tax' => 0,
                'shipping' => 0,
                'total' => 0,
                'currency' => 'USD',
                'order_date' => $representedAt,
                'delivered_at' => $representedAt->endOfDay(),
                'notes' => 'Manually reconciled TikTok US daily aggregate sales.',
                'metadata' => [
                    'channel' => 'TikTok US',
                    'entry_method' => 'weekly_manual',
                ],
            ]);
            $order->saveQuietly();
            $ordersByDate[$date] = $order;
        }

        return $ordersByDate;
    }

    /**
     * @param  array<int, array{
     *     date: string,
     *     entry_key: string,
     *     product_id: int,
     *     product_variant_id: ?int,
     *     difference: int,
     *     line: array<string, mixed>
     * }>  $changes
     * @param  array<string, Order>  $ordersByDate
     */
    private function applyInventoryChanges(User $user, array $changes, array $ordersByDate): void
    {
        $restorations = array_values(array_filter(
            $changes,
            fn (array $change): bool => $change['difference'] < 0
        ));
        $decrements = array_values(array_filter(
            $changes,
            fn (array $change): bool => $change['difference'] > 0
        ));
        $runningStock = [];

        foreach ([...$restorations, ...$decrements] as $change) {
            $order = $ordersByDate[$change['date']];
            foreach ($change['line']['stockTargets'] as $stockTarget) {
                $key = $stockTarget['key'];
                $target = $stockTarget['target'];
                $quantity = (int) $stockTarget['qty'];
                $before = $runningStock[$key] ?? (int) $target->stock;
                $adjustmentQuantity = $change['difference'] < 0 ? $quantity : -$quantity;
                $after = $before + $adjustmentQuantity;

                if ($after < 0) {
                    $sellableSku = $change['line']['product']->sku ?? $change['line']['product']->name;
                    throw new InsufficientStockException(
                        "Insufficient stock for {$sellableSku} on {$change['date']}; "
                        ."{$stockTarget['label']} has {$before} available and needs {$quantity}."
                    );
                }

                $runningStock[$key] = $after;
                $target->updateQuietly(['stock' => $after]);

                StockAdjustment::create([
                    'organization_id' => $user->organization_id,
                    'product_id' => $target instanceof ProductVariant ? $target->product_id : $target->id,
                    'product_variant_id' => $target instanceof ProductVariant ? $target->id : null,
                    'user_id' => $user->id,
                    'type' => $adjustmentQuantity > 0 ? 'order_cancellation' : 'order_fulfillment',
                    'quantity_before' => $before,
                    'quantity_after' => $after,
                    'adjustment_quantity' => $adjustmentQuantity,
                    'reason' => "TikTok US weekly sales reconciled for {$change['date']}",
                    'notes' => "Sellable SKU: {$change['line']['product']->sku}",
                    'reference_type' => Order::class,
                    'reference_id' => $order->id,
                ]);
            }
        }
    }

    /**
     * @param  array<int, string>  $dates
     * @param  array<string, array<string, int>>  $newByDate
     * @param  Collection<string, array{product: Product, variant: ?ProductVariant}>  $entries
     * @param  array<string, Order>  $ordersByDate
     * @param  array<string, array<int, array<string, mixed>>>  $preservedByDate
     */
    private function replaceDailyOrderItems(
        array $dates,
        array $newByDate,
        Collection $entries,
        array $ordersByDate,
        array $preservedByDate
    ): void {
        foreach ($dates as $date) {
            $order = $ordersByDate[$date] ?? null;
            if ($order === null) {
                continue;
            }

            $subtotal = '0';
            $rows = $preservedByDate[$date] ?? [];
            foreach ($rows as $row) {
                $subtotal = Money::add($subtotal, $row['subtotal']);
            }

            foreach ($newByDate[$date] as $entryKey => $quantity) {
                $entry = $entries->get($entryKey);
                if ($entry === null) {
                    continue;
                }
                $product = $entry['product'];
                $variant = $entry['variant'];
                $unitPrice = $variant?->price ?? $product->selling_price ?? $product->price ?? 0;
                $lineSubtotal = Money::multiply($unitPrice, $quantity);
                $subtotal = Money::add($subtotal, $lineSubtotal);
                $rows[] = [
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'product_name' => $variant === null ? $product->name : $this->variantDisplayName($product, $variant),
                    'sku' => $variant?->sku ?? $product->sku,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'subtotal' => $lineSubtotal,
                    'tax' => 0,
                    'total' => $lineSubtotal,
                    'metadata' => [
                        'entry_method' => 'weekly_manual',
                        'entry_key' => $entryKey,
                        'variant_title' => $variant?->title,
                    ],
                ];
            }

            $order->items()->delete();
            if ($rows === []) {
                $order->deleteQuietly();

                continue;
            }

            $order->items()->createMany($rows);
            $order->updateQuietly([
                'subtotal' => $subtotal,
                'tax' => 0,
                'shipping' => 0,
                'total' => $subtotal,
            ]);
        }
    }

    private function entryKey(int $productId, ?int $variantId = null): string
    {
        return $variantId !== null ? "v:{$variantId}" : "p:{$productId}";
    }

    private function orderItemEntryKey($item): ?string
    {
        if ($item->product_variant_id !== null) {
            return "v:{$item->product_variant_id}";
        }
        if ($item->product_id !== null) {
            return "p:{$item->product_id}";
        }

        return null;
    }

    private function variantDisplayName(Product $product, ProductVariant $variant): string
    {
        $descriptor = $variant->title ?? $variant->sku ?? "Variant {$variant->id}";

        return "{$product->name} - {$descriptor}";
    }
}
