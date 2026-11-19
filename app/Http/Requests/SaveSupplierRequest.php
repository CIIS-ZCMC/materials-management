<?php

namespace App\Http\Requests;

use App\Models\Supplier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        $supplier = $this->route('supplier');

        return $supplier instanceof Supplier
            ? $this->user()->can('update', $supplier)
            : $this->user()->can('create', Supplier::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $supplier = $this->route('supplier');
        $ignoreId = $supplier instanceof Supplier ? $supplier->id : null;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('suppliers', 'name')->ignore($ignoreId)],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            // Printed on the PO forms (step 4.5). All optional.
            'tin' => ['nullable', 'string', 'max:30', 'regex:/^[0-9][0-9 -]*$/'],
            'contact_no' => ['nullable', 'string', 'max:50'],
            'supply_chain_role' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'A supplier with this name already exists.',
            'tin.regex' => 'Use digits, spaces and hyphens only.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tin' => 'TIN',
            'contact_no' => 'contact number',
            'supply_chain_role' => 'supply chain role',
        ];
    }
}