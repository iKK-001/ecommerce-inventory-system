# Inventory Planning Technical Spike Design

## Purpose

Validate whether Inventoros can serve as the foundation for the first US TikTok
Shop inventory decision-support Web App.

This spike is deliberately narrower than the full product. It must prove the
five business rules that determine whether the upstream project is a viable
base:

1. A 1-pack, 2-pack, and 3-pack share one underlying base-unit inventory.
2. Recent sales can be converted into average daily base-unit consumption.
3. Sent purchase orders can represent stock already shipped from China but not
   yet received by the US warehouse.
4. Receiving a shipment allocates domestic and first-leg freight by base-unit
   quantity and updates moving weighted-average cost.
5. A report can show warehouse days, in-transit days, low-stock status, SKU
   landed cost, gross profit, and gross margin.

## Scope

The spike supports one organization, one store, one effective warehouse, and
manual data entry. It uses the existing Inventoros product, order, purchase
order, stock-adjustment, report, and settings modules.

It does not add TikTok APIs, warehouse APIs, multi-store mapping, per-warehouse
stock accounting, automated demand forecasting, or a dedicated high-speed
sales-entry screen.

## Domain Mapping

### Product and pack sizes

- A physical base unit is an Inventoros `standard` product. Its `stock` is the
  source of truth for warehouse inventory.
- A sellable pack is an Inventoros `kit` product.
- A 2-pack has one component row pointing to the base product with quantity 2.
- A 3-pack has one component row pointing to the base product with quantity 3.
- Selling a kit decrements component stock and writes component-level stock
  adjustment rows. Kit stock remains virtual.

The spike supports one-level kits. Nested kits are explicitly out of scope.

### Manual sales

Existing Inventoros orders are used as the first manual sales-entry mechanism.
One order can contain the aggregate SKU quantities for a day. The order date is
the sales date, and order items are converted to base units through kit
components.

### In-transit inventory

Existing purchase orders are reused as inbound shipment batches:

- `draft`: purchased or being prepared, excluded from in-transit inventory.
- `sent` and `partial`: shipped from China, included in in-transit inventory.
- `received`: arrived at the US warehouse, excluded from in-transit inventory.
- `expected_date`: expected arrival date.
- New `shipping_method`: `air` or `sea`.
- New freight fields store domestic and first-leg freight in CNY.

### Cost model

Base products receive two new CNY cost fields:

- `weighted_average_cost_cny`: current moving weighted-average landed cost.
- `packaging_cost_cny`: packaging cost per sellable SKU.

When a purchase order is received:

```text
allocated freight per base unit
  = (domestic freight CNY + first-leg freight CNY)
    / total base units ordered in the shipment

batch landed unit cost CNY
  = purchase order item unit cost CNY + allocated freight per base unit

new weighted-average cost CNY
  = (old stock * old weighted-average cost + received quantity * batch landed unit cost)
    / (old stock + received quantity)
```

For a sellable kit:

```text
SKU cost CNY
  = sum(component weighted-average cost CNY * component quantity)
    + kit packaging cost CNY

SKU cost USD = SKU cost CNY / configured CNY-per-USD exchange rate
gross profit USD = selling price USD - SKU cost USD
gross margin % = gross profit USD / selling price USD * 100
```

The spike excludes platform fees, ads, last-mile delivery, overseas warehouse
fees, and taxes.

## Inventory Planning Report

The report uses a selectable 7, 14, or 30 day window, defaulting to 7 days.
Each row represents a base product and includes its sellable pack SKUs.

```text
average daily base-unit consumption
  = base units consumed by all sellable SKUs in the window / window days

warehouse coverage days = warehouse base-unit stock / average daily consumption
in-transit coverage days = sent or partial PO base units / average daily consumption
total coverage days = warehouse coverage days + in-transit coverage days
```

If there are no recent sales, coverage fields are null and the row is not
marked low stock. Otherwise, a row is low stock when warehouse coverage days is
less than or equal to the configured global threshold.

The report reads these organization settings, with safe defaults:

- `inventory.exchange_rate_cny_per_usd`: default `7.20`
- `inventory.low_stock_days`: default `21`

## Error Handling and Consistency

- Order creation locks every stock-bearing component row before checking or
  decrementing inventory.
- An order containing multiple kit lines that share a component accumulates
  demand before validating stock.
- Shipment receipt runs in a database transaction and locks products before
  updating stock and weighted-average cost.
- Freight allocation rejects shipments with zero total ordered units.
- All new queries remain organization-scoped.

## Verification Criteria

The technical spike is successful when automated tests prove:

1. Selling two 3-packs consumes six base units and does not decrement virtual
   kit stock.
2. A 7-day report converts mixed 2-pack and 3-pack sales into base-unit daily
   demand and calculates warehouse coverage correctly.
3. Draft purchase orders are excluded from in-transit inventory while sent and
   partial purchase orders are included.
4. Receiving a multi-item CNY shipment allocates freight per base unit and
   updates each product's moving weighted-average cost.
5. The inventory-planning report is reachable through the authenticated reports
   module and does not expose another organization's data.

## Spike Verdict Rules

- Proceed with Inventoros if the five criteria pass without replacing its stock
  ledger, order service, or purchase-order workflow.
- Reconsider ERPNext if component stock consumption or receiving requires a
  broad rewrite of core upstream behavior.
