<?php

namespace App\Http\Requests;

use App\Enums\IarSeriesType;
use App\Policies\DeliveryPolicy;
use App\Support\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create (POST /deliveries) and edit (PUT /deliveries/{delivery}) share one request.
 * The IAR series and number are accepted only when creating: an edit never touches them.
 * Whether the PO accepts a delivery and the date rules live in DeliveryService.
 *
 * Step 5.2b (decision 58): the inspectors and the end-user are TYPED BY HAND, not chosen from the personnel directory.
 *   inspectors = [{name, position}], end_user = {name, position}
 * Cleaning (trim, collapse repeated spaces, keep the typed case; a row whose name AND position are empty is dropped) happens
 * in prepareForValidation(). The kept rows keep the index they were sent with, so an error such as "inspectors.2.name" belongs
 * to the third row of the form even when a blank row came before it. DeliveryService::normalize() checks the same things
 * again with the same sentences (second line of defence).
 */
class SaveDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(DeliveryPolicy::ENCODE);
    }

    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach (['invoice_no', 'fund_cluster', 'date_received'] as $key) {
            if (is_string($this->input($key))) {
                $clean[$key] = trim($this->input($key));
            }
        }

        if ($this->isMethod('POST') && is_string($this->input('iar_number'))) {
            $clean['iar_number'] = strtoupper(trim($this->input('iar_number')));
        }

        $amount = $this->input('amount');

        if (is_string($amount) || is_numeric($amount)) {
            $text = str_replace([',', ' ', '₱'], '', (string) $amount);
            $clean['amount'] = preg_replace('/^PHP/i', '', $text) ?? $text;
        }

        $inspectors = $this->input('inspectors');

        if (is_array($inspectors)) {
            $rows = [];

            foreach ($inspectors as $index => $row) {
                $person = $this->cleanPerson($row);

                if ($person !== null) {
                    $rows[$index] = $person; // keeps the index as sent
                }
            }

            $clean['inspectors'] = $rows;
        }

        if ($this->has('end_user')) {
            // Empty row (or not an array) becomes null, so "required" says "Enter the end-user.".
            $clean['end_user'] = $this->cleanPerson($this->input('end_user'));
        }

        $this->merge($clean);
    }

    /**
     * @return array{name: string, position: string|null}|null  null = nothing typed (or not an array)
     */
    private function cleanPerson(mixed $row): ?array
    {
        if (! is_array($row)) {
            return null;
        }

        $text = function (mixed $value): string {
            if (! is_scalar($value)) {
                return '';
            }

            return trim(preg_replace('/\s+/u', ' ', (string) $value) ?? (string) $value);
        };

        $name = $text($row['name'] ?? null);
        $position = $text($row['position'] ?? null);

        if ($name === '' && $position === '') {
            return null;
        }

        return ['name' => $name, 'position' => $position === '' ? null : $position];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $min = max(1, (int) config('mms.delivery.min_inspectors', 1));
        $max = max($min, (int) config('mms.delivery.max_inspectors', 3));

        $rules = [
            'invoice_no' => ['required', 'string', 'max:100'],
            'fund_cluster' => ['required', 'string', 'max:100'],
            'amount' => [
                'required',
                'regex:/^\d{1,11}(\.\d{1,2})?$/',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (Money::cents($value) < 1) {
                        $fail('The amount must be more than zero.');
                    }
                },
            ],
            'date_received' => ['required', 'date_format:Y-m-d'],
            'inspectors' => ['required', 'array', "min:{$min}", "max:{$max}"],
            'inspectors.*.name' => [
                'required',
                'string',
                'min:2',
                'max:150',
                // The same inspector name twice (ignoring case) is refused on the SECOND row.
                function (string $attribute, mixed $value, Closure $fail): void {
                    $index = explode('.', $attribute)[1] ?? null;
                    $mine = mb_strtolower(is_string($value) ? $value : '');

                    foreach ((array) $this->input('inspectors', []) as $other => $row) {
                        if ((string) $other === (string) $index) {
                            break; // only rows BEFORE this one count
                        }

                        if (is_array($row) && mb_strtolower((string) ($row['name'] ?? '')) === $mine) {
                            $fail('The same inspector name is entered twice.');

                            return;
                        }
                    }
                },
            ],
            'inspectors.*.position' => ['nullable', 'string', 'max:150'],
            'end_user' => ['required', 'array'],
            // required_with: when end_user is missing altogether the single "Enter the end-user." error is enough.
            'end_user.name' => ['required_with:end_user', 'nullable', 'string', 'min:2', 'max:150'],
            'end_user.position' => ['nullable', 'string', 'max:150'],
        ];

        if ($this->isMethod('POST')) {
            $rules['purchase_order_id'] = ['required', 'integer', 'exists:purchase_orders,id'];
            $rules['iar_series'] = ['required', Rule::enum(IarSeriesType::class)];
            $rules['iar_number'] = ['required', 'string', 'max:20'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $min = max(1, (int) config('mms.delivery.min_inspectors', 1));
        $max = max($min, (int) config('mms.delivery.max_inspectors', 3));
        $atLeast = $min === 1 ? 'Enter at least one inspector.' : "Enter at least {$min} inspectors.";

        return [
            'amount.regex' => 'Enter the amount in pesos, for example 125000.50.',
            'inspectors.required' => $atLeast,
            'inspectors.array' => $atLeast,
            'inspectors.min' => $atLeast,
            'inspectors.max' => "Enter at most {$max} inspectors.",
            'inspectors.*.name.required' => 'Enter the name.',
            'inspectors.*.name.min' => 'The name must be 2 to 150 characters.',
            'inspectors.*.name.max' => 'The name must be 2 to 150 characters.',
            'inspectors.*.position.max' => 'The position can be at most 150 characters.',
            'end_user.required' => 'Enter the end-user.',
            'end_user.array' => 'Enter the end-user.',
            'end_user.name.required_with' => 'Enter the name.',
            'end_user.name.min' => 'The name must be 2 to 150 characters.',
            'end_user.name.max' => 'The name must be 2 to 150 characters.',
            'end_user.position.max' => 'The position can be at most 150 characters.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'invoice_no' => 'invoice number',
            'iar_series' => 'IAR series',
            'iar_number' => 'IAR number',
            'fund_cluster' => 'fund cluster',
            'date_received' => 'date received',
            'end_user' => 'end-user',
            'inspectors.*.name' => 'name',
            'inspectors.*.position' => 'position',
            'end_user.name' => 'name',
            'end_user.position' => 'position',
        ];
    }
}