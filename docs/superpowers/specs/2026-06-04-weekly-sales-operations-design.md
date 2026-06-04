# TikTok US Weekly Sales Operations Design

## Purpose

Build a single operating page where an operator can review SKU economics,
inventory health, and weekly TikTok US sales, then enter or correct daily sales
without creating normal orders one at a time.

The first release supports one store only: `TikTok US`.

## Confirmed Requirements

- Sales are entered by day, Monday through Sunday.
- Saving sales directly adjusts inventory.
- A previously saved week can be overwritten.
- Overwrite saves adjust inventory by the difference between old and new sales.
- Only active products explicitly marked as sellable appear on the page.
- The primary surface is a SKU operations table, not a permanently expanded
  seven-day spreadsheet.
- Each SKU row displays price, costs, margin, inventory, in-transit inventory,
  coverage, and weekly sales.
- Clicking a row action expands Monday-through-Sunday quantity inputs.

## Scope

### Included

- TikTok US weekly sales operations page.
- Product sellable flag.
- Per-SKU US last-mile fulfillment cost.
- Daily aggregate sales orders.
- Idempotent overwrite and inventory-difference reconciliation.
- SKU economics and inventory health metrics.
- English and Simplified Chinese labels for the new workflow.
- Desktop and mobile UI acceptance review of core operating pages.

### Excluded

- Multiple stores or channel selector.
- TikTok Shop API integration.
- Editing price or costs directly in the weekly sales table.
- Separate historical cost snapshots.
- Marketplace fees, advertising spend, returns, taxes, and discounts in margin.
- A new independent sales-ledger domain separate from orders.

## Product Fields

Add three product fields:

| Field | Type | Default | Purpose |
| --- | --- | --- | --- |
| `is_sellable` | boolean | `true` | Controls whether the product appears as a sellable SKU. |
| `last_mile_cost_usd` | decimal(14,4) | `0` | US last-mile delivery or fulfillment cost per sold SKU. |
| `packing_labor_cost_cny` | decimal(14,4) | `0` | Packing labor cost per sold SKU. |

The existing `packaging_cost_cny` field represents packaging-material cost.
Product create and edit pages expose the sellable flag, last-mile cost,
packaging-material cost, and packing-labor cost. The weekly sales page only
loads products that are active and sellable.

## Cost and Margin Rules

All SKU operating metrics are read-only on the weekly sales page.

### Standard product

```text
product cost USD
  = weighted average landed cost CNY
    / configured CNY-per-USD exchange rate
```

### Kit product

```text
product cost USD
  = sum(component weighted average landed cost CNY * component quantity)
    / configured CNY-per-USD exchange rate
```

### All sellable SKUs

```text
packing cost USD
  = (packaging-material cost CNY + packing-labor cost CNY)
    / configured CNY-per-USD exchange rate

unit total cost USD
  = product cost USD
    + last-mile cost USD
    + packing cost USD

gross profit USD
  = selling price USD - unit total cost USD

gross margin percent
  = gross profit USD / selling price USD * 100
```

When selling price is zero, gross margin percent is null.

This initial margin excludes marketplace fees, advertising, returns, taxes, and
discounts. The UI labels the metric as estimated gross profit.

## Inventory Metrics

### Standard product

- Actual warehouse stock is the product's current stock.
- In-transit inventory is the remaining quantity on sent or partially received
  purchase orders.

### Kit product

- Actual warehouse stock is the number of complete kits currently fulfillable
  from component stock.
- In-transit inventory is the number of complete kits fulfillable from the
  remaining component quantities on sent or partially received purchase orders.
- For multi-component kits, both values use the limiting component.

### Coverage

Sellable days use rolling recent sales, not the currently selected historical
week:

```text
average daily SKU sales = sales in the latest 7 calendar days / 7
sellable days = actual warehouse stock / average daily SKU sales
```

Coverage is null when the SKU has no recent sales. Replenishment status uses the
configured low-stock coverage threshold.

## Page Design

Add `Weekly Sales` under the Workspace navigation section.

### Header

- Page title: SKU Operations and Weekly Sales.
- Store label: TikTok US.
- Selected Monday-through-Sunday date range.
- Previous week, current week, and next week controls.
- Save weekly sales action.

Weeks start on Monday in the organization's configured timezone.

### Summary cards

- Selected-week units sold.
- Estimated selected-week revenue.
- Estimated selected-week gross profit.
- Number of SKUs requiring replenishment.

Revenue and gross profit use current price and current cost values. They are
operating estimates, not historical accounting snapshots.

### Main table

Each row represents one active, sellable SKU and displays:

1. SKU and product name.
2. Selling price.
3. Product cost.
4. Last-mile logistics cost.
5. Packing cost, equal to packaging-material cost plus packing-labor cost.
6. Unit total cost.
7. Estimated gross profit.
8. Estimated gross margin.
9. Actual warehouse stock.
10. In-transit inventory.
11. Sellable days.
12. Selected-week sales total.
13. Sales-entry action.

The table supports product/SKU search, replenishment-status filtering, and a
filter for rows with unsaved sales changes. Wide desktop layouts use horizontal
scrolling with the SKU column kept visually prominent.

