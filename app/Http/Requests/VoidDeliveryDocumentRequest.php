<?php

namespace App\Http\Requests;

use App\Models\Delivery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Voiding a wrong file of a delivery (step 5.3): a reason of 5 to 500 characters. DeliveryPolicy::voidDocument decides (403 with a message). */
class VoidDeliveryDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $delivery = $this->route('delivery');

        if (! $delivery instanceof Delivery) {
            return false;
        }

        Gate::authorize('voidDocument', $delivery);

        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
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
            'reason.required' => 'Write the reason for voiding this file.',
            'reason.min' => 'The reason is too short. Say briefly what is wrong with the file.',
            'reason.max' => 'The reason is too long (500 characters at most).',
        ];
    }
}
