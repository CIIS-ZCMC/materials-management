import { Link, useForm } from '@inertiajs/react';
import { Eye, EyeOff } from 'lucide-react';
import { useMemo, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export type AccessOptions = {
    roles: { name: string; permissions: string[] }[];
    groups: {
        key: string;
        label: string;
        permissions: { name: string; label: string }[];
    }[];
    presets: { key: string; label: string; permissions: string[] }[];
    base_role: string;
    admin_role: string;
};

export type EditableUser = {
    id: number;
    employee_no: string | null;
    name: string;
    position: string | null;
    email: string;
    is_active: boolean;
    role: string;
    direct_permissions: string[];
};

type Props = {
    mode: 'create' | 'edit';
    user?: EditableUser;
    access: AccessOptions;
    canManageAccess: boolean;
};

export default function UserForm({ mode, user, access, canManageAccess }: Props) {
    const [showPassword, setShowPassword] = useState(false);

    const form = useForm({
        employee_no: user?.employee_no ?? '',
        name: user?.name ?? '',
        position: user?.position ?? '',
        email: user?.email ?? '',
        password: '',
        role: user?.role ?? access.base_role,
        permissions: user?.direct_permissions ?? ([] as string[]),
    });

    const rolePermissions = useMemo(
        () =>
            new Set(
                access.roles.find((r) => r.name === form.data.role)?.permissions ?? [],
            ),
        [access.roles, form.data.role],
    );
    const direct = new Set(form.data.permissions);

    const changeRole = (role: string) => {
        const next = new Set(access.roles.find((r) => r.name === role)?.permissions ?? []);

        // Anything the new role already grants is no longer a direct permission.
        form.setData((d) => ({
            ...d,
            role,
            permissions: d.permissions.filter((p) => !next.has(p)),
        }));
    };

    const togglePermission = (name: string, on: boolean) => {
        form.setData(
            'permissions',
            on
                ? [...new Set([...form.data.permissions, name])]
                : form.data.permissions.filter((p) => p !== name),
        );
    };

    const requiredFor = (preset: AccessOptions['presets'][number]) =>
        preset.permissions.filter((p) => !rolePermissions.has(p));

    const applyPreset = (preset: AccessOptions['presets'][number]) => {
        form.setData('permissions', requiredFor(preset));
    };

    const isPresetActive = (preset: AccessOptions['presets'][number]) => {
        const required = requiredFor(preset);

        return (
            required.length > 0 &&
            required.length === direct.size &&
            required.every((p) => direct.has(p))
        );
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        form.transform((d) => ({
            employee_no: d.employee_no,
            name: d.name,
            position: d.position,
            email: d.email,
            ...(mode === 'create' ? { password: d.password } : {}),
            ...(canManageAccess ? { role: d.role, permissions: d.permissions } : {}),
        }));

        if (mode === 'create') {
            form.post('/admin/users');
        } else if (user) {
            form.put(`/admin/users/${user.id}`);
        }
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <Card>
                <CardHeader>
                    <CardTitle>Account details</CardTitle>
                    <CardDescription>
                        The employee number is the login and is saved exactly as typed.
                    </CardDescription>
                </CardHeader>
                <CardContent className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="employee_no">Employee No.</Label>
                        <Input
                            id="employee_no"
                            value={form.data.employee_no}
                            onChange={(e) => form.setData('employee_no', e.target.value)}
                            autoComplete="off"
                            required
                        />
                        <InputError message={form.errors.employee_no} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="name">Name</Label>
                        <Input
                            id="name"
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            required
                        />
                        <InputError message={form.errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="position">Position</Label>
                        <Input
                            id="position"
                            value={form.data.position}
                            onChange={(e) => form.setData('position', e.target.value)}
                        />
                        <InputError message={form.errors.position} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="email">Email</Label>
                        <Input
                            id="email"
                            type="email"
                            value={form.data.email}
                            onChange={(e) => form.setData('email', e.target.value)}
                            required
                        />
                        <InputError message={form.errors.email} />
                    </div>

                    {mode === 'create' && (
                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="password">Initial password</Label>
                            <div className="relative">
                                <Input
                                    id="password"
                                    type={showPassword ? 'text' : 'password'}
                                    value={form.data.password}
                                    onChange={(e) => form.setData('password', e.target.value)}
                                    autoComplete="new-password"
                                    className="pr-10"
                                    required
                                />
                                <button
                                    type="button"
                                    onClick={() => setShowPassword((v) => !v)}
                                    className="absolute inset-y-0 right-0 flex items-center px-3 text-muted-foreground"
                                    aria-label={showPassword ? 'Hide password' : 'Show password'}
                                >
                                    {showPassword ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                                </button>
                            </div>
                            <InputError message={form.errors.password} />
                        </div>
                    )}
                </CardContent>
            </Card>

            {canManageAccess ? (
                <Card>
                    <CardHeader>
                        <CardTitle>Access</CardTitle>
                        <CardDescription>
                            Role permissions are ticked and locked. A job preset ticks its
                            permissions as direct ones; tick or untick direct permissions per person.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <div className="grid max-w-xs gap-2">
                            <Label>Role</Label>
                            <Select value={form.data.role} onValueChange={changeRole}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Select a role" />
                                </SelectTrigger>
                                <SelectContent>
                                    {access.roles.map((role) => (
                                        <SelectItem key={role.name} value={role.name}>
                                            {role.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.role} />
                        </div>

                        {access.presets.length > 0 && (
                            <div className="grid gap-2">
                                <Label>Job presets</Label>
                                <div className="flex flex-wrap gap-2">
                                    {access.presets.map((preset) => (
                                        <Button
                                            key={preset.key}
                                            type="button"
                                            size="sm"
                                            variant={isPresetActive(preset) ? 'default' : 'outline'}
                                            onClick={() => applyPreset(preset)}
                                        >
                                            {preset.label}
                                        </Button>
                                    ))}
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => form.setData('permissions', [])}
                                    >
                                        Clear direct permissions
                                    </Button>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    A preset replaces the current direct permissions. Adjust them
                                    below afterwards.
                                </p>
                            </div>
                        )}

                        <div className="grid gap-4 md:grid-cols-2">
                            {access.groups.map((group) => (
                                <fieldset key={group.key} className="rounded-md border p-3">
                                    <legend className="px-1 text-sm font-medium">{group.label}</legend>
                                    <div className="space-y-2">
                                        {group.permissions.map((permission) => {
                                            const locked = rolePermissions.has(permission.name);
                                            const id = `perm-${permission.name}`;

                                            return (
                                                <div key={permission.name} className="flex items-center gap-2">
                                                    <Checkbox
                                                        id={id}
                                                        checked={locked || direct.has(permission.name)}
                                                        disabled={locked}
                                                        onCheckedChange={(v) =>
                                                            togglePermission(permission.name, v === true)
                                                        }
                                                    />
                                                    <Label
                                                        htmlFor={id}
                                                        className={locked ? 'text-muted-foreground' : undefined}
                                                    >
                                                        {permission.label}
                                                        {locked && (
                                                            <span className="ml-1 text-xs">(from role)</span>
                                                        )}
                                                    </Label>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </fieldset>
                            ))}
                        </div>
                        <InputError message={form.errors.permissions} />
                    </CardContent>
                </Card>
            ) : (
                <p className="text-sm text-muted-foreground">
                    You do not have the roles.manage permission, so role and permissions cannot be
                    changed here.
                    {mode === 'create' && ` New users are created as ${access.base_role}.`}
                </p>
            )}

            <div className="flex items-center gap-3">
                <Button type="submit" disabled={form.processing}>
                    {mode === 'create' ? 'Create user' : 'Save changes'}
                </Button>
                <Button variant="outline" asChild>
                    <Link href="/admin/users">Cancel</Link>
                </Button>
            </div>
        </form>
    );
}