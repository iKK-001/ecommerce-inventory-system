<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import {
    AlertTriangle,
    Boxes,
    CalendarDays,
    CheckCircle2,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    ChevronUp,
    ClipboardPaste,
    DollarSign,
    PackageCheck,
    PencilLine,
    RotateCcw,
    Save,
    Search,
    TrendingUp,
} from 'lucide-vue-next';

import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import StatTile from '@/Components/ui/StatTile.vue';

const props = defineProps({
    report: { type: Object, required: true },
    canSave: { type: Boolean, default: false },
    canEditCosts: { type: Boolean, default: false },
});

const { t, locale } = useI18n();
const page = usePage();
const form = useForm({ week_start: props.report.week_start, sales: [] });
const costForm = useForm({
    week_start: props.report.week_start,
    selling_price_usd: 0,
    product_cost_usd: 0,
    domestic_logistics_cost_usd: 0,
    us_first_leg_cost_usd: 0,
    us_last_mile_cost_usd: 0,
    packing_cost_usd: 0,
});
const search = ref('');
const stockFilter = ref('all');
const changedOnly = ref(false);
const expandedIds = ref(new Set());
const originalQuantities = ref({});
const draftQuantities = ref({});
const costDrafts = ref({});
const editingCostProductId = ref(null);
const clientError = ref('');
const allowNavigation = ref(false);
const inputRefs = {};

const costFields = [
    { key: 'selling_price_usd', label: 'weeklySales.sellingPrice', valueKey: 'selling_price_usd', strong: true, digits: 2 },
    { key: 'product_cost_usd', label: 'weeklySales.productCost', valueKey: 'product_cost_usd', digits: 4 },
    { key: 'domestic_logistics_cost_usd', label: 'weeklySales.domesticLogistics', valueKey: 'domestic_logistics_cost_usd', digits: 4 },
    { key: 'packing_cost_usd', label: 'weeklySales.packingCost', valueKey: 'packing_cost_usd', digits: 4 },
    { key: 'us_first_leg_cost_usd', label: 'weeklySales.usFirstLeg', valueKey: 'us_first_leg_cost_usd', digits: 4 },
    { key: 'us_last_mile_cost_usd', label: 'weeklySales.usLastMile', valueKey: 'us_last_mile_cost_usd', digits: 4 },
];

const copyDailyQuantities = (row) =>
    Object.fromEntries(props.report.days.map((day) => [day.date, Number(row.daily_quantities?.[day.date] ?? 0)]));

const initializeDrafts = () => {
    originalQuantities.value = Object.fromEntries(props.report.rows.map((row) => [row.product_id, copyDailyQuantities(row)]));
    draftQuantities.value = Object.fromEntries(props.report.rows.map((row) => [row.product_id, copyDailyQuantities(row)]));
    clientError.value = '';
    form.clearErrors();
};

initializeDrafts();

const copyCostDraft = (row) =>
    Object.fromEntries(costFields.map((field) => [field.key, Number(row[field.valueKey] ?? 0).toFixed(field.digits)]));

const initializeCostDrafts = () => {
    costDrafts.value = Object.fromEntries(props.report.rows.map((row) => [row.product_id, copyCostDraft(row)]));
    costForm.clearErrors();
};

initializeCostDrafts();

watch(
    () => props.report.rows,
    () => initializeCostDrafts()
);

const isDirty = (productId) =>
    props.report.days.some(
        (day) => Number(draftQuantities.value[productId]?.[day.date] || 0) !== Number(originalQuantities.value[productId]?.[day.date] || 0)
    );

const dirtyProductIds = computed(() => props.report.rows.filter((row) => isDirty(row.product_id)).map((row) => row.product_id));
const dirtyCount = computed(() => dirtyProductIds.value.length);

const visibleRows = computed(() => {
    const query = search.value.trim().toLowerCase();

    return props.report.rows.filter((row) => {
        const matchesSearch =
            !query || `${row.sku || ''} ${row.name || ''}`.toLowerCase().includes(query);
        const matchesStock =
            stockFilter.value === 'all' ||
            (stockFilter.value === 'replenish' && row.is_low_stock) ||
            (stockFilter.value === 'healthy' && !row.is_low_stock);
        const matchesChanged = !changedOnly.value || isDirty(row.product_id);

        return matchesSearch && matchesStock && matchesChanged;
    });
});

