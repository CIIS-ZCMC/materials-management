<?php

namespace App\Http\Requests;

use App\Models\Unit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        $unit = $this->route('unit');

        return $unit instanceof Unit
            ? $this->user()->can('update', $unit)
            : $this->user()->can('create', Unit::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $unit = $this->route('unit');
        $ignoreId = $unit instanceof Unit ? $unit->id : null;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('units', 'name')->ignore($ignoreId)],
            'code' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'A unit with this name already exists.',
        ];
    }
}
