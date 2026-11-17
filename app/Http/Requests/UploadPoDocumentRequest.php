<?php

namespace App\Http\Requests;

use App\Models\PurchaseOrder;
use App\Services\PoDocumentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Adding a file to the PO Bundle (steps 4.2 and 4.2b). PurchaseOrderPolicy::uploadDocument
 * decides: po.upload-files, and the PO must be draft, uploaded or escalated (403 with a
 * message otherwise). Only the size and "is a file" are checked here; the real content
 * checks (PDF, JPG, PNG, HEIC, interlaced PNG, qpdf) are in PoDocumentService.
 */
class UploadPoDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $purchaseOrder = $this->route('purchaseOrder');

        if (! $purchaseOrder instanceof PurchaseOrder) {
            return false;
        }

        // Throws the 403 (with the Policy's message) when denied.
        Gate::authorize('uploadDocument', $purchaseOrder);

        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
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
            'file.required' => 'Choose a file to upload.',
            'file.file' => $uploadFailed,
            'file.uploaded' => $uploadFailed,
            'file.max' => 'The file is too large. The limit is '.PoDocumentService::maxMegabytes().' MB per file.',
        ];
    }
}