const firstError = computed(() => clientError.value || Object.values(form.errors)[0] || Object.values(costForm.errors)[0] || null);
const flashSuccess = computed(() => page.props.flash?.success);

const formatUsd = (value) =>
    new Intl.NumberFormat(locale.value, { style: 'currency', currency: 'USD', maximumFractionDigits: 2 }).format(value ?? 0);

const formatNumber = (value, digits = 2) => {
    if (value === null || value === undefined) return '-';
    return new Intl.NumberFormat(locale.value, { maximumFractionDigits: digits }).format(value);
};

const dateObject = (date) => new Date(`${date}T12:00:00`);
const formatDate = (date) => dateObject(date).toLocaleDateString(locale.value, { month: 'short', day: 'numeric' });
const formatDay = (date) => dateObject(date).toLocaleDateString(locale.value, { weekday: 'short' });
const formatRange = () => `${formatDate(props.report.week_start)} – ${formatDate(props.report.week_end)}`;

const addDays = (date, days) => {
    const value = dateObject(date);
    value.setDate(value.getDate() + days);
    return [
        value.getFullYear(),
        String(value.getMonth() + 1).padStart(2, '0'),
        String(value.getDate()).padStart(2, '0'),
    ].join('-');
};

const currentMonday = () => {
    const value = new Date();
    const daysSinceMonday = (value.getDay() + 6) % 7;
    value.setDate(value.getDate() - daysSinceMonday);
    return [
        value.getFullYear(),
        String(value.getMonth() + 1).padStart(2, '0'),
        String(value.getDate()).padStart(2, '0'),
    ].join('-');
};

const navigateWeek = (weekStart) => {
    router.get(route('weekly-sales.index'), { week_start: weekStart }, { preserveScroll: false });
};

const setExpanded = (productId, expanded) => {
    const next = new Set(expandedIds.value);
    if (expanded) next.add(productId);
    else next.delete(productId);
    expandedIds.value = next;
};

const registerInput = (productId, date, element) => {
    const key = `${productId}:${date}`;
    if (element) inputRefs[key] = element;
    else delete inputRefs[key];
};

const focusInput = async (productId, dayIndex = 0) => {
    setExpanded(productId, true);
    await nextTick();
    const date = props.report.days[dayIndex]?.date;
    const input = inputRefs[`${productId}:${date}`];
    input?.focus();
    input?.select();
};

const toggleRow = (row) => {
    if (expandedIds.value.has(row.product_id)) {
        setExpanded(row.product_id, false);
    } else {
        focusInput(row.product_id);
    }
};

const selectValue = (event) => event.target.select();

const isEditingCosts = (row) => editingCostProductId.value === row.product_id;

const startCostEdit = (row) => {
    if (!props.canEditCosts || costForm.processing) return;
    costDrafts.value[row.product_id] = copyCostDraft(row);
    editingCostProductId.value = row.product_id;
    costForm.clearErrors();
};

const cancelCostEdit = (row) => {
    costDrafts.value[row.product_id] = copyCostDraft(row);
    if (editingCostProductId.value === row.product_id) editingCostProductId.value = null;
    costForm.clearErrors();
};

const updateCostDraft = (row, key, event) => {
    costDrafts.value[row.product_id][key] = event.target.value;
    costForm.clearErrors();
};

const normalizeCostDraft = (row, key) => {
    const field = costFields.find((candidate) => candidate.key === key);
    const value = Number(costDrafts.value[row.product_id][key]);
    const digits = field?.digits ?? 2;
    costDrafts.value[row.product_id][key] = Number.isFinite(value) && value >= 0 ? value.toFixed(digits) : Number(0).toFixed(digits);
};

const costDraftValue = (row, key) => Number(costDrafts.value[row.product_id]?.[key] || 0);

const saveCostEdits = (row) => {
    if (!props.canEditCosts || !isEditingCosts(row) || costForm.processing) return;

    costFields.forEach((field) => normalizeCostDraft(row, field.key));
    costForm.week_start = props.report.week_start;
    costFields.forEach((field) => {
        costForm[field.key] = costDraftValue(row, field.key);
    });

    allowNavigation.value = true;
    costForm.put(route('weekly-sales.costs.update', row.product_id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            editingCostProductId.value = null;
            initializeCostDrafts();
        },
        onFinish: () => {
            allowNavigation.value = false;
        },
    });
};

