<?php

namespace App\Http\Requests;

use App\Models\Delivery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** "Reopen for uploading" (step 5.3): complete -> for_uploading needs a reason (decision 17). DeliveryPolicy::reopenDocuments decides. */
class ReopenDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $delivery = $this->route('delivery');

        if (! $delivery instanceof Delivery) {
            return false;
        }

        Gate::authorize('reopenDocuments', $delivery);

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
            'reason.required' => 'Write the reason for reopening this delivery.',
            'reason.min' => 'The reason is too short. Say briefly why the delivery must be reopened.',
            'reason.max' => 'The reason is too long (500 characters at most).',
        ];
    }
}
