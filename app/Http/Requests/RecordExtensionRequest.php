<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordExtensionRequest extends FormRequest
{
    /** Permission only. The PO state rules are checked by the Service under a row lock. */
    public function authorize(): bool
    {
        return $this->user()?->can('extension.manage') ?? false;
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
        $max = (int) config('mms.delivery.extension_max_days');

        return [
            'extra_days' => ['required', 'integer', 'min:1', "max:{$max}"],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $max = (int) config('mms.delivery.extension_max_days');

        return [
            'extra_days.required' => 'Enter how many days to add.',
            'extra_days.integer' => 'Days must be a whole number.',
            'extra_days.min' => 'Add at least 1 day.',
            'extra_days.max' => "An extension can add at most {$max} days.",
            'reason.required' => 'Enter the reason for the extension.',
            'reason.max' => 'The reason can have at most 500 characters.',
        ];
    }
}