const updateQuantity = (productId, date, event) => {
    const rawValue = event.target.value;
    draftQuantities.value[productId][date] = rawValue === ''
        ? ''
        : Math.max(0, Math.floor(Number(rawValue) || 0));
    clientError.value = '';
};

const normalizeQuantity = (productId, date) => {
    const value = Number(draftQuantities.value[productId][date]);
    draftQuantities.value[productId][date] = Number.isInteger(value) && value >= 0 ? value : 0;
};

const weeklyTotal = (row) =>
    props.report.days.reduce((total, day) => total + Number(draftQuantities.value[row.product_id]?.[day.date] || 0), 0);

const originalWeeklyTotal = (row) =>
    props.report.days.reduce((total, day) => total + Number(originalQuantities.value[row.product_id]?.[day.date] || 0), 0);

const inventoryDelta = (row) => weeklyTotal(row) - originalWeeklyTotal(row);

const projectedComponentStock = (row, component) =>
    component.warehouse_stock - inventoryDelta(row) * component.quantity_per_sale;

const handlePaste = (event, row, dayIndex) => {
    const text = event.clipboardData?.getData('text')?.trim() || '';
    if (!text) return;

    const values = text.split(/[\s,]+/).filter(Boolean);
    if (values.length === 1) return;

    event.preventDefault();
    if (dayIndex !== 0 || values.length !== 7 || values.some((value) => !/^\d+$/.test(value))) {
        clientError.value = t('weeklySales.pasteError');
        return;
    }

    props.report.days.forEach((day, index) => {
        draftQuantities.value[row.product_id][day.date] = Number(values[index]);
    });
    clientError.value = '';
};

const handleEnter = (row, dayIndex) => {
    const rowIndex = visibleRows.value.findIndex((candidate) => candidate.product_id === row.product_id);
    const nextRow = visibleRows.value[rowIndex + 1];
    if (nextRow) focusInput(nextRow.product_id, dayIndex);
};

const discardChanges = () => {
    draftQuantities.value = Object.fromEntries(
        props.report.rows.map((row) => [
            row.product_id,
            Object.fromEntries(props.report.days.map((day) => [day.date, Number(originalQuantities.value[row.product_id]?.[day.date] ?? 0)])),
        ])
    );
    clientError.value = '';
    form.clearErrors();
};

const focusFirstError = async () => {
    const errorEntry = Object.entries(form.errors)[0];
    let row = null;
    let dayIndex = 0;

    if (errorEntry) {
        const [key, message] = errorEntry;
        const indexMatch = key.match(/^sales\.(\d+)/);
        if (indexMatch) row = props.report.rows[Number(indexMatch[1])];
        if (!row) row = props.report.rows.find((candidate) => String(message).includes(candidate.sku));
        const dateMatch = key.match(/(\d{4}-\d{2}-\d{2})$/);
        if (dateMatch) dayIndex = Math.max(0, props.report.days.findIndex((day) => day.date === dateMatch[1]));
    }

    row ||= props.report.rows.find((candidate) => isDirty(candidate.product_id));
    if (row) await focusInput(row.product_id, dayIndex);
};

const saveWeek = () => {
    if (!props.canSave || dirtyCount.value === 0 || form.processing) return;

    form.week_start = props.report.week_start;
    form.sales = props.report.rows
        .filter((row) => row.is_entry_supported)
        .map((row) => ({
            product_id: row.product_id,
            daily_quantities: Object.fromEntries(
                props.report.days.map((day) => [day.date, Number(draftQuantities.value[row.product_id]?.[day.date] || 0)])
            ),
        }));

    allowNavigation.value = true;
    form.post(route('weekly-sales.store'), {
        preserveScroll: true,
        onError: () => {
            allowNavigation.value = false;
            focusFirstError();
        },
        onSuccess: () => nextTick(initializeDrafts),
        onFinish: () => {
            allowNavigation.value = false;
        },
    });
};

