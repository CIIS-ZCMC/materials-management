import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useFlashToast } from '@/components/admin/use-flash-toast';
import Heading from '@/components/heading';
import PersonPicker from '@/components/property/person-picker';
import type { PersonOption } from '@/components/property/person-picker';
import ProvisionalNotice from '@/components/property/provisional-notice';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Gate = { allowed: boolean; reason: string | null };
type Band = { value: string; label: string; documents: string[]; remarks_required: boolean };

type Decision = {
    id: number;
    is_equipment: boolean;
    category_default: boolean;
    override_reason: string | null;
    band: Band;
    unit_price: string;
    par_threshold: string;
    ics_min: string;
    rule_provisional: boolean;
    remarks: string | null;
    status: string;
    decided_by: string | null;
    decided_at: string | null;
    void_reason: string | null;
    voided_by: string | null;
    voided_at: string | null;
};

type Doc = {
    id: number;
    type: 'par' | 'sub_par' | 'ics';
    type_label: string;
    number: string;
    status: string;
    status_label: string;
    user_name: string | null;
    user_employee_no: string | null;
    user_position: string | null;
    issued_by: string | null;
    issued_at: string | null;
    void_reason: string | null;
    voided_by: string | null;
    voided_at: string | null;
    current: boolean;
    can_void: Gate;
};

type Props = {
    item: {
        id: number;
        description: string;
        unit: string | null;
        quantity: string;
        unit_price: string;
        category: string | null;
        category_is_equipment: boolean;
        iar_number: string;
        delivery_id: number;
        po_number: string;
        purchase_order_id: number;
        delivered_on: string | null;
        source_problem: string | null;
    };
    preview: { equipment: Band; not_equipment: Band };
    rule: { provisional: boolean; par_threshold: string; ics_min: string };
    decision: Decision | null;
    history: Decision[];
    documents: Doc[];
    missing: ('par' | 'sub_par' | 'ics')[];
    can: { decide: Gate; void_decision: Gate; issue: Record<'par' | 'sub_par' | 'ics', Gate> };
    year: number;
};

type VoidTarget = { kind: 'decision' | 'document'; id: number; label: string };

const yesNo = (value: boolean) => (value ? 'Equipment' : 'Not equipment');

