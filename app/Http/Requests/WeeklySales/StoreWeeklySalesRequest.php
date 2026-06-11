<?php

declare(strict_types=1);

namespace App\Http\Requests\WeeklySales;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreWeeklySalesRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;

        return [
            'week_start' => ['required', 'date_format:Y-m-d'],
            'sales' => ['present', 'array'],
            'sales.*.product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where(fn ($query) => $query
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true)
                    ->where('is_sellable', true)),
            ],
            'sales.*.product_variant_id' => [
                'nullable',
                'integer',
                Rule::exists('product_variants', 'id')->where(fn ($query) => $query
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true)),
            ],
            'sales.*.daily_quantities' => ['required', 'array', 'size:7'],
            'sales.*.daily_quantities.*' => ['required', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $weekStartInput = $this->input('week_start');
            if (! is_string($weekStartInput)) {
                return;
            }

            try {
                $weekStart = CarbonImmutable::createFromFormat('Y-m-d', $weekStartInput)->startOfDay();
            } catch (\Throwable) {
                return;
            }

            if ($weekStart->toDateString() !== $weekStartInput || ! $weekStart->isMonday()) {
                $validator->errors()->add('week_start', 'The week start must be a Monday.');

                return;
            }

            $expectedDates = [];
            for ($day = 0; $day < 7; $day++) {
                $expectedDates[] = $weekStart->addDays($day)->toDateString();
            }
            sort($expectedDates);

            foreach ($this->input('sales', []) as $index => $row) {
                $dates = array_keys($row['daily_quantities'] ?? []);
                sort($dates);
                if ($dates !== $expectedDates) {
                    $validator->errors()->add(
                        "sales.{$index}.daily_quantities",
                        'Daily quantities must contain exactly Monday through Sunday for the selected week.'
                    );
                }
            }

            $this->validateProductVariantRows($validator);
        });
    }

    private function validateProductVariantRows(Validator $validator): void
    {
        $organizationId = $this->user()->organization_id;
        $rows = $this->input('sales', []);
        if (! is_array($rows)) {
            return;
        }

        $productIds = collect($rows)
            ->pluck('product_id')
            ->filter(fn ($productId): bool => filter_var($productId, FILTER_VALIDATE_INT) !== false)
            ->map(fn ($productId): int => (int) $productId)
            ->unique()
            ->values()
            ->all();
        $variantIds = collect($rows)
            ->pluck('product_variant_id')
            ->filter(fn ($variantId): bool => $variantId !== null && filter_var($variantId, FILTER_VALIDATE_INT) !== false)
            ->map(fn ($variantId): int => (int) $variantId)
            ->unique()
            ->values()
            ->all();

        $products = Product::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');
        $variants = ProductVariant::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $variantIds)
            ->get()
            ->keyBy('id');
        $productIdsWithActiveVariants = ProductVariant::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereIn('product_id', $productIds)
            ->pluck('product_id')
            ->map(fn ($productId): int => (int) $productId)
            ->flip();

        $seen = [];
        foreach ($rows as $index => $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            $variantId = array_key_exists('product_variant_id', $row) && $row['product_variant_id'] !== null
                ? (int) $row['product_variant_id']
                : null;
            $product = $products->get($productId);

            if (! $product) {
                continue;
            }

            $usesVariants = $product->has_variants || $productIdsWithActiveVariants->has($product->id);

            if ($usesVariants) {
                $variant = $variantId !== null ? $variants->get($variantId) : null;
                if (! $variant) {
                    $validator->errors()->add(
                        "sales.{$index}.product_variant_id",
                        'A variant is required for this product.'
                    );

                    continue;
                }
                if ($variant->product_id !== $product->id) {
                    $validator->errors()->add(
                        "sales.{$index}.product_variant_id",
                        'The selected variant does not belong to this product.'
                    );

                    continue;
                }
            } elseif ($variantId !== null) {
                $validator->errors()->add(
                    "sales.{$index}.product_variant_id",
                    'This product does not use variant sales entry.'
                );

                continue;
            }

            $entryKey = $variantId !== null ? "v:{$variantId}" : "p:{$productId}";
            if (isset($seen[$entryKey])) {
                $field = $variantId !== null ? 'product_variant_id' : 'product_id';
                $validator->errors()->add(
                    "sales.{$index}.{$field}",
                    'Weekly sales rows must be unique per SKU or variant.'
                );

                continue;
            }

            $seen[$entryKey] = true;
        }
    }
}