const rowError = (row) => {
    const index = props.report.rows.findIndex((candidate) => candidate.product_id === row.product_id);
    return Object.entries(form.errors).find(
        ([key, message]) => key.startsWith(`sales.${index}.`) || String(message).includes(row.sku)
    )?.[1];
};

const handleBeforeUnload = (event) => {
    if (dirtyCount.value === 0 && editingCostProductId.value === null) return;
    event.preventDefault();
    event.returnValue = '';
};

let removeBeforeListener;
onMounted(() => {
    window.addEventListener('beforeunload', handleBeforeUnload);
    removeBeforeListener = router.on('before', (event) => {
        if (!allowNavigation.value && (dirtyCount.value > 0 || editingCostProductId.value !== null) && !window.confirm(t('weeklySales.leaveWarning'))) {
            event.preventDefault();
        }
    });
});

onUnmounted(() => {
    window.removeEventListener('beforeunload', handleBeforeUnload);
    removeBeforeListener?.();
});

const thClass = 'whitespace-nowrap px-3 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-text-tertiary';
const thRightClass = `${thClass} text-right`;
const tdRightClass = 'whitespace-nowrap px-3 py-3 text-right text-xs tabular-nums text-text-secondary';
const costInputClass = 'h-8 w-24 rounded-md border border-border-strong bg-surface-canvas px-2 text-right text-xs font-semibold tabular-nums text-text-primary ds-focus-ring';
const editableCostClass = 'rounded-md px-2 py-1 tabular-nums transition-colors hover:bg-brand-soft hover:text-brand ds-focus-ring';
</script>

