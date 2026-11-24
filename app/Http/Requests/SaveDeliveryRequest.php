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
 * Whether the PO accepts a delivery, the date rules and the people checks live in DeliveryService.
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
            $clean['inspectors'] = array_map(fn ($id) => is_scalar($id) ? trim((string) $id) : '', $inspectors);
        }

        $endUser = $this->input('end_user');

        if (is_scalar($endUser)) {
            $clean['end_user'] = trim((string) $endUser);
        }

        $this->merge($clean);
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
            'inspectors.*' => ['required', 'string', 'max:64', 'distinct'],
            'end_user' => ['required', 'string', 'max:64'],
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
        return [
            'amount.regex' => 'Enter the amount in pesos, for example 125000.50.',
            'inspectors.required' => 'Choose at least one inspector.',
            'inspectors.min' => 'Choose at least :min inspector(s).',
            'inspectors.max' => 'Choose at most :max inspectors.',
            'end_user.required' => 'Choose the end-user.',
            'inspectors.*.distinct' => 'The same inspector is chosen twice.',
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
        ];
    }
}
