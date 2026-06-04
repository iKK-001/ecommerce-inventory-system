# TikTok US Weekly Sales Operations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a TikTok US SKU operations page that combines SKU economics and inventory health with overwriteable Monday-through-Sunday sales entry that reconciles inventory by difference.

**Architecture:** Keep orders as the sales source of truth. Add product-level sellable and operating-cost fields, extract shared sellable-SKU-to-physical-stock resolution, calculate the table through a focused operations service, and reconcile one aggregate order per sales date through a transactional weekly-sales service.

**Tech Stack:** Laravel 12, Eloquent, Inertia, Vue 3, Tailwind CSS, PHPUnit, Vite.

---

## File Structure

- `database/migrations/2026_06_04_000002_add_weekly_sales_product_fields.php`
  adds `is_sellable`, `last_mile_cost_usd`, and `packing_labor_cost_cny`.
- `app/Services/SalesStockResolver.php`
  resolves standard and one-level kit sales into physical product requirements.
- `app/Services/WeeklySalesService.php`
  reconciles daily aggregate orders and inventory differences for one week.
- `app/Services/SkuOperationsService.php`
  calculates table rows, weekly daily quantities, summaries, costs, stock, and coverage.
- `app/Http/Requests/WeeklySales/StoreWeeklySalesRequest.php`
  validates a Monday week start and organization-scoped sellable product quantities.
- `app/Http/Controllers/Order/WeeklySalesController.php`
  renders the operations page and saves the weekly payload.
- `resources/js/Pages/WeeklySales/Index.vue`
  renders the operations table and expandable seven-day entry grid.

## Task 1: Add Sellable and Operating-Cost Product Fields

**Files:**
- Create: `database/migrations/2026_06_04_000002_add_weekly_sales_product_fields.php`
- Modify: `app/Models/Inventory/Product.php`
- Modify: `app/Http/Requests/Product/StoreProductRequest.php`
- Modify: `app/Http/Requests/Product/UpdateProductRequest.php`
- Modify: `app/Http/Requests/Api/Product/StoreProductRequest.php`
- Modify: `app/Http/Requests/Api/Product/UpdateProductRequest.php`
- Modify: `resources/js/Pages/Products/Create.vue`
- Modify: `resources/js/Pages/Products/Edit.vue`
- Test: `tests/Feature/WeeklySalesSchemaTest.php`
- Test: `tests/Feature/ProductControllerTest.php`

- [ ] **Step 1: Write failing schema and product persistence tests**

Assert that products default to sellable and cast the new fields correctly:

```php
$product = Product::create([
    'organization_id' => $organization->id,
    'sku' => 'SELL-001',
    'name' => 'Sellable SKU',
    'price' => 20,
    'stock' => 10,
    'min_stock' => 0,
    'is_active' => true,
    'last_mile_cost_usd' => 2.15,
    'packing_labor_cost_cny' => 1.75,
]);

$this->assertTrue($product->fresh()->is_sellable);
$this->assertSame('2.1500', (string) $product->fresh()->last_mile_cost_usd);
$this->assertSame('1.7500', (string) $product->fresh()->packing_labor_cost_cny);
```

- [ ] **Step 2: Run the focused tests and verify RED**

Run:

```bash
PATH="/opt/homebrew/opt/php@8.4/bin:$PATH" php artisan test tests/Feature/WeeklySalesSchemaTest.php tests/Feature/ProductControllerTest.php
```

Expected: failure because the new product columns and request fields do not exist.

- [ ] **Step 3: Implement migration, model casts, validation, and product form fields**

The migration adds:

```php
$table->boolean('is_sellable')->default(true)->after('is_active');
$table->decimal('last_mile_cost_usd', 14, 4)->default(0)->after('packaging_cost_cny');
$table->decimal('packing_labor_cost_cny', 14, 4)->default(0)->after('last_mile_cost_usd');
```

Product forms expose:

- `Sellable SKU` checkbox.
- `US last-mile cost (USD)` numeric input.
- `Packaging material cost (CNY)` using the existing field.
- `Packing labor cost (CNY)` numeric input.

- [ ] **Step 4: Run the focused tests and verify GREEN**

