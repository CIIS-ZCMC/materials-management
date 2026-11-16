<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendBackPurchaseOrderRequest extends FormRequest
{
    /** Permission only (po.edit, as decided: no new permission). The PO rules are checked by the Service under a row lock. */
    public function authorize(): bool
    {
        return $this->user()?->can('po.edit') ?? false;
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
            'reason.required' => 'Enter a note for the Reviewer: what did you fix?',
            'reason.max' => 'The note can have at most 500 characters.',
        ];
    }
}