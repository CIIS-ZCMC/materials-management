import { Link, useForm } from '@inertiajs/react';
import { Check, Plus, Trash2, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import ConfirmDialog from '@/components/admin/confirm-dialog';
import InputError from '@/components/input-error';
import ItemSearchBox from '@/components/purchase-orders/item-search-box';
import type { CatalogHit } from '@/components/purchase-orders/item-search-box';
import { formatPhp } from '@/components/purchase-orders/po-format';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type SupplierOption = { id: number; name: string; is_active: boolean };
export type DueDateRules = { term: 'calendar' | 'working'; extension: 'calendar' | 'working' };

type ItemStatus = 'new' | 'ok' | 'inactive' | 'missing';

export type EditableLine = {
    id: number;
    item_id: number | null;
    item_name: string | null;
    item_status: 'ok' | 'inactive' | 'missing';
    description: string;
    category: string;
    unit: string;
    unit_price: string;
    quantity_ordered: number;
    stock_no: string | null;
    brand: string | null;
    manufacturer: string | null;
    country_of_origin: string | null;
    specifications: string | null;
};

export type EditablePo = {
    id: number;
    po_number: string;
    supplier_id: number;
    po_date: string | null;
    emailed_date: string | null;
    delivery_term_days: number | null;
    pr_no: string | null;
    caf_no: string | null;
    mode_of_procurement: string | null;
    remarks: string | null;
    status_label: string;
    items: EditableLine[];
    schedule_dates: string[];
    extension_days: number[];
};

type Props = {
    mode: 'create' | 'edit';
    supplierOptions: SupplierOption[];
    dueDateRules: DueDateRules;
    procurementModes: string[];
    purchaseOrder?: EditablePo;
};

// One item row of the form. `key`, `item_name`, `item_status`, `category` and `unit` are only
// for the screen; the other fields are sent to the server.
type FormLine = {
    key: string;
    id: number | null;
    item_id: number | null;
    item_name: string | null;
    item_status: ItemStatus;
    description: string;
    category: string;
    unit: string;
    unit_price: string;
    quantity_ordered: string;
    stock_no: string;
    brand: string;
    manufacturer: string;
    country_of_origin: string;
    specifications: string;
};

type PoFormData = {
    supplier_id: string;
    po_number: string;
    po_date: string;
    emailed_date: string;
    delivery_term_days: string;
    pr_no: string;
    caf_no: string;
    mode_of_procurement: string;
    remarks: string;
    schedule_dates: string[];
    items: FormLine[];
};

const control =
    'h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';

// Rows need a stable key for React. A counter works on any page (crypto.randomUUID needs https).
let lineCounter = 0;
const nextKey = () => `line-${++lineCounter}`;

const blankLine = (): FormLine => ({
    key: nextKey(),
    id: null,
    item_id: null,
    item_name: null,
    item_status: 'new',
    description: '',
    category: '',
    unit: '',
    unit_price: '',
    quantity_ordered: '1',
    stock_no: '',
    brand: '',
    manufacturer: '',
    country_of_origin: '',
    specifications: '',
});

function buildInitial(po?: EditablePo): PoFormData {
    if (!po) {
        return {
            supplier_id: '',
            po_number: '',
            po_date: '',
            emailed_date: '',
            delivery_term_days: '',
            pr_no: '',
            caf_no: '',
            mode_of_procurement: '',
            remarks: '',
            schedule_dates: [],
            items: [blankLine()],
        };
    }

    return {
        supplier_id: String(po.supplier_id),
        po_number: po.po_number,
        po_date: po.po_date ?? '',
        emailed_date: po.emailed_date ?? '',
        delivery_term_days: po.delivery_term_days === null ? '' : String(po.delivery_term_days),
        pr_no: po.pr_no ?? '',
        caf_no: po.caf_no ?? '',
        mode_of_procurement: po.mode_of_procurement ?? '',
        remarks: po.remarks ?? '',
        schedule_dates: po.schedule_dates,
        items: po.items.map((i) => ({
            key: nextKey(),
            id: i.id,
            item_id: i.item_id,
            item_name: i.item_name,
            item_status: i.item_status,
            description: i.description,
            category: i.category,
            unit: i.unit,
            unit_price: i.unit_price,
            quantity_ordered: String(i.quantity_ordered),
            stock_no: i.stock_no ?? '',
            brand: i.brand ?? '',
            manufacturer: i.manufacturer ?? '',
            country_of_origin: i.country_of_origin ?? '',
            specifications: i.specifications ?? '',
        })),
    };
}

// ---- money: whole centavos, so totals never drift ----
const toCents = (value: string) => {
    const n = Number(value);

    return Number.isFinite(n) && n > 0 ? Math.round(n * 100) : 0;
};
const lineCents = (line: FormLine) => toCents(line.unit_price) * (parseInt(line.quantity_ordered, 10) || 0);
const centsToDecimal = (cents: number) => `${Math.trunc(cents / 100)}.${String(cents % 100).padStart(2, '0')}`;

// ---- due date preview: same counting as App\Support\DueDate (the server decides on save) ----
const parseYmd = (value: string): Date | null => {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);

    return m ? new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])) : null;
};
const toYmd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

