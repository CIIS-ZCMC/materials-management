<?php

namespace App\Http\Requests;

use App\Contracts\PersonnelDirectory;
use App\Models\Personnel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchPersonnelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Personnel::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', Rule::in(PersonnelDirectory::ROLES)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.PersonnelDirectory::MAX_LIMIT],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role.in' => 'Role must be one of: '.implode(', ', PersonnelDirectory::ROLES).'.',
        ];
    }
}