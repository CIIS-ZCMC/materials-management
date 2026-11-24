<?php

namespace App\Http\Requests;

use App\Policies\DeliveryPolicy;
use Illuminate\Foundation\Http\FormRequest;

/** POST /deliveries/{delivery}/void: a reason of 5 to 500 characters (the Service checks it again). */
class VoidDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(DeliveryPolicy::ENCODE);
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
}
