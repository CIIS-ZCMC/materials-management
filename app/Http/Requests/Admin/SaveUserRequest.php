<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Support\MmsAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class SaveUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $target = $this->route('user');

        $allowed = $target instanceof User
            ? $actor->can('update', $target)
            : $actor->can('create', User::class);

        // Role and permission fields need roles.manage.
        if ($allowed && ($this->has('role') || $this->has('permissions'))) {
            return $actor->can('manageAccess', User::class);
        }

        return $allowed;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $target = $this->route('user');
        $ignoreId = $target instanceof User ? $target->id : null;

        return [
            'employee_no' => ['required', 'string', 'max:50', Rule::unique('users', 'employee_no')->ignore($ignoreId)],
            'name' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($ignoreId)],
            'password' => $ignoreId === null
                ? ['required', 'string', Password::defaults()]
                : ['prohibited'],
            'role' => ['sometimes', 'string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(MmsAccess::permissionNames())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_no.unique' => 'This employee number is already used by another user.',
            'email.unique' => 'This email address is already used by another user.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['employee_no' => 'employee number'];
    }
}