Run the Task 1 command again. Expected: all focused tests pass.

## Task 2: Extract Shared Physical Stock Resolution

**Files:**
- Create: `app/Services/SalesStockResolver.php`
- Modify: `app/Services/OrderService.php`
- Test: `tests/Feature/SalesStockResolverTest.php`
- Test: `tests/Feature/OrderServiceTest.php`

- [ ] **Step 1: Write failing resolver tests**

Cover:

- A standard product resolves to itself.
- A three-pack kit resolves to three base units.
- Two kit SKUs backed by one base product aggregate onto the same physical target.
- A kit with missing or fractional components raises `InvalidOrderItemException`.

The wished-for API is:

```php
$requirements = app(SalesStockResolver::class)->resolve(
    organizationId: $organization->id,
    items: [['product_id' => $threePack->id, 'quantity' => 2]]
);

$this->assertSame(6, $requirements['targets']["p{$base->id}"]['quantity']);
```

- [ ] **Step 2: Run the resolver test and verify RED**

Run:

```bash
PATH="/opt/homebrew/opt/php@8.4/bin:$PATH" php artisan test tests/Feature/SalesStockResolverTest.php
```

Expected: failure because `SalesStockResolver` does not exist.

- [ ] **Step 3: Implement the resolver and refactor OrderService to use it**

The resolver returns sold-line snapshots plus physical targets:

```php
[
    'lines' => [
        [
            'item' => $submittedItem,
            'product' => $sellableProduct,
            'variant' => $variantOrNull,
            'quantity' => $soldQuantity,
            'stock_targets' => [
                ['key' => 'p12', 'target' => $baseProduct, 'quantity' => 6, 'label' => '...'],
            ],
        ],
    ],
    'targets' => [
        'p12' => ['target' => $baseProduct, 'quantity' => 6, 'label' => '...'],
    ],
]
```

`OrderService` continues to own order creation, stock validation, ledger rows,
and stock decrements, but consumes resolved lines rather than duplicating kit
and variant expansion.

- [ ] **Step 4: Run resolver and order tests and verify GREEN**

Run:

```bash
PATH="/opt/homebrew/opt/php@8.4/bin:$PATH" php artisan test tests/Feature/SalesStockResolverTest.php tests/Feature/OrderServiceTest.php
```

Expected: all resolver and existing order-service tests pass.

## Task 3: Build Weekly Aggregate-Order Reconciliation

**Files:**
- Create: `app/Services/WeeklySalesService.php`
- Test: `tests/Feature/WeeklySalesServiceTest.php`

- [ ] **Step 1: Write failing create and overwrite tests**

Use a Monday week start and payload shaped as product rows:

```php
$sales = [
    ['product_id' => $product->id, 'daily_quantities' => [
        '2026-06-01' => 3,
        '2026-06-02' => 4,
        '2026-06-03' => 0,
        '2026-06-04' => 0,
        '2026-06-05' => 0,
        '2026-06-06' => 0,
        '2026-06-07' => 0,
    ]],
];

app(WeeklySalesService::class)->save($user, CarbonImmutable::parse('2026-06-01'), $sales);
```

Assert:

- Two delivered `tiktok_us_manual` daily aggregate orders are created.
- Stock decreases by seven.
- Increasing a saved day decrements only the difference.
- Reducing a saved day restores only the difference.
- Clearing a date restores stock and soft-deletes its aggregate order.

- [ ] **Step 2: Run the service tests and verify RED**

Run:

```bash
PATH="/opt/homebrew/opt/php@8.4/bin:$PATH" php artisan test tests/Feature/WeeklySalesServiceTest.php
```

Expected: failure because `WeeklySalesService` does not exist.

- [ ] **Step 3: Implement transactional reconciliation**

`save()`:

1. Starts one database transaction and locks the organization row.
2. Loads existing daily aggregate orders by `source=tiktok_us_manual` and
   `external_id=tiktok-us-sales:YYYY-MM-DD`.
