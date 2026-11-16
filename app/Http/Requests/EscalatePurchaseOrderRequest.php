<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EscalatePurchaseOrderRequest extends FormRequest
{
    /** Permission only. The PO state rule is checked by the Service under a row lock. */
    public function authorize(): bool
    {
        return $this->user()?->can('po.escalate') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Enter the remarks for the Admin: what is wrong with this PO.',
            'reason.max' => 'The remarks can have at most 500 characters.',
        ];
    }
}