export default function PropertyDocumentShow(props: Props) {
    const { item, rule, decision, history, documents, missing, can, preview } = props;

    useFlashToast();

    const [target, setTarget] = useState<VoidTarget | null>(null);

    return (
        <>
            <Head title={`Property: ${item.description}`} />

            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Heading title={item.description} description="Equipment decision and property documents for this delivered line" />
                    <Button asChild variant="outline" size="sm">
                        <Link href="/property-documents">Back to the list</Link>
                    </Button>
                </div>

                {rule.provisional && <ProvisionalNotice />}

                {item.source_problem && (
                    <div className="rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive">
                        {item.source_problem}
                        {decision && ' Void the decision and its documents here by hand if they should not stand.'}
                    </div>
                )}

                <section className="grid gap-x-8 gap-y-2 rounded-md border p-4 text-sm sm:grid-cols-2">
                    <Fact label="Quantity delivered" value={`${item.quantity}${item.unit ? ` ${item.unit}` : ''}`} />
                    <Fact label="Unit price" value={item.unit_price} />
                    <Fact label="Category" value={`${item.category ?? '—'} (${yesNo(item.category_is_equipment).toLowerCase()} by default)`} />
                    <Fact label="Delivered on" value={item.delivered_on ?? '—'} />
                    <Fact label="IAR" value={<span className="font-mono">{item.iar_number}</span>} />
                    <Fact label="Purchase order" value={<span className="font-mono">{item.po_number}</span>} />
                </section>

                <section className="space-y-3">
                    <h2 className="text-base font-semibold">1. Equipment decision</h2>

                    {decision ? (
                        <DecisionCard decision={decision} gate={can.void_decision} onVoid={() => setTarget({ kind: 'decision', id: decision.id, label: 'this decision' })} />
                    ) : can.decide.allowed ? (
                        <DecisionForm itemId={item.id} defaultEquipment={item.category_is_equipment} preview={preview} rule={rule} />
                    ) : (
                        <p className="text-sm text-muted-foreground">{can.decide.reason ?? 'No equipment decision has been recorded yet.'}</p>
                    )}
                </section>

                {decision && (
                    <section className="space-y-3">
                        <h2 className="text-base font-semibold">2. Property documents</h2>

                        {decision.band.documents.length === 0 && (
                            <p className="text-sm text-muted-foreground">This decision calls for no document ({decision.band.label}).</p>
                        )}

                        {documents.length > 0 && (
                            <ul className="divide-y rounded-md border">
                                {documents.map((doc) => (
                                    <li key={doc.id} className="flex flex-wrap items-start justify-between gap-3 p-3 text-sm">
                                        <div className="space-y-0.5">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="font-medium">{doc.type_label}</span>
                                                <span className="font-mono">{doc.number}</span>
                                                <Badge variant={doc.status === 'issued' ? 'default' : 'outline'}>{doc.status_label}</Badge>
                                            </div>
                                            {doc.user_name && (
                                                <div className="text-muted-foreground">
                                                    User: {doc.user_name} ({doc.user_employee_no}){doc.user_position ? `, ${doc.user_position}` : ''}
                                                </div>
                                            )}
                                            <div className="text-muted-foreground">
                                                Issued {doc.issued_at ?? '—'} by {doc.issued_by ?? '—'}
                                            </div>
                                            {doc.status !== 'issued' && (
                                                <div className="text-muted-foreground">
                                                    Voided {doc.voided_at ?? '—'} by {doc.voided_by ?? '—'}: {doc.void_reason}
                                                </div>
                                            )}
                                        </div>
                                        {doc.can_void.allowed && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => setTarget({ kind: 'document', id: doc.id, label: `${doc.type_label} ${doc.number}` })}
                                            >
                                                Void
                                            </Button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}

                        {missing.includes('par') && <IssueBlock decisionId={decision.id} mode="par" gate={can.issue.par} year={props.year} />}
                        {!missing.includes('par') && missing.includes('sub_par') && (
                            <IssueBlock decisionId={decision.id} mode="sub_par" gate={can.issue.sub_par} year={props.year} />
                        )}
                        {missing.includes('ics') && <IssueBlock decisionId={decision.id} mode="ics" gate={can.issue.ics} year={props.year} />}
                    </section>
                )}

                {target && <VoidForm key={`${target.kind}-${target.id}`} target={target} onClose={() => setTarget(null)} />}

                {history.length > 0 && (
                    <section className="space-y-3">
                        <h2 className="text-base font-semibold">Voided decisions</h2>
                        <ul className="divide-y rounded-md border text-sm">
                            {history.map((d) => (
                                <li key={d.id} className="space-y-0.5 p-3">
                                    <div className="font-medium">
                                        {yesNo(d.is_equipment)}: {d.band.label}
                                    </div>
                                    <div className="text-muted-foreground">
                                        Decided {d.decided_at ?? '—'} by {d.decided_by ?? '—'}. Voided {d.voided_at ?? '—'} by {d.voided_by ?? '—'}:{' '}
                                        {d.void_reason}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}

PropertyDocumentShow.layout = {
    breadcrumbs: [
        { title: 'Property documents', href: '/property-documents' },
        { title: 'Delivered line', href: '#' },
    ],
};

function Fact({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="flex justify-between gap-4">
            <span className="text-muted-foreground">{label}</span>
            <span className="text-right">{value}</span>
        </div>
    );
}

function FieldError({ message }: { message?: string }) {
    return message ? <p className="text-sm text-destructive">{message}</p> : null;
}

function DecisionCard({ decision, gate, onVoid }: { decision: Decision; gate: Gate; onVoid: () => void }) {
    return (
        <div className="space-y-2 rounded-md border p-4 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <Badge>{yesNo(decision.is_equipment)}</Badge>
                <span className="font-medium">{decision.band.label}</span>
                {decision.rule_provisional && <Badge variant="outline">Made under the provisional rule</Badge>}
            </div>
            <div className="text-muted-foreground">
                Unit price {decision.unit_price}. Rule at that time: above {decision.par_threshold} is a PAR with a Sub-PAR; from {decision.ics_min} up to
                {' '}{decision.par_threshold} is an ICS.
            </div>
            {decision.override_reason && (
                <div>
                    The category says {decision.category_default ? 'equipment' : 'not equipment'}. Changed because: {decision.override_reason}
                </div>
            )}
            {decision.remarks && <div>Remarks: {decision.remarks}</div>}
            <div className="text-muted-foreground">
                Decided {decision.decided_at ?? '—'} by {decision.decided_by ?? '—'}
            </div>
            {gate.allowed && (
                <Button size="sm" variant="outline" onClick={onVoid}>
                    Void this decision
                </Button>
            )}
            {!gate.allowed && gate.reason && <p className="text-muted-foreground">{gate.reason}</p>}
        </div>
    );
}

function DecisionForm({
    itemId,
    defaultEquipment,
    preview,
    rule,
}: {
    itemId: number;
    defaultEquipment: boolean;
    preview: Props['preview'];
    rule: Props['rule'];
}) {
    const form = useForm({ is_equipment: defaultEquipment, override_reason: '', remarks: '' });
    const band = form.data.is_equipment ? preview.equipment : preview.not_equipment;
    const changed = form.data.is_equipment !== defaultEquipment;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/property-documents/items/${itemId}/decision`, { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-4 rounded-md border p-4">
            <fieldset className="space-y-2">
                <legend className="text-sm font-medium">Is this line equipment?</legend>
                {[true, false].map((value) => (
                    <label key={String(value)} className="flex items-center gap-2 text-sm">
                        <input
                            type="radio"
                            name="is_equipment"
                            checked={form.data.is_equipment === value}
                            onChange={() => form.setData('is_equipment', value)}
                        />
                        {value ? 'Equipment' : 'Not equipment'}
                        {value === defaultEquipment && <span className="text-muted-foreground">(what the category says)</span>}
                    </label>
                ))}
                <FieldError message={form.errors.is_equipment} />
            </fieldset>

            <p className="text-sm">
                Rule result: <span className="font-medium">{band.label}</span>
                {rule.provisional && <span className="text-muted-foreground"> (provisional)</span>}
            </p>

            {changed && (
                <div className="space-y-2">
                    <Label htmlFor="override_reason">
                        Why is this different from the category? (required, 5 to 500 characters)
                    </Label>
                    <Input id="override_reason" value={form.data.override_reason} onChange={(e) => form.setData('override_reason', e.target.value)} />
                    <FieldError message={form.errors.override_reason} />
                </div>
            )}

            <div className="space-y-2">
                <Label htmlFor="remarks">{band.remarks_required ? 'Remarks (required below the ICS minimum)' : 'Remarks (optional)'}</Label>
                <Input id="remarks" value={form.data.remarks} onChange={(e) => form.setData('remarks', e.target.value)} />
                <FieldError message={form.errors.remarks} />
            </div>

            <FieldError message={(form.errors as Record<string, string>).form} />

            <Button type="submit" disabled={form.processing}>
                Record decision
            </Button>
        </form>
    );
}

function IssueBlock({ decisionId, mode, gate, year }: { decisionId: number; mode: 'par' | 'sub_par' | 'ics'; gate: Gate; year: number }) {
    if (!gate.allowed) {
        return gate.reason ? <p className="text-sm text-muted-foreground">{gate.reason}</p> : null;
    }

    return <IssueForm decisionId={decisionId} mode={mode} year={year} />;
}

function useSuggestion(type: 'par' | 'sub_par' | 'ics', enabled: boolean, onNumber: (number: string) => void) {
    useEffect(() => {
        if (!enabled) {
            return;
        }

        let cancelled = false;

        fetch(`/property-documents/number-suggestion?type=${type}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((json: { number: string } | null) => {
                if (!cancelled && json) {
                    onNumber(json.number);
                }
            })
            .catch(() => undefined);

        return () => {
            cancelled = true;
        };
        // The suggestion is fetched once when the form opens; the user may then edit the number.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [type]);
}

function IssueForm({ decisionId, mode, year }: { decisionId: number; mode: 'par' | 'sub_par' | 'ics'; year: number }) {
    const form = useForm({ type: mode, number: '', sub_par_number: '', person_id: '' as string | number });
    const [person, setPerson] = useState<PersonOption | null>(null);

    useSuggestion(mode, true, (number) => form.setData((data) => (data.number === '' ? { ...data, number } : data)));
    useSuggestion('sub_par', mode === 'par', (number) => {
        form.setData((data) => (data.sub_par_number === '' ? { ...data, sub_par_number: number } : data));
    });

    const title = mode === 'par' ? 'Issue PAR and Sub-PAR' : mode === 'sub_par' ? 'Issue a new Sub-PAR' : 'Issue ICS';
    const label = mode === 'par' ? 'PAR number' : mode === 'sub_par' ? 'Sub-PAR number' : 'ICS number';

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/property-documents/decisions/${decisionId}/documents`, { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-4 rounded-md border p-4">
            <h3 className="text-sm font-medium">{title}</h3>
            <p className="text-sm text-muted-foreground">
                The number is only a suggestion for {year}. You may type another one. It is used up when you issue, and a voided number is never used
                again.
            </p>

            <div className="space-y-2">
                <Label htmlFor={`number-${mode}`}>{label}</Label>
                <Input id={`number-${mode}`} className="font-mono" value={form.data.number} onChange={(e) => form.setData('number', e.target.value)} />
                <FieldError message={form.errors.number} />
            </div>

            {mode === 'par' && (
                <div className="space-y-2">
                    <Label htmlFor="sub-par-number">Sub-PAR number (issued together with the PAR)</Label>
                    <Input
                        id="sub-par-number"
                        className="font-mono"
                        value={form.data.sub_par_number}
                        onChange={(e) => form.setData('sub_par_number', e.target.value)}
                    />
                    <FieldError message={form.errors.sub_par_number} />
                </div>
            )}

            {mode !== 'ics' && (
                <div className="space-y-2">
                    <Label htmlFor="person">Sub-PAR user (an end-user from the personnel list)</Label>
                    <PersonPicker
                        id="person"
                        role="end-user"
                        value={person}
                        onChange={(next) => {
                            setPerson(next);
                            form.setData('person_id', next ? next.id : '');
                        }}
                    />
                    <FieldError message={form.errors.person_id} />
                </div>
            )}

            <FieldError message={(form.errors as Record<string, string>).form} />

            <Button type="submit" disabled={form.processing}>
                {title}
            </Button>
        </form>
    );
}

function VoidForm({ target, onClose }: { target: VoidTarget; onClose: () => void }) {
    const form = useForm({ reason: '' });
    const url = target.kind === 'decision' ? `/property-documents/decisions/${target.id}/void` : `/property-documents/documents/${target.id}/void`;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(url, { preserveScroll: true, onSuccess: onClose });
    };

    const errors = form.errors as Record<string, string>;

    return (
        <form onSubmit={submit} className="space-y-3 rounded-md border border-amber-500/40 bg-amber-500/10 p-4">
            <h3 className="text-sm font-medium">Void {target.label}</h3>
            {target.kind === 'document' && target.label.startsWith('PAR') && (
                <p className="text-sm">Voiding a PAR also voids its Sub-PAR. The numbers are never used again.</p>
            )}
            <div className="space-y-2">
                <Label htmlFor="void-reason">Reason (5 to 500 characters)</Label>
                <Input id="void-reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                <FieldError message={errors.reason} />
                <FieldError message={errors.form} />
            </div>
            <div className="flex gap-2">
                <Button type="submit" variant="destructive" disabled={form.processing}>
                    Void
                </Button>
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}
