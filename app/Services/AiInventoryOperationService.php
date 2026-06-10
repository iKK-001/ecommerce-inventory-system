<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AiOperationDraft;
use App\Models\Inventory\Product;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\Supplier;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseOrderItem;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class AiInventoryOperationService
{
    private const AI_SUPPLIER_CODE = 'AI-IN-TRANSIT';

    private const COST_FIELDS = [
        'selling_price_usd',
        'product_cost_usd',
        'domestic_logistics_cost_usd',
        'us_first_leg_cost_usd',
        'us_last_mile_cost_usd',
        'packing_cost_usd',
    ];

    private const FIELD_LABELS = [
        'selling_price_usd' => '售价',
        'product_cost_usd' => '商品成本',
        'domestic_logistics_cost_usd' => '国内运费',
        'us_first_leg_cost_usd' => '美国头程',
        'us_last_mile_cost_usd' => '美国尾程',
        'packing_cost_usd' => '打包成本',
        'actual_stock' => '实际在库',
        'in_transit_quantity' => '在途库存',
    ];

    public function __construct(
        private readonly MiniMaxInventoryAiClient $client
    ) {}

    public function createDraft(User $user, string $instruction): AiOperationDraft
    {
        $this->assertAnyPermission($user, $this->aiWritePermissions());
        $organizationId = $this->organizationId($user);
        $products = $this->sellableProducts($organizationId);

        $minimax = $this->client->draftOperations(
            $instruction,
            $this->skuContext($products, $organizationId)
        );
        $operations = $this->normalizeOperations($minimax['operations'], $products, $organizationId);

        if ($operations === []) {
            throw ValidationException::withMessages([
                'instruction' => 'MiniMax 没有生成可执行的修改草稿。',
            ]);
        }

        $warnings = collect($operations)
            ->flatMap(fn (array $operation): array => $operation['warnings'] ?? [])
            ->values()
            ->all();

        return AiOperationDraft::create([
            'organization_id' => $organizationId,
            'user_id' => $user->id,
            'status' => AiOperationDraft::STATUS_DRAFT,
            'input_text' => $instruction,
            'minimax_request' => $minimax['request'],
            'minimax_response' => $minimax['response'],
            'operations' => $operations,
            'warnings' => $warnings,
        ]);
    }

    public function executeDraft(User $user, int $draftId): AiOperationDraft
    {
        $this->assertAnyPermission($user, $this->aiWritePermissions());
        $organizationId = $this->organizationId($user);

        return DB::transaction(function () use ($user, $draftId, $organizationId): AiOperationDraft {
            $draft = AiOperationDraft::where('organization_id', $organizationId)
                ->whereKey($draftId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($draft->status !== AiOperationDraft::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'draft_id' => '这份 AI 修改草稿已经执行过，不能重复执行。',
                ]);
            }

            foreach ($draft->operations as $operation) {
                $this->assertOperationPermission($user, $operation);
                $this->executeOperation($draft, $operation);
            }

            $draft->update([
                'status' => AiOperationDraft::STATUS_EXECUTED,
                'executed_by' => $user->id,
                'executed_at' => now(),
            ]);

            return $draft->refresh();
        });
    }

    /**
     * @return array<string>
     */
    public function aiWritePermissions(): array
    {
        return [
            'edit_products',
            'manage_stock',
            'manage_purchase_orders',
            'create_purchase_orders',
            'edit_purchase_orders',
        ];
    }

    /**
     * @return EloquentCollection<int, Product>
     */
    private function sellableProducts(int $organizationId): EloquentCollection
    {
        return Product::forOrganization($organizationId)
            ->active()
            ->where('is_sellable', true)
            ->orderBy('sku')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  EloquentCollection<int, Product>  $products
     * @return array<int, array<string, mixed>>
     */
    private function skuContext(EloquentCollection $products, int $organizationId): array
    {
        $exchangeRate = $this->exchangeRate($organizationId);

        return $products
            ->map(function (Product $product) use ($exchangeRate, $organizationId): array {
                return [
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'selling_price_usd' => $this->currentCostValues($product, $exchangeRate)['selling_price_usd'],
                    'actual_stock' => (int) $product->stock,
                    'in_transit_quantity' => $this->currentInTransitQuantity($organizationId, (int) $product->id),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rawOperations
     * @param  EloquentCollection<int, Product>  $products
     * @return array<int, array<string, mixed>>
     */
    private function normalizeOperations(array $rawOperations, EloquentCollection $products, int $organizationId): array
    {
        $exchangeRate = $this->exchangeRate($organizationId);
        $operations = [];

        foreach ($rawOperations as $index => $rawOperation) {
            $type = (string) ($rawOperation['type'] ?? '');
            $product = $this->matchProduct($rawOperation, $products);
            $changes = [];
            $warnings = [];

            if ($type === 'cost_update') {
                $current = $this->currentCostValues($product, $exchangeRate);
                $fields = $rawOperation['fields'] ?? [];
                if (! is_array($fields) || $fields === []) {
                    throw ValidationException::withMessages([
                        'instruction' => "SKU {$product->sku} 的成本修改没有提供字段。",
                    ]);
                }

                foreach ($fields as $field => $value) {
                    if (! in_array($field, self::COST_FIELDS, true)) {
                        throw ValidationException::withMessages([
                            'instruction' => "不支持修改字段：{$field}。",
                        ]);
                    }

                    $newValue = $this->nonNegativeFloat($value, self::FIELD_LABELS[$field]);
                    $changes[] = $this->change($field, $current[$field], $newValue, 'USD');
                }
            } elseif ($type === 'stock_set') {
                $target = $this->nonNegativeInteger($rawOperation['actual_stock'] ?? null, '实际在库');
                $changes[] = $this->change('actual_stock', (int) $product->stock, $target, 'units');
            } elseif ($type === 'in_transit_set') {
                $current = $this->currentInTransitQuantity($organizationId, (int) $product->id);
                $target = $this->nonNegativeInteger($rawOperation['in_transit_quantity'] ?? null, '在途库存');
                if ($target < $current) {
                    $warnings[] = "SKU {$product->sku} 当前在途 {$current}，v1 不能通过 AI 降低在途库存；执行时会被拒绝。";
                }
                $changes[] = $this->change('in_transit_quantity', $current, $target, 'units');
            } else {
                throw ValidationException::withMessages([
                    'instruction' => "不支持的 AI 操作类型：{$type}。",
                ]);
            }

            $operations[] = [
                'id' => 'op_'.($index + 1),
                'type' => $type,
                'label' => $this->operationLabel($type),
                'product_id' => $product->id,
                'product_sku' => $product->sku,
                'product_name' => $product->name,
                'changes' => $changes,
                'warnings' => $warnings,
            ];
        }

        return $operations;
    }

    /**
     * @param  array<string, mixed>  $rawOperation
     * @param  EloquentCollection<int, Product>  $products
     */
    private function matchProduct(array $rawOperation, EloquentCollection $products): Product
    {
        $productId = $rawOperation['product_id'] ?? null;
        if (is_numeric($productId)) {
            $product = $products->firstWhere('id', (int) $productId);
            if ($product instanceof Product) {
                return $product;
            }
        }

        $productRef = trim((string) ($rawOperation['product_ref'] ?? ''));
        if ($productRef === '') {
            throw ValidationException::withMessages([
                'instruction' => 'AI 修改草稿缺少 SKU 或商品名称。',
            ]);
        }

        $normalizedRef = $this->normalizeText($productRef);
        $exact = $products->first(function (Product $product) use ($normalizedRef): bool {
            return $this->normalizeText((string) $product->sku) === $normalizedRef
                || $this->normalizeText($product->name) === $normalizedRef;
        });

        if ($exact instanceof Product) {
            return $exact;
        }

        $partial = $products->filter(function (Product $product) use ($normalizedRef): bool {
            return Str::contains($this->normalizeText((string) $product->sku), $normalizedRef)
                || Str::contains($this->normalizeText($product->name), $normalizedRef);
        });

        if ($partial->count() === 1) {
            return $partial->first();
        }

        if ($partial->count() > 1) {
            throw ValidationException::withMessages([
                'instruction' => "“{$productRef}” 匹配到多个 SKU，请输入更完整的 SKU 或商品名。",
            ]);
        }

        throw ValidationException::withMessages([
            'instruction' => "未找到 SKU 或商品：{$productRef}。",
        ]);
    }

    private function executeOperation(AiOperationDraft $draft, array $operation): void
    {
        $product = Product::where('organization_id', $draft->organization_id)
            ->whereKey($operation['product_id'])
            ->firstOrFail();

        match ($operation['type']) {
            'cost_update' => $this->applyCostUpdate($draft, $product, $operation),
            'stock_set' => $this->applyStockSet($draft, $product, $operation),
            'in_transit_set' => $this->applyInTransitSet($draft, $product, $operation),
            default => throw new InvalidArgumentException('Unsupported AI operation type.'),
        };
    }

    private function applyCostUpdate(AiOperationDraft $draft, Product $product, array $operation): void
    {
        $exchangeRate = $this->exchangeRate((int) $draft->organization_id);
        $values = $this->currentCostValues($product, $exchangeRate);

        foreach ($operation['changes'] as $change) {
            $field = (string) ($change['field'] ?? '');
            if (in_array($field, self::COST_FIELDS, true)) {
                $values[$field] = (float) $change['new_value'];
            }
        }

        $this->updateProductCosts($product, $values, $exchangeRate);
    }

    private function applyStockSet(AiOperationDraft $draft, Product $product, array $operation): void
    {
        $change = $operation['changes'][0] ?? null;
        if (! is_array($change) || ($change['field'] ?? null) !== 'actual_stock') {
            throw ValidationException::withMessages([
                'draft_id' => '实际库存修改草稿格式不正确。',
            ]);
        }

        $target = $this->nonNegativeInteger($change['new_value'] ?? null, '实际在库');
        $delta = $target - (int) $product->stock;
        if ($delta === 0) {
            return;
        }

        StockAdjustment::adjust(
            $product,
            $delta,
            'count',
            'AI 批量修改',
            "AI draft #{$draft->id}",
            $draft
        );
    }

    private function applyInTransitSet(AiOperationDraft $draft, Product $product, array $operation): void
    {
        $change = $operation['changes'][0] ?? null;
        if (! is_array($change) || ($change['field'] ?? null) !== 'in_transit_quantity') {
            throw ValidationException::withMessages([
                'draft_id' => '在途库存修改草稿格式不正确。',
            ]);
        }

        $target = $this->nonNegativeInteger($change['new_value'] ?? null, '在途库存');
        $current = $this->currentInTransitQuantity((int) $draft->organization_id, (int) $product->id);
        if ($target < $current) {
            throw ValidationException::withMessages([
                'draft_id' => "SKU {$product->sku} 当前在途 {$current}，AI v1 只能新增在途调整，不能减少历史采购单在途数量。",
            ]);
        }

        $delta = $target - $current;
        if ($delta === 0) {
            return;
        }

        $exchangeRate = $this->exchangeRate((int) $draft->organization_id);
        $unitCostUsd = $this->currentCostValues($product, $exchangeRate)['product_cost_usd'];
        $subtotal = round($unitCostUsd * $delta, 2);
        $supplier = $this->aiSupplier((int) $draft->organization_id);

        $purchaseOrder = PurchaseOrder::create([
            'organization_id' => $draft->organization_id,
            'supplier_id' => $supplier->id,
            'created_by' => $draft->executed_by ?? auth()->id(),
            'po_number' => PurchaseOrder::generatePONumber((int) $draft->organization_id),
            'status' => PurchaseOrder::STATUS_SENT,
            'order_date' => now()->toDateString(),
            'expected_date' => null,
            'subtotal' => $subtotal,
            'tax' => 0,
            'shipping' => 0,
            'domestic_freight_cny' => 0,
            'first_leg_freight_cny' => 0,
            'total' => $subtotal,
            'currency' => 'USD',
            'notes' => "AI 在途调整，来源草稿 #{$draft->id}",
            'metadata' => [
                'ai_operation_draft_id' => $draft->id,
                'ai_operation_type' => 'in_transit_set',
            ],
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $purchaseOrder->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'quantity_ordered' => $delta,
            'quantity_received' => 0,
            'unit_cost' => round($unitCostUsd, 2),
            'landed_unit_cost_cny' => round($unitCostUsd * $exchangeRate, 4),
            'subtotal' => $subtotal,
            'tax' => 0,
            'total' => $subtotal,
            'notes' => "AI 在途调整，目标在途 {$target}",
            'metadata' => [
                'ai_operation_draft_id' => $draft->id,
                'target_in_transit_quantity' => $target,
                'previous_in_transit_quantity' => $current,
            ],
        ]);
    }

    /**
     * @param  array<string, float|int>  $values
     */
    private function updateProductCosts(Product $product, array $values, float $exchangeRate): void
    {
        $productCostCny = round((float) $values['product_cost_usd'] * $exchangeRate, 4);
        $domesticLogisticsCostCny = round((float) $values['domestic_logistics_cost_usd'] * $exchangeRate, 4);
        $usFirstLegCostCny = round((float) $values['us_first_leg_cost_usd'] * $exchangeRate, 4);
        $packingCostCny = round((float) $values['packing_cost_usd'] * $exchangeRate, 4);
        $weightedAverageCostCny = round($productCostCny + $domesticLogisticsCostCny + $usFirstLegCostCny, 4);

        $metadata = $product->metadata ?? [];
        $metadata['unit_goods_cost_cny'] = $productCostCny;
        $metadata['domestic_logistics_unit_cny'] = $domesticLogisticsCostCny;
        $metadata['first_leg_freight_unit_cny'] = $usFirstLegCostCny;
        $metadata['weekly_sales_costs_updated_at'] = now()->toISOString();
        $metadata['ai_costs_updated_at'] = now()->toISOString();

        $product->update([
            'price' => round((float) $values['selling_price_usd'], 2),
            'selling_price' => round((float) $values['selling_price_usd'], 2),
            'currency' => 'USD',
            'purchase_price' => $productCostCny,
            'weighted_average_cost_cny' => $weightedAverageCostCny,
            'last_mile_cost_usd' => round((float) $values['us_last_mile_cost_usd'], 4),
            'packaging_cost_cny' => $packingCostCny,
            'packing_labor_cost_cny' => 0,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @return array<string, float>
     */
    private function currentCostValues(Product $product, float $exchangeRate): array
    {
        $metadata = $product->metadata ?? [];
        $productCostCny = $this->metadataNumber(
            $metadata,
            ['unit_goods_cost_cny', 'goods_unit_cost_cny'],
            (float) $product->weighted_average_cost_cny
        );
        $domesticLogisticsCostCny = $this->metadataNumber(
            $metadata,
            ['domestic_logistics_unit_cny', 'domestic_freight_unit_cny']
        );
        $usFirstLegCostCny = $this->metadataNumber(
            $metadata,
            ['first_leg_freight_unit_cny', 'first_leg_unit_cost_cny', 'first_leg_unit_cny']
        );
        $packingCostCny = (float) $product->packaging_cost_cny + (float) $product->packing_labor_cost_cny;

        return [
            'selling_price_usd' => round((float) ($product->selling_price ?? $product->price ?? 0), 4),
            'product_cost_usd' => round($productCostCny / $exchangeRate, 4),
            'domestic_logistics_cost_usd' => round($domesticLogisticsCostCny / $exchangeRate, 4),
            'us_first_leg_cost_usd' => round($usFirstLegCostCny / $exchangeRate, 4),
            'us_last_mile_cost_usd' => round((float) $product->last_mile_cost_usd, 4),
            'packing_cost_usd' => round($packingCostCny / $exchangeRate, 4),
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<int, string>  $keys
     */
    private function metadataNumber(array $metadata, array $keys, float $fallback = 0.0): float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $metadata) && is_numeric($metadata[$key])) {
                return (float) $metadata[$key];
            }
        }

        return $fallback;
    }

    private function currentInTransitQuantity(int $organizationId, int $productId): int
    {
        return PurchaseOrderItem::query()
            ->where('product_id', $productId)
            ->whereHas('purchaseOrder', function ($query) use ($organizationId): void {
                $query->where('organization_id', $organizationId)
                    ->whereIn('status', [PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_PARTIAL]);
            })
            ->get()
            ->sum(fn (PurchaseOrderItem $item): int => max(
                0,
                (int) $item->quantity_ordered - (int) $item->quantity_received
            ));
    }

    private function aiSupplier(int $organizationId): Supplier
    {
        return Supplier::firstOrCreate(
            [
                'organization_id' => $organizationId,
                'code' => self::AI_SUPPLIER_CODE,
            ],
            [
                'name' => 'AI在途调整/未指定供应商',
                'currency' => 'USD',
                'is_active' => true,
                'notes' => '系统自动创建，用于 AI 在途库存调整。',
            ]
        );
    }

    private function exchangeRate(int $organizationId): float
    {
        $value = Setting::forOrganization($organizationId)
            ->where('key', 'inventory.exchange_rate_cny_per_usd')
            ->value('value');

        $exchangeRate = (float) ($value ?? SkuOperationsService::DEFAULT_EXCHANGE_RATE_CNY_PER_USD);

        return $exchangeRate > 0 ? $exchangeRate : SkuOperationsService::DEFAULT_EXCHANGE_RATE_CNY_PER_USD;
    }

    /**
     * @return array<string, mixed>
     */
    private function change(string $field, float|int $oldValue, float|int $newValue, string $unit): array
    {
        return [
            'field' => $field,
            'label' => self::FIELD_LABELS[$field] ?? $field,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'unit' => $unit,
        ];
    }

    private function nonNegativeFloat(mixed $value, string $label): float
    {
        if (! is_numeric($value)) {
            throw ValidationException::withMessages([
                'instruction' => "{$label} 必须是数字。",
            ]);
        }

        $number = (float) $value;
        if ($number < 0) {
            throw ValidationException::withMessages([
                'instruction' => "{$label} 不能小于 0。",
            ]);
        }

        return round($number, 4);
    }

    private function nonNegativeInteger(mixed $value, string $label): int
    {
        if (! is_numeric($value)) {
            throw ValidationException::withMessages([
                'instruction' => "{$label} 必须是整数。",
            ]);
        }

        $number = (int) floor((float) $value);
        if ($number < 0) {
            throw ValidationException::withMessages([
                'instruction' => "{$label} 不能小于 0。",
            ]);
        }

        return $number;
    }

    private function operationLabel(string $type): string
    {
        return match ($type) {
            'cost_update' => '更新售价/成本',
            'stock_set' => '调整实际在库',
            'in_transit_set' => '调整在途库存',
            default => $type,
        };
    }

    private function normalizeText(string $value): string
    {
        return Str::lower(trim($value));
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function assertAnyPermission(User $user, array $permissions): void
    {
        if (! $user->hasAnyPermission($permissions)) {
            throw new AuthorizationException('You do not have permission to use AI inventory operations.');
        }
    }

    private function assertOperationPermission(User $user, array $operation): void
    {
        $type = (string) ($operation['type'] ?? '');
        $permissions = match ($type) {
            'cost_update' => ['edit_products'],
            'stock_set' => ['manage_stock'],
            'in_transit_set' => ['manage_purchase_orders', 'create_purchase_orders', 'edit_purchase_orders'],
            default => [],
        };

        if ($permissions === [] || ! $user->hasAnyPermission($permissions)) {
            throw new AuthorizationException('You do not have permission to execute one or more AI operations.');
        }
    }

    private function organizationId(User $user): int
    {
        $organizationId = (int) $user->organization_id;
        if ($organizationId <= 0) {
            throw ValidationException::withMessages([
                'instruction' => '当前用户没有绑定组织，不能使用 AI 批量修改。',
            ]);
        }

        return $organizationId;
    }
}
