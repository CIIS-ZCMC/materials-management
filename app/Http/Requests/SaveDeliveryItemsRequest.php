<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Step 6.1: several PO lines in one save. Who may: delivery.update-items (the PO Reviewer job).
 * The shape is checked here; the exact decimals, the PO of each line, the dates and the over-delivery rule are
 * checked again by DeliveryItemService under the row locks.
 *
 * lines[]: { po_item_id, quantity, delivered_on (YYYY-MM-DD), over_delivery (optional), over_delivery_reason (optional) }
 */
class SaveDeliveryItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('delivery.update-items');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.po_item_id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity' => ['required', 'regex:/^\d{1,9}(\.\d{1,3})?$/'],
            'lines.*.delivered_on' => ['required', 'date_format:Y-m-d'],
            'lines.*.over_delivery' => ['sometimes', 'boolean'],
            'lines.*.over_delivery_reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'Choose at least one PO line to update.',
            'lines.min' => 'Choose at least one PO line to update.',
            'lines.*.quantity.required' => 'Enter the quantity delivered.',
            'lines.*.quantity.regex' => 'Enter the quantity delivered with up to 3 decimals, for example 2.5.',
            'lines.*.delivered_on.required' => 'Enter the delivery date.',
            'lines.*.delivered_on.date_format' => 'Enter the delivery date as YYYY-MM-DD.',
            'lines.*.over_delivery_reason.max' => 'The over-delivery reason can be at most 500 characters.',
        ];
    }
}