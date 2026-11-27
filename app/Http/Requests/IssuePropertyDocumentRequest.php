<?php

namespace App\Http\Requests;

use App\Enums\PropertyDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step 6.2: issue a PAR (with its Sub-PAR), a Sub-PAR alone (re-issue) or an ICS.
 *   type           par | sub_par | ics
 *   number         the number of that document (suggested or typed)
 *   sub_par_number only for type par: the Sub-PAR is issued together with the PAR
 *   person_id      the Sub-PAR user, from the personnel directory (par and sub_par)
 */
class IssuePropertyDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $type = PropertyDocumentType::tryFrom((string) $this->input('type'));

        // An unknown type is answered by validation below (422), not by a 403.
        return $type === null
            ? $this->user() !== null
            : ($this->user()?->can($type->permission()) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(PropertyDocumentType::class)],
            'number' => ['required', 'string', 'max:30'],
            'sub_par_number' => ['nullable', 'required_if:type,par', 'string', 'max:30'],
            'person_id' => ['nullable', 'required_if:type,par', 'required_if:type,sub_par', 'max:40'],
        ];
    }

    public function messages(): array
    {
        return [
            'number.required' => 'Enter the document number.',
            'sub_par_number.required_if' => 'Confirm the Sub-PAR number.',
            'person_id.required_if' => 'Choose the user the Sub-PAR is issued to.',
        ];
    }
}
