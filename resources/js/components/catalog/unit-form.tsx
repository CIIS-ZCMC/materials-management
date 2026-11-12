import { Link, useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type EditableUnit = {
    id: number;
    name: string;
    code: string | null;
    is_active: boolean;
};

type Props = {
    mode: 'create' | 'edit';
    unit?: EditableUnit;
};

export default function UnitForm({ mode, unit }: Props) {
    const form = useForm({
        name: unit?.name ?? '',
        code: unit?.code ?? '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        if (mode === 'create') {
            form.post('/catalog/units');
        } else if (unit) {
            form.put(`/catalog/units/${unit.id}`);
        }
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <Card>
                <CardHeader>
                    <CardTitle>Unit details</CardTitle>
                    <CardDescription>Only the name is required. The name must be unique.</CardDescription>
                </CardHeader>
                <CardContent className="grid gap-4 sm:grid-cols-2">
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
                        <Label htmlFor="code">Short code</Label>
                        <Input
                            id="code"
                            value={form.data.code}
                            maxLength={20}
                            placeholder="pc, box, btl"
                            onChange={(e) => form.setData('code', e.target.value)}
                        />
                        <InputError message={form.errors.code} />
                    </div>
                </CardContent>
            </Card>

            <div className="flex items-center gap-3">
                <Button type="submit" disabled={form.processing}>
                    {mode === 'create' ? 'Create unit' : 'Save changes'}
                </Button>
                <Button variant="outline" asChild>
                    <Link href="/catalog/units">Cancel</Link>
                </Button>
            </div>
        </form>
    );
}
