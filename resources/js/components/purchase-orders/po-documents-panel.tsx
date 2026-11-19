import { router, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Ban, Download, ExternalLink, Eye, FolderOpen, RefreshCw, Trash2, Upload, X } from 'lucide-react';
import { useRef, useState, type FormEvent } from 'react';
import { orDash } from '@/components/purchase-orders/po-format';
import ResponsiveList from '@/components/responsive-list';
import type { ListColumn } from '@/components/responsive-list';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

// One line of the page index. page_from / page_to come from our own records (the scans have no text layer).
export type PoDocumentRow = {
    id: number;
    sort_order: number;
    type: string;
    original_name: string;
    size_bytes: number;
    status: string;
    page_from: number | null;
    page_to: number | null;
    void_reason: string | null;
    uploaded_by: string | null;
    uploaded_at: string | null;
};

// The combined PDF of the PO. "stale" = the documents changed but the last rebuild failed, so the viewer shows the previous version.
export type ConsolidatedInfo = {
    exists: boolean;
    pages: number | null;
    built_at: string | null;
    stale: boolean;
    error: string | null;
};

// The server decides every can_* flag (PurchaseOrderPolicy + PoDocumentRule); the page only draws what it is told.
export type PoDocumentsData = {
    can_upload: boolean;
    can_submit: boolean;
    can_remove: boolean;
    can_reorder: boolean;
    can_view: boolean; // the single original files (Admin, MMS Head, uploaders while the PO is open)
    can_view_consolidated: boolean;
    can_void: boolean;
    can_replace: boolean;
    can_rebuild: boolean;
    locked_reason: string | null;
    accept: string;
    max_mb: string;
    consolidated: ConsolidatedInfo;
    rows: PoDocumentRow[];
};

type Props = { poId: number; isDraft: boolean; documents: PoDocumentsData };

type Confirm = { kind: 'remove'; id: number } | null;

// "void" and "replace" both ask for a reason; "replace" also asks for the new file.
type Correction = { kind: 'void' | 'replace'; id: number; name: string } | null;

