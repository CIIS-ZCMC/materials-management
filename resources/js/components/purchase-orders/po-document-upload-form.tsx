import { router } from '@inertiajs/react';
import { FolderOpen, Plus, Trash2, Upload, X } from 'lucide-react';
import { useRef, useState, type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { SectionCard } from '@/components/section-card';
import { Label } from '@/components/ui/label';

// A document type the upload form offers (stage "po", or "delivery" on the delivery page, of the list in config/mms.php).
export type UploadType = { key: string; label: string };

// One row of the form: one type and all the files chosen for it. "id" only keeps React's rows apart.
type Row = { id: number; type: string; files: File[] };

type Props = {
    // Where the files are posted. Default: the PO's documents route. The delivery page passes its own (step 5.3).
    poId?: number;
    postUrl?: string;
    types: UploadType[];
    accept: string;
    maxMb: string;
    maxFiles: number;
    maxTotalMb: number;
    isDraft: boolean;
    // Set by the "Add files" action of a type: one row, the type fixed, no "Add another type".
    lockedType?: string | null;
    onDone?: () => void;
    onCancel?: () => void;
    // True when the form already sits inside a card (the delivery page): it then draws a plain inner block instead of its own card.
    embedded?: boolean;
};

function formatSize(bytes: number): string {
    if (bytes >= 1024 * 1024) {
        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    }

    return `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

function fileKey(file: File): string {
    return `${file.name}|${file.size}|${file.lastModified}`;
}

export default function PoDocumentUploadForm({ poId, postUrl, types, accept, maxMb, maxFiles, maxTotalMb, isDraft, lockedType = null, onDone, onCancel, embedded = false }: Props) {
    // React state, all of it only for this form:
    // - "rows" are the type rows with their files (nothing is sent until Upload);
    // - "errors" are the server's messages, keyed like the request: groups.0.type, groups.0.files.1, groups, form;
    // - "processing" blocks the buttons while the request runs.
    // The real <input type="file"> of each row is hidden; the visible "Choose files" button clicks it through "inputs".
    const nextId = useRef(2);
    const inputs = useRef<Record<number, HTMLInputElement | null>>({});
    const [rows, setRows] = useState<Row[]>([{ id: 1, type: lockedType ?? '', files: [] }]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const labelOf = (key: string) => types.find((type) => type.key === key)?.label ?? key;
    const allFiles = rows.flatMap((row) => row.files);
    const totalBytes = allFiles.reduce((sum, file) => sum + file.size, 0);
    const canAddRow = !lockedType && rows.length < types.length;

    // Checked here first so a person is not left waiting for a request the server would refuse (the server checks again).
    let problem: string | null = null;

    if (allFiles.length > maxFiles) {
        problem = `Too many files (${allFiles.length}). Upload at most ${maxFiles} files at once.`;
    } else if (totalBytes > maxTotalMb * 1024 * 1024) {
        problem = `The files together are ${formatSize(totalBytes)}. The limit is ${maxTotalMb} MB for one upload; upload some of them separately.`;
    } else {
        const tooBig = allFiles.find((file) => file.size > Number(maxMb) * 1024 * 1024);

        if (tooBig) {
            problem = `${tooBig.name} is bigger than ${maxMb} MB.`;
        }
    }

    const ready = rows.length > 0 && rows.every((row) => row.type !== '' && row.files.length > 0) && problem === null;

    function addFiles(rowId: number, list: FileList | null) {
        // Copy first: clearing the input afterwards empties the live FileList.
        const incoming = Array.from(list ?? []);

        if (incoming.length === 0) {
            return;
        }

        setRows((current) =>
            current.map((row) => {
                if (row.id !== rowId) {
                    return row;
                }

                const known = new Set(row.files.map(fileKey));

                return { ...row, files: [...row.files, ...incoming.filter((file) => !known.has(fileKey(file)))] };
            }),
        );
    }

    function removeFile(rowId: number, index: number) {
        setRows((current) => current.map((row) => (row.id === rowId ? { ...row, files: row.files.filter((_, i) => i !== index) } : row)));
    }

    function reset() {
        setRows([{ id: nextId.current++, type: lockedType ?? '', files: [] }]);
        setErrors({});
    }

    function submit(event: FormEvent) {
        event.preventDefault();

        if (!ready) {
            return;
        }

        // forceFormData: files cannot travel as JSON, so Inertia sends a normal form upload (groups[0][type], groups[0][files][0], ...).
        router.post(
            postUrl ?? `/purchase-orders/${poId}/documents`,
            { groups: rows.map((row) => ({ type: row.type, files: row.files })) },
            {
                forceFormData: true,
                preserveScroll: true,
                onStart: () => {
                    setProcessing(true);
                    setErrors({});
                },
                onError: (serverErrors: Record<string, string>) => setErrors(serverErrors),
                onSuccess: () => {
                    reset();
                    onDone?.();
                },
                onFinish: () => setProcessing(false),
            },
        );
    }

    const title = lockedType ? `Add files to ${labelOf(lockedType)}` : 'Upload documents';

    const body = (
        <form onSubmit={submit} className="space-y-4">

            {rows.map((row, i) => {
                const chosenElsewhere = rows.filter((other) => other.id !== row.id).map((other) => other.type);

                return (
                    <div key={row.id} className="space-y-3 rounded-lg border bg-muted/30 p-3">
                        <div className="flex flex-wrap items-end gap-2">
                            <div className="min-w-48 flex-1 space-y-1.5">
                                <Label htmlFor={`po-doc-type-${row.id}`}>Document type</Label>
                                <select
                                    id={`po-doc-type-${row.id}`}
                                    value={row.type}
                                    disabled={lockedType !== null || processing}
                                    onChange={(e) => setRows((current) => current.map((r) => (r.id === row.id ? { ...r, type: e.target.value } : r)))}
                                    className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                                >
                                    <option value="">Choose a type...</option>
                                    {types.map((type) => (
                                        <option key={type.key} value={type.key} disabled={chosenElsewhere.includes(type.key)}>
                                            {type.label}
                                            {chosenElsewhere.includes(type.key) ? ' (chosen in another row)' : ''}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <Button type="button" variant="outline" disabled={processing} onClick={() => inputs.current[row.id]?.click()}>
                                <FolderOpen className="size-4" /> Choose files
                            </Button>
                            {rows.length > 1 && !lockedType && (
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    aria-label="Remove this row"
                                    disabled={processing}
                                    onClick={() => setRows((current) => current.filter((r) => r.id !== row.id))}
                                >
                                    <Trash2 className="size-4" />
                                </Button>
                            )}
                        </div>
                        <input
                            ref={(element) => {
                                inputs.current[row.id] = element;
                            }}
                            type="file"
                            multiple
                            className="sr-only"
                            accept={accept}
                            aria-label={`Files for row ${i + 1}`}
                            onChange={(e) => {
                                addFiles(row.id, e.target.files);
                                e.target.value = '';
                            }}
                        />

                        {row.files.length === 0 ? (
                            <p className="text-sm text-muted-foreground">No files chosen. You can choose several at once, or choose more later.</p>
                        ) : (
                            <ul className="space-y-1">
                                {row.files.map((file, j) => (
                                    <li key={fileKey(file)} className="text-sm">
                                        <span className="flex items-center gap-2">
                                            <span className="min-w-0 flex-1 break-words">
                                                {file.name} <span className="text-muted-foreground">({formatSize(file.size)})</span>
                                            </span>
                                            <Button
                                                type="button"
                                                size="icon"
                                                variant="ghost"
                                                aria-label={`Remove ${file.name} from this upload`}
                                                disabled={processing}
                                                onClick={() => removeFile(row.id, j)}
                                            >
                                                <X className="size-4" />
                                            </Button>
                                        </span>
                                        {errors[`groups.${i}.files.${j}`] && <span className="block text-destructive">{errors[`groups.${i}.files.${j}`]}</span>}
                                    </li>
                                ))}
                            </ul>
                        )}

                        {errors[`groups.${i}.type`] && <p className="text-sm text-destructive">{errors[`groups.${i}.type`]}</p>}
                        {errors[`groups.${i}.files`] && <p className="text-sm text-destructive">{errors[`groups.${i}.files`]}</p>}
                    </div>
                );
            })}

            {canAddRow && (
                <Button type="button" size="sm" variant="outline" disabled={processing} onClick={() => setRows((current) => [...current, { id: nextId.current++, type: '', files: [] }])}>
                    <Plus className="size-4" /> Add another type
                </Button>
            )}

            <p className="rounded-lg bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
                PDF, JPG or PNG, up to {maxMb} MB per file, {maxFiles} files and {maxTotalMb} MB in one upload. New files go after the earlier ones; use the
                arrows to change the order.
                {isDraft && ' The PO stays a draft, and the PO Reviewer cannot see it, until you click Submit to Reviewer.'}
            </p>

            {problem && <p className="text-sm text-destructive">{problem}</p>}
            {errors.groups && <p className="text-sm text-destructive">{errors.groups}</p>}
            {errors.form && <p className="text-sm text-destructive">{errors.form}</p>}

            <div className="flex flex-wrap items-center gap-2">
                <Button type="submit" size="sm" disabled={processing || !ready}>
                    <Upload className="size-4" />{' '}
                    {processing ? 'Uploading and combining...' : allFiles.length > 1 ? `Upload ${allFiles.length} files` : 'Upload'}
                </Button>
                {onCancel && (
                    <Button type="button" size="sm" variant="ghost" disabled={processing} onClick={onCancel}>
                        Cancel
                    </Button>
                )}
            </div>
        </form>
    );

    if (embedded) {
        return (
            <div className="space-y-3 rounded-lg border bg-background p-4">
                <h4 className="text-sm font-semibold">{title}</h4>
                {body}
            </div>
        );
    }

    return (
        <SectionCard title={title} description="Choose a type, then the files for it. Nothing is sent until you click Upload.">
            {body}
        </SectionCard>
    );
}