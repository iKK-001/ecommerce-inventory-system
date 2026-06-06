<?php

declare(strict_types=1);

namespace App\Http\Requests\WeeklySales;

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
                'distinct',
                Rule::exists('products', 'id')->where(fn ($query) => $query
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true)
                    ->where('is_sellable', true)
                    ->where('has_variants', false)),
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
        });
    }
}
