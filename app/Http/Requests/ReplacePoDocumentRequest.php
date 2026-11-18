<?php

namespace App\Http\Requests;

use App\Models\PurchaseOrder;
use App\Services\PoDocumentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Replacing a wrong document (step 4.3): a reason and the new file. PurchaseOrderPolicy::replaceDocument
 * decides (documents.void and po.upload-files; uploaded or escalated PO). Only size and "is a file" are
 * checked here; the real content checks are in PoDocumentService, as for an upload.
 */
class ReplacePoDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $purchaseOrder = $this->route('purchaseOrder');

        if (! $purchaseOrder instanceof PurchaseOrder) {
            return false;
        }

        Gate::authorize('replaceDocument', $purchaseOrder);

        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'file' => ['required', 'file', 'max:'.(int) config('mms.uploads.max_size_kb')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $uploadFailed = 'The file could not be uploaded. It may be bigger than the server allows; ask IT to check the PHP upload limits.';

        return [
            'reason.required' => 'Write the reason for replacing this document.',
            'reason.min' => 'The reason is too short. Say briefly what is wrong with the document.',
            'reason.max' => 'The reason is too long (500 characters at most).',
            'file.required' => 'Choose the new file.',
            'file.file' => $uploadFailed,
            'file.uploaded' => $uploadFailed,
            'file.max' => 'The file is too large. The limit is '.PoDocumentService::maxMegabytes().' MB per file.',
        ];
    }
}