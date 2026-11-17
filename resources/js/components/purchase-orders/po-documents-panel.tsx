import { router, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Eye, FileText, FolderOpen, Send, Trash2, Upload, X } from 'lucide-react';
import { useRef, useState, type FormEvent } from 'react';
import { orDash } from '@/components/purchase-orders/po-format';
import ResponsiveList from '@/components/responsive-list';
import type { ListColumn } from '@/components/responsive-list';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

export type PoDocumentRow = {
    id: number;
    sort_order: number;
    type: string;
    original_name: string;
    size_bytes: number;
    status: string;
    uploaded_by: string | null;
    uploaded_at: string | null;
};

// The server decides every can_* flag (PurchaseOrderPolicy + PoDocumentRule); the page only draws what it is told.
export type PoDocumentsData = {
    can_upload: boolean;
    can_submit: boolean;
    can_remove: boolean;
    can_reorder: boolean;
    can_view: boolean;
    locked_reason: string | null;
    accept: string;
    max_mb: string;
    rows: PoDocumentRow[];
};

type Props = { poId: number; isDraft: boolean; documents: PoDocumentsData };

type Confirm = { kind: 'remove'; id: number } | { kind: 'submit' } | null;

function formatSize(bytes: number): string {
    if (bytes >= 1024 * 1024) {
        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    }

    return `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

const columns: ListColumn<PoDocumentRow>[] = [
    { key: 'sort_order', header: '#', className: 'tabular-nums', cell: (d) => d.sort_order },
    { key: 'original_name', header: 'File', card: 'title', className: 'break-words', cell: (d) => d.original_name },
    { key: 'type', header: 'Type', card: 'summary', cell: (d) => d.type },
    { key: 'size', header: 'Size', className: 'tabular-nums', cell: (d) => formatSize(d.size_bytes) },
    { key: 'uploaded_by', header: 'Uploaded by', cell: (d) => orDash(d.uploaded_by) },
    { key: 'uploaded_at', header: 'Uploaded on', cell: (d) => orDash(d.uploaded_at) },
    {
        key: 'status',
        header: 'Status',
        card: 'badge',
        cell: (d) => (d.status === 'void' ? <Badge variant="outline">Void</Badge> : <Badge variant="secondary">Active</Badge>),
    },
];

export default function PoDocumentsPanel({ poId, isDraft, documents }: Props) {
    // React state, all of it only for this tab:
    // - the chosen file and any server error live in the Inertia form;
    // - "inputKey" changes after a successful upload so the file box empties itself;
    // - "previewId" is the file shown in the preview frame (null = no preview);
    // - "confirm" is the button waiting for a second click ("Yes, remove" / "Yes, submit");
    // - "busy" blocks the buttons while a request runs; "actionError" is the server's message for a button.
    const form = useForm<{ file: File | null }>({ file: null });
    const [inputKey, setInputKey] = useState(0);
    // The real <input type="file"> is hidden; the visible "Choose file" button clicks it through this ref.
    const fileInput = useRef<HTMLInputElement>(null);
    const [previewId, setPreviewId] = useState<number | null>(null);
    const [confirm, setConfirm] = useState<Confirm>(null);
    const [busy, setBusy] = useState(false);
    const [actionError, setActionError] = useState<string | null>(null);
    const [seenCount, setSeenCount] = useState(documents.rows.length);

    const base = `/purchase-orders/${poId}/documents`;
    const rows = documents.rows;

    // When a new file shows up in the list, open it in the preview so the person can check it at once.
    // (React allows this "adjust state while rendering" pattern when the data comes from props.)
    if (rows.length !== seenCount) {
        setSeenCount(rows.length);

        if (documents.can_view && rows.length > seenCount) {
            setPreviewId(rows[rows.length - 1].id);
        }
    }

    const previewRow = rows.find((row) => row.id === previewId) ?? null;
    const activeIds = rows.filter((row) => row.status === 'active').map((row) => row.id);

    // Shared by every button that talks to the server. A server message for the button comes back as errors.form.
    const visit = {
        preserveScroll: true,
        onStart: () => {
            setBusy(true);
            setActionError(null);
        },
        onError: (errors: Record<string, string>) => setActionError(errors.form ?? errors.direction ?? 'The action could not be completed.'),
        onSuccess: () => setConfirm(null),
        onFinish: () => setBusy(false),
    };

    function submitFile(event: FormEvent) {
        event.preventDefault();

        if (!form.data.file) {
            return;
        }

        // forceFormData: a file cannot travel as JSON, so Inertia sends it as a normal form upload.
        form.post(base, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setInputKey((key) => key + 1);
            },
        });
    }

    const actionColumn: ListColumn<PoDocumentRow> = {
        key: 'actions',
        header: 'Actions',
        cell: (d) => {
            const isActive = d.status === 'active';
            const confirmingRemove = confirm?.kind === 'remove' && confirm.id === d.id;

            return (
                <div className="flex flex-wrap items-center gap-1">
                    {documents.can_view && (
                        <Button type="button" size="sm" variant="outline" onClick={() => setPreviewId(previewId === d.id ? null : d.id)}>
                            <Eye className="size-4" /> {previewId === d.id ? 'Hide' : 'View'}
                        </Button>
                    )}

                    {documents.can_reorder && isActive && (
                        <>
                            <Button
                                type="button"
                                size="icon"
                                variant="outline"
                                aria-label={`Move ${d.original_name} up`}
                                disabled={busy || activeIds[0] === d.id}
                                onClick={() => router.post(`${base}/${d.id}/move`, { direction: 'up' }, visit)}
                            >
                                <ArrowUp className="size-4" />
                            </Button>
                            <Button
                                type="button"
                                size="icon"
                                variant="outline"
                                aria-label={`Move ${d.original_name} down`}
                                disabled={busy || activeIds[activeIds.length - 1] === d.id}
                                onClick={() => router.post(`${base}/${d.id}/move`, { direction: 'down' }, visit)}
                            >
                                <ArrowDown className="size-4" />
                            </Button>
                        </>
                    )}

                    {documents.can_remove &&
                        (confirmingRemove ? (
                            <span className="flex flex-wrap items-center gap-1 text-sm">
                                Remove this file?
                                <Button type="button" size="sm" variant="destructive" disabled={busy} onClick={() => router.delete(`${base}/${d.id}`, visit)}>
                                    Yes, remove
                                </Button>
                                <Button type="button" size="sm" variant="ghost" disabled={busy} onClick={() => setConfirm(null)}>
                                    Cancel
                                </Button>
                            </span>
                        ) : (
                            <Button type="button" size="sm" variant="outline" disabled={busy} onClick={() => setConfirm({ kind: 'remove', id: d.id })}>
                                <Trash2 className="size-4" /> Remove
                            </Button>
                        ))}
                </div>
            );
        },
    };

    const hasActions = documents.can_view || documents.can_reorder || documents.can_remove;
    const submitting = confirm?.kind === 'submit';

    return (
        <div className="space-y-4">
            {documents.locked_reason && <p className="text-sm text-muted-foreground">{documents.locked_reason}</p>}

            {documents.can_upload && (
                <form onSubmit={submitFile} className="space-y-3 rounded-md border p-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="po-bundle-file">Upload PO Bundle</Label>
                        <div className="flex flex-wrap items-center gap-3 rounded-md border-2 border-dashed p-3">
                            <Button type="button" variant="outline" onClick={() => fileInput.current?.click()}>
                                <FolderOpen className="size-4" /> Choose file
                            </Button>
                            <span className="min-w-0 break-words text-sm text-muted-foreground">{form.data.file ? form.data.file.name : 'No file chosen'}</span>
                        </div>
                        <input
                            ref={fileInput}
                            key={inputKey}
                            id="po-bundle-file"
                            type="file"
                            className="sr-only"
                            accept={documents.accept}
                            onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)}
                        />
                        <p className="text-sm text-muted-foreground">
                            PDF, JPG or PNG, up to {documents.max_mb} MB per file. A new file goes after the earlier ones; use the arrows to change the order.
                            {isDraft && ' The PO stays a draft, and the PO Reviewer cannot see it, until you click Submit to Reviewer.'}
                        </p>
                        {form.errors.file && <p className="text-sm text-destructive">{form.errors.file}</p>}
                    </div>
                    <Button type="submit" size="sm" disabled={form.processing || !form.data.file}>
                        <Upload className="size-4" /> {form.processing ? 'Uploading...' : 'Upload'}
                    </Button>
                </form>
            )}

            {actionError && <p className="text-sm text-destructive">{actionError}</p>}

            <ResponsiveList
                columns={hasActions ? [...columns, actionColumn] : columns}
                rows={rows}
                rowKey={(d) => d.id}
                emptyMessage="No documents uploaded yet."
            />

            {previewRow && documents.can_view && (
                <div className="space-y-2 rounded-md border p-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="min-w-0 break-words text-sm font-medium">Preview: {previewRow.original_name}</p>
                        <div className="flex flex-wrap gap-2">
                            <Button asChild size="sm" variant="outline">
                                <a href={`${base}/${previewRow.id}/file`} target="_blank" rel="noopener noreferrer">
                                    Open in new tab
                                </a>
                            </Button>
                            <Button type="button" size="sm" variant="ghost" onClick={() => setPreviewId(null)}>
                                <X className="size-4" /> Close
                            </Button>
                        </div>
                    </div>
                    <iframe
                        key={previewRow.id}
                        src={`${base}/${previewRow.id}/file`}
                        title={`Preview of ${previewRow.original_name}`}
                        className="h-[70vh] w-full rounded-md border bg-muted"
                    />
                </div>
            )}

            {documents.can_submit && (
                <div className="space-y-2 rounded-md border border-amber-500/40 bg-amber-500/10 p-4">
                    <p className="text-sm font-medium">Ready to send this PO to the PO Reviewer?</p>
                    <p className="text-sm text-muted-foreground">
                        Check the files above with View. After you submit, the PO becomes Uploaded and a file can no longer be removed (a wrong document is
                        voided with a reason).
                    </p>
                    {submitting ? (
                        <div className="flex flex-wrap items-center gap-2 text-sm">
                            Submit this PO now?
                            <Button type="button" size="sm" disabled={busy} onClick={() => router.post(`${base}/submit`, {}, visit)}>
                                <Send className="size-4" /> {busy ? 'Submitting...' : 'Yes, submit'}
                            </Button>
                            <Button type="button" size="sm" variant="ghost" disabled={busy} onClick={() => setConfirm(null)}>
                                Cancel
                            </Button>
                        </div>
                    ) : (
                        <Button type="button" size="sm" disabled={busy} onClick={() => setConfirm({ kind: 'submit' })}>
                            <Send className="size-4" /> Submit to Reviewer
                        </Button>
                    )}
                </div>
            )}

            {rows.length > 0 && (
                <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
                    <FileText className="size-4" /> The consolidated PDF of all documents comes in the next step.
                </p>
            )}
        </div>
    );
}