<template>
    <Head :title="t('weeklySales.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">{{ t('nav.sections.workspace') }}</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('weeklySales.title') }}</span>
            </div>
        </template>

        <PageHeader
            :title="t('weeklySales.title')"
            :description="t('weeklySales.description')"
            :eyebrow="`${report.store} · ${formatRange()}`"
        >
            <template #actions>
                <Button
                    v-if="canSave"
                    size="sm"
                    :disabled="dirtyCount === 0"
                    :loading="form.processing"
                    @click="saveWeek"
                >
                    <Save :size="14" />
                    {{ t('weeklySales.saveWeek') }}
                </Button>
            </template>
        </PageHeader>

        <div
            v-if="flashSuccess"
            class="mt-5 flex items-start gap-2 rounded-lg border border-status-success/20 bg-status-success-soft px-4 py-3 text-sm text-status-success"
        >
            <CheckCircle2 :size="16" class="mt-0.5 shrink-0" />
            {{ flashSuccess }}
        </div>

        <div
            v-if="firstError"
            class="mt-5 flex items-start gap-2 rounded-lg border border-status-danger/20 bg-status-danger-soft px-4 py-3 text-sm text-status-danger"
        >
            <AlertTriangle :size="16" class="mt-0.5 shrink-0" />
            <div>
                <p class="font-medium">{{ t('weeklySales.saveError') }}</p>
                <p class="mt-0.5">{{ firstError }}</p>
            </div>
        </div>

        <Card class="mt-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex items-center gap-3">
                    <div class="grid h-10 w-10 place-items-center rounded-lg bg-brand-soft text-brand">
                        <CalendarDays :size="18" />
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wider text-text-tertiary">{{ t('weeklySales.selectedWeek') }}</p>
                        <p class="mt-0.5 text-sm font-semibold text-text-primary">{{ formatRange() }}</p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Button variant="secondary" size="sm" @click="navigateWeek(addDays(report.week_start, -7))">
                        <ChevronLeft :size="14" />
                        {{ t('weeklySales.previousWeek') }}
                    </Button>
                    <Button variant="outline" size="sm" @click="navigateWeek(currentMonday())">
                        {{ t('weeklySales.currentWeek') }}
                    </Button>
                    <Button variant="secondary" size="sm" @click="navigateWeek(addDays(report.week_start, 7))">
                        {{ t('weeklySales.nextWeek') }}
                        <ChevronRight :size="14" />
                    </Button>
                </div>
            </div>
        </Card>

        <section class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <StatTile :label="t('weeklySales.unitsSold')" :value="formatNumber(report.summary.units_sold, 0)" icon-tone="brand">
                <template #icon><PackageCheck :size="17" /></template>
            </StatTile>
            <StatTile :label="t('weeklySales.estimatedRevenue')" :value="formatUsd(report.summary.estimated_revenue_usd)" icon-tone="success">
                <template #icon><DollarSign :size="17" /></template>
            </StatTile>
            <StatTile :label="t('weeklySales.estimatedProfit')" :value="formatUsd(report.summary.estimated_gross_profit_usd)" icon-tone="info">
                <template #icon><TrendingUp :size="17" /></template>
            </StatTile>
            <StatTile :label="t('weeklySales.replenishmentCount')" :value="formatNumber(report.summary.replenishment_sku_count, 0)" icon-tone="warning">
                <template #icon><AlertTriangle :size="17" /></template>
            </StatTile>
        </section>

        <Card class="mt-4">
            <div class="grid gap-3 lg:grid-cols-[minmax(260px,1fr)_220px_auto] lg:items-end">
                <div>
                    <label for="weekly-sales-search" class="mb-1 block text-xs font-medium text-text-secondary">
                        {{ t('weeklySales.searchLabel') }}
                    </label>
                    <div class="relative">
                        <Search :size="15" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-text-tertiary" />
                        <input
                            id="weekly-sales-search"
                            v-model="search"
                            type="search"
                            :placeholder="t('weeklySales.searchPlaceholder')"
                            class="h-9 w-full rounded-md border border-border-subtle bg-surface-canvas pl-9 pr-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring"
                        />
                    </div>
                </div>
                <div>
                    <label for="weekly-sales-stock-filter" class="mb-1 block text-xs font-medium text-text-secondary">
                        {{ t('weeklySales.stockFilter') }}
                    </label>
                    <select
                        id="weekly-sales-stock-filter"
                        v-model="stockFilter"
                        class="h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary ds-focus-ring"
                    >
                        <option value="all">{{ t('weeklySales.allStock') }}</option>
                        <option value="replenish">{{ t('weeklySales.replenish') }}</option>
                        <option value="healthy">{{ t('weeklySales.healthy') }}</option>
                    </select>
                </div>
                <button
                    type="button"
                    :class="[
                        'inline-flex h-9 items-center justify-center gap-2 rounded-md border px-3 text-sm font-medium transition-colors ds-focus-ring',
                        changedOnly
                            ? 'border-brand/30 bg-brand-soft text-brand'
                            : 'border-border-subtle bg-surface-canvas text-text-secondary hover:bg-surface-overlay',
                    ]"
                    @click="changedOnly = !changedOnly"
                >
                    <PencilLine :size="14" />
                    {{ t('weeklySales.changedOnly') }}
                    <Badge v-if="dirtyCount > 0" variant="warning" size="sm">{{ dirtyCount }}</Badge>
                </button>
            </div>
            <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs text-text-tertiary">
                <p>{{ t('weeklySales.tableHint') }}</p>
                <p>¥{{ formatNumber(report.settings.exchange_rate_cny_per_usd, 2) }}/USD · {{ t('weeklySales.lowStockThreshold', { days: formatNumber(report.settings.low_stock_days, 0) }) }}</p>
            </div>
        </Card>

        <Card class="mt-4" :padded="false">
            <div v-if="visibleRows.length > 0" class="w-full overflow-x-auto">
                <table class="min-w-[2060px] w-full border-separate border-spacing-0 text-sm">
                    <thead class="bg-surface-raised">
                        <tr>
                            <th :class="[thClass, 'sticky left-0 z-20 min-w-[240px] border-b border-r border-border-subtle bg-surface-raised']">
                                {{ t('weeklySales.skuProduct') }}
                            </th>
                            <th
                                v-for="field in costFields"
                                :key="field.key"
                                :class="[thRightClass, 'border-b border-border-subtle']"
                            >
                                {{ t(field.label) }}
                            </th>
                            <th :class="[thRightClass, 'border-b border-border-subtle']">{{ t('weeklySales.totalCost') }}</th>
                            <th :class="[thRightClass, 'border-b border-border-subtle']">{{ t('weeklySales.grossProfit') }}</th>
                            <th :class="[thRightClass, 'border-b border-border-subtle']">{{ t('weeklySales.margin') }}</th>
                            <th :class="[thRightClass, 'border-b border-border-subtle']">{{ t('weeklySales.actualStock') }}</th>
                            <th :class="[thRightClass, 'border-b border-border-subtle']">{{ t('weeklySales.inTransit') }}</th>
                            <th :class="[thRightClass, 'border-b border-border-subtle']">{{ t('weeklySales.sellableDays') }}</th>
                            <th :class="[thRightClass, 'border-b border-border-subtle']">{{ t('weeklySales.weekSales') }}</th>
                            <th :class="[thRightClass, 'border-b border-border-subtle']">{{ t('weeklySales.entry') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="row in visibleRows" :key="row.product_id">
                            <tr
                                :class="[
                                    'group transition-colors hover:bg-surface-overlay',
                                    isDirty(row.product_id) ? 'bg-brand-soft' : 'bg-surface-raised',
                                ]"
                            >
                                <td
                                    :class="[
                                        'sticky left-0 z-10 border-b border-r border-border-subtle px-3 py-3',
                                        isDirty(row.product_id) ? 'bg-brand-soft' : 'bg-surface-raised group-hover:bg-surface-overlay',
                                    ]"
                                >
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-1.5">
                                                <p class="truncate font-medium text-text-primary">{{ row.name }}</p>
                                                <Badge v-if="row.type === 'kit'" variant="info" size="sm">{{ t('products.create.kit') }}</Badge>
                                                <Badge v-if="isDirty(row.product_id)" variant="warning" size="sm" dot>{{ t('weeklySales.unsaved') }}</Badge>
                                                <Badge v-if="isEditingCosts(row)" variant="info" size="sm">{{ t('weeklySales.editingCosts') }}</Badge>
                                            </div>
                                            <p class="mt-1 truncate font-mono text-[11px] text-text-tertiary">{{ row.sku || '—' }}</p>
                                        </div>
                                        <Badge :variant="row.is_low_stock ? 'danger' : 'success'" size="sm" dot>
                                            {{ row.is_low_stock ? t('weeklySales.replenish') : t('weeklySales.healthy') }}
                                        </Badge>
                                    </div>
                                </td>
                                <td
                                    v-for="field in costFields"
                                    :key="field.key"
                                    :class="[tdRightClass, props.canEditCosts ? 'align-middle' : '']"
                                >
                                    <input
                                        v-if="isEditingCosts(row)"
                                        :value="costDrafts[row.product_id]?.[field.key]"
                                        :aria-label="`${row.sku || row.name} ${t(field.label)}`"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        inputmode="decimal"
                                        :class="costInputClass"
                                        @input="updateCostDraft(row, field.key, $event)"
                                        @blur="normalizeCostDraft(row, field.key)"
                                        @focus="selectValue"
                                        @keydown.enter.prevent="saveCostEdits(row)"
                                        @keydown.esc.prevent="cancelCostEdit(row)"
                                    />
                                    <button
                                        v-else-if="props.canEditCosts"
                                        type="button"
                                        :class="[editableCostClass, field.strong ? 'font-medium text-text-primary' : 'text-text-secondary']"
                                        :title="t('weeklySales.editCostHint')"
                                        @click="startCostEdit(row)"
                                    >
                                        {{ formatUsd(row[field.valueKey]) }}
                                    </button>
                                    <span v-else :class="field.strong ? 'font-medium text-text-primary' : ''">
                                        {{ formatUsd(row[field.valueKey]) }}
                                    </span>
                                </td>
                                <td :class="tdRightClass"><span class="font-medium text-text-primary">{{ formatUsd(row.unit_total_cost_usd) }}</span></td>
                                <td :class="tdRightClass"><span :class="row.gross_profit_usd >= 0 ? 'text-status-success' : 'text-status-danger'">{{ formatUsd(row.gross_profit_usd) }}</span></td>
                                <td :class="tdRightClass">{{ row.gross_margin_percent === null ? '—' : `${formatNumber(row.gross_margin_percent)}%` }}</td>
                                <td :class="tdRightClass"><span class="font-semibold text-text-primary">{{ formatNumber(row.warehouse_stock, 0) }}</span></td>
                                <td :class="tdRightClass">{{ formatNumber(row.in_transit_quantity, 0) }}</td>
                                <td :class="tdRightClass">
                                    <span v-if="row.sellable_days !== null" :class="row.is_low_stock ? 'font-medium text-status-danger' : 'text-text-secondary'">
                                        {{ formatNumber(row.sellable_days) }}
                                    </span>
                                    <span v-else class="text-text-tertiary">—</span>
                                </td>
                                <td :class="tdRightClass"><span class="font-semibold text-text-primary">{{ formatNumber(weeklyTotal(row), 0) }}</span></td>
                                <td class="whitespace-nowrap border-b border-border-subtle px-3 py-3 text-right">
                                    <div v-if="isEditingCosts(row)" class="flex items-center justify-end gap-1">
                                        <Button size="xs" :loading="costForm.processing" @click="saveCostEdits(row)">
                                            <Save :size="13" />
                                            {{ t('weeklySales.saveCosts') }}
                                        </Button>
                                        <Button variant="secondary" size="xs" :disabled="costForm.processing" @click="cancelCostEdit(row)">
                                            {{ t('weeklySales.cancelCosts') }}
                                        </Button>
                                    </div>
                                    <template v-else>
                                        <Button
                                            variant="secondary"
                                            size="xs"
                                            :disabled="!row.is_entry_supported"
                                            @click="toggleRow(row)"
                                        >
                                            <component :is="expandedIds.has(row.product_id) ? ChevronUp : ChevronDown" :size="13" />
                                            {{ canSave ? t('weeklySales.enterSales') : t('weeklySales.viewSales') }}
                                        </Button>
                                        <button
                                            v-if="props.canEditCosts"
                                            type="button"
                                            class="mt-1 block w-full text-right text-[10px] font-medium text-brand hover:text-brand-hover ds-focus-ring"
                                            @click="startCostEdit(row)"
                                        >
                                            {{ t('weeklySales.editCosts') }}
                                        </button>
                                        <p v-if="!row.is_entry_supported" class="mt-1 text-[10px] text-status-warning">{{ t('weeklySales.unsupportedVariants') }}</p>
                                    </template>
                                </td>
                            </tr>
                            <tr v-if="expandedIds.has(row.product_id)" class="bg-surface-canvas">
                                <td colspan="15" class="border-b border-border-subtle px-4 py-4">
                                    <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center justify-between gap-2">
                                                <div>
                                                    <p class="text-sm font-semibold text-text-primary">{{ t('weeklySales.dailyEntry') }}</p>
                                                    <p class="mt-0.5 flex items-center gap-1.5 text-xs text-text-tertiary">
                                                        <ClipboardPaste :size="13" />
                                                        {{ t('weeklySales.pasteHint') }}
                                                    </p>
                                                </div>
                                                <p v-if="rowError(row)" class="text-xs font-medium text-status-danger">{{ rowError(row) }}</p>
                                            </div>

                                            <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4 xl:grid-cols-7">
                                                <label
                                                    v-for="(day, dayIndex) in report.days"
                                                    :key="day.date"
                                                    class="rounded-lg border border-border-subtle bg-surface-raised p-2.5"
                                                >
                                                    <span class="flex items-center justify-between gap-2">
                                                        <span class="text-xs font-semibold text-text-primary">{{ formatDay(day.date) }}</span>
                                                        <span class="text-[10px] text-text-tertiary">{{ formatDate(day.date) }}</span>
                                                    </span>
                                                    <input
                                                        :ref="(element) => registerInput(row.product_id, day.date, element)"
                                                        :value="draftQuantities[row.product_id][day.date]"
                                                        :disabled="!canSave"
                                                        type="number"
                                                        min="0"
                                                        step="1"
                                                        inputmode="numeric"
                                                        class="mt-2 h-10 w-full rounded-md border border-border-strong bg-surface-canvas px-2 text-center text-base font-semibold tabular-nums text-text-primary ds-focus-ring disabled:cursor-not-allowed disabled:opacity-60"
                                                        @input="updateQuantity(row.product_id, day.date, $event)"
                                                        @blur="normalizeQuantity(row.product_id, day.date)"
                                                        @focus="selectValue"
                                                        @paste="handlePaste($event, row, dayIndex)"
                                                        @keydown.enter.prevent="handleEnter(row, dayIndex)"
                                                    />
                                                </label>
                                            </div>

                                            <div v-if="row.component_impact.length > 0" class="mt-3 rounded-lg border border-border-subtle bg-surface-raised px-3 py-2.5">
                                                <p class="text-xs font-semibold text-text-primary">{{ t('weeklySales.componentImpact') }}</p>
                                                <div class="mt-2 flex flex-wrap gap-2">
                                                    <div
                                                        v-for="component in row.component_impact"
                                                        :key="component.product_id"
                                                        :class="[
                                                            'rounded-md border px-2.5 py-2 text-xs',
                                                            projectedComponentStock(row, component) < 0
                                                                ? 'border-status-danger/30 bg-status-danger-soft'
                                                                : 'border-border-subtle bg-surface-canvas',
                                                        ]"
                                                    >
                                                        <div class="flex flex-wrap items-center gap-1.5">
                                                            <p class="font-medium text-text-primary">{{ component.sku || component.name }}</p>
                                                            <Badge v-if="projectedComponentStock(row, component) < 0" variant="danger" size="sm">
                                                                {{ t('weeklySales.componentShortage') }}
                                                            </Badge>
                                                        </div>
                                                        <p :class="['mt-0.5', projectedComponentStock(row, component) < 0 ? 'font-medium text-status-danger' : 'text-text-tertiary']">
                                                            {{ formatNumber(component.quantity_per_sale, 2) }} × {{ formatNumber(weeklyTotal(row), 0) }}
                                                            · {{ t('weeklySales.componentAfter') }} {{ formatNumber(projectedComponentStock(row, component), 0) }}
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="grid min-w-[210px] grid-cols-2 gap-2 xl:grid-cols-1">
                                            <div class="rounded-lg border border-border-subtle bg-surface-raised px-3 py-3">
                                                <p class="text-[10px] font-semibold uppercase tracking-wider text-text-tertiary">{{ t('weeklySales.weeklyTotal') }}</p>
                                                <p class="mt-1 text-2xl font-semibold tabular-nums text-text-primary">{{ formatNumber(weeklyTotal(row), 0) }}</p>
                                            </div>
                                            <div class="rounded-lg border border-border-subtle bg-surface-raised px-3 py-3">
                                                <p class="text-[10px] font-semibold uppercase tracking-wider text-text-tertiary">{{ t('weeklySales.inventoryDelta') }}</p>
                                                <p
                                                    :class="[
                                                        'mt-1 text-2xl font-semibold tabular-nums',
                                                        inventoryDelta(row) > 0 ? 'text-status-danger' : inventoryDelta(row) < 0 ? 'text-status-success' : 'text-text-primary',
                                                    ]"
                                                >
                                                    {{ inventoryDelta(row) > 0 ? '-' : inventoryDelta(row) < 0 ? '+' : '' }}{{ formatNumber(Math.abs(inventoryDelta(row)), 0) }}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <div v-else class="flex flex-col items-center gap-2 px-6 py-14 text-center">
                <Boxes :size="24" class="text-text-tertiary" />
                <p class="text-sm font-medium text-text-primary">{{ t('weeklySales.noRows') }}</p>
                <p class="text-sm text-text-tertiary">{{ t('weeklySales.noRowsHint') }}</p>
            </div>
        </Card>

        <div v-if="dirtyCount > 0" class="h-24" aria-hidden="true" />

        <div
            v-if="dirtyCount > 0"
            class="fixed bottom-4 left-4 right-4 z-40 rounded-xl border border-brand/20 bg-surface-raised/95 px-4 py-3 shadow-lg backdrop-blur md:left-[17rem] md:right-8"
        >
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3">
                    <div class="grid h-9 w-9 place-items-center rounded-lg bg-brand-soft text-brand">
                        <PencilLine :size="16" />
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-text-primary">{{ t('weeklySales.modifiedSkus', { count: dirtyCount }) }}</p>
                        <p class="text-xs text-text-tertiary">{{ t('weeklySales.unsavedHint') }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button variant="secondary" size="sm" :disabled="form.processing" @click="discardChanges">
                        <RotateCcw :size="14" />
                        {{ t('weeklySales.discard') }}
                    </Button>
                    <Button v-if="canSave" size="sm" :loading="form.processing" @click="saveWeek">
                        <Save :size="14" />
                        {{ t('weeklySales.saveWeek') }}
                    </Button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
