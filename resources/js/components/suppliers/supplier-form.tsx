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