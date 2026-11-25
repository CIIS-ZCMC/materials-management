<?php

namespace App\Http\Requests;

use App\Enums\PoDocumentType;
use App\Models\Delivery;
use App\Services\PoDocumentService;
use App\Support\PoDocumentChecklist;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Adding files to a DELIVERY (step 5.3), the twin of UploadPoDocumentRequest. DeliveryPolicy::uploadDocuments
 * decides: delivery.upload-docs, and the delivery must be "For uploading" with an open PO (403 with a message
 * otherwise). The request carries NO delivery_id: the delivery is the one in the URL, so a file can never be
 * pointed at another delivery or at the PO Bundle.
 *   groups[i][type]      a type of stage "delivery" (PoDocumentChecklist::deliveryTypes), once per request
 *   groups[i][files][]   one or more files
 * Here only the shape, the type, the size of each file and the totals are checked; the real content
 * checks (PDF, JPG, PNG, HEIC, interlaced PNG, qpdf) are in PoDocumentService::uploadMany().
 * Errors come back under groups.i.type, groups.i.files, groups.i.files.j and "groups" (the totals).
 */
class UploadDeliveryDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $delivery = $this->route('delivery');

        if (! $delivery instanceof Delivery) {
            return false;
        }

        // Throws the 403 (with the Policy's message) when denied.
        Gate::authorize('uploadDocuments', $delivery);

        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'groups' => ['required', 'array', 'min:1'],
            'groups.*.type' => ['required', 'string', 'distinct', $this->typeRule()],
            'groups.*.files' => ['required', 'array', 'min:1'],
            'groups.*.files.*' => ['required', 'file', 'max:'.(int) config('mms.uploads.max_size_kb')],
        ];
    }

    /** Total number of files and total size of the whole request. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $files = $this->uploadedFiles();
            $maxFiles = (int) config('mms.uploads.max_files_per_request');
            $maxKb = (int) config('mms.uploads.max_request_kb');

            if ($maxFiles > 0 && count($files) > $maxFiles) {
                $validator->errors()->add('groups', "Too many files at once. Upload at most {$maxFiles} files in one go; you chose ".count($files).'.');

                return;
            }

            $totalKb = array_sum(array_map(fn (UploadedFile $file) => (int) ceil(((int) $file->getSize()) / 1024), $files));

            if ($maxKb > 0 && $totalKb > $maxKb) {
                $validator->errors()->add('groups', 'The files together are too big ('.number_format($totalKb / 1024, 1).' MB). The limit is '.number_format($maxKb / 1024, 0).' MB for one upload; upload some of the files separately.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $uploadFailed = 'The file could not be uploaded. It may be bigger than the server allows; ask IT to check the PHP upload limits.';

        return [
            'groups.required' => 'Choose a document type and at least one file.',
            'groups.array' => 'Choose a document type and at least one file.',
            'groups.min' => 'Choose a document type and at least one file.',
            'groups.*.type.required' => 'Choose the document type.',
            'groups.*.type.distinct' => 'Each document type can be chosen once in the same upload. Put all the files of that type in one row.',
            'groups.*.files.required' => 'Choose at least one file for this type.',
            'groups.*.files.array' => 'Choose at least one file for this type.',
            'groups.*.files.min' => 'Choose at least one file for this type.',
            'groups.*.files.*.required' => 'Choose at least one file for this type.',
            'groups.*.files.*.file' => $uploadFailed,
            'groups.*.files.*.uploaded' => $uploadFailed,
            'groups.*.files.*.max' => 'The file is too large. The limit is '.PoDocumentService::maxMegabytes().' MB per file.',
        ];
    }

    /** A known type that belongs to this stage ("delivery"); PO types such as the PO Bundle are refused. */
    private function typeRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $type = is_string($value) ? PoDocumentType::tryFrom($value) : null;

            if ($type === null) {
                $fail('That document type is not known. Choose one from the list.');

                return;
            }

            if (! PoDocumentChecklist::isDeliveryType($type->value)) {
                $fail("{$type->label()} documents cannot be added here. Choose a type from the list.");
            }
        };
    }

    /** @return list<UploadedFile> every uploaded file of every group */
    private function uploadedFiles(): array
    {
        $found = [];
        $files = $this->allFiles();

        array_walk_recursive($files, function (mixed $item) use (&$found): void {
            if ($item instanceof UploadedFile) {
                $found[] = $item;
            }
        });

        return $found;
    }
}