import { router } from '@inertiajs/react';
import { Ban, CheckCircle2, ExternalLink, Eye, FolderOpen, RefreshCw, RotateCcw, X } from 'lucide-react';
import { useRef, useState, type FormEvent } from 'react';
import type { DeliveryDocumentRow, DeliveryDocumentsData } from '@/components/deliveries/delivery-types';
import PoDocumentUploadForm from '@/components/purchase-orders/po-document-upload-form';
import { orDash } from '@/components/purchase-orders/po-format';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

// Step 5.3: the documents of ONE delivery: the checklist of required files, upload, view, void, replace,
// "Mark complete" and "Reopen for uploading". The server decides every can_* flag and checks every action again
// (DeliveryPolicy + DeliveryDocumentRule); this page only draws what it is told.
// React state here: "correction" is the void / replace form that is open (with "reason" and, for replace, the new file),
// "reopening" is the reopen form, "previewId" is the file shown in a frame, "busy" blocks the buttons while a request runs
// and "actionError" is the server's message for a button.

type Props = {
    deliveryId: number;
    poId: number;
    status: string; // 'for_uploading' | 'complete' | 'voided'
    documents: DeliveryDocumentsData;
};

type Correction = { kind: 'void' | 'replace'; id: number; name: string } | null;

const MIN_REASON = 5;