3. Calculates old/new quantity differences.
4. Uses `SalesStockResolver` for the absolute differences.
5. Locks all physical targets in stable ID order.
6. Applies stock restorations before stock decrements.
7. Rejects any final negative stock with a SKU/date/component-specific error.
8. Writes `StockAdjustment` rows with the daily order as reference.
9. Replaces daily order items/totals or removes an all-zero order.

- [ ] **Step 4: Add kit and rollback tests**

Assert:

- Updating a three-pack from one sale to three sales consumes six additional
  base units.
- One insufficient SKU rolls back all dates and all SKU changes in the week.
- Repeating an identical save does not change stock or duplicate orders.

- [ ] **Step 5: Run the weekly-sales service tests and verify GREEN**

Run the Task 3 command again. Expected: all weekly reconciliation tests pass.

## Task 4: Calculate SKU Operations Rows

**Files:**
- Create: `app/Services/SkuOperationsService.php`
- Test: `tests/Feature/SkuOperationsServiceTest.php`

- [ ] **Step 1: Write failing filtering and economics tests**

Assert:

- Only active, sellable products from the current organization are returned.
- Standard cost uses weighted-average CNY cost converted to USD.
- Kit product cost sums component costs.
- Packing cost equals packaging-material cost plus packing-labor cost.
- Estimated profit subtracts product, last-mile, and packing costs.

- [ ] **Step 2: Run the service test and verify RED**

Run:

```bash
PATH="/opt/homebrew/opt/php@8.4/bin:$PATH" php artisan test tests/Feature/SkuOperationsServiceTest.php
```

Expected: failure because `SkuOperationsService` does not exist.

- [ ] **Step 3: Implement economics and inventory metrics**

The service accepts organization ID and selected Monday, then returns:

```php
[
    'store' => 'TikTok US',
    'week_start' => '2026-06-01',
    'week_end' => '2026-06-07',
    'days' => [['date' => '2026-06-01', 'label' => 'Mon'], ...],
    'summary' => [
        'units_sold' => 0,
        'estimated_revenue_usd' => 0,
        'estimated_gross_profit_usd' => 0,
        'replenishment_sku_count' => 0,
    ],
    'rows' => [[
        'product_id' => 1,
        'sku' => 'SKU-001',
        'name' => 'Sellable SKU',
        'selling_price_usd' => 20,
        'product_cost_usd' => 6,
        'last_mile_cost_usd' => 2,
        'packing_cost_usd' => 1,
        'unit_total_cost_usd' => 9,
        'gross_profit_usd' => 11,
        'gross_margin_percent' => 55,
        'warehouse_stock' => 20,
        'in_transit_quantity' => 40,
        'sellable_days' => 14,
        'is_low_stock' => true,
        'daily_quantities' => ['2026-06-01' => 0, ...],
        'weekly_sales_total' => 0,
    ]],
]
```

For kits, warehouse and in-transit quantities use the limiting component.

- [ ] **Step 4: Add selected-week, rolling-coverage, and kit-stock tests**

Assert selected-week daily quantities come from TikTok aggregate orders while
sellable days use the latest seven calendar days of non-cancelled sales.

- [ ] **Step 5: Run operations-service tests and verify GREEN**

Run the Task 4 command again. Expected: all operations calculations pass.

## Task 5: Add Weekly Sales Routes, Validation, Page, and Navigation

**Files:**
- Create: `app/Http/Requests/WeeklySales/StoreWeeklySalesRequest.php`
- Create: `app/Http/Controllers/Order/WeeklySalesController.php`
- Create: `resources/js/Pages/WeeklySales/Index.vue`
- Modify: `routes/web/sales.php`
- Modify: `resources/js/Layouts/AppLayout.vue`
- Modify: `resources/js/i18n/locales/en.json`
- Modify: `resources/js/i18n/locales/zh-CN.json`
- Test: `tests/Feature/WeeklySalesControllerTest.php`

- [ ] **Step 1: Write failing controller and permission tests**

Assert:

- `view_orders` can open the page.
- A user without `view_orders` receives 403.
- The page is organization-scoped and renders `WeeklySales/Index`.
- `create_orders` can save.
- Invalid non-Monday week starts, negative quantities, foreign products, and
  non-sellable products are rejected.

- [ ] **Step 2: Run controller tests and verify RED**

Run:

