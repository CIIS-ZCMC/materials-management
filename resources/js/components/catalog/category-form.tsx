import { Link, useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type EditableCategory = {
    id: number;
    name: string;
    is_equipment: boolean;
    is_active: boolean;
};

type Props = {
    mode: 'create' | 'edit';
    category?: EditableCategory;
};

export default function CategoryForm({ mode, category }: Props) {
    const form = useForm({
        name: category?.name ?? '',
        is_equipment: category?.is_equipment ?? false,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        if (mode === 'create') {
            form.post('/catalog/categories');
        } else if (category) {
            form.put(`/catalog/categories/${category.id}`);
        }
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <Card>
                <CardHeader>
                    <CardTitle>Category details</CardTitle>
                    <CardDescription>The name must be unique.</CardDescription>
                </CardHeader>
                <CardContent className="grid gap-4">
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
                        <div className="flex items-start gap-3">
                            <Checkbox
                                id="is_equipment"
                                checked={form.data.is_equipment}
                                onCheckedChange={(checked) => form.setData('is_equipment', checked === true)}
                            />
                            <div className="grid gap-1">
                                <Label htmlFor="is_equipment">Equipment category</Label>
                                <p className="text-sm text-muted-foreground">
                                    Items in this category count as equipment when the Inventory Team answers
                                    “Equipment?” for a delivery.
                                </p>
                            </div>
                        </div>
                        <InputError message={form.errors.is_equipment} />
                    </div>
                </CardContent>
            </Card>

            <div className="flex items-center gap-3">
                <Button type="submit" disabled={form.processing}>
                    {mode === 'create' ? 'Create category' : 'Save changes'}
                </Button>
                <Button variant="outline" asChild>
                    <Link href="/catalog/categories">Cancel</Link>
                </Button>
            </div>
        </form>
    );
}
