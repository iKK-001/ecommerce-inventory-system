<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, PackageSearch, Ship } from 'lucide-vue-next';

const props = defineProps({
    report: Object,
});

const changeWindow = (window) => {
    router.get(route('reports.inventory-planning'), { window }, {
        preserveScroll: true,
        preserveState: true,
    });
};

const formatNumber = (value, digits = 2) => {
    if (value === null || value === undefined) return '-';
    return new Intl.NumberFormat('en-US', { maximumFractionDigits: digits }).format(value);
};

const formatUsd = (value) => {
    if (value === null || value === undefined) return '-';
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(value);
};

const thClass = 'px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary';
const thClassRight = 'px-4 py-2.5 text-right text-xs font-medium tracking-tight text-text-secondary';
</script>

<template>
    <Head title="Inventory Planning" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">Workspace</span>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('reports.index')" class="text-text-tertiary transition-colors hover:text-text-primary">Reports</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">Inventory Planning</span>
            </div>
        </template>

        <PageHeader
            title="Inventory Planning"
            :description="`Base-unit demand and coverage using the last ${report.window_days} days of manual sales.`"
        >
            <template #actions>
                <Link
                    :href="route('reports.index')"
                    class="inline-flex items-center gap-2 rounded-md border border-border-subtle px-3 py-2 text-sm text-text-secondary transition-colors hover:bg-surface-overlay hover:text-text-primary"
                >
                    <ArrowLeft :size="14" />
                    Back to reports
                </Link>
            </template>
        </PageHeader>

        <section class="mt-6 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <button
                    v-for="window in [7, 14, 30]"
                    :key="window"
                    type="button"
                    :class="[
                        'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                        report.window_days === window
                            ? 'bg-brand text-white'
                            : 'border border-border-subtle text-text-secondary hover:bg-surface-overlay',
                    ]"
                    @click="changeWindow(window)"
                >
                    {{ window }} days
                </button>
            </div>
            <p class="text-xs text-text-tertiary">
                Alert threshold: {{ formatNumber(report.low_stock_days, 0) }} warehouse days
                · Exchange rate: ¥{{ formatNumber(report.exchange_rate_cny_per_usd, 2) }}/USD
            </p>
        </section>

        <Card class="mt-4" :padded="false">
            <div v-if="report.rows.length > 0" class="w-full overflow-x-auto">
                <table class="min-w-[1280px] w-full text-sm">
                    <thead>
                        <tr class="border-b border-border-subtle">
                            <th :class="thClass">Status</th>
                            <th :class="thClass">Base product</th>
                            <th :class="thClassRight">Base units sold</th>
                            <th :class="thClassRight">Avg / day</th>
                            <th :class="thClassRight">Warehouse</th>
                            <th :class="thClassRight">In transit</th>
                            <th :class="thClassRight">Warehouse days</th>
                            <th :class="thClassRight">In-transit days</th>
                            <th :class="thClassRight">Total days</th>
                            <th :class="thClass">Sellable SKU economics</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in report.rows"
                            :key="row.base_product_id"
                            class="border-b border-border-subtle align-top last:border-b-0 hover:bg-surface-overlay"
                        >
                            <td class="px-4 py-3">
                                <Badge :variant="row.is_low_stock ? 'danger' : 'success'" size="sm" dot>
                                    {{ row.is_low_stock ? 'Replenish' : 'Healthy' }}
                                </Badge>
                            </td>
                            <td class="px-4 py-3">
                                <p class="font-medium text-text-primary">{{ row.name }}</p>
                                <p class="font-mono text-xs text-text-tertiary">SKU: {{ row.sku }}</p>
                                <div v-if="row.shipments.length > 0" class="mt-2 space-y-1">
                                    <p
                                        v-for="shipment in row.shipments"
                                        :key="`${shipment.purchase_order_id}-${shipment.remaining_quantity}`"
                                        class="flex items-center gap-1 text-xs text-text-tertiary"
                                    >
                                        <Ship :size="12" />
                                        {{ shipment.po_number }} · {{ shipment.shipping_method || 'method unset' }}
                                        · ETA {{ shipment.expected_date || 'unset' }}
                                    </p>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ formatNumber(row.base_units_sold, 0) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ formatNumber(row.average_daily_units) }}</td>
                            <td class="px-4 py-3 text-right font-medium tabular-nums">{{ formatNumber(row.warehouse_stock, 0) }}</td>
                            <td class="px-4 py-3 text-right font-medium tabular-nums">{{ formatNumber(row.in_transit_quantity, 0) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ formatNumber(row.warehouse_days) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ formatNumber(row.in_transit_days) }}</td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ formatNumber(row.total_days) }}</td>
                            <td class="px-4 py-3">
                                <div class="space-y-2">
                                    <div
                                        v-for="sku in row.skus"
                                        :key="sku.product_id"
                                        class="rounded-md border border-border-subtle px-3 py-2"
                                    >
                                        <div class="flex items-center justify-between gap-4">
                                            <div>
                                                <p class="font-medium text-text-primary">{{ sku.name }}</p>
                                                <p class="font-mono text-xs text-text-tertiary">
                                                    {{ sku.sku }} · {{ formatNumber(sku.units_per_base, 2) }} base units
                                                </p>
                                            </div>
                                            <div class="text-right text-xs">
                                                <p class="font-medium text-text-primary">{{ formatUsd(sku.gross_profit_usd) }} profit</p>
                                                <p class="text-text-tertiary">{{ formatNumber(sku.gross_margin_percent) }}% margin</p>
                                            </div>
                                        </div>
                                        <p class="mt-1 text-xs text-text-tertiary">
                                            Cost ¥{{ formatNumber(sku.cost_cny, 4) }} / {{ formatUsd(sku.cost_usd) }}
                                            · Price {{ formatUsd(sku.selling_price_usd) }}
                                        </p>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="flex flex-col items-center gap-2 px-5 py-12 text-center">
                <PackageSearch :size="24" class="text-text-tertiary" />
                <p class="text-sm font-medium text-text-primary">No base products found</p>
                <p class="text-sm text-text-tertiary">Create standard products and kit pack sizes to begin planning.</p>
            </div>
        </Card>
    </AppLayout>
</template>
