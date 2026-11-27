import { Check } from 'lucide-react';
import { Notice, SectionCard } from '@/components/section-card';
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
    const percent = checklist.required_total > 0 ? Math.round((checklist.required_done / checklist.required_total) * 100) : 0;

    return (
        <SectionCard
            title="Document types"
            description={
                checklist.required_total > 0
                    ? `${checklist.required_done} of ${checklist.required_total} required ${checklist.required_total === 1 ? 'type' : 'types'} uploaded.`
                    : 'No type is required in the list.'
            }
            actions={checklist.is_sample && <Badge variant="outline">Sample list</Badge>}
        >
            {checklist.required_total > 0 && (
                <div className="h-2 overflow-hidden rounded-full bg-muted" role="progressbar" aria-valuenow={percent} aria-valuemin={0} aria-valuemax={100} aria-label="Required types uploaded">
                    <div className="h-full rounded-full bg-primary transition-all" style={{ width: `${percent}%` }} />
                </div>
            )}

            {checklist.missing.length > 0 && (
                <Notice tone="warning">
                    Missing: {checklist.missing.join(', ')}. This is information only: you can still submit the PO.
                </Notice>
            )}

            <ul className="divide-y rounded-lg border bg-background">
                {checklist.items.map((item) => (
                    <li key={item.key} className="flex flex-wrap items-center gap-2 px-3 py-2 text-sm">
                        <span className="min-w-0 flex-1 font-medium break-words">{item.label}</span>
                        {item.required && <Badge variant="secondary">Required</Badge>}
                        <span className="text-muted-foreground tabular-nums">
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
        </SectionCard>
    );
}