function formatSize(bytes: number): string {
    if (bytes >= 1024 * 1024) {
        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    }

    return `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

function pagesLabel(d: PoDocumentRow): string {
    if (d.page_from === null || d.page_to === null) {
        return '—';
    }

    return d.page_from === d.page_to ? `${d.page_from}` : `${d.page_from}–${d.page_to}`;
}

const MIN_REASON = 5;

export default function PoDocumentsPanel({ poId, isDraft, documents }: Props) {
    // React state, all of it only for this tab:
    // - the chosen file and any server error live in the Inertia form;
    // - "inputKey" changes after a successful upload so the file box empties itself;
    // - "viewerPage" is the page the combined PDF opens at (null = the first page);
    // - "previewId" is one ORIGINAL file shown in a second frame (null = none; only for people who may open originals);
    // - "confirm" is the Remove button waiting for a second click ("Yes, remove");
    // - "correction" is the void / replace form that is open, with its reason and (for replace) the new file;
    // - "busy" blocks the buttons while a request runs; "actionError" is the server's message for a button.
    // (Submit to Reviewer is not here any more: it is the header button of the PO page, PoSubmitButton.)
    const form = useForm<{ file: File | null }>({ file: null });
    const [inputKey, setInputKey] = useState(0);
    // The real <input type="file"> is hidden; the visible "Choose file" button clicks it through this ref.
    const fileInput = useRef<HTMLInputElement>(null);
    const replaceInput = useRef<HTMLInputElement>(null);
    const [viewerPage, setViewerPage] = useState<number | null>(null);
    const [previewId, setPreviewId] = useState<number | null>(null);
    const [confirm, setConfirm] = useState<Confirm>(null);
    const [correction, setCorrection] = useState<Correction>(null);
    const [reason, setReason] = useState('');
    const [replaceFile, setReplaceFile] = useState<File | null>(null);
    const [replaceKey, setReplaceKey] = useState(0);
    const [busy, setBusy] = useState(false);
    const [actionError, setActionError] = useState<string | null>(null);
    const [seenCount, setSeenCount] = useState(documents.rows.length);

    const base = `/purchase-orders/${poId}/documents`;
    const rows = documents.rows;
    const { consolidated } = documents;

    // When a new file shows up in the list, open the combined PDF at its first page so the person can check it at once.
    // (React allows this "adjust state while rendering" pattern when the data comes from props.)
    if (rows.length !== seenCount) {
        setSeenCount(rows.length);

        if (rows.length > seenCount) {
            const newest = [...rows].reverse().find((row) => row.status === 'active' && row.page_from !== null);
            setViewerPage(newest?.page_from ?? null);
        }
    }

    const previewRow = rows.find((row) => row.id === previewId) ?? null;
    const activeIds = rows.filter((row) => row.status === 'active').map((row) => row.id);

    // Shared by every button that talks to the server. A server message for the button comes back as errors.form
    // (the void / replace form can also get errors.reason and errors.file).
    const visit = {
        preserveScroll: true,
        onStart: () => {
            setBusy(true);
            setActionError(null);
        },
        onError: (errors: Record<string, string>) =>
            setActionError(errors.form ?? errors.reason ?? errors.file ?? errors.direction ?? 'The action could not be completed.'),
        onSuccess: () => setConfirm(null),
        onFinish: () => setBusy(false),
    };

    function closeCorrection() {
        setCorrection(null);
        setReason('');
        setReplaceFile(null);
        setReplaceKey((key) => key + 1);
    }

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

    function submitCorrection(event: FormEvent) {
        event.preventDefault();

        if (!correction || reason.trim().length < MIN_REASON) {
            return;
        }

        const done = {
            ...visit,
            onSuccess: () => {
                visit.onSuccess();
                closeCorrection();
            },
        };

        if (correction.kind === 'void') {
            router.post(`${base}/${correction.id}/void`, { reason: reason.trim() }, done);

            return;
        }

        if (!replaceFile) {
            return;
        }

        // The new file travels as a normal form upload, like the first upload.
        router.post(`${base}/${correction.id}/replace`, { reason: reason.trim(), file: replaceFile }, { ...done, forceFormData: true });
    }

    const columns: ListColumn<PoDocumentRow>[] = [
        { key: 'pages', header: 'Pages', className: 'tabular-nums whitespace-nowrap', cell: (d) => pagesLabel(d) },
        { key: 'type', header: 'Type', card: 'summary', cell: (d) => d.type },
        {
            key: 'original_name',
            header: 'File',
            card: 'title',
            className: 'break-words',
            cell: (d) => (
                <>
                    {d.original_name}
                    {d.void_reason && <span className="mt-0.5 block text-xs text-muted-foreground">Void: {d.void_reason}</span>}
                </>
            ),
        },
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

    const canJump = documents.can_view_consolidated && consolidated.exists;

    const actionColumn: ListColumn<PoDocumentRow> = {
        key: 'actions',
        header: 'Actions',
        cell: (d) => {
            const isActive = d.status === 'active';
            const confirmingRemove = confirm?.kind === 'remove' && confirm.id === d.id;

            return (
                <div className="flex flex-wrap items-center gap-1">
                    {canJump && isActive && d.page_from !== null && (
                        <Button type="button" size="sm" variant="outline" onClick={() => setViewerPage(d.page_from)}>
                            Go to page {d.page_from}
                        </Button>
                    )}

                    {documents.can_view && (
                        <Button type="button" size="sm" variant="outline" onClick={() => setPreviewId(previewId === d.id ? null : d.id)}>
                            <Eye className="size-4" /> {previewId === d.id ? 'Hide original' : 'Original'}
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

                    {documents.can_void && isActive && (
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={busy}
                            onClick={() => {
                                closeCorrection();
                                setCorrection({ kind: 'void', id: d.id, name: d.original_name });
                            }}
                        >
                            <Ban className="size-4" /> Void
                        </Button>
                    )}

                    {documents.can_replace && isActive && (
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={busy}
                            onClick={() => {
                                closeCorrection();
                                setCorrection({ kind: 'replace', id: d.id, name: d.original_name });
                            }}
                        >
                            <RefreshCw className="size-4" /> Replace
                        </Button>
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

    const hasActions = canJump || documents.can_view || documents.can_reorder || documents.can_remove || documents.can_void || documents.can_replace;
    const viewerUrl = `${base}/consolidated`;

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
                        <Upload className="size-4" /> {form.processing ? 'Uploading and combining...' : 'Upload'}
                    </Button>
                </form>
            )}

            {actionError && <p className="text-sm text-destructive">{actionError}</p>}

            {/* The documents changed but the last rebuild failed: the viewer shows the previous version. Said to everyone, because the Reviewer must not trust an out-of-date PDF. */}
            {consolidated.stale && (
                <div className="space-y-2 rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm">
                    <p className="font-medium">The combined PDF is out of date</p>
                    <p className="text-muted-foreground">
                        The documents were changed, but the combined PDF could not be rebuilt
                        {consolidated.error ? ` (${consolidated.error.replace(/\.$/, '')})` : ''}. The viewer shows the previous version
                        {consolidated.built_at ? `, built on ${consolidated.built_at}` : ''}. The page numbers in the list below follow the latest files.
                    </p>
                    {documents.can_rebuild && (
                        <Button type="button" size="sm" disabled={busy} onClick={() => router.post(`${base}/rebuild`, {}, visit)}>
                            <RefreshCw className="size-4" /> {busy ? 'Rebuilding...' : 'Rebuild combined PDF'}
                        </Button>
                    )}
                </div>
            )}

            {/* The combined PDF: every active document, in page order. Pages keep their own size, so the browser's PDF viewer scrolls through them. */}
            {documents.can_view_consolidated && (
                <section className="space-y-2 rounded-md border p-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="min-w-0">
                            <h3 className="text-base font-medium">Combined PDF</h3>
                            {consolidated.exists && (
                                <p className="text-sm text-muted-foreground">
                                    {consolidated.pages ?? 0} {consolidated.pages === 1 ? 'page' : 'pages'}
                                    {consolidated.built_at ? ` · built ${consolidated.built_at}` : ''}
                                </p>
                            )}
                        </div>
                        {consolidated.exists && (
                            <div className="flex flex-wrap gap-2">
                                <Button asChild size="sm" variant="outline">
                                    <a href={viewerUrl} target="_blank" rel="noopener noreferrer">
                                        <ExternalLink className="size-4" /> Open in new tab
                                    </a>
                                </Button>
                                <Button asChild size="sm" variant="outline">
                                    <a href={`${viewerUrl}?download=1`}>
                                        <Download className="size-4" /> Download
                                    </a>
                                </Button>
                                {documents.can_rebuild && !consolidated.stale && (
                                    <Button type="button" size="sm" variant="ghost" disabled={busy} onClick={() => router.post(`${base}/rebuild`, {}, visit)}>
                                        <RefreshCw className="size-4" /> Rebuild
                                    </Button>
                                )}
                            </div>
                        )}
                    </div>

                    {consolidated.exists ? (
                        <>
                            <iframe
                                key={`${consolidated.built_at ?? 'none'}-${viewerPage ?? 1}`}
                                src={`${viewerUrl}#page=${viewerPage ?? 1}&view=FitH`}
                                title="Combined PDF of all documents"
                                className="h-[75vh] w-full rounded-md border bg-muted"
                            />
                            <p className="text-xs text-muted-foreground">If your phone shows only the first page here, use Open in new tab.</p>
                        </>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            {rows.some((row) => row.status === 'active') ? 'The combined PDF is not built yet.' : 'No documents uploaded yet.'}
                        </p>
                    )}
                </section>
            )}

            {correction && (
                <form onSubmit={submitCorrection} className="space-y-3 rounded-md border border-amber-500/40 bg-amber-500/10 p-4">
                    <p className="min-w-0 break-words text-sm font-medium">
                        {correction.kind === 'void' ? 'Void' : 'Replace'}: {correction.name}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {correction.kind === 'void'
                            ? 'The document stays on record with your reason, but its pages leave the combined PDF.'
                            : 'The old document stays on record with your reason, and the new file takes its place in the combined PDF.'}
                    </p>
                    <div className="space-y-1.5">
                        <Label htmlFor="po-doc-reason">Reason (required)</Label>
                        <textarea
                            id="po-doc-reason"
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            maxLength={500}
                            rows={3}
                            className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                            placeholder="For example: wrong supplier's invoice, scanned upside down, missing pages."
                        />
                    </div>
                    {correction.kind === 'replace' && (
                        <div className="space-y-1.5">
                            <Label htmlFor="po-doc-replace-file">New file</Label>
                            <div className="flex flex-wrap items-center gap-3 rounded-md border-2 border-dashed p-3">
                                <Button type="button" variant="outline" onClick={() => replaceInput.current?.click()}>
                                    <FolderOpen className="size-4" /> Choose file
                                </Button>
                                <span className="min-w-0 break-words text-sm text-muted-foreground">{replaceFile ? replaceFile.name : 'No file chosen'}</span>
                            </div>
                            <input
                                ref={replaceInput}
                                key={replaceKey}
                                id="po-doc-replace-file"
                                type="file"
                                className="sr-only"
                                accept={documents.accept}
                                onChange={(e) => setReplaceFile(e.target.files?.[0] ?? null)}
                            />
                        </div>
                    )}
                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            type="submit"
                            size="sm"
                            variant={correction.kind === 'void' ? 'destructive' : 'default'}
                            disabled={busy || reason.trim().length < MIN_REASON || (correction.kind === 'replace' && !replaceFile)}
                        >
                            {busy ? 'Working...' : correction.kind === 'void' ? 'Void document' : 'Replace document'}
                        </Button>
                        <Button type="button" size="sm" variant="ghost" disabled={busy} onClick={closeCorrection}>
                            Cancel
                        </Button>
                    </div>
                </form>
            )}

            {/* The page index: which document is on which pages. For people who only read, it is the whole list; the buttons appear for those who may use them. */}
            <section className="space-y-2">
                <h3 className="text-base font-medium">Page index</h3>
                <ResponsiveList
                    columns={hasActions ? [...columns, actionColumn] : columns}
                    rows={rows}
                    rowKey={(d) => d.id}
                    emptyMessage="No documents uploaded yet."
                />
            </section>

            {previewRow && documents.can_view && (
                <div className="space-y-2 rounded-md border p-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="min-w-0 break-words text-sm font-medium">Original file: {previewRow.original_name}</p>
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
                        title={`Original file ${previewRow.original_name}`}
                        className="h-[70vh] w-full rounded-md border bg-muted"
                    />
                </div>
            )}

            {/* Hint only. The Submit to Reviewer button is in the page header (PoSubmitButton), on every tab. */}
            {documents.can_submit && (
                <p className="text-sm text-muted-foreground">
                    Ready to send this PO to the PO Reviewer? Check the combined PDF above, then use Submit to Reviewer at the top of the page. After you submit,
                    the PO becomes Uploaded and a file can no longer be removed (a wrong document is voided with a reason).
                </p>
            )}
        </div>
    );
}