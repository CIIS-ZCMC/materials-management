<?php

namespace App\Http\Requests;

use App\Models\PropertyDecision;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Step 6.2: record the equipment decision of one delivered line. Permission only here (equipment.decide); the state of
 * the line is checked by PropertyPolicy / PropertyDocumentRule in the controller and again by the Service under a lock.
 */
class DecidePropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('equipment.decide') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'is_equipment' => ['required', 'boolean'],
            'override_reason' => ['nullable', 'string', 'max:500'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'is_equipment.required' => 'Choose whether this line is equipment.',
            'is_equipment.boolean' => 'Choose whether this line is equipment.',
        ];
    }
}