function addDays(from: Date, days: number, mode: 'calendar' | 'working'): Date {
    const d = new Date(from.getFullYear(), from.getMonth(), from.getDate());

    if (mode === 'calendar') {
        d.setDate(d.getDate() + days);

        return d;
    }

    // Working days skip Saturday and Sunday.
    let left = days;

    while (left > 0) {
        d.setDate(d.getDate() + 1);

        if (d.getDay() !== 0 && d.getDay() !== 6) {
            left--;
        }
    }

    return d;
}

function previewDueDate(emailed: string, term: string, rules: DueDateRules, extensionDays: number[]): string | null {
    const start = parseYmd(emailed);
    const days = parseInt(term, 10);

    if (!start || !Number.isFinite(days) || days <= 0) {
        return null;
    }

    let due = addDays(start, days, rules.term);

    for (const extra of extensionDays) {
        due = addDays(due, extra, rules.extension);
    }

    return toYmd(due);
}

const itemNote: Record<ItemStatus, string> = {
    new: '',
    ok: '',
    inactive: 'This catalog item is inactive now. The line can still be saved.',
    missing: 'Not in the catalog. The line can still be saved; choose a catalog item above to link it.',
};

/**
 * Shows which catalog item a line is linked to, right under the search box:
 *  - green chip with a check icon: linked (name, category and unit; an "Inactive" badge if needed)
 *  - amber chip: the line has no catalog item (for example imported from BizBox)
 *  - dashed grey box: nothing chosen yet
 */
function LinkedItem({ line }: { line: FormLine }) {
    if (line.item_status === 'new') {
        return <div className="rounded-md border border-dashed px-3 py-2 text-sm text-muted-foreground">No item chosen</div>;
    }

    if (line.item_status === 'missing') {
        return (
            <div className="flex flex-wrap items-center gap-2 rounded-md border border-amber-500/50 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
                <TriangleAlert className="size-4 shrink-0" aria-hidden />
                <span className="font-medium">Not in catalog</span>
            </div>
        );
    }

    const inactive = line.item_status === 'inactive';

    return (
        <div
            className={`flex flex-wrap items-center gap-x-2 gap-y-1 rounded-md border px-3 py-2 text-sm ${
                inactive
                    ? 'border-amber-500/50 bg-amber-50 text-amber-900 dark:bg-amber-950/30 dark:text-amber-100'
                    : 'border-green-600/40 bg-green-50 text-green-900 dark:bg-green-950/30 dark:text-green-100'
            }`}
        >
            {inactive ? <TriangleAlert className="size-4 shrink-0" aria-hidden /> : <Check className="size-4 shrink-0" aria-hidden />}
            <span className="min-w-0 font-medium break-words">{line.item_name}</span>
            {line.category && (
                <span className="text-xs break-words opacity-80">
                    {line.category} · {line.unit}
                </span>
            )}
            {inactive && <span className="rounded-full bg-amber-200 px-2 py-0.5 text-xs font-medium text-amber-900">Inactive</span>}
        </div>
    );
}

// BizBox is not connected yet (step 3.4). Phase 13 will replace this constant with a value
// from the server and give the button an onClick. While false, the button and its box stay disabled.
const BIZBOX_FETCH_AVAILABLE: boolean = false;

