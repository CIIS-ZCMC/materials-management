import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { DeliveryDetail, DeliveryFormOptions, PoSummary, SignatoryRow } from '@/components/deliveries/delivery-types';
import SignatoryFields, { emptyPerson, type TypedPerson } from '@/components/deliveries/signatory-fields';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Props = {
    mode: 'create' | 'edit';
    purchaseOrder: PoSummary;
    options: DeliveryFormOptions;
    delivery?: DeliveryDetail;
};

type FormShape = {
    purchase_order_id: number;
    invoice_no: string;
    iar_series: string;
    iar_number: string;
    fund_cluster: string;
    amount: string;
    date_received: string;
    inspectors: TypedPerson[];
    end_user: TypedPerson;
};

const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;

// A signatory saved on the delivery becomes a typed row (an old row that came from the directory works too: only name and position are used).
const toTyped = (s: SignatoryRow): TypedPerson => ({ name: s.name, position: s.position ?? '' });

const selectClass =
    'border-input h-9 w-full rounded-md border bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

// One form for "Record delivery" and "Edit delivery". When editing, the IAR series and number are shown but locked:
// a wrong number is fixed by voiding the delivery (the number is never reused) and recording it again.
export default function DeliveryForm({ mode, purchaseOrder, options, delivery }: Props) {
    const editing = mode === 'edit';

    // Creating starts with one empty inspector row; editing starts from the saved rows.
    const savedInspectors = delivery?.signatories.filter((s) => s.role === 'inspector').map(toTyped) ?? [];
    const startInspectors = savedInspectors.length > 0 ? savedInspectors : [emptyPerson()];
    const savedEndUser = delivery?.signatories.find((s) => s.role === 'end_user');

    const [suggested, setSuggested] = useState<string | null>(null);
    const [numberTouched, setNumberTouched] = useState(false);

    const form = useForm<FormShape>({
        purchase_order_id: purchaseOrder.id,
        invoice_no: delivery?.invoice_no ?? '',
        iar_series: delivery?.iar_series.value ?? options.series[0]?.value ?? 'regular',
        iar_number: delivery?.iar_number ?? '',
        fund_cluster: delivery?.fund_cluster ?? '',
        amount: delivery?.amount ?? '',
        date_received: delivery?.date_received ?? options.today,
        inspectors: startInspectors,
        end_user: savedEndUser ? toTyped(savedEndUser) : emptyPerson(),
    });

    const { data, setData } = form;
    const errors = form.errors as Record<string, string | undefined>;

    // Creating: suggest the next IAR number of the chosen series for the year of the date received.
    // The suggestion fills the box until the person types their own number.
    useEffect(() => {
        if (editing || !DATE_PATTERN.test(data.date_received)) {
            return;
        }

        const controller = new AbortController();
        const query = new URLSearchParams({ series: data.iar_series, date: data.date_received });

        fetch(`/deliveries/iar-suggestion?${query.toString()}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then((response) => (response.ok ? (response.json() as Promise<{ number: string }>) : null))
            .then((json) => {
                if (json) {
                    setSuggested(json.number);

                    if (!numberTouched) {
                        setData('iar_number', json.number);
                    }
                }
            })
            .catch(() => undefined);

        return () => controller.abort();
        // numberTouched is read, not a trigger: typing must not fetch again.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [editing, data.iar_series, data.date_received]);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        if (editing && delivery) {
            form.put(`/deliveries/${delivery.id}`);
        } else {
            form.post('/deliveries');
        }
    };

    return (
        <form onSubmit={submit} className="max-w-3xl space-y-6">
            {errors.form && <p className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm">{errors.form}</p>}

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                    <Label htmlFor="invoice_no">Invoice no.</Label>
                    <Input id="invoice_no" value={data.invoice_no} onChange={(e) => setData('invoice_no', e.target.value)} maxLength={100} />
                    <InputError message={errors.invoice_no} />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="fund_cluster">Fund cluster</Label>
                    <Input id="fund_cluster" value={data.fund_cluster} onChange={(e) => setData('fund_cluster', e.target.value)} maxLength={100} />
                    <InputError message={errors.fund_cluster} />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="amount">Amount (PHP)</Label>
                    <Input
                        id="amount"
                        inputMode="decimal"
                        placeholder="125000.50"
                        value={data.amount}
                        onChange={(e) => setData('amount', e.target.value)}
                    />
                    <InputError message={errors.amount} />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="date_received">Date received</Label>
                    <Input id="date_received" type="date" value={data.date_received} max={options.today} onChange={(e) => setData('date_received', e.target.value)} />
                    <p className="text-xs text-muted-foreground">
                        The {options.iar_days}-day IAR window starts on this date.
                        {editing ? ' It must stay in the same year as the IAR number.' : ' The year of the IAR number comes from it.'}
                    </p>
                    <InputError message={errors.date_received} />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="iar_series">IAR series</Label>
                    {editing ? (
                        <p className="py-1.5 text-sm font-medium">{delivery?.iar_series.label}</p>
                    ) : (
                        <select
                            id="iar_series"
                            className={selectClass}
                            value={data.iar_series}
                            onChange={(e) => {
                                setNumberTouched(false);
                                setData('iar_series', e.target.value);
                            }}
                        >
                            {options.series.map((s) => (
                                <option key={s.value} value={s.value}>
                                    {s.label}
                                </option>
                            ))}
                        </select>
                    )}
                    <InputError message={errors.iar_series} />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="iar_number">IAR no.</Label>
                    {editing ? (
                        <>
                            <p className="py-1.5 text-sm font-medium tabular-nums">{delivery?.iar_number}</p>
                            <p className="text-xs text-muted-foreground">Locked. To fix a wrong number, void this delivery and record it again.</p>
                        </>
                    ) : (
                        <>
                            <Input
                                id="iar_number"
                                className="tabular-nums"
                                value={data.iar_number}
                                onChange={(e) => {
                                    setNumberTouched(true);
                                    setData('iar_number', e.target.value.toUpperCase());
                                }}
                                maxLength={20}
                            />
                            {suggested && data.iar_number !== suggested && (
                                <Button
                                    type="button"
                                    variant="link"
                                    size="sm"
                                    className="h-auto p-0"
                                    onClick={() => {
                                        setNumberTouched(false);
                                        setData('iar_number', suggested);
                                    }}
                                >
                                    Use the suggested number {suggested}
                                </Button>
                            )}
                        </>
                    )}
                    <InputError message={errors.iar_number} />
                </div>
            </div>

            <SignatoryFields
                inspectors={data.inspectors}
                endUser={data.end_user}
                onInspectorsChange={(rows) => setData('inspectors', rows)}
                onEndUserChange={(person) => setData('end_user', person)}
                min={options.min_inspectors}
                max={options.max_inspectors}
                errors={errors}
            />

            <div className="flex flex-wrap gap-2">
                <Button type="submit" disabled={form.processing}>
                    {editing ? 'Save changes' : 'Record delivery'}
                </Button>
                <Button type="button" variant="outline" onClick={() => window.history.back()}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}