```bash
PATH="/opt/homebrew/opt/php@8.4/bin:$PATH" php artisan test tests/Feature/WeeklySalesControllerTest.php
```

Expected: failure because the routes and controller do not exist.

- [ ] **Step 3: Implement routes, request, controller, and navigation**

Routes:

```php
Route::get('/weekly-sales', [WeeklySalesController::class, 'index'])
    ->name('weekly-sales.index')
    ->middleware('permission:view_orders');
Route::post('/weekly-sales', [WeeklySalesController::class, 'store'])
    ->name('weekly-sales.store')
    ->middleware('permission:create_orders');
```

The controller normalizes a missing week to the current Monday and passes
`SkuOperationsService::report()` to Inertia.

- [ ] **Step 4: Implement the Vue operations table**

The page includes:

- Week navigation and TikTok US label.
- Four summary cards.
- Search, replenishment-status, and unsaved-change filters.
- Horizontally scrollable SKU economics table.
- Expandable seven-day quantity editor.
- Select-on-focus daily inputs with natural Tab and Shift+Tab movement.
- Enter-key movement to the same day on the next visible SKU.
- Seven-value tab/comma/space/newline paste into an expanded row.
- Dirty-row highlighting and a sticky Save/Discard bar with modified-SKU count.
- Inline server validation and insufficient-stock errors.
- Error-driven row expansion and focus while preserving entered values.
- Unsaved-navigation warning and disabled/progress state during save.
- Mobile wrapping for summary cards and daily inputs.

- [ ] **Step 5: Run controller tests and frontend build**

Run:

```bash
PATH="/opt/homebrew/opt/php@8.4/bin:$PATH" php artisan test tests/Feature/WeeklySalesControllerTest.php
npm run build
```

Expected: controller tests pass and Vite build exits zero.

## Task 6: Verify End-to-End Behavior and Overall UI

**Files:**
- Modify only files required by findings from verification.

- [ ] **Step 1: Run focused weekly-sales suite**

```bash
PATH="/opt/homebrew/opt/php@8.4/bin:$PATH" php artisan test \
  tests/Feature/WeeklySalesSchemaTest.php \
  tests/Feature/SalesStockResolverTest.php \
  tests/Feature/WeeklySalesServiceTest.php \
  tests/Feature/SkuOperationsServiceTest.php \
  tests/Feature/WeeklySalesControllerTest.php \
  tests/Feature/OrderServiceTest.php \
  tests/Feature/ProductControllerTest.php
```

Expected: all focused tests pass.

- [ ] **Step 2: Run formatting and full verification**

```bash
PATH="/opt/homebrew/opt/php@8.4/bin:$PATH" vendor/bin/pint --dirty
PATH="/opt/homebrew/opt/php@8.4/bin:$PATH" php artisan test
npm run build
git diff --check
```

Expected: formatter exits zero, full suite passes, build exits zero, and no
whitespace errors are reported.

- [ ] **Step 3: Start the local application and prepare realistic demo rows**

Use the existing local SQLite environment, migrate the new fields, and seed a
small set of sellable standard and kit products with costs, stock, inbound
purchase quantities, and selected-week TikTok US sales.

- [ ] **Step 4: Inspect core desktop UI**

Use the in-app browser to inspect:

- Dashboard.
- Products list, create, and edit.
- Weekly Sales expanded and collapsed states.
- Orders.
- Purchase Orders.
- Inventory Planning.

Capture screenshots of Weekly Sales and any page with an actionable visual
issue. Check browser console errors.

- [ ] **Step 5: Inspect mobile UI and fix actionable issues**

Set a mobile viewport, verify Weekly Sales navigation, table scrolling, expanded
daily input wrapping, and core page readability. Reset the viewport after the
check.

- [ ] **Step 6: Verify high-frequency input UX**

Using only keyboard input, verify row expansion, select-on-focus replacement,
Tab/Shift+Tab movement, Enter-to-next-SKU movement, seven-value paste,
Save/Discard behavior, unsaved-navigation warning, save progress, and
validation-error focus.

- [ ] **Step 7: Re-run verification after UI fixes**

Repeat the full verification commands from Step 2.
