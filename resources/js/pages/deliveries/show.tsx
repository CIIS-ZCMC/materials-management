import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Pencil } from 'lucide-react';
import type { ReactNode } from 'react';
import DeliveryStatusBadge from '@/components/deliveries/delivery-status-badge';
import DeliveryDocumentsPanel from '@/components/deliveries/delivery-documents-panel';
import type { DeliveryDetail, DeliveryDocumentsData } from '@/components/deliveries/delivery-types';
import VoidDeliveryDialog from '@/components/deliveries/void-delivery-dialog';
import Heading from '@/components/heading';
import { formatPhp, orDash } from '@/components/purchase-orders/po-format';
import { Button } from '@/components/ui/button';

type Props = {
    delivery: DeliveryDetail;
    can: { edit: boolean; void: boolean; edit_reason: string | null; void_reason: string | null };
    documents: DeliveryDocumentsData;
};

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="mt-0.5 min-w-0 font-medium break-words">{children}</dd>
        </div>
    );
}

export default function DeliveryShow({ delivery, can, documents }: Props) {
    const voided = delivery.status.value === 'voided';
    const inspectors = delivery.signatories.filter((s) => s.role === 'inspector');
    const endUser = delivery.signatories.find((s) => s.role === 'end_user');

    return (
        <>
            <Head title={`Delivery ${delivery.iar_number}`} />

            <div className="space-y-4 p-4 md:space-y-6">
                <Button asChild variant="outline" size="sm">
                    <Link href={`/purchase-orders/${delivery.purchase_order.id}`}>
                        <ArrowLeft className="size-4" /> Back to PO {delivery.purchase_order.po_number}
                    </Link>
                </Button>

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Heading title={`IAR ${delivery.iar_number}`} description={`PO ${delivery.purchase_order.po_number}${delivery.purchase_order.supplier ? ` · ${delivery.purchase_order.supplier}` : ''}`} />
                    <div className="flex flex-wrap items-center gap-2">
                        {can.edit && (
                            <Button asChild size="sm">
                                <Link href={`/deliveries/${delivery.id}/edit`}>
                                    <Pencil className="size-4" /> Edit
                                </Link>
                            </Button>
                        )}
                        {can.void && <VoidDeliveryDialog deliveryId={delivery.id} iarNumber={delivery.iar_number} />}
                        <DeliveryStatusBadge status={delivery.status} />
                    </div>
                </div>

                {can.edit_reason && <p className="text-sm text-muted-foreground">{can.edit_reason}</p>}
                {can.void_reason && !voided && <p className="text-sm text-muted-foreground">{can.void_reason}</p>}

                {voided && (
                    <div className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm">
                        <p className="font-medium">Voided</p>
                        <p className="mt-0.5 break-words">{delivery.void_reason ?? 'No reason was recorded.'}</p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            {orDash(delivery.voided_by)} · {orDash(delivery.voided_at)}. The IAR number stays recorded and cannot be used again.
                        </p>
                    </div>
                )}

                <dl className="grid gap-x-8 gap-y-4 sm:grid-cols-2">
                    <Field label="IAR series">{delivery.iar_series.label}</Field>
                    <Field label="IAR no.">
                        <span className="tabular-nums">{delivery.iar_number}</span>
                    </Field>
                    <Field label="Invoice no.">{delivery.invoice_no}</Field>
                    <Field label="Fund cluster">{delivery.fund_cluster}</Field>
                    <Field label="Amount">
                        <span className="tabular-nums">{formatPhp(delivery.amount)}</span>
                    </Field>
                    <Field label="Date received">
                        <span className="tabular-nums">{delivery.date_received}</span>
                    </Field>
                    <Field label="IAR processing deadline">
                        <span className="tabular-nums">{orDash(delivery.iar_deadline)}</span>
                        {delivery.iar_overdue && <span className="ml-2 text-sm font-normal text-destructive">Past the deadline, documents still missing</span>}
                    </Field>
                    <Field label="Recorded by">{`${orDash(delivery.created_by)}${delivery.created_at ? ` · ${delivery.created_at}` : ''}`}</Field>
                </dl>

                <section className="space-y-2">
                    <h3 className="text-base font-medium">Signatories</h3>
                    <dl className="grid gap-x-8 gap-y-4 sm:grid-cols-2">
                        <Field label="Inspectors">
                            {inspectors.length === 0 ? (
                                '—'
                            ) : (
                                <ul className="space-y-1">
                                    {inspectors.map((s) => (
                                        <li key={s.person_id}>
                                            {s.name}
                                            {s.position ? <span className="block text-xs font-normal text-muted-foreground">{s.position}</span> : null}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Field>
                        <Field label="End-user">
                            {endUser ? (
                                <>
                                    {endUser.name}
                                    {endUser.position ? <span className="block text-xs font-normal text-muted-foreground">{endUser.position}</span> : null}
                                </>
                            ) : (
                                '—'
                            )}
                        </Field>
                    </dl>
                </section>

                <DeliveryDocumentsPanel deliveryId={delivery.id} poId={delivery.purchase_order.id} status={delivery.status.value} documents={documents} />
            </div>
        </>
    );
}

DeliveryShow.layout = {
    breadcrumbs: [
        { title: 'Deliveries', href: '/deliveries' },
        { title: 'Delivery', href: '#' },
    ],
};
