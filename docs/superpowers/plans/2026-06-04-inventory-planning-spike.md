# Inventory Planning Technical Spike Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prove that Inventoros can support shared base-unit inventory, manual sales-based coverage planning, in-transit stock, landed-cost allocation, and moving weighted-average cost.

**Architecture:** Reuse Inventoros kits for sellable packs, orders for manual daily sales, purchase orders for inbound shipment batches, and reports for the decision dashboard. Add only the cost and shipment fields that cannot be represented safely in existing columns, then isolate calculations in services.

**Tech Stack:** Laravel 13, PHP 8.3+, Eloquent, PHPUnit, Inertia.js, Vue 3, Tailwind CSS.

---

### Task 1: Add planning and inbound cost fields

**Files:**
- Create: `database/migrations/2026_06_04_000001_add_inventory_planning_fields.php`
- Modify: `app/Models/Inventory/Product.php`
- Modify: `app/Models/Purchasing/PurchaseOrder.php`
- Modify: `app/Models/Purchasing/PurchaseOrderItem.php`
- Test: `tests/Feature/InventoryPlanningSchemaTest.php`

- [ ] Write a failing schema/model test for `weighted_average_cost_cny`,
  `packaging_cost_cny`, `shipping_method`, `domestic_freight_cny`,
  `first_leg_freight_cny`, and `landed_unit_cost_cny`.
- [ ] Run `php artisan test tests/Feature/InventoryPlanningSchemaTest.php` and
  confirm it fails because the fields do not exist.
- [ ] Add the migration, fillable attributes, casts, and product relationships
  required by later tasks.
- [ ] Re-run the test and commit.

### Task 2: Make kit sales consume shared base inventory

**Files:**
- Modify: `app/Services/OrderService.php`
- Test: `tests/Feature/OrderServiceTest.php`

- [ ] Add a failing test where an order for two 3-packs consumes six units from
  the component product and leaves the virtual kit stock unchanged.
- [ ] Add a failing test where two different packs share one component and their
  total base-unit demand is validated before any stock is changed.
- [ ] Update `OrderService` to expand one-level kit lines into locked component
  stock targets while retaining the sellable kit on the order item.
- [ ] Write component-level stock-adjustment rows and run the full
  `OrderServiceTest`.
- [ ] Commit the passing behavior.

### Task 3: Allocate freight and update moving weighted-average cost

**Files:**
- Create: `app/Services/InboundReceivingService.php`
- Modify: `app/Models/Purchasing/PurchaseOrderItem.php`
- Modify: `app/Http/Controllers/Purchasing/PurchaseOrderController.php`
- Test: `tests/Feature/InboundReceivingServiceTest.php`

- [ ] Add a failing test for quantity-based allocation of domestic plus
  first-leg freight across multiple products.
- [ ] Add a failing test for moving weighted-average cost after receipt.
- [ ] Implement `InboundReceivingService::receiveItem()` inside a transaction,
  persist `landed_unit_cost_cny`, update the locked product cost, and then use
  the existing stock-adjustment ledger.
- [ ] Route Web purchase-order receipt through the service.
- [ ] Run the service and purchase-order tests, then commit.

### Task 4: Calculate inventory coverage and SKU economics

**Files:**
- Create: `app/Services/InventoryPlanningService.php`
- Test: `tests/Feature/InventoryPlanningServiceTest.php`

- [ ] Add a failing 7-day mixed-pack sales test for base-unit consumption,
  warehouse days, and low-stock status.
- [ ] Add a failing test proving draft POs are excluded while sent and partial
  POs are included in in-transit quantity.
- [ ] Add a failing test for kit cost, USD conversion, gross profit, and gross
  margin.
- [ ] Implement `InventoryPlanningService::report()` with organization scoping,
  one-level kit expansion, zero-sales handling, and configurable window,
  threshold, and exchange rate.
- [ ] Run the planning-service tests and commit.

### Task 5: Expose the planning report in the Web App

**Files:**
- Modify: `app/Http/Controllers/Reports/ReportController.php`
- Modify: `routes/web/reports.php`
- Create: `resources/js/Pages/Reports/InventoryPlanning.vue`
- Modify: `resources/js/Pages/Reports/Index.vue`
- Test: `tests/Feature/InventoryPlanningReportTest.php`

- [ ] Add a failing authenticated report test for page data and organization
  isolation.
- [ ] Add the report route and controller action, reading settings
  `inventory.exchange_rate_cny_per_usd` and `inventory.low_stock_days`.
- [ ] Add a report card and a table showing stock, in-transit, coverage, alert,
  and sellable SKU economics.
- [ ] Run the report test and `npm run build`, then commit.

### Task 6: Verify the spike and record the verdict

**Files:**
- Create: `docs/inventory-planning-spike-verdict.md`

- [ ] Run the focused inventory-planning and order-service test suites.
- [ ] Run `composer test`.
- [ ] Run `npm run build`.
- [ ] Record which requirements passed, remaining upstream risks, and the
  recommendation to continue with Inventoros or switch to ERPNext.
- [ ] Review `git diff upstream/main...HEAD` for scope and commit the verdict.
