<?php

namespace App\Http\Requests;

use App\Models\Item;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $item = $this->route('item');

        return $item instanceof Item
            ? $this->user()->can('update', $item)
            : $this->user()->can('create', Item::class);
    }

    /**
     * New choices must be active. When editing, the item may keep its current
     * category or unit even if that one was deactivated later.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $item = $this->route('item');
        $current = $item instanceof Item ? $item : null;

        $keepCategory = $current?->category_id ?? 0;
        $keepUnit = $current?->unit_id ?? 0;

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('items', 'name')
                    ->where('unit_id', $this->input('unit_id'))
                    ->ignore($current?->id),
            ],
            'category_id' => [
                'required', 'integer',
                Rule::exists('item_categories', 'id')->where(
                    fn ($q) => $q->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $keepCategory)),
                ),
            ],
            'unit_id' => [
                'required', 'integer',
                Rule::exists('units', 'id')->where(
                    fn ($q) => $q->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $keepUnit)),
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'This item already exists with the same unit.',
            'category_id.required' => 'Choose a category.',
            'category_id.exists' => 'Choose an active category.',
            'unit_id.required' => 'Choose a unit.',
            'unit_id.exists' => 'Choose an active unit.',
        ];
    }
}
