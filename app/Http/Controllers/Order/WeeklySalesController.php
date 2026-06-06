<?php

declare(strict_types=1);

namespace App\Http\Controllers\Order;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidOrderItemException;
use App\Http\Controllers\Controller;
use App\Http\Requests\WeeklySales\StoreWeeklySalesRequest;
use App\Models\Inventory\Product;
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

        $validated = $request->validate([
            'week_start' => ['nullable', 'date_format:Y-m-d'],
            'selling_price_usd' => ['required', 'numeric', 'min:0'],
            'product_cost_usd' => ['required', 'numeric', 'min:0'],
            'domestic_logistics_cost_usd' => ['required', 'numeric', 'min:0'],
            'us_first_leg_cost_usd' => ['required', 'numeric', 'min:0'],
            'us_last_mile_cost_usd' => ['required', 'numeric', 'min:0'],
            'packing_cost_usd' => ['required', 'numeric', 'min:0'],
        ]);

        $exchangeRate = $this->exchangeRate((int) $request->user()->organization_id);
        $productCostCny = round((float) $validated['product_cost_usd'] * $exchangeRate, 4);
        $domesticLogisticsCostCny = round((float) $validated['domestic_logistics_cost_usd'] * $exchangeRate, 4);
        $usFirstLegCostCny = round((float) $validated['us_first_leg_cost_usd'] * $exchangeRate, 4);
        $packingCostCny = round((float) $validated['packing_cost_usd'] * $exchangeRate, 4);
        $weightedAverageCostCny = round($productCostCny + $domesticLogisticsCostCny + $usFirstLegCostCny, 4);

        $metadata = $product->metadata ?? [];
        $metadata['unit_goods_cost_cny'] = $productCostCny;
        $metadata['domestic_logistics_unit_cny'] = $domesticLogisticsCostCny;
        $metadata['first_leg_freight_unit_cny'] = $usFirstLegCostCny;
        $metadata['weekly_sales_costs_updated_at'] = now()->toISOString();

        $product->update([
            'price' => round((float) $validated['selling_price_usd'], 2),
            'selling_price' => round((float) $validated['selling_price_usd'], 2),
            'currency' => 'USD',
            'purchase_price' => $productCostCny,
            'weighted_average_cost_cny' => $weightedAverageCostCny,
            'last_mile_cost_usd' => round((float) $validated['us_last_mile_cost_usd'], 4),
            'packaging_cost_cny' => $packingCostCny,
            'packing_labor_cost_cny' => 0,
            'metadata' => $metadata,
        ]);

        $weekStart = $validated['week_start'] ?? $this->selectedWeek($request)->toDateString();

        return redirect()
            ->route('weekly-sales.index', ['week_start' => $weekStart])
            ->with('success', 'SKU 成本已更新。');
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
