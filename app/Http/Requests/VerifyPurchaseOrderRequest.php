<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyPurchaseOrderRequest extends FormRequest
{
    /** Permission only (PO Reviewer job). The PO state rule is checked by the Service under a row lock. */
    public function authorize(): bool
    {
        return $this->user()?->can('po.review') ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }
}