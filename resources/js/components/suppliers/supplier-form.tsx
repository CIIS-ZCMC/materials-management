import { Link, useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type EditableSupplier = {
    id: number;
    name: string;
    email: string | null;
    contact_person: string | null;
    address: string | null;
    tin: string | null;
    contact_no: string | null;
    supply_chain_role: string | null;
    is_active: boolean;
};

type Props = {
    mode: 'create' | 'edit';
    supplier?: EditableSupplier;
};

export default function SupplierForm({ mode, supplier }: Props) {
    const form = useForm({
        name: supplier?.name ?? '',
        email: supplier?.email ?? '',
        contact_person: supplier?.contact_person ?? '',
        address: supplier?.address ?? '',
        tin: supplier?.tin ?? '',
        contact_no: supplier?.contact_no ?? '',
        supply_chain_role: supplier?.supply_chain_role ?? '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        if (mode === 'create') {
            form.post('/suppliers');
        } else if (supplier) {
            form.put(`/suppliers/${supplier.id}`);
        }
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <Card>
                <CardHeader>
                    <CardTitle>Supplier details</CardTitle>
                    <CardDescription>Only the name is required. The name must be unique.</CardDescription>
                </CardHeader>
                <CardContent className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2 sm:col-span-2">
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
                        <Label htmlFor="contact_person">Contact person</Label>
                        <Input
                            id="contact_person"
                            value={form.data.contact_person}
                            onChange={(e) => form.setData('contact_person', e.target.value)}
                        />
                        <InputError message={form.errors.contact_person} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="email">Email</Label>
                        <Input
                            id="email"
                            type="email"
                            value={form.data.email}
                            onChange={(e) => form.setData('email', e.target.value)}
                        />
                        <InputError message={form.errors.email} />
                    </div>

                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="address">Address</Label>
                        <Input
                            id="address"
                            value={form.data.address}
                            onChange={(e) => form.setData('address', e.target.value)}
                        />
                        <InputError message={form.errors.address} />
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Printed on the purchase order</CardTitle>
                    <CardDescription>
                        These three appear in the supplier box of the printed PO. All are optional; leave them empty if you do not have them yet.
                    </CardDescription>
                </CardHeader>
                <CardContent className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="tin">TIN</Label>
                        <Input
                            id="tin"
                            value={form.data.tin}
                            onChange={(e) => form.setData('tin', e.target.value)}
                            maxLength={30}
                            placeholder="000-000-000-000"
                        />
                        <InputError message={form.errors.tin} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="contact_no">Contact number</Label>
                        <Input
                            id="contact_no"
                            value={form.data.contact_no}
                            onChange={(e) => form.setData('contact_no', e.target.value)}
                            maxLength={50}
                        />
                        <InputError message={form.errors.contact_no} />
                    </div>

                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="supply_chain_role">Supply chain role</Label>
                        <Input
                            id="supply_chain_role"
                            value={form.data.supply_chain_role}
                            onChange={(e) => form.setData('supply_chain_role', e.target.value)}
                            maxLength={100}
                            placeholder="For example: Distributor"
                        />
                        <InputError message={form.errors.supply_chain_role} />
                    </div>
                </CardContent>
            </Card>

            <div className="flex items-center gap-3">
                <Button type="submit" disabled={form.processing}>
                    {mode === 'create' ? 'Create supplier' : 'Save changes'}
                </Button>
                <Button variant="outline" asChild>
                    <Link href="/suppliers">Cancel</Link>
                </Button>
            </div>
        </form>
    );
}