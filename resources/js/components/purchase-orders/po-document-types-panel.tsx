import { Check } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

// One line of the checklist. "count" is the number of ACTIVE files of that type; "selectable" = the upload form offers the type.
export type ChecklistItem = { key: string; label: string; required: boolean; count: number; selectable: boolean };

// The indicator comes from the SAMPLE list in config/mms.php. It is information only: Submit to Reviewer never waits for it.
export type ChecklistData = {
    is_sample: boolean;
    required_total: number;
    required_done: number;
    complete: boolean;
    missing: string[];
    items: ChecklistItem[];
};

type Props = {
    checklist: ChecklistData;
    activeFilter: string; // "all" or a type key
    onFilter: (key: string) => void;
    canAdd: boolean;
    onAddFiles: (key: string) => void;
};

export default function PoDocumentTypesPanel({ checklist, activeFilter, onFilter, canAdd, onAddFiles }: Props) {
    return (
        <section className="space-y-2 rounded-md border p-3">
            <div className="flex flex-wrap items-center gap-2">
                <h3 className="text-base font-medium">Document types</h3>
                {checklist.is_sample && <Badge variant="outline">Sample list</Badge>}
            </div>

            <p className="text-sm text-muted-foreground">
                {checklist.required_total > 0
                    ? `${checklist.required_done} of ${checklist.required_total} required ${checklist.required_total === 1 ? 'type' : 'types'} uploaded.`
                    : 'No type is required in the list.'}
                {checklist.missing.length > 0 && ` Missing: ${checklist.missing.join(', ')}. This is information only: you can still submit the PO.`}
            </p>

            <ul className="divide-y">
                {checklist.items.map((item) => (
                    <li key={item.key} className="flex flex-wrap items-center gap-2 py-2 text-sm">
                        <span className="min-w-0 flex-1 break-words font-medium">{item.label}</span>
                        {item.required && <Badge variant="secondary">Required</Badge>}
                        <span className="tabular-nums text-muted-foreground">
                            {item.count} {item.count === 1 ? 'file' : 'files'}
                        </span>
                        {item.required &&
                            (item.count > 0 ? (
                                <span className="flex items-center gap-1 text-muted-foreground">
                                    <Check className="size-4" /> Uploaded
                                </span>
                            ) : (
                                <Badge variant="outline">Missing</Badge>
                            ))}
                        {item.count > 0 && (
                            <Button type="button" size="sm" variant={activeFilter === item.key ? 'default' : 'outline'} onClick={() => onFilter(item.key)}>
                                {activeFilter === item.key ? 'Show all' : 'Show only these'}
                            </Button>
                        )}
                        {canAdd && item.selectable && item.count > 0 && (
                            <Button type="button" size="sm" variant="outline" onClick={() => onAddFiles(item.key)}>
                                Add files
                            </Button>
                        )}
                    </li>
                ))}
            </ul>
        </section>
    );
}