### Expanded daily entry

Clicking `Enter sales` expands a row containing seven non-negative integer
inputs, Monday through Sunday, plus the weekly total.

For kits, the expanded area also previews the expected component inventory
impact. A modified row is visibly marked until the week is saved.

On mobile, the operations table becomes horizontally scrollable and the
expanded daily inputs wrap into a compact grid.

## Daily Aggregate Orders

Existing orders remain the source of truth for sales and inventory planning.
Each date with non-zero TikTok US sales has one aggregate order:

| Attribute | Value |
| --- | --- |
| `source` | `tiktok_us_manual` |
| `external_id` | `tiktok-us-sales:YYYY-MM-DD` |
| `customer_name` | `TikTok US Daily Aggregate` |
| `status` | `delivered` |
| `order_date` | The represented sales date |
| `items` | Non-zero sellable SKU quantities for that date |

Order item unit price uses the product's current selling price at save time.
The order is visible in the normal Orders page for auditability.

The weekly sales service locks the organization row before reconciliation so
two concurrent weekly saves cannot create duplicate daily aggregate orders.

## Overwrite and Inventory Reconciliation

Saving a week is one database transaction.

For each date:

1. Load and lock the existing TikTok US aggregate order, if present.
2. Compare existing SKU quantities with the submitted quantities.
3. Expand each positive or negative SKU difference into physical stock targets.
   Standard products target themselves. Kits target their components.
4. Validate all positive sales differences against available physical stock.
5. Apply positive differences as fulfillment adjustments and decrement stock.
6. Apply negative differences as cancellation adjustments and restore stock.
7. Replace the aggregate order items and recalculate order totals.
8. Delete the daily aggregate order when every submitted quantity is zero.

Stock targets are locked in stable ID order. The shared stock-expansion logic is
used by normal order creation and weekly-sales reconciliation so kit behavior
cannot drift between the two paths.

If any date or SKU fails, the entire weekly save rolls back.

## Validation and Error Handling

- The submitted week must resolve to a Monday.
- Quantities must be integers greater than or equal to zero.
- Product IDs must belong to the authenticated organization.
- Submitted products must be active and sellable.
- An increased quantity cannot exceed available physical stock.
- An insufficient-stock error identifies the sellable SKU, date, and limiting
  physical product or component.
- Validation failures preserve entered values in the page.
- Navigating away with unsaved changes prompts the operator.
- Successful saves refresh the displayed stock, totals, and coverage metrics.

## Permissions

- `view_orders` can view the page.
- `create_orders` can save weekly sales.
- Existing product create/edit permissions control `is_sellable`,
  `last_mile_cost_usd`, packaging-material cost, and packing-labor cost.

## Components and Service Boundaries

### `WeeklySalesController`

- Normalizes the selected week.
- Loads page data through the operations-report service.
- Validates and submits a full-week sales payload.

### `SkuOperationsService`

- Produces sellable SKU economics.
- Calculates standard and kit inventory metrics.
- Loads selected-week daily quantities and rolling sales coverage.
- Produces summary-card totals.

### `WeeklySalesService`

- Owns weekly aggregate-order reconciliation.
- Serializes saves per organization.
- Applies inventory differences in one transaction.
- Creates, updates, or removes daily aggregate orders.

### Shared sales stock resolver

- Expands a sellable product and quantity into physical stock requirements.
- Supports standard products, variants where applicable, and one-level kits.
- Is reused by normal order creation and weekly-sales overwrite logic.

## Testing

Add focused feature and service coverage for:

- Page only displays active, sellable products from the current organization.
- Economics for standard products and kits.
- Actual and in-transit kit quantities use the limiting component.
- New weekly sales create daily aggregate orders and decrement inventory.
- Increasing saved sales decrements only the positive difference.
- Reducing saved sales restores only the negative difference.
- Clearing a date restores inventory and removes its aggregate order.
- Kit sales adjust shared base-component inventory.
- An insufficient-stock failure rolls back the entire week.
- Concurrent/idempotent repeated saves do not duplicate daily orders.
- Permission and cross-organization isolation.
- Product create and edit persist the new fields and calculate packing cost
  from packaging-material cost plus packing-labor cost.

Run the complete PHP test suite, frontend production build, PHP formatter, and
`git diff --check`.

## UI Acceptance Review

After implementation, start the local application and inspect these pages in the
in-app browser:

- Dashboard.
- Products list, create, and edit.
- Weekly Sales.
- Orders.
- Purchase Orders.
- Inventory Planning.

Verify:

- Desktop layout and wide-table horizontal scrolling.
- Mobile layout and expanded daily-entry grid.
- Navigation active state.
- Empty, loading, saved, modified, replenishment, and validation-error states.
- No browser console errors.
- Core page visual consistency with the existing design system.

Capture key screenshots and summarize any remaining UI inconsistencies.

## Future Extensions

- Multiple stores and per-store timezones.
- TikTok Shop sales import.
- TikTok fees, advertising spend, returns, and contribution margin.
- Historical price and cost snapshots.
- Overseas warehouse synchronization.
