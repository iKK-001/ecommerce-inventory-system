<?php

declare(strict_types=1);

namespace App\Http\Controllers\Order;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidOrderItemException;
use App\Http\Controllers\Controller;
use App\Http\Requests\WeeklySales\StoreWeeklySalesRequest;
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
}
