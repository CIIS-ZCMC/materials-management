import { Link, useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

export type EditableItem = {
    id: number;
    name: string;
    category_id: number;
    category: string | null;
    is_equipment: boolean;
    unit_id: number;
    unit: string | null;
    unit_code: string | null;
    is_active: boolean;
};

export type CategoryOption = { id: number; name: string; is_equipment: boolean; is_active: boolean };
export type UnitOption = { id: number; name: string; code: string | null; is_active: boolean };
export type ItemFormOptions = { categories: CategoryOption[]; units: UnitOption[] };

type Props = {
    mode: 'create' | 'edit';
    options: ItemFormOptions;
    item?: EditableItem;
};

export default function ItemForm({ mode, options, item }: Props) {
    // The Select component works with text values, so the ids are kept as text
    // in the form. Laravel accepts "3" as the number 3.
    const form = useForm({
        name: item?.name ?? '',
        category_id: item ? String(item.category_id) : '',
        unit_id: item ? String(item.unit_id) : '',
    });

    const selectedCategory = options.categories.find((c) => String(c.id) === form.data.category_id);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        if (mode === 'create') {
            form.post('/catalog/items');
        } else if (item) {
            form.put(`/catalog/items/${item.id}`);
        }
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <Card>
                <CardHeader>
                    <CardTitle>Item details</CardTitle>
                    <CardDescription>
                        The same name can exist with a different unit (for example a box and a piece), but not twice with
                        the same unit.
                    </CardDescription>
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
                        <Label htmlFor="category_id">Category</Label>
                        <Select value={form.data.category_id} onValueChange={(value) => form.setData('category_id', value)}>
                            <SelectTrigger id="category_id" className="w-full">
                                <SelectValue placeholder="Choose a category" />
                            </SelectTrigger>
                            <SelectContent>
                                {options.categories.map((category) => (
                                    <SelectItem key={category.id} value={String(category.id)}>
                                        {category.name}
                                        {category.is_active ? '' : ' (inactive)'}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {selectedCategory?.is_equipment && (
                            <p className="text-sm text-muted-foreground">This category is marked as equipment.</p>
                        )}
                        <InputError message={form.errors.category_id} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="unit_id">Unit</Label>
                        <Select value={form.data.unit_id} onValueChange={(value) => form.setData('unit_id', value)}>
                            <SelectTrigger id="unit_id" className="w-full">
                                <SelectValue placeholder="Choose a unit" />
                            </SelectTrigger>
                            <SelectContent>
                                {options.units.map((unit) => (
                                    <SelectItem key={unit.id} value={String(unit.id)}>
                                        {unit.code ? `${unit.name} (${unit.code})` : unit.name}
                                        {unit.is_active ? '' : ' (inactive)'}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.unit_id} />
                    </div>
                </CardContent>
            </Card>

            <div className="flex items-center gap-3">
                <Button type="submit" disabled={form.processing}>
                    {mode === 'create' ? 'Create item' : 'Save changes'}
                </Button>
                <Button variant="outline" asChild>
                    <Link href="/catalog/items">Cancel</Link>
                </Button>
            </div>
        </form>
    );
}
