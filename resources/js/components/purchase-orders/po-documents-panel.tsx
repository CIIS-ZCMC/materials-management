import { router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Ban, Download, ExternalLink, Eye, FolderOpen, RefreshCw, Trash2, X } from 'lucide-react';
import { useRef, useState, type FormEvent } from 'react';
import PoDocumentTypesPanel from '@/components/purchase-orders/po-document-types-panel';
import type { ChecklistData } from '@/components/purchase-orders/po-document-types-panel';
import PoDocumentUploadForm from '@/components/purchase-orders/po-document-upload-form';
import type { UploadType } from '@/components/purchase-orders/po-document-upload-form';
import { orDash } from '@/components/purchase-orders/po-format';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

// One line of the page index. page_from / page_to come from our own records (the scans have no text layer).
export type PoDocumentRow = {
    id: number;
    sort_order: number;
    type: string; // the label shown on screen
    type_key: string; // the stored string (step 4.2c), used by the type filter
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
    max_files: number; // most files in one upload (step 4.2c)
    max_total_mb: number; // most MB in one upload
    types: UploadType[]; // what the upload form offers (stage "po" of the list in config/mms.php)
    checklist: ChecklistData; // information only, never blocks Submit
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

// The "Go to page N" buttons of the page index. Set to false to remove them (the Pages column stays).
const SHOW_GO_TO_PAGE = true;

export default function PoDocumentsPanel({ poId, isDraft, documents }: Props) {
    // React state, all of it only for this tab:
    // - the upload form (types, files, errors) keeps its own state in PoDocumentUploadForm;
    // - "typeFilter" is the type picked in the filter ("all" = everything); it only narrows the page index, the combined PDF stays whole;
    // - "addTo" is the type whose "Add files" form is open (null = none);
    // - "viewerPage" is the page the combined PDF opens at (null = the first page); "viewerNonce" goes up on every "Go to page"
    //   click so the frame reloads even when the same page is clicked again (or the person scrolled inside the frame);
    //   "viewerSection" is the Combined PDF box, scrolled into view when a "Go to page" is clicked;
    // - "previewId" is one ORIGINAL file shown in a second frame (null = none; only for people who may open originals);
    // - "confirm" is the Remove button waiting for a second click ("Yes, remove");
    // - "correction" is the void / replace form that is open, with its reason and (for replace) the new file;
    // - "busy" blocks the buttons while a request runs; "actionError" is the server's message for a button.
    // (Submit to Reviewer is not here any more: it is the header button of the PO page, PoSubmitButton.)
    const [typeFilter, setTypeFilter] = useState('all');
    const [addTo, setAddTo] = useState<string | null>(null);
    // The real <input type="file"> of the replace form is hidden; the visible "Choose file" button clicks it through this ref.
    const replaceInput = useRef<HTMLInputElement>(null);
    const [viewerPage, setViewerPage] = useState<number | null>(null);
    const [viewerNonce, setViewerNonce] = useState(0);
    const viewerSection = useRef<HTMLElement>(null);
    const [previewId, setPreviewId] = useState<number | null>(null);
    const [confirm, setConfirm] = useState<Confirm>(null);
    const [correction, setCorrection] = useState<Correction>(null);
    const [reason, setReason] = useState('');
    const [replaceFile, setReplaceFile] = useState<File | null>(null);
    const [replaceKey, setReplaceKey] = useState(0);
    const [busy, setBusy] = useState(false);
    const [actionError, setActionError] = useState<string | null>(null);
    const [seenIds, setSeenIds] = useState<number[]>(documents.rows.map((row) => row.id));

    const base = `/purchase-orders/${poId}/documents`;
    const rows = documents.rows;
    const { consolidated } = documents;

    // When new files show up in the list, open the combined PDF at the first page of the first new one so the person can check it at once.
    // (React allows this "adjust state while rendering" pattern when the data comes from props.)
    const known = new Set(seenIds);
    const added = rows.filter((row) => !known.has(row.id));

    if (added.length > 0 || rows.length !== seenIds.length) {
        setSeenIds(rows.map((row) => row.id));

        if (added.length > 0) {
            const first = added.find((row) => row.status === 'active' && row.page_from !== null);
            setViewerPage(first?.page_from ?? null);
        }
    }

    const previewRow = rows.find((row) => row.id === previewId) ?? null;
    const activeIds = rows.filter((row) => row.status === 'active').map((row) => row.id);

    // The type filter (step 4.2c). The count is the number of rows the list will show for that type.
    // A filter whose type has no rows any more (the last file was removed) falls back to "all".
    const typeCounts = new Map<string, { label: string; count: number }>();

    for (const row of rows) {
        typeCounts.set(row.type_key, { label: row.type, count: (typeCounts.get(row.type_key)?.count ?? 0) + 1 });
    }

    const typeOptions = [...typeCounts.entries()].sort((a, b) => a[1].label.localeCompare(b[1].label));
    const activeFilter = typeFilter !== 'all' && typeCounts.has(typeFilter) ? typeFilter : 'all';
    const shownRows = activeFilter === 'all' ? rows : rows.filter((row) => row.type_key === activeFilter);
    // The server moves a file past its neighbour in the WHOLE list, so with a filter on (neighbours hidden) the arrows are off.
    const reorderLocked = activeFilter !== 'all';

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

    // "Go to page N": reload the combined PDF at that page and bring the viewer into sight (it sits above the list).
    function goToPage(page: number | null) {
        if (page === null) {
            return;
        }

        setViewerPage(page);
        setViewerNonce((n) => n + 1);
        viewerSection.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // Move a file. When the server has saved it and rebuilt the combined PDF, the viewer opens at the file's NEW first page,
    // so the change can be checked at once. No scrolling here: the person is working in the list.
    function move(d: PoDocumentRow, direction: 'up' | 'down') {
        router.post(`${base}/${d.id}/move`, { direction }, {
            ...visit,
            onSuccess: (page) => {
                visit.onSuccess();

                const next = (page.props as unknown as { purchaseOrder?: { documents?: PoDocumentsData } }).purchaseOrder?.documents?.rows.find(
                    (row) => row.id === d.id,
                );

                if (next?.page_from) {
                    setViewerPage(next.page_from);
                }
            },
        });
    }

    function closeCorrection() {
        setCorrection(null);
        setReason('');
        setReplaceFile(null);
        setReplaceKey((key) => key + 1);
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


    const canJump = SHOW_GO_TO_PAGE && documents.can_view_consolidated && consolidated.exists;
    const viewerUrl = `${base}/consolidated`;

    // The buttons of one row of the page index (same buttons and rules as before, only the place changed).
    function renderActions(d: PoDocumentRow) {
        const isActive = d.status === 'active';
        const confirmingRemove = confirm?.kind === 'remove' && confirm.id === d.id;

        return (
            <div className="flex flex-wrap items-center gap-1">
                {canJump && isActive && d.page_from !== null && (
                    <Button type="button" size="sm" variant="outline" onClick={() => goToPage(d.page_from)}>
                        Go to page {d.page_from}
                    </Button>
                )}

                {documents.can_view && (
                    <Button type="button" size="sm" variant={previewId === d.id ? 'default' : 'outline'} onClick={() => setPreviewId(previewId === d.id ? null : d.id)}>
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
                            disabled={busy || reorderLocked || activeIds[0] === d.id}
                            onClick={() => move(d, 'up')}
                        >
                            <ArrowUp className="size-4" />
                        </Button>
                        <Button
                            type="button"
                            size="icon"
                            variant="outline"
                            aria-label={`Move ${d.original_name} down`}
                            disabled={busy || reorderLocked || activeIds[activeIds.length - 1] === d.id}
                            onClick={() => move(d, 'down')}
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
    }

    // The void / replace form. It opens INSIDE the row it belongs to, so the person never loses sight of which file it is about.
    function renderCorrectionForm(c: NonNullable<Correction>) {
        return (
            <form onSubmit={submitCorrection} className="space-y-3 rounded-md border border-amber-500/40 bg-amber-500/10 p-4">
                <p className="min-w-0 break-words text-sm font-medium">
                    {c.kind === 'void' ? 'Void' : 'Replace'}: {c.name}
                </p>
                <p className="text-sm text-muted-foreground">
                    {c.kind === 'void'
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
                {c.kind === 'replace' && (
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
                        variant={c.kind === 'void' ? 'destructive' : 'default'}
                        disabled={busy || reason.trim().length < MIN_REASON || (c.kind === 'replace' && !replaceFile)}
                    >
                        {busy ? 'Working...' : c.kind === 'void' ? 'Void document' : 'Replace document'}
                    </Button>
                    <Button type="button" size="sm" variant="ghost" disabled={busy} onClick={closeCorrection}>
                        Cancel
                    </Button>
                </div>
            </form>
        );
    }

    return (
        <div className="space-y-4">
            {documents.locked_reason && <p className="text-sm text-muted-foreground">{documents.locked_reason}</p>}

            {documents.can_upload && (
                <PoDocumentUploadForm
                    poId={poId}
                    types={documents.types}
                    accept={documents.accept}
                    maxMb={documents.max_mb}
                    maxFiles={documents.max_files}
                    maxTotalMb={documents.max_total_mb}
                    isDraft={isDraft}
                />
            )}

            {actionError && <p className="text-sm text-destructive">{actionError}</p>}

            {/* 1. Document types: the checklist (sample list) and the type filter. Information only; Submit never waits for it. */}
            <PoDocumentTypesPanel
                checklist={documents.checklist}
                activeFilter={activeFilter}
                onFilter={(key) => setTypeFilter(activeFilter === key ? 'all' : key)}
                canAdd={documents.can_upload}
                onAddFiles={(key) => setAddTo(key)}
            />

            {/* "Add files" of one type: the same form with one row and the type fixed. */}
            {addTo && documents.can_upload && (
                <PoDocumentUploadForm
                    key={addTo}
                    poId={poId}
                    types={documents.types}
                    accept={documents.accept}
                    maxMb={documents.max_mb}
                    maxFiles={documents.max_files}
                    maxTotalMb={documents.max_total_mb}
                    isDraft={isDraft}
                    lockedType={addTo}
                    onDone={() => setAddTo(null)}
                    onCancel={() => setAddTo(null)}
                />
            )}

            {/* The documents changed but the last rebuild failed. Said to everyone, because the Reviewer must not trust an out-of-date PDF. */}
            {consolidated.stale && (
                <div className="space-y-2 rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm">
                    <p className="font-medium">The combined PDF is out of date</p>
                    <p className="text-muted-foreground">
                        The documents were changed, but the combined PDF could not be rebuilt
                        {consolidated.error ? ` (${consolidated.error.replace(/\.$/, '')})` : ''}. The viewer at the bottom shows the previous version
                        {consolidated.built_at ? `, built on ${consolidated.built_at}` : ''}. The page numbers in the index follow the latest files.
                    </p>
                    {documents.can_rebuild && (
                        <Button type="button" size="sm" disabled={busy} onClick={() => router.post(`${base}/rebuild`, {}, visit)}>
                            <RefreshCw className="size-4" /> {busy ? 'Rebuilding...' : 'Rebuild combined PDF'}
                        </Button>
                    )}
                </div>
            )}

            {/* 2. Page index. Each document is one card; its original file, and the void / replace form, open INSIDE that card. */}
            <section className="space-y-2">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h3 className="text-base font-medium">Page index</h3>
                    {typeOptions.length > 0 && (
                        <div className="flex items-center gap-2">
                            <Label htmlFor="po-doc-type-filter" className="text-sm">
                                Show
                            </Label>
                            <select
                                id="po-doc-type-filter"
                                value={activeFilter}
                                onChange={(e) => setTypeFilter(e.target.value)}
                                className="rounded-md border bg-background px-3 py-1.5 text-sm"
                            >
                                <option value="all">All documents ({rows.length})</option>
                                {typeOptions.map(([key, entry]) => (
                                    <option key={key} value={key}>
                                        {entry.label} ({entry.count})
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}
                </div>
                {reorderLocked && documents.can_reorder && (
                    <p className="text-sm text-muted-foreground">Showing {typeCounts.get(activeFilter)?.label} only. Show all documents to change the order.</p>
                )}

                {shownRows.length === 0 ? (
                    <p className="text-sm text-muted-foreground">No documents uploaded yet.</p>
                ) : (
                    <ol className="space-y-2">
                        {shownRows.map((d) => {
                            const isOpen = documents.can_view && previewId === d.id;
                            const isCorrecting = correction?.id === d.id;
                            const pages = pagesLabel(d);

                            return (
                                <li key={d.id} className={`space-y-3 rounded-md border p-3 ${isOpen ? 'border-primary/60 bg-muted/20' : ''}`}>
                                    <div className="flex flex-wrap items-start gap-3">
                                        <div className="min-w-14 rounded-md bg-muted px-2 py-1 text-center text-xs font-medium tabular-nums">
                                            <span className="block text-muted-foreground">{d.page_from !== null && d.page_from !== d.page_to ? 'Pages' : 'Page'}</span>
                                            {pages}
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <p className="break-words text-sm font-medium">{d.original_name}</p>
                                            <p className="text-xs text-muted-foreground">
                                                {d.type} · {formatSize(d.size_bytes)} · {orDash(d.uploaded_by)} · {orDash(d.uploaded_at)}
                                            </p>
                                            {d.void_reason && <p className="mt-0.5 text-xs text-muted-foreground">Void: {d.void_reason}</p>}
                                        </div>
                                        {d.status === 'void' ? <Badge variant="outline">Void</Badge> : <Badge variant="secondary">Active</Badge>}
                                    </div>

                                    {renderActions(d)}

                                    {isCorrecting && correction && renderCorrectionForm(correction)}

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
                                            <iframe
                                                key={d.id}
                                                src={`${base}/${d.id}/file`}
                                                title={`Original file ${d.original_name}`}
                                                className="h-[70vh] w-full rounded-md border bg-muted"
                                            />
                                        </div>
                                    )}
                                </li>
                            );
                        })}
                    </ol>
                )}
            </section>

            {/* 3. The combined PDF, last: every active document, in page order. "Go to page N" scrolls down to it. */}
            {documents.can_view_consolidated && (
                <section ref={viewerSection} className="scroll-mt-4 space-y-2 rounded-md border p-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="min-w-0">
                            <h3 className="text-base font-medium">
                                Combined PDF
                                {viewerPage !== null && viewerPage > 1 && (
                                    <span className="ml-2 text-sm font-normal text-muted-foreground">opened at page {viewerPage}</span>
                                )}
                            </h3>
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
                                key={`${consolidated.built_at ?? 'none'}-${viewerPage ?? 1}-${viewerNonce}`}
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