function formatSize(bytes: number): string {
    if (bytes >= 1024 * 1024) {
        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    }

    return `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

function pagesLabel(d: DeliveryDocumentRow): string {
    if (d.page_from === null || d.page_to === null) {
        return '—';
    }

    return d.page_from === d.page_to ? `${d.page_from}` : `${d.page_from}–${d.page_to}`;
}

export default function DeliveryDocumentsPanel({ deliveryId, poId, status, documents }: Props) {
    const [correction, setCorrection] = useState<Correction>(null);
    const [reason, setReason] = useState('');
    const [replaceFile, setReplaceFile] = useState<File | null>(null);
    const [replaceKey, setReplaceKey] = useState(0);
    const replaceInput = useRef<HTMLInputElement>(null);
    const [reopening, setReopening] = useState(false);
    const [previewId, setPreviewId] = useState<number | null>(null);
    const [busy, setBusy] = useState(false);
    const [actionError, setActionError] = useState<string | null>(null);

    const base = `/deliveries/${deliveryId}/documents`;
    const { checklist, rows } = documents;
    const voided = status === 'voided';
    const complete = status === 'complete';

    const visit = {
        preserveScroll: true,
        onStart: () => {
            setBusy(true);
            setActionError(null);
        },
        onError: (errors: Record<string, string>) => setActionError(errors.form ?? errors.reason ?? errors.file ?? 'The action could not be completed.'),
        onFinish: () => setBusy(false),
    };

    function closeForms() {
        setCorrection(null);
        setReopening(false);
        setReason('');
        setReplaceFile(null);
        setReplaceKey((key) => key + 1);
    }

    function submitReason(event: FormEvent) {
        event.preventDefault();

        if (reason.trim().length < MIN_REASON) {
            return;
        }

        const done = { ...visit, onSuccess: () => closeForms() };

        if (reopening) {
            router.post(`${base}/reopen`, { reason: reason.trim() }, done);

            return;
        }

        if (!correction) {
            return;
        }

        if (correction.kind === 'void') {
            router.post(`${base}/${correction.id}/void`, { reason: reason.trim() }, done);

            return;
        }

        if (!replaceFile) {
            return;
        }

        router.post(`${base}/${correction.id}/replace`, { reason: reason.trim(), file: replaceFile }, { ...done, forceFormData: true });
    }

    function renderReasonForm(title: string, help: string, button: string, needsFile: boolean, destructive: boolean) {
        return (
            <form onSubmit={submitReason} className="space-y-3 rounded-md border border-amber-500/40 bg-amber-500/10 p-4">
                <p className="min-w-0 text-sm font-medium break-words">{title}</p>
                <p className="text-sm text-muted-foreground">{help}</p>
                <div className="space-y-1.5">
                    <Label htmlFor="delivery-doc-reason">Reason (required)</Label>
                    <textarea
                        id="delivery-doc-reason"
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        maxLength={500}
                        rows={3}
                        className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                        placeholder="Say briefly what is wrong or why this is needed."
                    />
                </div>
                {needsFile && (
                    <div className="space-y-1.5">
                        <Label htmlFor="delivery-doc-replace-file">New file</Label>
                        <div className="flex flex-wrap items-center gap-3 rounded-md border-2 border-dashed p-3">
                            <Button type="button" variant="outline" onClick={() => replaceInput.current?.click()}>
                                <FolderOpen className="size-4" /> Choose file
                            </Button>
                            <span className="min-w-0 text-sm break-words text-muted-foreground">{replaceFile ? replaceFile.name : 'No file chosen'}</span>
                        </div>
                        <input
                            ref={replaceInput}
                            key={replaceKey}
                            id="delivery-doc-replace-file"
                            type="file"
                            className="sr-only"
                            accept={documents.accept}
                            onChange={(e) => setReplaceFile(e.target.files?.[0] ?? null)}
                        />
                    </div>
                )}
                <div className="flex flex-wrap items-center gap-2">
                    <Button type="submit" size="sm" variant={destructive ? 'destructive' : 'default'} disabled={busy || reason.trim().length < MIN_REASON || (needsFile && !replaceFile)}>
                        {busy ? 'Working...' : button}
                    </Button>
                    <Button type="button" size="sm" variant="ghost" disabled={busy} onClick={closeForms}>
                        Cancel
                    </Button>
                </div>
            </form>
        );
    }

    return (
        <section className="space-y-4 rounded-md border p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h3 className="text-base font-medium">Documents</h3>
                    <p className="text-sm text-muted-foreground">
                        {checklist.required_done} of {checklist.required_total} required files uploaded.
                        {documents.pdf_mode === 'when_complete' ? ' The files join the PO\'s combined PDF when the delivery is complete.' : " Each file joins the PO's combined PDF as soon as it is uploaded."}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {documents.can_view_combined && (
                        <Button asChild size="sm" variant="outline">
                            <a href={`/purchase-orders/${poId}/documents/consolidated`} target="_blank" rel="noopener noreferrer">
                                <ExternalLink className="size-4" /> Combined PDF of the PO
                            </a>
                        </Button>
                    )}
                    {documents.can_complete && (
                        <Button type="button" size="sm" disabled={busy} onClick={() => router.post(`${base}/complete`, {}, visit)}>
                            <CheckCircle2 className="size-4" /> Mark complete
                        </Button>
                    )}
                    {documents.can_reopen && (
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={busy}
                            onClick={() => {
                                closeForms();
                                setReopening(true);
                            }}
                        >
                            <RotateCcw className="size-4" /> Reopen for uploading
                        </Button>
                    )}
                </div>
            </div>

            {complete && <p className="text-sm text-muted-foreground">All required files are uploaded and the delivery is marked complete. To add a file, reopen it for uploading first.</p>}
            {documents.locked_reason && !complete && <p className="text-sm text-muted-foreground">{documents.locked_reason}</p>}
            {actionError && <p className="text-sm text-destructive">{actionError}</p>}

            {/* The checklist: which required files are there, which are missing, by name. */}
            <ul className="grid gap-2 sm:grid-cols-2">
                {checklist.items.map((item) => {
                    const missing = item.required && item.count === 0;

                    return (
                        <li key={item.key} className={`flex items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm ${missing ? 'border-destructive/40 bg-destructive/5' : ''}`}>
                            <span className="min-w-0 break-words">
                                {item.label}
                                <span className="ml-2 text-xs text-muted-foreground">{item.required ? 'Required' : 'Optional'}</span>
                            </span>
                            {missing ? <Badge variant="outline">Missing</Badge> : item.count > 0 ? <Badge variant="secondary">{item.count} uploaded</Badge> : <span className="text-xs text-muted-foreground">None</span>}
                        </li>
                    );
                })}
            </ul>
            {!checklist.complete && !voided && <p className="text-sm text-destructive">Missing: {checklist.missing.join(', ')}.</p>}

            {documents.can_upload && (
                <PoDocumentUploadForm
                    postUrl={base}
                    types={documents.types}
                    accept={documents.accept}
                    maxMb={documents.max_mb}
                    maxFiles={documents.max_files}
                    maxTotalMb={documents.max_total_mb}
                    isDraft={false}
                />
            )}

            {reopening && renderReasonForm('Reopen this delivery for uploading', 'The delivery goes back to "For uploading" so a file can be added or corrected. Your reason is kept in the audit log.', 'Reopen delivery', false, false)}

            {rows.length === 0 ? (
                <p className="text-sm text-muted-foreground">No files uploaded yet.</p>
            ) : (
                <ol className="space-y-2">
                    {rows.map((d) => {
                        const active = d.status === 'active';
                        const isOpen = documents.can_view && previewId === d.id;
                        const isCorrecting = correction?.id === d.id;

                        return (
                            <li key={d.id} className={`space-y-3 rounded-md border p-3 ${isOpen ? 'border-primary/60 bg-muted/20' : ''}`}>
                                <div className="flex flex-wrap items-start gap-3">
                                    <div className="min-w-14 rounded-md bg-muted px-2 py-1 text-center text-xs font-medium tabular-nums">
                                        <span className="block text-muted-foreground">PO PDF page</span>
                                        {pagesLabel(d)}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="mb-1 flex flex-wrap items-center gap-2">
                                            {/* The type is the first thing to read: a clear label, so nobody mistakes a DR for an OR or an IAR. */}
                                            <span className="rounded-md bg-primary px-2.5 py-1 text-sm font-semibold tracking-wide text-primary-foreground">{d.type}</span>
                                        </div>
                                        <p className="text-sm font-medium break-words">{d.original_name}</p>
                                        <p className="text-xs text-muted-foreground">
                                            {formatSize(d.size_bytes)} · {orDash(d.uploaded_by)} · {orDash(d.uploaded_at)}
                                        </p>
                                        {d.void_reason && <p className="mt-0.5 text-xs text-muted-foreground">Void: {d.void_reason}</p>}
                                    </div>
                                    {active ? <Badge variant="secondary">Active</Badge> : <Badge variant="outline">Void</Badge>}
                                </div>

                                <div className="flex flex-wrap items-center gap-1">
                                    {documents.can_view && (
                                        <Button type="button" size="sm" variant={isOpen ? 'default' : 'outline'} onClick={() => setPreviewId(isOpen ? null : d.id)}>
                                            <Eye className="size-4" /> {isOpen ? 'Hide' : 'View'}
                                        </Button>
                                    )}
                                    {documents.can_void && active && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            disabled={busy}
                                            onClick={() => {
                                                closeForms();
                                                setCorrection({ kind: 'void', id: d.id, name: d.original_name });
                                            }}
                                        >
                                            <Ban className="size-4" /> Void
                                        </Button>
                                    )}
                                    {documents.can_replace && active && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            disabled={busy}
                                            onClick={() => {
                                                closeForms();
                                                setCorrection({ kind: 'replace', id: d.id, name: d.original_name });
                                            }}
                                        >
                                            <RefreshCw className="size-4" /> Replace
                                        </Button>
                                    )}
                                </div>

                                {isCorrecting &&
                                    correction &&
                                    (correction.kind === 'void'
                                        ? renderReasonForm(`Void: ${correction.name}`, complete ? 'The file stays on record with your reason, but its pages leave the combined PDF. If a required file is then missing, the delivery goes back to "For uploading".' : 'The file stays on record with your reason, but its pages leave the combined PDF.', 'Void file', false, true)
                                        : renderReasonForm(`Replace: ${correction.name}`, 'The old file stays on record with your reason, and the new file takes its place in the combined PDF.', 'Replace file', true, false))}

                                {isOpen && (
                                    <div className="space-y-2 border-t pt-3">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <p className="text-sm font-medium">Original file</p>
                                            <div className="flex flex-wrap gap-2">
                                                <Button asChild size="sm" variant="outline">
                                                    <a href={`${base}/${d.id}/file`} target="_blank" rel="noopener noreferrer">
                                                        <ExternalLink className="size-4" /> Open in new tab
                                                    </a>
                                                </Button>
                                                <Button type="button" size="sm" variant="ghost" onClick={() => setPreviewId(null)}>
                                                    <X className="size-4" /> Close
                                                </Button>
                                            </div>
                                        </div>
                                        <iframe key={d.id} src={`${base}/${d.id}/file`} title={`File ${d.original_name}`} className="h-[70vh] w-full rounded-md border bg-muted" />
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ol>
            )}
        </section>
    );
}