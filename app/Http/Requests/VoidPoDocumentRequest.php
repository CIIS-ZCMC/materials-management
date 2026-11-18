<?php

namespace App\Http\Requests;

use App\Models\PurchaseOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Voiding a wrong document (step 4.3). PurchaseOrderPolicy::voidDocument decides: documents.void and
 * po.upload-files, and the PO must be uploaded or escalated (403 with a message otherwise).
 * The reason is required: it is kept on the document and in the audit log.
 */
class VoidPoDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $purchaseOrder = $this->route('purchaseOrder');

        if (! $purchaseOrder instanceof PurchaseOrder) {
            return false;
        }

        Gate::authorize('voidDocument', $purchaseOrder);

        return true;
    }

    /**
     * @return array<string, array<int, string>>
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
            'reason.required' => 'Write the reason for voiding this document.',
            'reason.min' => 'The reason is too short. Say briefly what is wrong with the document.',
            'reason.max' => 'The reason is too long (500 characters at most).',
        ];
    }
}