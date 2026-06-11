<?php

declare(strict_types=1);

namespace App\Http\Controllers\Order;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidOrderItemException;
use App\Http\Controllers\Controller;
use App\Http\Requests\WeeklySales\StoreWeeklySalesRequest;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Setting;
use App\Services\SkuOperationsService;
use App\Services\WeeklySalesService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class WeeklySalesController extends Controller
{
    public function __construct(
        private readonly SkuOperationsService $skuOperationsService,
        private readonly WeeklySalesService $weeklySalesService,
    ) {}

    public function index(Request $request): Response
    {
        $weekStart = $this->selectedWeek($request);

        return Inertia::render('WeeklySales/Index', [
            'report' => $this->skuOperationsService->report(
                (int) $request->user()->organization_id,
                $weekStart
            ),
            'canSave' => $request->user()->hasPermission('create_orders'),
            'canEditCosts' => $request->user()->hasPermission('edit_products'),
            'canUseAiOperations' => $request->user()->hasAnyPermission([
                'edit_products',
                'manage_stock',
                'manage_purchase_orders',
                'create_purchase_orders',
                'edit_purchase_orders',
            ]),
        ]);
    }

    public function store(StoreWeeklySalesRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $this->weeklySalesService->save(
                $request->user(),
                CarbonImmutable::parse($validated['week_start']),
                $validated['sales']
            );
        } catch (InsufficientStockException|InvalidOrderItemException|InvalidArgumentException $exception) {
            return redirect()->back()
                ->withInput()
                ->withErrors(['sales' => $exception->getMessage()]);
        }

        return redirect()
            ->route('weekly-sales.index', ['week_start' => $validated['week_start']])
            ->with('success', 'Weekly sales saved and inventory reconciled.');
    }

    public function updateCosts(Request $request, Product $product): RedirectResponse
    {
        if ($product->organization_id !== $request->user()->organization_id) {
            abort(404);
        }

        $validated = $this->validateCostInput($request);
        $costs = $this->costValues($validated, (int) $request->user()->organization_id);

        $metadata = $product->metadata ?? [];
        $metadata['unit_goods_cost_cny'] = $costs['product_cost_cny'];
        $metadata['domestic_logistics_unit_cny'] = $costs['domestic_logistics_cost_cny'];
        $metadata['first_leg_freight_unit_cny'] = $costs['us_first_leg_cost_cny'];
        $metadata['weekly_sales_costs_updated_at'] = now()->toISOString();

        $product->update([
            'price' => $costs['selling_price_usd'],
            'selling_price' => $costs['selling_price_usd'],
            'currency' => 'USD',
            'purchase_price' => $costs['product_cost_cny'],
            'weighted_average_cost_cny' => $costs['weighted_average_cost_cny'],
            'last_mile_cost_usd' => $costs['us_last_mile_cost_usd'],
            'packaging_cost_cny' => $costs['packing_cost_cny'],
            'packing_labor_cost_cny' => 0,
            'metadata' => $metadata,
        ]);

        $weekStart = $validated['week_start'] ?? $this->selectedWeek($request)->toDateString();

        return redirect()
            ->route('weekly-sales.index', ['week_start' => $weekStart])
            ->with('success', 'SKU 成本已更新。');
    }

    public function updateVariantCosts(Request $request, ProductVariant $variant): RedirectResponse
    {
        if ($variant->organization_id !== $request->user()->organization_id) {
            abort(404);
        }

        $validated = $this->validateCostInput($request);
        $costs = $this->costValues($validated, (int) $request->user()->organization_id);

        $metadata = $variant->metadata ?? [];
        $metadata['unit_goods_cost_cny'] = $costs['product_cost_cny'];
        $metadata['domestic_logistics_unit_cny'] = $costs['domestic_logistics_cost_cny'];
        $metadata['first_leg_freight_unit_cny'] = $costs['us_first_leg_cost_cny'];
        $metadata['last_mile_cost_usd'] = $costs['us_last_mile_cost_usd'];
        $metadata['packing_cost_cny'] = $costs['packing_cost_cny'];
        $metadata['weekly_sales_costs_updated_at'] = now()->toISOString();

        $variant->update([
            'price' => $costs['selling_price_usd'],
            'purchase_price' => $costs['product_cost_cny'],
            'metadata' => $metadata,
        ]);

        $weekStart = $validated['week_start'] ?? $this->selectedWeek($request)->toDateString();

        return redirect()
            ->route('weekly-sales.index', ['week_start' => $weekStart])
            ->with('success', 'SKU 成本已更新。');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateCostInput(Request $request): array
    {
        return $request->validate([
            'week_start' => ['nullable', 'date_format:Y-m-d'],
            'selling_price_usd' => ['required', 'numeric', 'min:0'],
            'product_cost_usd' => ['required', 'numeric', 'min:0'],
            'domestic_logistics_cost_usd' => ['required', 'numeric', 'min:0'],
            'us_first_leg_cost_usd' => ['required', 'numeric', 'min:0'],
            'us_last_mile_cost_usd' => ['required', 'numeric', 'min:0'],
            'packing_cost_usd' => ['required', 'numeric', 'min:0'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, float>
     */
    private function costValues(array $validated, int $organizationId): array
    {
        $exchangeRate = $this->exchangeRate($organizationId);
        $productCostCny = round((float) $validated['product_cost_usd'] * $exchangeRate, 4);
        $domesticLogisticsCostCny = round((float) $validated['domestic_logistics_cost_usd'] * $exchangeRate, 4);
        $usFirstLegCostCny = round((float) $validated['us_first_leg_cost_usd'] * $exchangeRate, 4);
        $packingCostCny = round((float) $validated['packing_cost_usd'] * $exchangeRate, 4);

        return [
            'selling_price_usd' => round((float) $validated['selling_price_usd'], 2),
            'product_cost_cny' => $productCostCny,
            'domestic_logistics_cost_cny' => $domesticLogisticsCostCny,
            'us_first_leg_cost_cny' => $usFirstLegCostCny,
            'us_last_mile_cost_usd' => round((float) $validated['us_last_mile_cost_usd'], 4),
            'packing_cost_cny' => $packingCostCny,
            'weighted_average_cost_cny' => round($productCostCny + $domesticLogisticsCostCny + $usFirstLegCostCny, 4),
        ];
    }

    private function selectedWeek(Request $request): CarbonImmutable
    {
        $timezone = $request->user()->organization?->timezone ?? config('app.timezone');
        $requested = $request->string('week_start')->toString();

        if ($requested !== '') {
            try {
                $parsed = CarbonImmutable::createFromFormat('Y-m-d', $requested, $timezone)->startOfDay();
                if ($parsed->toDateString() === $requested) {
                    return $parsed->startOfWeek(CarbonInterface::MONDAY);
                }
            } catch (\Throwable) {
                // Fall through to the current week.
            }
        }

        return CarbonImmutable::now($timezone)->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
    }

    private function exchangeRate(int $organizationId): float
    {
        $value = Setting::forOrganization($organizationId)
            ->where('key', 'inventory.exchange_rate_cny_per_usd')
            ->value('value');

        $exchangeRate = (float) ($value ?? SkuOperationsService::DEFAULT_EXCHANGE_RATE_CNY_PER_USD);

        return $exchangeRate > 0 ? $exchangeRate : SkuOperationsService::DEFAULT_EXCHANGE_RATE_CNY_PER_USD;
    }
}
