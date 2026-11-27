<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Step 6.2: the reason for voiding a decision or a document. Who may void which is decided by PropertyPolicy in the
 * controller (equipment.decide, par.generate or ics.generate), so this request only checks the reason.
 */
class VoidPropertyRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Give a reason of 5 to 500 characters.',
            'reason.min' => 'Give a reason of 5 to 500 characters.',
            'reason.max' => 'Give a reason of 5 to 500 characters.',
        ];
    }
}
