<?php

namespace App\Http\Requests;

use App\Models\ItemCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveItemCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $category = $this->route('category');

        return $category instanceof ItemCategory
            ? $this->user()->can('update', $category)
            : $this->user()->can('create', ItemCategory::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $category = $this->route('category');
        $ignoreId = $category instanceof ItemCategory ? $category->id : null;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('item_categories', 'name')->ignore($ignoreId)],
            'is_equipment' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'A category with this name already exists.',
        ];
    }
}
