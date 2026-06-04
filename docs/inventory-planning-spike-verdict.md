# Inventoros Inventory Planning Spike Verdict

Date: 2026-06-04

## Recommendation

Proceed with Inventoros as the foundation for the first TikTok Shop inventory
planning Web App.

It is a better starting point than `enilu/web-flash` for this product.
`web-flash` is primarily an admin-system scaffold, while Inventoros already
provides the product, kit/component, order, purchase-order, supplier,
stock-adjustment, permission, and report domains required by the MVP.

ERPNext remains the stronger fallback if the product later requires formal
accounting, true multi-warehouse inventory ledgers, complex procurement
approval, or broader ERP workflows. Those requirements are not necessary for
the current single-store, single-effective-warehouse MVP.

## What The Spike Proved

The implemented spike proves these business-critical workflows:

1. A physical base unit can back multiple sellable pack SKUs.
   - Selling a 1-pack, 2-pack, or 3-pack consumes the shared base inventory.
   - Kit stock remains virtual.
   - Multiple pack lines sharing one base product are validated together before
     any stock is changed.
2. Existing orders can serve as the first manual daily-sales entry mechanism.
   - The 7, 14, and 30 day report converts pack sales into base-unit demand.
   - It calculates average daily consumption and warehouse coverage days.
3. Existing purchase orders can represent inbound shipment batches.
   - `sent` and `partial` quantities count as in transit.
   - `draft` and `received` quantities do not count as in transit.
   - Shipping method and ETA are visible in the planning report.
4. CNY landed cost can be calculated when a purchase order is received.
   - China domestic freight and first-leg freight are allocated by total
     ordered base-unit quantity.
   - Product moving weighted-average cost is updated on receipt.
5. The report calculates sellable-SKU economics.
   - Packaging cost
   - Cost in CNY and USD
   - Gross profit
   - Gross margin
   - Configurable CNY-per-USD exchange rate
6. Required manual-entry fields are available through the existing Web forms
   and REST API.

## Verification Evidence

- Focused inventory-planning regression:
  - 79 tests passed
  - 315 assertions passed
- Full Inventoros test suite:
  - 1199 tests passed
  - 3703 assertions passed
- Production Vue/Vite build passed.
- Pint passed for all PHP files changed by the spike.
- Inventory-planning route is registered and protected by the existing
  `view_reports` permission.

## Important Upstream Risks

1. Inventoros stock is effectively global at the product level.
   - It has warehouse and location concepts, but the main product `stock`
     column is still the source of truth.
   - This is acceptable for the current single-effective-warehouse MVP.
   - It is not sufficient for independently reconciled Japan, US, and China
     warehouse balances.
2. Upstream kit support did not consume component inventory during order
   creation.
   - The spike adds this behavior to the core order service.
   - This patch must be maintained when upgrading Inventoros.
3. One-level kits are supported by the spike.
   - Nested kits and fractional component quantities are intentionally rejected.
4. Inventory planning currently treats every active standard product as a
   sellable base SKU in addition to its kits.
   - Add an explicit sellable/internal flag if internal base products should be
     hidden from commercial SKU economics.
5. The current Composer lock is incompatible with PHP 8.5 because the locked
   PhpSpreadsheet version requires PHP below 8.5.
   - The verified runtime is PHP 8.4.21.
6. The upstream project still emits a Vite large-chunk warning.
   - This is not a blocker for the inventory-planning MVP.

## Remaining MVP Work

Before real operational use, implement:

1. A dedicated weekly bulk sales-entry grid so operators do not need to create
   one normal order at a time.
2. A settings page for:
   - CNY-per-USD exchange rate
   - Low-stock coverage threshold
3. A clear sellable-SKU/internal-base-product flag.
4. Demo data and an operator acceptance pass using real products, packs,
   shipment costs, and seven days of sales.
5. Production deployment, backups, access control review, and monitoring.

Later integrations can add TikTok Shop order imports and overseas warehouse
stock synchronization without changing the validated base-unit planning model.

## Decision

Do not switch to ERPNext or build on `web-flash` for the current MVP.

Continue with Inventoros on PHP 8.4, keep the custom inventory-planning behavior
covered by regression tests, and treat true multi-warehouse accounting as the
main future decision point.
