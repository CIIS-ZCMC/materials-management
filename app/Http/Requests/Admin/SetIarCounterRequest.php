<?php

namespace App\Http\Requests\Admin;

use App\Enums\IarSeriesType;
use App\Models\IarNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetIarCounterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('setCounter', IarNumber::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(IarSeriesType::class)],
            'year' => ['required', 'integer', 'between:2000,2099'],
            'next_number' => ['required', 'integer', 'min:1', 'max:999999'],
        ];
    }
}