export default function PoForm({ mode, supplierOptions, dueDateRules, procurementModes, purchaseOrder }: Props) {
    // useForm keeps every field (including the rows) in one object and sends it on submit.
    const [initial] = useState(() => buildInitial(purchaseOrder));
    const form = useForm<PoFormData>(initial);
    const errors = form.errors as Record<string, string | undefined>;
    const [removeIndex, setRemoveIndex] = useState<number | null>(null);

    const extensionDays = purchaseOrder?.extension_days ?? [];
    const dueDate = previewDueDate(form.data.emailed_date, form.data.delivery_term_days, dueDateRules, extensionDays);
    const totalCents = form.data.items.reduce((sum, line) => sum + lineCents(line), 0);

    // The list of modes, plus the value this PO already has when it is no longer in the list.
    const currentMode = form.data.mode_of_procurement;
    const modeChoices = currentMode !== '' && !procurementModes.includes(currentMode) ? [currentMode, ...procurementModes] : procurementModes;

    // Rule: every delivery date delivers at least one unit, so dates can never outnumber the units ordered.
    const totalQuantity = form.data.items.reduce((sum, line) => sum + (parseInt(line.quantity_ordered, 10) || 0), 0);
    const dateCount = new Set(form.data.schedule_dates.filter((d) => d !== '')).size;
    const tooManyDates = dateCount > totalQuantity;

    // Rows are plain array items: to change one, copy the array with that row replaced.
    const updateLine = (index: number, patch: Partial<FormLine>) =>
        form.setData(
            'items',
            form.data.items.map((line, i) => (i === index ? { ...line, ...patch } : line)),
        );

    const removeLine = (index: number) =>
        form.setData(
            'items',
            form.data.items.filter((_, i) => i !== index),
        );

    // A catalog choice fills description, category and unit. The description stays editable.
    // The stock number is emptied so the server copies the new catalog item's stock number.
    const chooseItem = (index: number, hit: CatalogHit) =>
        updateLine(index, {
            item_id: hit.id,
            item_name: hit.name,
            item_status: 'ok',
            description: hit.name,
            category: hit.category,
            unit: hit.unit,
            stock_no: '',
        });

    const updateDate = (index: number, value: string) =>
        form.setData(
            'schedule_dates',
            form.data.schedule_dates.map((d, i) => (i === index ? value : d)),
        );

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        // Send only what the server needs from each row.
        form.transform((data) => ({
            ...data,
            items: data.items.map((l) => ({
                id: l.id,
                item_id: l.item_id,
                description: l.description,
                unit_price: l.unit_price,
                quantity_ordered: l.quantity_ordered,
                stock_no: l.stock_no,
                brand: l.brand,
                manufacturer: l.manufacturer,
                country_of_origin: l.country_of_origin,
                specifications: l.specifications,
            })),
        }));

        if (mode === 'create') {
            form.post('/purchase-orders');
        } else if (purchaseOrder) {
            form.put(`/purchase-orders/${purchaseOrder.id}`);
        }
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            {errors.form && (
                <div role="alert" className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
                    {errors.form}
                </div>
            )}

            {mode === 'create' && (
                <Card className="gap-3 py-4">
                    <CardHeader className="gap-0.5">
                        <CardTitle className="text-base">How is this PO made?</CardTitle>
                        <CardDescription>Step 1 of the flow.</CardDescription>
                    </CardHeader>
                    <CardContent className="grid items-start gap-3 sm:grid-cols-2">
                        <div className="flex items-center gap-3 rounded-md border-2 border-primary bg-primary/5 px-3 py-2.5">
                            <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground">
                                <Check className="size-3" aria-hidden />
                            </span>
                            <div className="min-w-0">
                                <p className="text-sm font-medium">Create PO in the system</p>
                                <p className="text-xs text-muted-foreground">Fill in the form below.</p>
                            </div>
                        </div>

                        <div aria-disabled="true" className="space-y-2 rounded-md border border-dashed px-3 py-2.5">
                            <div className="flex items-center justify-between gap-2">
                                <p className="text-sm font-medium text-muted-foreground">Post directly in BizBox</p>
                                <span className="shrink-0 rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">Not yet available</span>
                            </div>
                            <div className="flex gap-2">
                                <Input
                                    id="bizbox_reference"
                                    aria-label="BizBox PO number"
                                    disabled={!BIZBOX_FETCH_AVAILABLE}
                                    placeholder="BizBox PO number"
                                    className="h-8 min-w-0 flex-1 text-sm"
                                />
                                <span title={BIZBOX_FETCH_AVAILABLE ? undefined : 'Not yet available'} className="inline-block">
                                    <Button type="button" size="sm" variant="outline" disabled={!BIZBOX_FETCH_AVAILABLE}>
                                        Fetch from BizBox
                                    </Button>
                                </span>
                            </div>
                            <p className="text-xs text-muted-foreground">BizBox is not connected yet. Use the form below.</p>
                        </div>
                    </CardContent>
                </Card>
            )}

            <Card>
                <CardHeader>
                    <CardTitle>PO details</CardTitle>
                    <CardDescription>
                        {mode === 'edit' && purchaseOrder
                            ? `Status: ${purchaseOrder.status_label}. The status does not change here.`
                            : 'A new PO starts as a draft.'}
                    </CardDescription>
                </CardHeader>
                <CardContent className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="supplier_id">Supplier</Label>
                        <select
                            id="supplier_id"
                            className={control}
                            value={form.data.supplier_id}
                            onChange={(e) => form.setData('supplier_id', e.target.value)}
                            required
                        >
                            <option value="">Choose a supplier</option>
                            {supplierOptions.map((s) => (
                                <option key={s.id} value={String(s.id)}>
                                    {s.name}
                                    {s.is_active ? '' : ' (inactive)'}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.supplier_id} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="po_number">PO number</Label>
                        <Input id="po_number" value={form.data.po_number} onChange={(e) => form.setData('po_number', e.target.value)} maxLength={30} required />
                        <InputError message={errors.po_number} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="po_date">PO date</Label>
                        <input id="po_date" type="date" className={control} value={form.data.po_date} onChange={(e) => form.setData('po_date', e.target.value)} required />
                        <InputError message={errors.po_date} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="emailed_date">Emailed date</Label>
                        <input
                            id="emailed_date"
                            type="date"
                            className={control}
                            value={form.data.emailed_date}
                            onChange={(e) => form.setData('emailed_date', e.target.value)}
                        />
                        <p className="text-sm text-muted-foreground">The delivery term counts from this date. Leave empty on a draft.</p>
                        <InputError message={errors.emailed_date} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="delivery_term_days">Delivery term (days)</Label>
                        <Input
                            id="delivery_term_days"
                            type="number"
                            min={0}
                            max={365}
                            step={1}
                            value={form.data.delivery_term_days}
                            onChange={(e) => form.setData('delivery_term_days', e.target.value)}
                        />
                        <InputError message={errors.delivery_term_days} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="due_date_preview">Due date (preview)</Label>
                        <Input id="due_date_preview" value={dueDate ?? '—'} readOnly tabIndex={-1} className="bg-muted/40 tabular-nums" />
                        <p className="text-sm text-muted-foreground">
                            Counted in {dueDateRules.term} days from the emailed date
                            {extensionDays.length > 0 && `, plus ${extensionDays.length} approved ${extensionDays.length === 1 ? 'extension' : 'extensions'}`}. The
                            system sets the real due date when you save.
                        </p>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Printed on the purchase order</CardTitle>
                    <CardDescription>These appear on the printed PO. All are optional; leave them empty if you do not have them yet.</CardDescription>
                </CardHeader>
                <CardContent className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="pr_no">P.R. no.</Label>
                        <Input id="pr_no" value={form.data.pr_no} onChange={(e) => form.setData('pr_no', e.target.value)} maxLength={50} placeholder="26-03-0112" />
                        <InputError message={errors.pr_no} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="caf_no">CAF no.</Label>
                        <Input id="caf_no" value={form.data.caf_no} onChange={(e) => form.setData('caf_no', e.target.value)} maxLength={50} placeholder="2026-0860" />
                        <InputError message={errors.caf_no} />
                    </div>

                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="mode_of_procurement">Mode of procurement</Label>
                        <select
                            id="mode_of_procurement"
                            className={control}
                            value={form.data.mode_of_procurement}
                            onChange={(e) => form.setData('mode_of_procurement', e.target.value)}
                        >
                            <option value="">Not set</option>
                            {modeChoices.map((m) => (
                                <option key={m} value={m}>
                                    {m}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.mode_of_procurement} />
                    </div>

                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="remarks">Free-text line</Label>
                        <Input
                            id="remarks"
                            value={form.data.remarks}
                            onChange={(e) => form.setData('remarks', e.target.value)}
                            maxLength={255}
                            placeholder="For example: for Pharmacy use"
                        />
                        <p className="text-sm text-muted-foreground">Printed under the items.</p>
                        <InputError message={errors.remarks} />
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Items</CardTitle>
                    <CardDescription>
                        Click the catalog box to browse, or type to search. Category and unit follow the catalog item; the description is a copy you can edit.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <InputError message={errors.items} />

                    {form.data.items.map((line, index) => {
                        const field = (name: string) => errors[`items.${index}.${name}`];
                        const total = lineCents(line);

                        return (
                            <div key={line.key} className="space-y-3 rounded-md border p-3">
                                <div className="flex items-start justify-between gap-2">
                                    <p className="text-sm font-medium">Line {index + 1}</p>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label={`Remove line ${index + 1}`}
                                        onClick={() => (line.id === null ? removeLine(index) : setRemoveIndex(index))}
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                </div>

                                <div className="grid gap-2">
                                    <Label>Catalog item</Label>
                                    <ItemSearchBox onSelect={(hit) => chooseItem(index, hit)} />
                                    <LinkedItem line={line} />
                                    {itemNote[line.item_status] !== '' && <p className="text-sm text-muted-foreground">{itemNote[line.item_status]}</p>}
                                    <InputError message={field('item_id') ?? field('id')} />
                                </div>

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-2 sm:col-span-2">
                                        <Label htmlFor={`description-${line.key}`}>Description</Label>
                                        <Input
                                            id={`description-${line.key}`}
                                            value={line.description}
                                            onChange={(e) => updateLine(index, { description: e.target.value })}
                                            maxLength={255}
                                        />
                                        <InputError message={field('description')} />
                                    </div>

                                    <div className="text-sm">
                                        <span className="text-muted-foreground">Category: </span>
                                        {line.category || '—'}
                                    </div>
                                    <div className="text-sm">
                                        <span className="text-muted-foreground">Unit: </span>
                                        {line.unit || '—'}
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor={`price-${line.key}`}>Unit price (PHP)</Label>
                                        <Input
                                            id={`price-${line.key}`}
                                            type="number"
                                            min={0}
                                            step="0.01"
                                            inputMode="decimal"
                                            value={line.unit_price}
                                            onChange={(e) => updateLine(index, { unit_price: e.target.value })}
                                        />
                                        <InputError message={field('unit_price')} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor={`qty-${line.key}`}>Quantity ordered</Label>
                                        <Input
                                            id={`qty-${line.key}`}
                                            type="number"
                                            min={1}
                                            step={1}
                                            inputMode="numeric"
                                            value={line.quantity_ordered}
                                            onChange={(e) => updateLine(index, { quantity_ordered: e.target.value })}
                                        />
                                        <InputError message={field('quantity_ordered')} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor={`stock-${line.key}`}>Stock/Property no.</Label>
                                        <Input
                                            id={`stock-${line.key}`}
                                            value={line.stock_no}
                                            onChange={(e) => updateLine(index, { stock_no: e.target.value })}
                                            maxLength={50}
                                        />
                                        <p className="text-sm text-muted-foreground">Leave empty to use the catalog item's number when you save.</p>
                                        <InputError message={field('stock_no')} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor={`country-${line.key}`}>Country of origin</Label>
                                        <Input
                                            id={`country-${line.key}`}
                                            value={line.country_of_origin}
                                            onChange={(e) => updateLine(index, { country_of_origin: e.target.value })}
                                            maxLength={100}
                                        />
                                        <InputError message={field('country_of_origin')} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor={`brand-${line.key}`}>Brand name</Label>
                                        <Input
                                            id={`brand-${line.key}`}
                                            value={line.brand}
                                            onChange={(e) => updateLine(index, { brand: e.target.value })}
                                            maxLength={150}
                                        />
                                        <InputError message={field('brand')} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor={`manufacturer-${line.key}`}>Manufacturer</Label>
                                        <Input
                                            id={`manufacturer-${line.key}`}
                                            value={line.manufacturer}
                                            onChange={(e) => updateLine(index, { manufacturer: e.target.value })}
                                            maxLength={150}
                                        />
                                        <InputError message={field('manufacturer')} />
                                    </div>

                                    <div className="grid gap-2 sm:col-span-2">
                                        <Label htmlFor={`specifications-${line.key}`}>Supplier's specifications (free text)</Label>
                                        <Input
                                            id={`specifications-${line.key}`}
                                            value={line.specifications}
                                            onChange={(e) => updateLine(index, { specifications: e.target.value })}
                                            maxLength={500}
                                        />
                                        <InputError message={field('specifications')} />
                                    </div>
                                </div>

                                <p className="text-right text-sm">
                                    Line total: <span className="font-medium tabular-nums">{formatPhp(centsToDecimal(total))}</span>
                                </p>
                            </div>
                        );
                    })}

                    <Button type="button" variant="outline" size="sm" onClick={() => form.setData('items', [...form.data.items, blankLine()])}>
                        <Plus className="size-4" /> Add line
                    </Button>

                    <div className="flex items-center justify-end gap-4 rounded-md border bg-muted/30 px-3 py-3">
                        <span className="text-sm text-muted-foreground">PO total</span>
                        <span className="text-lg font-semibold tabular-nums">{formatPhp(centsToDecimal(totalCents))}</span>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Schedule Term</CardTitle>
                    <CardDescription>
                        The dates the supplier is expected to deliver the items above (for example one date per batch). Optional. There can be no more dates than
                        units ordered.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-3">
                    {form.data.schedule_dates.length === 0 && <p className="text-sm text-muted-foreground">No scheduled dates.</p>}

                    {form.data.schedule_dates.map((date, index) => (
                        <div key={index} className="grid gap-1">
                            <div className="flex items-center gap-2">
                                <input
                                    type="date"
                                    aria-label={`Scheduled date ${index + 1}`}
                                    className={`${control} max-w-48`}
                                    value={date}
                                    onChange={(e) => updateDate(index, e.target.value)}
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`Remove scheduled date ${index + 1}`}
                                    onClick={() =>
                                        form.setData(
                                            'schedule_dates',
                                            form.data.schedule_dates.filter((_, i) => i !== index),
                                        )
                                    }
                                >
                                    <Trash2 className="size-4" />
                                </Button>
                            </div>
                            <InputError message={errors[`schedule_dates.${index}`]} />
                        </div>
                    ))}

                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={form.data.schedule_dates.length >= totalQuantity}
                        onClick={() => form.setData('schedule_dates', [...form.data.schedule_dates, ''])}
                    >
                        <Plus className="size-4" /> Add date
                    </Button>

                    <p className={`text-sm ${tooManyDates ? 'text-destructive' : 'text-muted-foreground'}`}>
                        {totalQuantity === 0
                            ? 'Add items first. The number of dates cannot be more than the total quantity ordered.'
                            : `${dateCount} ${dateCount === 1 ? 'date' : 'dates'} for ${totalQuantity} ${totalQuantity === 1 ? 'unit' : 'units'} ordered (at most ${totalQuantity}).`}
                        {tooManyDates && ' Remove dates or increase the quantity to save.'}
                    </p>
                    <InputError message={errors.schedule_dates} />
                </CardContent>
            </Card>

            <div className="flex items-center gap-3">
                <Button type="submit" disabled={form.processing || tooManyDates}>
                    {mode === 'create' ? 'Create PO' : 'Save changes'}
                </Button>
                <Button variant="outline" asChild>
                    <Link href={mode === 'edit' && purchaseOrder ? `/purchase-orders/${purchaseOrder.id}` : '/purchase-orders'}>Cancel</Link>
                </Button>
            </div>

            <ConfirmDialog
                open={removeIndex !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setRemoveIndex(null);
                    }
                }}
                title="Remove this line?"
                description="The line is deleted from the PO when you save the changes."
                confirmLabel="Remove line"
                destructive
                onConfirm={() => {
                    if (removeIndex !== null) {
                        removeLine(removeIndex);
                    }

                    setRemoveIndex(null);
                }}
            />
        </form>
    );
}