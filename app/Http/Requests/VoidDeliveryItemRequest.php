<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Step 6.1: void ONE delivered-item row with a reason (5 to 500 characters).
 * Who may: delivery.update-items. DeliveryPolicy::voidItem and DeliveryItemRule check the delivery status.
 */
class VoidDeliveryItemRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Give a reason of 5 to 500 characters.',
            'reason.min' => 'Give a reason of 5 to 500 characters.',
            'reason.max' => 'Give a reason of 5 to 500 characters.',
        ];
    }
}